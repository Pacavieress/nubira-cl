import type { Request, Response } from "express";
import { actualizarBloqueo, contarPendientes, listarReportes } from "./adminReportesServicios.repository.js";
import type { EstadoReporte, ReporteServicio, ReportesResumen } from "./adminReportesServicios.types.js";

function normalizarEstado(v: unknown): EstadoReporte {
  return v === "revisados" || v === "todos" ? v : "pendientes";
}

export async function getReportes(req: Request, res: Response): Promise<void> {
  const estado = normalizarEstado(req.query.estado);
  const [countPendientes, filas] = await Promise.all([contarPendientes(), listarReportes(estado)]);

  const reportes: ReporteServicio[] = filas.map((r) => ({
    id: r.id,
    servicioId: r.servicio_id,
    tituloServicio: r.titulo_servicio,
    motivo: r.motivo,
    mensaje: r.mensaje,
    fecha: r.fecha.toISOString(),
    revisado: r.revisado === 1,
    usuarioReporta: { nombre: r.usuario_reporta, correo: r.correo_reporta },
    usuarioReportado: { id: r.id_usuario_reportado, nombre: r.usuario_reportado, correo: r.correo_reportado, bloqueado: r.bloqueado_reportado === 1 },
  }));

  const body: ReportesResumen = { estado, countPendientes, reportes };
  res.status(200).json(body);
}

// Puerto de la rama 'bloquear_usuario' (admin_reportes_servicios.php:46-59).
export async function putBloqueoUsuario(req: Request, res: Response): Promise<void> {
  const id = Number(req.params.id);
  const body = req.body as { bloqueado?: unknown };

  if (!Number.isInteger(id) || id <= 0 || typeof body.bloqueado !== "boolean") {
    res.status(400).json({ error: "datos_invalidos" });
    return;
  }

  const actualizado = await actualizarBloqueo(id, body.bloqueado);
  if (!actualizado) {
    res.status(404).json({ error: "not_found" });
    return;
  }
  res.status(200).json({ ok: true, bloqueado: body.bloqueado });
}
