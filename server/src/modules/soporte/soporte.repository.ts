import type { ResultSetHeader, RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";
import type { CrearTicketInput, MensajeHiloRow, TicketMaestroRow } from "./soporte.types.js";

interface TicketMaestroDbRow extends TicketMaestroRow, RowDataPacket {}
interface MensajeHiloDbRow extends MensajeHiloRow, RowDataPacket {}

// Puerto exacto de reclamos_sugerencias.php:216-219 (mismo alias de columnas, mismo
// filtro estado != 'eliminado', mismo ORDER BY).
export async function getTicketsMaestros(usuarioId: number): Promise<TicketMaestroRow[]> {
  const [rows] = await pool.query<TicketMaestroDbRow[]>(
    `SELECT id, fecha AS fecha_creacion, categoria, texto AS mensaje, respuesta_admin AS respuesta, estado, revisado_usuario
     FROM reclamos_sugerencias
     WHERE usuario_id = ? AND estado != 'eliminado'
     ORDER BY fecha DESC`,
    [usuarioId],
  );
  return rows;
}

// Puerto exacto de reclamos_sugerencias.php:232-239.
export async function getMensajesPorTickets(ticketIds: number[]): Promise<MensajeHiloRow[]> {
  if (ticketIds.length === 0) return [];
  const placeholders = ticketIds.map(() => "?").join(",");
  const [rows] = await pool.query<MensajeHiloDbRow[]>(
    `SELECT id, reclamo_id, remitente, mensaje, fecha FROM reclamos_mensajes WHERE reclamo_id IN (${placeholders}) ORDER BY fecha ASC`,
    ticketIds,
  );
  return rows;
}

// Puerto exacto de reclamos_sugerencias.php:88-95 — mismo formato "ASUNTO EN MAYÚSCULAS:\n
// mensaje" concatenado en un solo campo `texto` (no hay columna `asunto` separada en la
// tabla real), mismo estado inicial 'pendiente', mismo revisado_usuario=1 al crear (el
// propio autor del ticket ya lo "leyó", obviamente).
export async function crearTicket(usuarioId: number, input: CrearTicketInput): Promise<number> {
  const textoCompleto = `${input.asunto.toUpperCase()}:\n${input.mensaje}`;
  const [result] = await pool.query<ResultSetHeader>(
    "INSERT INTO reclamos_sugerencias (usuario_id, texto, categoria, fecha, estado, revisado_usuario) VALUES (?, ?, ?, NOW(), 'pendiente', 1)",
    [usuarioId, textoCompleto, input.categoria],
  );
  return result.insertId;
}

// Puerto exacto de reclamos_sugerencias.php:123-127 — ownership check previo, separado de
// la escritura (mismo patrón que el PHP real, no un atajo).
export async function existeTicketDeUsuario(ticketId: number, usuarioId: number): Promise<boolean> {
  const [rows] = await pool.query<RowDataPacket[]>("SELECT id FROM reclamos_sugerencias WHERE id = ? AND usuario_id = ?", [
    ticketId,
    usuarioId,
  ]);
  return rows.length > 0;
}

// Puerto exacto de reclamos_sugerencias.php:133-146 — misma transacción (INSERT mensaje +
// UPDATE estado/revisado), mismo criterio: responder reabre el ticket a 'pendiente' (por
// si estaba 'en_proceso') y lo deja "leído" para el propio usuario que acaba de escribir.
export async function responderTicket(ticketId: number, mensajeUsuario: string): Promise<void> {
  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();
    await conn.query("INSERT INTO reclamos_mensajes (reclamo_id, remitente, mensaje, fecha) VALUES (?, 'usuario', ?, NOW())", [
      ticketId,
      mensajeUsuario,
    ]);
    await conn.query("UPDATE reclamos_sugerencias SET estado = 'pendiente', revisado_usuario = 1 WHERE id = ?", [ticketId]);
    await conn.commit();
  } catch (err) {
    await conn.rollback();
    throw err;
  } finally {
    conn.release();
  }
}

// Puerto exacto de reclamos_sugerencias.php:204-206 — ownership en el WHERE mismo (no un
// check separado), affected_rows>0 decide éxito (mismo criterio que el PHP real: si el
// ticket no es tuyo o no existe, 0 filas afectadas = "no se pudo").
export async function marcarResuelto(ticketId: number, usuarioId: number): Promise<boolean> {
  const [result] = await pool.query<ResultSetHeader>(
    "UPDATE reclamos_sugerencias SET estado = 'resuelto', revisado_usuario = 1 WHERE id = ? AND usuario_id = ?",
    [ticketId, usuarioId],
  );
  return result.affectedRows > 0;
}

// Puerto exacto de reclamos_sugerencias.php:175-190 (soft delete, nunca un DELETE real) —
// ownership en el WHERE, acepta 1 o varios ids a la vez (mismo endpoint cubre ambos casos
// reales: eliminar 1 ticket desde la fila, o varios desde la barra de selección múltiple).
export async function eliminarTickets(ticketIds: number[], usuarioId: number): Promise<number> {
  if (ticketIds.length === 0) return 0;
  const placeholders = ticketIds.map(() => "?").join(",");
  const [result] = await pool.query<ResultSetHeader>(
    `UPDATE reclamos_sugerencias SET estado = 'eliminado' WHERE id IN (${placeholders}) AND usuario_id = ?`,
    [...ticketIds, usuarioId],
  );
  return result.affectedRows;
}

// Puerto exacto de reclamos_sugerencias.php:68 — mismo ownership en el WHERE.
export async function marcarLeido(ticketId: number, usuarioId: number): Promise<void> {
  await pool.query("UPDATE reclamos_sugerencias SET revisado_usuario = 1 WHERE id = ? AND usuario_id = ?", [ticketId, usuarioId]);
}
