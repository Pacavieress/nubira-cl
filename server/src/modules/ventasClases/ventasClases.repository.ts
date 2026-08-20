import type { RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";
import type { VentaClaseRow } from "./ventasClases.types.js";

interface VentaClaseDbRow extends VentaClaseRow, RowDataPacket {}

// Puerto exacto de app/ventas_clases.php:40-46 (mismo JOIN, mismo ORDER BY).
export async function getVentasClasesByVendedor(vendedorId: number): Promise<VentaClaseRow[]> {
  const [rows] = await pool.query<VentaClaseDbRow[]>(
    `SELECT c.id AS id_contrato, s.titulo, s.imagen, al.nombre AS comprador_nombre, al.correo AS comprador_email,
            c.monto, c.monto_subsidio, c.monto_comision, c.fecha_creacion, c.fecha_pago, c.estado, c.calificacion_vendedor
     FROM contratos c
     JOIN servicios s ON s.id = c.servicio_id
     JOIN alumnos al ON al.id = c.comprador_id
     WHERE c.vendedor_id = ?
     ORDER BY c.fecha_creacion DESC`,
    [vendedorId],
  );
  return rows;
}
