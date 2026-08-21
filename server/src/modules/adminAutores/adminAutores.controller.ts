import type { Request, Response } from "express";
import { env } from "../../config/env.js";
import { listarAutores } from "./adminAutores.repository.js";
import type { AutorServicio } from "./adminAutores.types.js";

// Puerto de admin_autores_servicios.php:193-194 — SOLO la carpeta legacy /upload/servicios/
// (columna servicios.imagen cruda, sin pasar por el banco de imágenes), con el mismo
// placeholder por defecto. Deliberadamente NO usa resolverPortada() de media.ts: esa
// función resuelve el pipeline de banco_imagenes (3 tamaños webp), un caso distinto al que
// esta query real consulta (s2.imagen tal cual).
function resolverPortadaAutor(imagen: string | null): string {
  const archivo = imagen || "default_clases.webp";
  const ruta = `/upload/servicios/${archivo}`;
  return ruta.startsWith("http") ? ruta : `${env.assetsBaseUrl}${ruta}`;
}

// Puerto de strip_tags() aplicado al mensaje en admin_autores_servicios.php:285 — el cuerpo
// del correo se guarda con HTML (saltos de línea como <br> u otro formato simple); acá se
// limpia antes de exponerlo en la API, mismo criterio que el PHP real.
function stripTags(html: string | null): string | null {
  if (html === null) return null;
  return html.replace(/<[^>]*>/g, "");
}

export async function getAutores(req: Request, res: Response): Promise<void> {
  const q = typeof req.query.q === "string" ? req.query.q.trim() : "";
  const filtro = req.query.filtro === "incompleto" ? "incompleto" : undefined;

  const filas = await listarAutores({ q: q || undefined, filtro });

  const body: AutorServicio[] = filas.map((r) => ({
    idUsuario: r.id_usuario,
    nombre: r.nombre_usuario,
    correo: r.correo,
    institucion: r.institucion,
    fotoPerfil: r.foto_perfil,
    bio: r.bio,
    tipo: r.tipo,
    cantidadServicios: r.cantidad_servicios,
    serviciosConHorario: r.servicios_con_horario,
    ultimaPublicacion: r.ultima_publicacion ? r.ultima_publicacion.toISOString() : null,
    totalConversaciones: r.total_conversaciones,
    portadaUrl: resolverPortadaAutor(r.portada_servicio),
    ultimoCorreo: r.fecha_ultimo_correo
      ? {
          asunto: r.ultimo_asunto,
          mensaje: stripTags(r.ultimo_mensaje),
          fecha: r.fecha_ultimo_correo.toISOString(),
          exito: r.exito_ultimo === 1,
        }
      : null,
  }));

  res.status(200).json(body);
}
