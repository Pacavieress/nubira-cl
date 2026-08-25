import type { ResultSetHeader, RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";
import type { AccionLote, Contadores, EstadoFiltro } from "./adminReclamos.types.js";

interface TicketRow extends RowDataPacket {
  id: number;
  fecha: Date;
  texto: string;
  estado: string;
  respuesta_admin: string | null;
  usuario_raw: string;
  foto_perfil: string | null;
}

interface MensajeRow extends RowDataPacket {
  id: number;
  reclamo_id: number;
  remitente: "usuario" | "admin";
  mensaje: string;
  fecha: Date;
}

interface ContadorRow extends RowDataPacket {
  estado: string;
  total: number;
}

// Puerto exacto de admin_reclamos.php:150-162 — "activos" agrupa pendiente + en_proceso.
export async function contarPorEstado(): Promise<Contadores> {
  const contadores: Contadores = { activos: 0, resuelto: 0, eliminado: 0, todos: 0 };
  const [rows] = await pool.query<ContadorRow[]>("SELECT estado, COUNT(*) AS total FROM reclamos_sugerencias GROUP BY estado");
  for (const row of rows) {
    if (row.estado === "pendiente" || row.estado === "en_proceso") {
      contadores.activos += row.total;
    } else if (row.estado === "resuelto" || row.estado === "eliminado") {
      contadores[row.estado] += row.total;
    }
    if (row.estado !== "eliminado") contadores.todos += row.total;
  }
  return contadores;
}

// Puerto exacto de admin_reclamos.php:164-182.
export async function listarTickets(estadoFiltro: EstadoFiltro): Promise<TicketRow[]> {
  const base =
    "SELECT r.id, r.fecha, r.texto, r.estado, r.respuesta_admin, a.nombre AS usuario_raw, a.foto_perfil " +
    "FROM reclamos_sugerencias r JOIN alumnos a ON r.usuario_id = a.id ";

  if (estadoFiltro === "todos") {
    const [rows] = await pool.query<TicketRow[]>(base + "WHERE r.estado != 'eliminado' ORDER BY r.fecha DESC");
    return rows;
  }
  if (estadoFiltro === "activos") {
    const [rows] = await pool.query<TicketRow[]>(base + "WHERE r.estado IN ('pendiente', 'en_proceso') ORDER BY r.fecha DESC");
    return rows;
  }
  const [rows] = await pool.query<TicketRow[]>(base + "WHERE r.estado = ? ORDER BY r.fecha DESC", [estadoFiltro]);
  return rows;
}

// Puerto exacto de admin_reclamos.php:185-199.
export async function listarMensajesPorTicket(ids: number[]): Promise<Map<number, MensajeRow[]>> {
  const mapa = new Map<number, MensajeRow[]>();
  if (ids.length === 0) return mapa;
  const placeholders = ids.map(() => "?").join(",");
  const [rows] = await pool.query<MensajeRow[]>(`SELECT * FROM reclamos_mensajes WHERE reclamo_id IN (${placeholders}) ORDER BY fecha ASC`, ids);
  for (const row of rows) {
    const lista = mapa.get(row.reclamo_id) ?? [];
    lista.push(row);
    mapa.set(row.reclamo_id, lista);
  }
  return mapa;
}

// Puerto exacto de la rama "responder" (admin_reclamos.php:70-88): INSERT del mensaje +
// UPDATE de estado a 'en_proceso' dentro de una transacción, misma semántica COALESCE en
// respuesta_admin (solo guarda la primera respuesta ahí; el hilo completo vive en
// reclamos_mensajes).
export async function responder(id: number, respuesta: string): Promise<void> {
  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();
    await conn.query("INSERT INTO reclamos_mensajes (reclamo_id, remitente, mensaje, fecha) VALUES (?, 'admin', ?, NOW())", [id, respuesta]);
    await conn.query("UPDATE reclamos_sugerencias SET estado='en_proceso', respuesta_admin=COALESCE(respuesta_admin, ?), revisado_usuario=0 WHERE id=?", [
      respuesta,
      id,
    ]);
    await conn.commit();
  } catch (err) {
    await conn.rollback();
    throw err;
  } finally {
    conn.release();
  }
}

export async function resolver(id: number): Promise<boolean> {
  const [res] = await pool.query<ResultSetHeader>("UPDATE reclamos_sugerencias SET estado='resuelto', revisado_usuario=0 WHERE id=?", [id]);
  return res.affectedRows > 0;
}

export async function papelera(id: number): Promise<boolean> {
  const [res] = await pool.query<ResultSetHeader>("UPDATE reclamos_sugerencias SET estado='eliminado' WHERE id=?", [id]);
  return res.affectedRows > 0;
}

export async function restaurar(id: number): Promise<boolean> {
  const [res] = await pool.query<ResultSetHeader>("UPDATE reclamos_sugerencias SET estado='pendiente' WHERE id=?", [id]);
  return res.affectedRows > 0;
}

// Puerto exacto de "eliminar_hard" (admin_reclamos.php:96-100): primero mensajes, luego ticket.
export async function eliminarHard(id: number): Promise<boolean> {
  await pool.query("DELETE FROM reclamos_mensajes WHERE reclamo_id = ?", [id]);
  const [res] = await pool.query<ResultSetHeader>("DELETE FROM reclamos_sugerencias WHERE id = ?", [id]);
  return res.affectedRows > 0;
}

// Puerto exacto del bloque de acción en lote (admin_reclamos.php:113-144).
export async function accionLote(ids: number[], accion: AccionLote): Promise<number> {
  if (ids.length === 0) return 0;
  const placeholders = ids.map(() => "?").join(",");

  if (accion === "eliminar_hard") {
    await pool.query(`DELETE FROM reclamos_mensajes WHERE reclamo_id IN (${placeholders})`, ids);
    const [res] = await pool.query<ResultSetHeader>(`DELETE FROM reclamos_sugerencias WHERE id IN (${placeholders})`, ids);
    return res.affectedRows;
  }
  if (accion === "restaurar") {
    const [res] = await pool.query<ResultSetHeader>(`UPDATE reclamos_sugerencias SET estado='pendiente' WHERE id IN (${placeholders})`, ids);
    return res.affectedRows;
  }
  const [res] = await pool.query<ResultSetHeader>(`UPDATE reclamos_sugerencias SET estado='eliminado' WHERE id IN (${placeholders})`, ids);
  return res.affectedRows;
}
