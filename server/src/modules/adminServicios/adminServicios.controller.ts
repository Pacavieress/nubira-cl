import type { Request, Response } from "express";
import { env } from "../../config/env.js";
import { resolverPortada } from "../../lib/media.js";
import { actualizarVisibilidad, listarServiciosAdmin } from "./adminServicios.repository.js";
import type { ServicioAdmin } from "./adminServicios.types.js";

export async function getServiciosAdmin(req: Request, res: Response): Promise<void> {
  const q = typeof req.query.q === "string" ? req.query.q.trim() : "";
  const filas = await listarServiciosAdmin(q || undefined);

  const body: ServicioAdmin[] = filas.map((s) => ({
    id: s.id,
    titulo: s.titulo,
    nombreOferente: s.nombre_oferente,
    nombreAlumno: s.nombre_alumno,
    categoria: s.categoria,
    estado: s.estado,
    motivoRechazo: s.motivo_rechazo,
    visible: s.visible === null || s.visible === 1,
    fechaPublicacion: s.fecha_publicacion.toISOString(),
    portadaUrl: resolverPortada(s.banco_archivo, s.imagen, env.assetsBaseUrl).main,
  }));

  res.status(200).json(body);
}

// Puerto de la rama 'toggle_visibilidad' de admin_servicios_accion.php — única acción de
// escritura portada en esta pieza (ver nota de alcance en adminServicios.types.ts).
export async function putVisibilidad(req: Request, res: Response): Promise<void> {
  const id = Number(req.params.id);
  const body = req.body as { visible?: unknown };

  if (!Number.isInteger(id) || id <= 0 || typeof body.visible !== "boolean") {
    res.status(400).json({ error: "datos_invalidos" });
    return;
  }

  const actualizado = await actualizarVisibilidad(id, body.visible);
  if (!actualizado) {
    res.status(404).json({ error: "not_found" });
    return;
  }
  res.status(200).json({ ok: true, visible: body.visible });
}
