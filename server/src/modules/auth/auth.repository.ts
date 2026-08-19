import type { RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";

interface SesionApiRow extends RowDataPacket {
  usuario_id: number;
}

export async function findUsuarioIdBySessionId(sessionId: string): Promise<number | null> {
  const [rows] = await pool.query<SesionApiRow[]>(
    "SELECT usuario_id FROM sesiones_api WHERE session_id = ? AND expira_en > NOW() LIMIT 1",
    [sessionId],
  );
  return rows[0]?.usuario_id ?? null;
}
