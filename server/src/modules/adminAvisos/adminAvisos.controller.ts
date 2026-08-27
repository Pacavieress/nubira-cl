import type { Request, Response } from "express";
import { env } from "../../config/env.js";
import { esDobleSubmit } from "../../lib/idempotencyGuard.js";
import {
  buscarUsuarios,
  crearCampanaConDestinatarios,
  existeUsuarioValido,
  getStats,
  listarCampanas,
  listarImagenesDeCampanas,
  listarLectores,
  resolverDestinatarios,
} from "./adminAvisos.repository.js";
import { SEGMENTOS_AVISO, TIPOS_AVISO, type AvisoCampana, type AvisoLector, type AvisosResumen, type SegmentoAviso, type TipoAviso } from "./adminAvisos.types.js";

export async function getAvisos(_req: Request, res: Response): Promise<void> {
  const [stats, campanas] = await Promise.all([getStats(), listarCampanas()]);

  const campanaIds = campanas.map((c) => c.id);
  const imagenes = await listarImagenesDeCampanas(campanaIds);

  const body: AvisosResumen = {
    totalCampanas: Number(stats.total ?? 0),
    totalDestinatarios: Number(stats.destinatarios ?? 0),
    campanas: campanas.map(
      (c): AvisoCampana => ({
        id: c.id,
        titulo: c.titulo,
        mensaje: c.mensaje,
        tipo: c.tipo,
        segmento: c.segmento,
        totalDestinatarios: c.total_destinatarios,
        leidos: Number(c.leidos),
        fechaCreacion: c.fecha_creacion.toISOString(),
        imagenes: imagenes
          .filter((img) => img.campana_id === c.id)
          .map((img) => ({
            archivo: img.archivo,
            url: `${env.assetsBaseUrl}/upload/avisos/${c.id}/${img.archivo}`,
          })),
      }),
    ),
  };

  res.status(200).json(body);
}

export async function getLectoresDeCampana(req: Request, res: Response): Promise<void> {
  const campanaId = Number(req.params.id);
  if (!Number.isInteger(campanaId) || campanaId <= 0) {
    res.status(400).json({ error: "id_invalido" });
    return;
  }

  const filas = await listarLectores(campanaId);
  const body: AvisoLector[] = filas.map((r) => ({
    nombre: r.nombre,
    institucion: r.institucion,
    fechaLeido: r.fecha_leido.toISOString(),
  }));

  res.status(200).json(body);
}

// mb_strlen cuenta codepoints Unicode (charset UTF-8 del PHP real) — Array.from() itera por
// codepoint en vez de unidad UTF-16, mismo conteo que PHP para el rango de caracteres
// realista acá (tildes, ñ, emoji ocasional en el mensaje).
function longitudUnicode(s: string): number {
  return Array.from(s).length;
}

// Puerto de admin_enviar_aviso_masivo.php:29-234 — crea la campaña y los N avisos
// individuales en una transacción atómica (adminAvisos.repository.ts::crearCampanaConDestinatarios),
// SIN la sección de imágenes (ver nota de alcance en adminAvisos.types.ts). Guard
// anti-doble-submit agregado (el PHP real no lo tenía) — mismo admin + mismo contenido +
// mismo segmento dentro de 15s se rechaza con 409 en vez de crear 2 campañas duplicadas.
export async function postCrearCampana(req: Request, res: Response): Promise<void> {
  const adminId = req.usuarioId;
  if (!adminId) {
    res.status(401).json({ error: "no_autenticado" });
    return;
  }

  const body = req.body as { titulo?: unknown; mensaje?: unknown; tipo?: unknown; segmento?: unknown; usuarioId?: unknown };
  const titulo = typeof body.titulo === "string" ? body.titulo.trim() : "";
  const mensaje = typeof body.mensaje === "string" ? body.mensaje.trim() : "";
  const tipo = TIPOS_AVISO.includes(body.tipo as TipoAviso) ? (body.tipo as TipoAviso) : null;
  const segmento = SEGMENTOS_AVISO.includes(body.segmento as SegmentoAviso) ? (body.segmento as SegmentoAviso) : null;
  const usuarioId = Number.isInteger(body.usuarioId) && (body.usuarioId as number) > 0 ? (body.usuarioId as number) : null;

  if (longitudUnicode(titulo) < 3 || longitudUnicode(titulo) > 150) {
    res.status(400).json({ error: "titulo_invalido", mensaje: "El título debe tener entre 3 y 150 caracteres." });
    return;
  }
  if (longitudUnicode(mensaje) < 5 || longitudUnicode(mensaje) > 1000) {
    res.status(400).json({ error: "mensaje_invalido", mensaje: "El mensaje debe tener entre 5 y 1000 caracteres." });
    return;
  }
  if (!tipo) {
    res.status(400).json({ error: "tipo_invalido" });
    return;
  }
  if (!segmento) {
    res.status(400).json({ error: "segmento_invalido" });
    return;
  }
  if (segmento === "usuario") {
    if (!usuarioId) {
      res.status(400).json({ error: "usuario_requerido", mensaje: "Debes seleccionar un usuario." });
      return;
    }
    if (!(await existeUsuarioValido(usuarioId))) {
      res.status(400).json({ error: "usuario_invalido", mensaje: "Usuario inválido o inactivo." });
      return;
    }
  }

  if (esDobleSubmit(`avisos:${adminId}:${titulo}:${mensaje}:${segmento}:${usuarioId ?? ""}`)) {
    res.status(409).json({ error: "doble_envio", mensaje: "Esta campaña ya se envió hace unos segundos." });
    return;
  }

  const destinatarios = await resolverDestinatarios(segmento, usuarioId);
  if (destinatarios.length === 0) {
    res.status(400).json({ error: "sin_destinatarios", mensaje: "No hay destinatarios para este segmento." });
    return;
  }

  const campanaId = await crearCampanaConDestinatarios(adminId, titulo, mensaje, tipo, segmento, destinatarios);
  res.status(201).json({ campanaId, enviados: destinatarios.length });
}

// Puerto exacto de admin_buscar_usuarios.php — usado por el segmento "usuario específico"
// del formulario de nueva campaña.
export async function getBuscarUsuarios(req: Request, res: Response): Promise<void> {
  const q = typeof req.query.q === "string" ? req.query.q.trim() : "";
  if (longitudUnicode(q) < 2) {
    res.status(200).json([]);
    return;
  }
  const usuarios = await buscarUsuarios(q);
  res.status(200).json(usuarios);
}
