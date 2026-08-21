import type { RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";
import type { RecordatoriosFiltros } from "./adminRecordatorios.types.js";

interface ResumenRow extends RowDataPacket {
  enviados: number | null;
  pendientes: number | null;
}

// Puerto exacto de admin_recordatorios.php:22-36 (resumen del día real, no del filtro
// activo — mismo criterio que el PHP: siempre HOY, sin importar qué fecha esté filtrada
// en la tabla de abajo).
export async function getResumenHoy(): Promise<{ enviadosHoy: number; pendientesHoy: number }> {
  const [rows] = await pool.query<ResumenRow[]>(
    `SELECT SUM(estado = 'enviado') AS enviados, SUM(estado != 'enviado') AS pendientes
     FROM acciones_pendientes
     WHERE DATE(enviado_en) = CURDATE()`,
  );
  const row = rows[0];
  return { enviadosHoy: Number(row?.enviados ?? 0), pendientesHoy: Number(row?.pendientes ?? 0) };
}

interface RecordatorioRow extends RowDataPacket {
  id: number;
  alumno: string | null;
  correo: string | null;
  tipo: string;
  etapa: number;
  programado_para: Date;
  enviado_en: Date | null;
  estado: string;
  motivo_omision: string | null;
}

// Puerto exacto de admin_recordatorios.php:43-93 (filtros dinámicos + LIMIT 300, mismo
// orden: programado_para DESC, id DESC).
export async function listarRecordatorios(filtros: RecordatoriosFiltros): Promise<RecordatorioRow[]> {
  const condiciones: string[] = [];
  const params: string[] = [];

  if (filtros.fecha) {
    condiciones.push("DATE(ap.enviado_en) = ?");
    params.push(filtros.fecha);
  }
  if (filtros.tipo) {
    condiciones.push("ap.tipo = ?");
    params.push(filtros.tipo);
  }
  if (filtros.estado) {
    condiciones.push("ap.estado = ?");
    params.push(filtros.estado);
  }

  const where = condiciones.length > 0 ? `WHERE ${condiciones.join(" AND ")}` : "";
  const [rows] = await pool.query<RecordatorioRow[]>(
    `SELECT ap.id, a.nombre AS alumno, a.correo, ap.tipo, ap.etapa, ap.programado_para, ap.enviado_en, ap.estado, ap.motivo_omision
     FROM acciones_pendientes ap
     LEFT JOIN alumnos a ON ap.alumno_id = a.id
     ${where}
     ORDER BY ap.programado_para DESC, ap.id DESC
     LIMIT 300`,
    params,
  );
  return rows;
}
