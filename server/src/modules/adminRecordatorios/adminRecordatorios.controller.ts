import type { Request, Response } from "express";
import { getResumenHoy, listarRecordatorios } from "./adminRecordatorios.repository.js";
import type { RecordatoriosResumen } from "./adminRecordatorios.types.js";

// Puerto de labelTipo() (admin_recordatorios.php:96-103).
function labelTipo(tipo: string): string {
  switch (tipo) {
    case "recordatorio_3dias":
      return "3 días – Publicar";
    case "recordatorio_7dias":
      return "7 días – Explorar";
    case "recordatorio_14dias":
      return "14 días – Reenganche";
    default:
      return tipo;
  }
}

export async function getRecordatorios(req: Request, res: Response): Promise<void> {
  const fecha = typeof req.query.fecha === "string" ? req.query.fecha : undefined;
  const tipo = typeof req.query.tipo === "string" ? req.query.tipo : undefined;
  const estado = typeof req.query.estado === "string" ? req.query.estado : undefined;

  const [{ enviadosHoy, pendientesHoy }, filas] = await Promise.all([
    getResumenHoy(),
    listarRecordatorios({ fecha, tipo, estado }),
  ]);

  const body: RecordatoriosResumen = {
    enviadosHoy,
    pendientesHoy,
    registros: filas.map((r) => ({
      id: r.id,
      alumno: r.alumno,
      correo: r.correo,
      tipo: labelTipo(r.tipo),
      etapa: r.etapa,
      programadoPara: r.programado_para.toISOString(),
      enviadoEn: r.enviado_en ? r.enviado_en.toISOString() : null,
      estado: r.estado,
      motivoOmision: r.motivo_omision,
    })),
  };

  res.status(200).json(body);
}
