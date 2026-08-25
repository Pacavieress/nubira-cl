import type { ResultSetHeader, RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";

interface ApunteRow extends RowDataPacket {
  id: number;
  titulo: string;
  asignatura: string;
  fecha_subida: Date;
  publico: number;
  estado: string | null;
  portada: string | null;
  archivo: string | null;
  autor: string;
  total_ventas: number;
}

// Puerto exacto de admin_apuntes.php:53-79 (búsqueda + LIMIT 100, mismo criterio de
// coincidencia en titulo/nombre del autor/asignatura, mismo subquery de ventas_apuntes con
// precio > 0 para total_ventas).
export async function listarApuntes(q: string): Promise<ApunteRow[]> {
  const base =
    "SELECT a.*, u.nombre AS autor, " +
    "(SELECT COUNT(*) FROM ventas_apuntes va WHERE va.apunte_id = a.id AND va.precio > 0) AS total_ventas " +
    "FROM apuntes a JOIN alumnos u ON a.id_alumno = u.id ";

  if (q) {
    const like = `%${q}%`;
    const [rows] = await pool.query<ApunteRow[]>(base + "WHERE a.titulo LIKE ? OR u.nombre LIKE ? OR a.asignatura LIKE ? ORDER BY a.fecha_subida DESC LIMIT 100", [
      like,
      like,
      like,
    ]);
    return rows;
  }
  const [rows] = await pool.query<ApunteRow[]>(base + "ORDER BY a.fecha_subida DESC LIMIT 100");
  return rows;
}

// Puerto exacto de la rama "alternar" (acciones_apunte.php:218-225).
export async function alternarVisibilidad(id: number): Promise<boolean> {
  const [res] = await pool.query<ResultSetHeader>("UPDATE apuntes SET publico = NOT publico WHERE id = ?", [id]);
  return res.affectedRows > 0;
}
