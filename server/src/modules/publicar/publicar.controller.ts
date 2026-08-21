import type { Request, Response } from "express";
import fs from "node:fs/promises";
import path from "node:path";
import sharp from "sharp";
import { env } from "../../config/env.js";
import { contieneContacto } from "../../lib/contactoFilter.js";
import { abreviarInstitucion } from "../../lib/institucion.js";
import { tieneAlMenosUnBloque, validarHorariosJson } from "../../lib/horarios.js";
import { generarSlug } from "../../lib/slug.js";
import {
  actualizarPreviewApunte,
  actualizarSlugServicio,
  eliminarServicioIncompleto as repoEliminarServicioIncompleto,
  elegirImagenBancoPorCategoria,
  getAlumnoParaPublicar,
  guardarHorarioServicio as repoGuardarHorarioServicio,
  incrementarContadorPublicaciones,
  insertarApunte,
  insertarServicio,
} from "./publicar.repository.js";
import type { HorariosJson } from "../../lib/horarios.js";

// Puerto de app/publicar_servicio.php + app/formulario_subir_apunte.php — SOLO el camino
// de creación real de contenido. Excluido a propósito (confirmado con el usuario antes de
// construir, no un olvido):
//   - Generación de descripción/categorización por IA (Gemini) — depende de
//     app/datos/ia_nubira.php, que CLAUDE.md ya documenta como desactivado en producción
//     pendiente de una decisión de reactivación aparte.
//   - Pago de republicación de servicio ($3.000, 2da publicación en adelante) y compra de
//     créditos IA — ambos vía MercadoPago, dinero real.
//   - Video de presentación de servicio (opcional en el form real).
//   - actualizar_score_servicio() (gamificación de score_nubira) y enviar_push_nubira()
//     (aviso push al admin) — quedan en 0/sin enviar; no bloquean la creación.
//   - Preview/portada de PDF: el PHP real la genera server-side con Imagick (sin
//     equivalente directo en Node). Acá se cierra distinto — pdf.js renderiza la página 1
//     client-side (web/src/lib/pdfPreview.ts) y sube ese blob junto al archivo real; sharp
//     lo normaliza a webp server-side, igual que la ruta de imágenes. Simplificación real
//     respecto al PHP: siempre página 1, SIN el selector de portada multi-página
//     (selector-portada-container) del form real.

const PRECIO_MINIMO_SERVICIO = 10000;

function resolverInstitucion(institucionDb: string | null, universidad: string | null): string {
  const institucionLimpia = (institucionDb ?? "").trim();
  if (institucionLimpia !== "") return institucionLimpia.slice(0, 50);
  const universidadLimpia = (universidad ?? "").trim();
  if (universidadLimpia === "") return "";
  return abreviarInstitucion(universidadLimpia, 50);
}

export async function crearServicio(req: Request, res: Response): Promise<void> {
  const usuarioId = req.usuarioId as number;
  const body = req.body as Record<string, unknown>;

  const titulo = typeof body.titulo === "string" ? body.titulo.trim() : "";
  const descripcion = typeof body.descripcion === "string" ? body.descripcion.trim() : "";
  const categoria = typeof body.categoria === "string" ? body.categoria.trim() : "";
  const modalidad = typeof body.modalidad === "string" ? body.modalidad.trim() : "Online";
  const ubicacion = typeof body.ubicacion === "string" ? body.ubicacion.trim() : "";
  const precio = Number(body.precio ?? 0);
  const esPaes = body.esPaes === true;

  if (titulo.length > 70 || descripcion.length > 1500) {
    res.status(400).json({ ok: false, error: "titulo_o_descripcion_excede_limite" });
    return;
  }
  if (!titulo || !descripcion || !categoria || !modalidad) {
    res.status(400).json({ ok: false, error: "campos_obligatorios_faltantes" });
    return;
  }
  if (!Number.isFinite(precio) || precio < PRECIO_MINIMO_SERVICIO) {
    res.status(400).json({ ok: false, error: "precio_bajo_minimo" });
    return;
  }
  if (contieneContacto(titulo) || contieneContacto(descripcion)) {
    res.status(400).json({ ok: false, error: "contiene_contacto" });
    return;
  }

  const alumno = await getAlumnoParaPublicar(usuarioId);
  if (!alumno) {
    res.status(404).json({ ok: false, error: "usuario_no_encontrado" });
    return;
  }

  // Cupo: 1 publicación gratis de por vida. Desde la 2da, el PHP real cobra $3.000 vía
  // MercadoPago — excluido de esta pasada (ver nota de alcance arriba), así que acá se
  // corta con un error explícito en vez de crear un registro 'pendiente_pago' que después
  // no tiene forma de pagarse desde este panel.
  if (alumno.servicios_publicados_total > 0) {
    res.status(403).json({ ok: false, error: "cupo_gratis_agotado" });
    return;
  }

  const imagenBancoId = await elegirImagenBancoPorCategoria(categoria);
  if (imagenBancoId === null) {
    res.status(400).json({ ok: false, error: "sin_imagenes_para_categoria" });
    return;
  }

  const institucion = resolverInstitucion(alumno.institucion, alumno.universidad);
  const nombreOferente = alumno.nombre ?? "";
  const correo = alumno.correo ?? "";

  const servicioId = await insertarServicio(
    usuarioId,
    { titulo, descripcion, categoria, modalidad, ubicacion, precio, esPaes },
    institucion,
    nombreOferente,
    correo,
    imagenBancoId,
    "pendiente",
  );

  await incrementarContadorPublicaciones(usuarioId);

  const slug = generarSlug(titulo);
  if (slug) await actualizarSlugServicio(servicioId, slug);

  res.status(201).json({ ok: true, servicioId });
}

export async function guardarHorarioServicio(req: Request, res: Response): Promise<void> {
  const usuarioId = req.usuarioId as number;
  const servicioId = Number(req.params.id);
  if (!Number.isInteger(servicioId) || servicioId <= 0) {
    res.status(400).json({ ok: false, error: "id_invalido" });
    return;
  }

  const body = req.body as { horariosJson?: unknown };
  const horariosJson = typeof body.horariosJson === "string" ? body.horariosJson.trim() : "";
  if (!horariosJson) {
    res.status(400).json({ ok: false, error: "horarios_json_requerido" });
    return;
  }

  const errorValidacion = validarHorariosJson(horariosJson);
  if (errorValidacion !== null) {
    res.status(400).json({ ok: false, error: errorValidacion });
    return;
  }

  const parsed = JSON.parse(horariosJson) as HorariosJson;
  if (!tieneAlMenosUnBloque(parsed)) {
    res.status(400).json({ ok: false, error: "Debes marcar al menos un bloque de disponibilidad." });
    return;
  }

  const guardado = await repoGuardarHorarioServicio(servicioId, usuarioId, horariosJson);
  if (!guardado) {
    res.status(403).json({ ok: false, error: "No tienes permiso sobre este servicio." });
    return;
  }

  res.status(200).json({ ok: true });
}

export async function eliminarServicioIncompleto(req: Request, res: Response): Promise<void> {
  const usuarioId = req.usuarioId as number;
  const servicioId = Number(req.params.id);
  if (!Number.isInteger(servicioId) || servicioId <= 0) {
    res.status(400).json({ ok: false, error: "id_invalido" });
    return;
  }

  const borrado = await repoEliminarServicioIncompleto(servicioId, usuarioId);
  res.status(200).json({ ok: borrado });
}

const EXTS_IMAGEN = new Set(["jpg", "jpeg", "png", "webp"]);
const EXTS_VALIDAS = new Set(["jpg", "jpeg", "png", "webp", "pdf"]);
const NIVELES_VALIDOS = new Set(["universitario", "paes", "escolar"]);
const MATERIAS_VALIDAS = new Set([
  "calculo",
  "fisica",
  "algebra",
  "programacion",
  "quimica",
  "biologia",
  "contabilidad",
  "economia",
  "derecho",
  "psicologia",
  "idiomas",
  "redaccion",
]);

// Sniffing por magic bytes — equivalente a finfo_file() de PHP (contenido real, no solo la
// extensión del nombre de archivo declarado por el cliente). Solo los 4 formatos que este
// formulario acepta, evita agregar una librería nueva para esto.
function detectarTipoReal(buffer: Buffer): "jpg" | "png" | "webp" | "pdf" | null {
  if (buffer.length < 12) return null;
  if (buffer[0] === 0xff && buffer[1] === 0xd8 && buffer[2] === 0xff) return "jpg";
  if (buffer[0] === 0x89 && buffer[1] === 0x50 && buffer[2] === 0x4e && buffer[3] === 0x47) return "png";
  if (buffer.toString("ascii", 0, 4) === "RIFF" && buffer.toString("ascii", 8, 12) === "WEBP") return "webp";
  if (buffer.toString("ascii", 0, 4) === "%PDF") return "pdf";
  return null;
}

export async function crearApunte(req: Request, res: Response): Promise<void> {
  const usuarioId = req.usuarioId as number;
  // multer.fields() entrega req.files como { [campo]: Multer.File[] } en vez del
  // req.file único de .single() — dos campos posibles: "archivo" (obligatorio) y
  // "preview" (opcional, solo para PDF, ver web/src/lib/pdfPreview.ts).
  const archivos = req.files as Record<string, Express.Multer.File[]> | undefined;
  const archivo = archivos?.archivo?.[0];
  const previewSubido = archivos?.preview?.[0];
  const body = req.body as Record<string, unknown>;

  const titulo = typeof body.titulo === "string" ? body.titulo.trim() : "";
  const descripcion = typeof body.descripcion === "string" ? body.descripcion.trim() : "";
  const semestre = Number(body.semestre ?? 1);
  const anio = Number(body.anio ?? new Date().getFullYear());
  const precio = Math.max(0, Number(body.precio ?? 0));
  const asignatura = typeof body.asignatura === "string" && body.asignatura.trim() !== "" ? body.asignatura.trim() : "General";
  const materiaCruda = typeof body.materia === "string" ? body.materia.trim() : "";
  const materia = MATERIAS_VALIDAS.has(materiaCruda) ? materiaCruda : null;
  const nivelCrudo = typeof body.nivelAcademico === "string" ? body.nivelAcademico.trim() : "";
  const nivelAcademico = (NIVELES_VALIDOS.has(nivelCrudo) ? nivelCrudo : "universitario") as "universitario" | "paes" | "escolar";
  const subtemaCrudo = typeof body.subtema === "string" ? body.subtema.trim().slice(0, 80) : "";
  const subtema = subtemaCrudo === "" ? null : subtemaCrudo;

  if (!titulo) {
    res.status(400).json({ error: "El título es obligatorio" });
    return;
  }
  if (titulo.length > 80) {
    res.status(400).json({ error: "El título no puede superar 80 caracteres" });
    return;
  }
  if (!descripcion) {
    res.status(400).json({ error: "La descripción es obligatoria" });
    return;
  }
  if (!archivo) {
    res.status(400).json({ error: "No se seleccionó ningún archivo" });
    return;
  }

  const extDeclarada = path.extname(archivo.originalname).slice(1).toLowerCase();
  if (!EXTS_VALIDAS.has(extDeclarada)) {
    res.status(400).json({ error: "Solo se aceptan archivos PDF o imágenes (.jpg, .jpeg, .png, .webp)" });
    return;
  }

  const tipoReal = detectarTipoReal(archivo.buffer);
  const extNormalizada = extDeclarada === "jpeg" ? "jpg" : extDeclarada;
  if (!tipoReal || tipoReal !== extNormalizada) {
    res.status(400).json({ error: "El contenido del archivo no coincide con su extensión." });
    return;
  }

  const alumno = await getAlumnoParaPublicar(usuarioId);
  const institucion = resolverInstitucion(alumno?.institucion ?? null, alumno?.universidad ?? null);

  const nombreArchivo = `${Date.now()}_${Math.floor(1000 + Math.random() * 9000)}.${extDeclarada}`;
  const dirApuntes = path.join(env.uploadDir, "apuntes");
  const dirPreview = path.join(env.uploadDir, "preview");
  await fs.mkdir(dirApuntes, { recursive: true });
  await fs.mkdir(dirPreview, { recursive: true });
  await fs.writeFile(path.join(dirApuntes, nombreArchivo), archivo.buffer);

  const apunteId = await insertarApunte(
    usuarioId,
    {
      titulo,
      descripcion,
      semestre: Number.isInteger(semestre) ? semestre : 1,
      anio: Number.isInteger(anio) ? anio : new Date().getFullYear(),
      precio: Number.isInteger(precio) ? precio : 0,
      asignatura,
      materia,
      nivelAcademico,
      subtema,
    },
    institucion,
    nombreArchivo,
  );

  // Preview real: para imágenes, sharp sobre el propio archivo (~ equivalente directo de
  // la ruta GD del PHP real). Para PDF, sharp sobre el blob que el navegador ya renderizó
  // client-side con pdf.js (página 1, ver web/src/lib/pdfPreview.ts) — nunca se intenta
  // procesar el PDF en sí server-side (sin equivalente a Imagick en Node). Pasar SIEMPRE
  // por sharp acá (no solo copiar el blob tal cual) normaliza a webp real sin importar qué
  // formato/calidad haya mandado el navegador.
  const bufferParaPreview = EXTS_IMAGEN.has(extDeclarada) ? archivo.buffer : previewSubido?.buffer;
  if (bufferParaPreview) {
    try {
      const nombrePreview = `${apunteId}.webp`;
      await sharp(bufferParaPreview)
        .resize({ width: 1200, withoutEnlargement: true })
        .webp({ quality: 70 })
        .toFile(path.join(dirPreview, nombrePreview));
      await actualizarPreviewApunte(apunteId, nombrePreview);
    } catch {
      // Mismo criterio que el try/catch de formulario_subir_apunte.php: una falla acá no
      // debe tumbar la publicación ya guardada, solo queda sin preview.
    }
  }

  res.status(201).json({ success: true, id: apunteId });
}
