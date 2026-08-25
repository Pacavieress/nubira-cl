import type { ResultSetHeader, RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";
import type { VistaDetalleInput } from "./vistasDetalle.types.js";

interface ExisteRow extends RowDataPacket {
  uno: number;
}

// Puerto exacto de track_vista.php:50-56: el geo lookup solo corre en el primer ping de
// cada (session_id, tipo, publicacion_id) — pings siguientes solo actualizan tiempo/scroll.
export async function esPrimerPing(sessionId: string, tipo: string, publicacionId: number): Promise<boolean> {
  const [rows] = await pool.query<ExisteRow[]>("SELECT 1 AS uno FROM vistas_detalle WHERE session_id = ? AND tipo = ? AND publicacion_id = ? LIMIT 1", [
    sessionId,
    tipo,
    publicacionId,
  ]);
  return rows.length === 0;
}

// Puerto exacto de track_vista.php:64-72 (mismo UNIQUE KEY uk_sesion_publi que la BD real:
// session_id+tipo+publicacion_id).
export async function upsertVistaDetalle(input: VistaDetalleInput, geo: { pais: string | null; ciudad: string | null }): Promise<void> {
  await pool.query<ResultSetHeader>(
    `INSERT INTO vistas_detalle
        (tipo, publicacion_id, user_id, session_id, fecha_ultimo_evento, tiempo_segundos, scroll_max_pct, leyo_completo, dispositivo, origen, ip, pais, ciudad)
     VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
        fecha_ultimo_evento = NOW(),
        tiempo_segundos     = GREATEST(tiempo_segundos, VALUES(tiempo_segundos)),
        scroll_max_pct      = GREATEST(scroll_max_pct,  VALUES(scroll_max_pct)),
        leyo_completo       = GREATEST(leyo_completo,   VALUES(leyo_completo)),
        user_id             = COALESCE(user_id,         VALUES(user_id))`,
    [
      input.tipo,
      input.publicacionId,
      input.usuarioId,
      input.sessionId,
      input.tiempoSegundos,
      input.scrollMaxPct,
      input.leyoCompleto ? 1 : 0,
      input.dispositivo,
      input.origen,
      input.ip,
      geo.pais,
      geo.ciudad,
    ],
  );
}
