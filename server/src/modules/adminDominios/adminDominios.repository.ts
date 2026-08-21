import type { ResultSetHeader, RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";
import type { DominioPermitido } from "./adminDominios.types.js";

interface DominioRow extends RowDataPacket {
  id: number;
  dominio: string;
  institucion: string;
  total_usuarios: number;
}

// Puerto exacto de la query de admin_dominios.php:96-99 (JOIN correlacionado contra
// alumnos.correo por LIKE '%@dominio') — mismo orden (institucion ASC).
export async function listarDominios(): Promise<DominioPermitido[]> {
  const [rows] = await pool.query<DominioRow[]>(
    `SELECT d.id, d.dominio, d.institucion,
            (SELECT COUNT(*) FROM alumnos a WHERE a.correo LIKE CONCAT('%@', d.dominio)) AS total_usuarios
     FROM dominios_permitidos d
     ORDER BY d.institucion ASC`,
  );
  return rows.map((r) => ({ id: r.id, dominio: r.dominio, institucion: r.institucion, totalUsuarios: r.total_usuarios }));
}

export async function existeDominio(dominio: string): Promise<boolean> {
  const [rows] = await pool.query<RowDataPacket[]>("SELECT id FROM dominios_permitidos WHERE dominio = ? LIMIT 1", [dominio]);
  return rows.length > 0;
}

export async function crearDominio(dominio: string, institucion: string): Promise<number> {
  const [res] = await pool.query<ResultSetHeader>(
    "INSERT INTO dominios_permitidos (dominio, institucion) VALUES (?, ?)",
    [dominio, institucion],
  );
  return res.insertId;
}

export async function actualizarInstitucion(id: number, institucion: string): Promise<boolean> {
  const [res] = await pool.query<ResultSetHeader>(
    "UPDATE dominios_permitidos SET institucion = ? WHERE id = ?",
    [institucion, id],
  );
  return res.affectedRows > 0;
}

// Puerto de admin_dominios.php:82-86 — el PHP real no chequea cuántos usuarios tiene el
// dominio antes de borrar (solo advierte con un confirm() del lado del cliente, que el
// usuario puede aceptar igual) — mismo criterio acá, la advertencia vive en la UI de web/,
// no un bloqueo server-side que el PHP real tampoco tiene.
export async function eliminarDominio(id: number): Promise<boolean> {
  const [res] = await pool.query<ResultSetHeader>("DELETE FROM dominios_permitidos WHERE id = ?", [id]);
  return res.affectedRows > 0;
}
