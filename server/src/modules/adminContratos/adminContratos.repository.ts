import type { RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";
import type { EstadoContrato } from "./adminContratos.types.js";

export const ESTADOS_VALIDOS: EstadoContrato[] = ["pendiente_pago", "en_progreso", "liberado", "cancelado"];

// Puerto exacto de admin_contratos.php:52-62 (COUNT por cada estado válido).
export async function getStatsPorEstado(): Promise<Record<EstadoContrato, number>> {
  const stats = {} as Record<EstadoContrato, number>;
  for (const estado of ESTADOS_VALIDOS) {
    const [rows] = await pool.query<RowDataPacket[]>("SELECT COUNT(*) AS total FROM contratos WHERE estado = ?", [estado]);
    stats[estado] = Number((rows[0] as { total: number } | undefined)?.total ?? 0);
  }
  return stats;
}

interface ContratoRow extends RowDataPacket {
  id: number;
  estado: string;
  monto: string | number;
  fecha_creacion: Date;
  fecha_estimada: Date | null;
  fecha_cierre: Date | null;
  conversacion_id: number | null;
  servicio_titulo: string;
  comprador_nombre: string;
  vendedor_nombre: string;
}

// Puerto exacto de admin_contratos.php:64-79 (mismos LEFT JOIN + COALESCE para
// servicio/usuario eliminado, mismo orden por fecha_creacion DESC).
export async function listarContratos(estado?: EstadoContrato): Promise<ContratoRow[]> {
  const where = estado ? "WHERE c.estado = ?" : "";
  const params = estado ? [estado] : [];
  const [rows] = await pool.query<ContratoRow[]>(
    `SELECT c.id, c.estado, c.monto, c.fecha_creacion, c.fecha_estimada, c.fecha_cierre, c.conversacion_id,
            COALESCE(s.titulo, '[Servicio Eliminado]') AS servicio_titulo,
            COALESCE(comp.nombre, '[Usuario Eliminado]') AS comprador_nombre,
            COALESCE(vend.nombre, '[Usuario Eliminado]') AS vendedor_nombre
     FROM contratos c
     LEFT JOIN servicios s ON c.servicio_id = s.id
     LEFT JOIN alumnos comp ON c.comprador_id = comp.id
     LEFT JOIN alumnos vend ON c.vendedor_id = vend.id
     ${where}
     ORDER BY c.fecha_creacion DESC`,
    params,
  );
  return rows;
}
