import type { RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";

interface CategoriaRow extends RowDataPacket {
  categoria: string;
}

export async function listCategorias(): Promise<string[]> {
  const [rows] = await pool.query<CategoriaRow[]>(
    `SELECT DISTINCT categoria
     FROM servicios
     WHERE estado = 'aprobado' AND visible = 1
     ORDER BY categoria`,
  );
  return rows.map((row) => row.categoria);
}
