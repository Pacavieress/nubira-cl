import type { RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";

interface AulaRow extends RowDataPacket {
  id: number;
  estado: string;
  fecha_creacion: Date;
  n1: string | null;
  f1: string | null;
  n2: string | null;
  f2: string | null;
  msg_aula: string | null;
  fecha_aula: Date | null;
}

interface InfoContratoRow extends RowDataPacket {
  n1: string | null;
  f1: string | null;
  n2: string | null;
  f2: string | null;
  estado: string;
  comprador_id: number;
}

interface MensajeRow extends RowDataPacket {
  remitente_id: number;
  mensaje: string;
  enviado_en: Date;
}

// Puerto exacto del SQL compartido entre el bloque ajax_search y la carga inicial
// (admin_chats_aula.php:41-49 / 180-186) — mismo ORDER BY (COALESCE del último mensaje de
// aula, si no hay aula cae a fecha_creacion del contrato).
export async function listarAulas(q: string | undefined, orden: "asc" | "desc"): Promise<AulaRow[]> {
  const ordenSql = orden === "asc" ? "ASC" : "DESC";
  const where = q ? "WHERE (u1.nombre LIKE ? OR u2.nombre LIKE ?)" : "";
  const params = q ? [`%${q}%`, `%${q}%`] : [];

  const [rows] = await pool.query<AulaRow[]>(
    `SELECT c.id, c.estado, c.fecha_creacion,
            u1.nombre as n1, u1.foto_perfil as f1,
            u2.nombre as n2, u2.foto_perfil as f2,
            (SELECT mensaje FROM chat_aula WHERE contrato_id = c.id ORDER BY id DESC LIMIT 1) as msg_aula,
            (SELECT fecha FROM chat_aula WHERE contrato_id = c.id ORDER BY id DESC LIMIT 1) as fecha_aula
     FROM contratos c
     LEFT JOIN alumnos u1 ON c.comprador_id = u1.id
     LEFT JOIN alumnos u2 ON c.vendedor_id = u2.id
     ${where}
     ORDER BY COALESCE((SELECT MAX(fecha) FROM chat_aula WHERE contrato_id = c.id), c.fecha_creacion) ${ordenSql}`,
    params,
  );
  return rows;
}

// Puerto exacto de admin_chats_aula.php:194.
export async function getInfoContrato(id: number): Promise<InfoContratoRow | null> {
  const [rows] = await pool.query<InfoContratoRow[]>(
    `SELECT u1.nombre as n1, u1.foto_perfil as f1, u2.nombre as n2, u2.foto_perfil as f2, c.estado, c.comprador_id
     FROM contratos c
     LEFT JOIN alumnos u1 ON c.comprador_id = u1.id
     LEFT JOIN alumnos u2 ON c.vendedor_id = u2.id
     WHERE c.id = ?`,
    [id],
  );
  return rows[0] ?? null;
}

// Puerto exacto de admin_chats_aula.php:331-335 — historial de chat previo a la compra
// (conversaciones/mensajes), si existe.
export async function listarMensajesPrevios(contratoId: number): Promise<MensajeRow[]> {
  const [rows] = await pool.query<MensajeRow[]>(
    `SELECT m.remitente_id, m.mensaje, m.enviado_en
     FROM conversaciones c
     JOIN mensajes m ON c.id = m.conversacion_id
     WHERE c.contrato_id = ?
     ORDER BY m.enviado_en ASC`,
    [contratoId],
  );
  return rows;
}

// Puerto exacto de admin_chats_aula.php:368-371 — mensajes del aula virtual (post-pago).
export async function listarMensajesAula(contratoId: number): Promise<MensajeRow[]> {
  const [rows] = await pool.query<MensajeRow[]>(
    `SELECT remitente_id, mensaje, fecha as enviado_en
     FROM chat_aula
     WHERE contrato_id = ?
     ORDER BY fecha ASC`,
    [contratoId],
  );
  return rows;
}
