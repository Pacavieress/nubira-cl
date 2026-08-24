import type { RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";
import type { EstadoSolicitud } from "./adminSolicitudes.types.js";

interface SolicitudRow extends RowDataPacket {
  id: number;
  institucion: string;
  email: string;
  fecha: Date | null;
  estado: "pendiente" | "revisada";
  correo_enviado: number;
}

// Puerto exacto del SQL de admin_solicitudes.php:102-119 — mismo WHERE condicional según
// estado (pendiente/revisada/sin filtro), mismo ORDER BY fecha DESC.
export async function listarSolicitudes(estado: EstadoSolicitud): Promise<SolicitudRow[]> {
  const where = estado === "pendiente" || estado === "revisada" ? "WHERE estado = ?" : "";
  const params = where ? [estado] : [];

  const [rows] = await pool.query<SolicitudRow[]>(
    `SELECT id, institucion, email, fecha, estado, correo_enviado
     FROM solicitudes_instituciones
     ${where}
     ORDER BY fecha DESC`,
    params,
  );
  return rows;
}
