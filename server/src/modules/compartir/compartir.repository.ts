import type { RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";
import type { FormatoShare, MateriaCompartir } from "./compartir.types.js";

export async function getMateriaActiva(slug: string): Promise<MateriaCompartir | null> {
  const [rows] = await pool.query<RowDataPacket[]>("SELECT slug, nombre FROM materias WHERE slug = ? AND activa = 1 LIMIT 1", [slug]);
  return (rows[0] as MateriaCompartir) ?? null;
}

// Puerto de app/track_share.php (tipo='desafio') — tabla real ya existía con filas de
// producción (confirmado con SELECT antes de tocar nada), ver
// sql/pendientes/shares_desafio_fase1.sql.
export async function registrarShareDesafio(materiaSlug: string, formato: FormatoShare, ip: string | null, userAgent: string | null): Promise<void> {
  await pool.query("INSERT INTO shares_desafio (materia_slug, formato, ip, user_agent) VALUES (?, ?, ?, ?)", [materiaSlug, formato, ip, userAgent]);
}
