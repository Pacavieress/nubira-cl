import type { Request, Response } from "express";
import { listarSolicitudes } from "./adminSolicitudes.repository.js";
import type { EstadoSolicitud, SolicitudInstitucion, SolicitudesResumen } from "./adminSolicitudes.types.js";

function normalizarEstado(v: unknown): EstadoSolicitud {
  return v === "pendiente" || v === "revisada" ? v : "";
}

export async function getSolicitudes(req: Request, res: Response): Promise<void> {
  const estado = normalizarEstado(req.query.estado);
  const filas = await listarSolicitudes(estado);

  const solicitudes: SolicitudInstitucion[] = filas.map((r) => ({
    id: r.id,
    institucion: r.institucion,
    email: r.email,
    fecha: r.fecha ? r.fecha.toISOString() : null,
    estado: r.estado,
    correoEnviado: r.correo_enviado === 1,
  }));

  const body: SolicitudesResumen = { estado, solicitudes };
  res.status(200).json(body);
}
