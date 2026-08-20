import type { RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";
import type { VentaApunteRow } from "./ventasApuntes.types.js";

interface VentaApunteDbRow extends VentaApunteRow, RowDataPacket {}

// Puerto de app/ventas_apuntes.php:37-42 — columnas explícitas en vez de `v.*, a.*`: la
// página real selecciona también a.precio_actual_apunte y a.portada pero nunca los
// renderiza (confirmado con grep sobre el archivo completo) — código muerto, no se porta.
export async function getVentasApuntesByVendedor(vendedorId: number): Promise<VentaApunteRow[]> {
  const [rows] = await pool.query<VentaApunteDbRow[]>(
    `SELECT v.id, v.apunte_id, v.fecha, v.pagado_al_vendedor, a.titulo, a.archivo, al.nombre AS comprador_nombre, v.precio
     FROM ventas_apuntes v
     JOIN apuntes a ON v.apunte_id = a.id
     JOIN alumnos al ON v.comprador_id = al.id
     WHERE v.vendedor_id = ?
     ORDER BY v.fecha DESC`,
    [vendedorId],
  );
  return rows;
}
