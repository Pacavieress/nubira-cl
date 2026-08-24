import type { ResultSetHeader, RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";
import type { NuevoCuponInput } from "./adminCupones.types.js";

interface CuponRow extends RowDataPacket {
  id: number;
  codigo: string;
  porcentaje_descuento: number;
  usos_actuales: number;
  usos_maximos: number;
  servicio_id: number | null;
  servicio_titulo: string | null;
  fecha_expiracion: Date | null;
}

interface ServicioRow extends RowDataPacket {
  id: number;
  titulo: string;
  precio: number;
}

interface CodigoRow extends RowDataPacket {
  id: number;
}

// Puerto exacto de cupones.php:32 — mismo ORDER BY, con el JOIN adicional para mostrar el
// título del servicio (el PHP real solo muestra "Servicio #<id>", ver la nota de mejora en
// AdminCuponesPanel.tsx).
export async function listarCupones(): Promise<CuponRow[]> {
  const [rows] = await pool.query<CuponRow[]>(
    `SELECT c.id, c.codigo, c.porcentaje_descuento, c.usos_actuales, c.usos_maximos, c.servicio_id,
            s.titulo AS servicio_titulo, c.fecha_expiracion
     FROM cupones c
     LEFT JOIN servicios s ON c.servicio_id = s.id
     ORDER BY c.creado_en DESC`,
  );
  return rows;
}

// Puerto exacto de cupones.php:36.
export async function listarServiciosAprobados(): Promise<ServicioRow[]> {
  const [rows] = await pool.query<ServicioRow[]>("SELECT id, titulo, precio FROM servicios WHERE estado = 'aprobado' ORDER BY titulo ASC");
  return rows;
}

export async function existeCodigo(codigo: string): Promise<boolean> {
  const [rows] = await pool.query<CodigoRow[]>("SELECT id FROM cupones WHERE codigo = ? LIMIT 1", [codigo]);
  return rows.length > 0;
}

// Puerto exacto de la rama de creación (admin_procesar_cupon.php:66-69).
export async function crearCupon(input: NuevoCuponInput): Promise<number> {
  const [res] = await pool.query<ResultSetHeader>(
    `INSERT INTO cupones (codigo, porcentaje_descuento, usos_maximos, servicio_id, fecha_expiracion, usos_actuales, creado_en)
     VALUES (?, ?, ?, ?, ?, 0, CURRENT_TIMESTAMP)`,
    [input.codigo, input.porcentajeDescuento, input.usosMaximos, input.servicioId, input.fechaExpiracion],
  );
  return res.insertId;
}

// Puerto exacto de la rama de eliminación (admin_procesar_cupon.php:30).
export async function eliminarCupon(id: number): Promise<boolean> {
  const [res] = await pool.query<ResultSetHeader>("DELETE FROM cupones WHERE id = ?", [id]);
  return res.affectedRows > 0;
}
