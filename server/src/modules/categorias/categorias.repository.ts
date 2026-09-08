import type { RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";

export interface CategoriaConteo {
  categoria: string;
  total: number;
}

interface CategoriaRow extends CategoriaConteo, RowDataPacket {}

// Puerto exacto de clases_servicios.php:72-81 — chips de categoría de /servicios (mismo
// patrón que los de /apuntes). Único consumidor de este endpoint hoy.
export async function listCategorias(): Promise<CategoriaConteo[]> {
  const [rows] = await pool.query<CategoriaRow[]>(`
    SELECT s.categoria, COUNT(*) AS total
    FROM servicios s
    LEFT JOIN alumnos a ON s.alumno_id = a.id
    WHERE s.estado = 'aprobado' AND s.visible = 1
      AND COALESCE(a.visible, 1) = 1 AND COALESCE(a.bloqueado, 0) = 0
      AND s.categoria IS NOT NULL AND s.categoria != ''
    GROUP BY s.categoria
    ORDER BY total DESC
  `);
  return rows;
}
