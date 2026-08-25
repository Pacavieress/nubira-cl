import type { ResultSetHeader, RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";

export type FiltroChats = "activos" | "cerrados" | "contrato" | "cotizacion" | "inactivos" | "alertas_dlp" | "moderacion";

interface ChatRow extends RowDataPacket {
  id: number;
  contrato_id: number | null;
  fecha_orden: Date | null;
  eliminado: number;
  uid1: number;
  n1: string | null;
  f1: string | null;
  uid2: number;
  n2: string | null;
  f2: string | null;
  servicio_titulo: string | null;
}

// Puerto exacto de build_listado_query() (admin_chats.php:181-210) — "moderacion" nunca
// matchea acá a propósito (WHERE 1=0), esa data vive en listarModeracion().
function construirWhere(filtro: FiltroChats): string {
  switch (filtro) {
    case "cerrados":
      return "AND c.eliminado = 1";
    case "contrato":
      return "AND c.eliminado = 0 AND c.contrato_id IS NOT NULL";
    case "cotizacion":
      return "AND c.eliminado = 0 AND c.contrato_id IS NULL";
    case "alertas_dlp":
      return "AND c.id IN (SELECT DISTINCT conversacion_id FROM dlp_intentos WHERE revisado_admin = 0)";
    case "moderacion":
      return "AND 1=0";
    case "inactivos":
      return "AND c.eliminado = 0 AND COALESCE(c.ultima_interaccion, c.creado_en) < DATE_SUB(NOW(), INTERVAL 7 DAY)";
    case "activos":
    default:
      return "AND c.eliminado = 0";
  }
}

// Puerto exacto del bloque ajax_search (admin_chats.php:364-424): mismo detector de
// búsqueda-por-ID ("C-123", "c123", "123") con fallback a LIKE por nombre/servicio.
export async function listarChats(filtro: FiltroChats, orden: "asc" | "desc", busqueda: string): Promise<ChatRow[]> {
  const whereBase = construirWhere(filtro);
  let busquedaSql = "";
  const params: (string | number)[] = [];

  if (busqueda !== "") {
    const matchId = busqueda.match(/^c?-?(\d+)$/i);
    const like = `%${busqueda}%`;
    if (matchId) {
      busquedaSql = "AND (c.id = ? OR u1.nombre LIKE ? OR u2.nombre LIKE ? OR s.titulo LIKE ?)";
      params.push(Number(matchId[1]), like, like, like);
    } else {
      busquedaSql = "AND (u1.nombre LIKE ? OR u2.nombre LIKE ? OR s.titulo LIKE ?)";
      params.push(like, like, like);
    }
  }

  const [rows] = await pool.query<ChatRow[]>(
    `SELECT
        c.id, c.contrato_id, COALESCE(c.ultima_interaccion, c.creado_en) AS fecha_orden, c.eliminado,
        u1.id AS uid1, u1.nombre AS n1, u1.foto_perfil AS f1,
        u2.id AS uid2, u2.nombre AS n2, u2.foto_perfil AS f2,
        s.titulo AS servicio_titulo
     FROM conversaciones c
     LEFT JOIN alumnos u1 ON c.comprador_id = u1.id
     LEFT JOIN alumnos u2 ON c.vendedor_id = u2.id
     LEFT JOIN servicios s ON c.servicio_id = s.id
     WHERE 1=1 ${whereBase} ${busquedaSql}
     ORDER BY fecha_orden ${orden === "asc" ? "ASC" : "DESC"}
     LIMIT 100`,
    params,
  );
  return rows;
}

interface ContadorRow extends RowDataPacket {
  n: number;
}

// Puerto exacto de admin_chats.php:625-644 (contadores de las pestañas).
export async function contarChats(): Promise<{
  activos: number;
  cerrados: number;
  contrato: number;
  cotizacion: number;
  inactivos: number;
  alertasDlp: number;
  moderacion: number;
}> {
  const [[activos]] = await pool.query<ContadorRow[]>("SELECT COUNT(*) AS n FROM conversaciones WHERE eliminado = 0");
  const [[cerrados]] = await pool.query<ContadorRow[]>("SELECT COUNT(*) AS n FROM conversaciones WHERE eliminado = 1");
  const [[contrato]] = await pool.query<ContadorRow[]>("SELECT COUNT(*) AS n FROM conversaciones WHERE eliminado = 0 AND contrato_id IS NOT NULL");
  const [[cotizacion]] = await pool.query<ContadorRow[]>("SELECT COUNT(*) AS n FROM conversaciones WHERE eliminado = 0 AND contrato_id IS NULL");
  const [[inactivos]] = await pool.query<ContadorRow[]>(
    "SELECT COUNT(*) AS n FROM conversaciones WHERE eliminado = 0 AND COALESCE(ultima_interaccion, creado_en) < DATE_SUB(NOW(), INTERVAL 7 DAY)",
  );
  const [[alertasDlp]] = await pool.query<ContadorRow[]>("SELECT COUNT(DISTINCT conversacion_id) AS n FROM dlp_intentos WHERE revisado_admin = 0");
  const [[moderacion]] = await pool.query<ContadorRow[]>("SELECT COUNT(*) AS n FROM mensajes WHERE visible = 0 AND archivo_ruta IS NOT NULL");

  return {
    activos: activos?.n ?? 0,
    cerrados: cerrados?.n ?? 0,
    contrato: contrato?.n ?? 0,
    cotizacion: cotizacion?.n ?? 0,
    inactivos: inactivos?.n ?? 0,
    alertasDlp: alertasDlp?.n ?? 0,
    moderacion: moderacion?.n ?? 0,
  };
}

interface ChatInfoRow extends RowDataPacket {
  uid1: number;
  n1: string | null;
  f1: string | null;
  uid2: number;
  n2: string | null;
  f2: string | null;
  servicio_id: number | null;
  eliminado: number;
  comprador_id: number;
  contrato_id: number | null;
  servicio_titulo: string | null;
}

// Puerto exacto de admin_chats.php:648-660.
export async function obtenerInfoChat(chatId: number): Promise<ChatInfoRow | null> {
  const [rows] = await pool.query<ChatInfoRow[]>(
    `SELECT u1.id AS uid1, u1.nombre as n1, u1.foto_perfil as f1, u2.id AS uid2, u2.nombre as n2, u2.foto_perfil as f2,
            c.servicio_id, c.eliminado, c.comprador_id, c.contrato_id, s.titulo AS servicio_titulo
     FROM conversaciones c
     LEFT JOIN alumnos u1 ON c.comprador_id = u1.id
     LEFT JOIN alumnos u2 ON c.vendedor_id = u2.id
     LEFT JOIN servicios s ON c.servicio_id = s.id
     WHERE c.id = ?`,
    [chatId],
  );
  return rows[0] ?? null;
}

interface MetadataRow extends RowDataPacket {
  total: number;
  archivos: number;
  primero: Date | null;
  ultimo: Date | null;
}

// Puerto exacto de ajax_metadata (admin_chats.php:484-500).
export async function obtenerMetadataChat(chatId: number): Promise<MetadataRow> {
  const [rows] = await pool.query<MetadataRow[]>(
    "SELECT COUNT(*) AS total, SUM(CASE WHEN archivo_ruta IS NOT NULL THEN 1 ELSE 0 END) AS archivos, MIN(enviado_en) AS primero, MAX(enviado_en) AS ultimo FROM mensajes WHERE conversacion_id = ?",
    [chatId],
  );
  return rows[0] ?? ({ total: 0, archivos: 0, primero: null, ultimo: null } as MetadataRow);
}

interface MensajeRow extends RowDataPacket {
  id: number;
  remitente_id: number;
  mensaje: string;
  archivo_nombre: string | null;
  archivo_ruta: string | null;
  archivo_tipo: string | null;
  archivo_peso: number | null;
  enviado_en: Date | null;
}

// Puerto exacto de ajax_messages, bloque de pre-venta (admin_chats.php:527-543).
export async function listarMensajes(chatId: number): Promise<MensajeRow[]> {
  const [rows] = await pool.query<MensajeRow[]>(
    "SELECT id, remitente_id, mensaje, archivo_nombre, archivo_ruta, archivo_tipo, archivo_peso, enviado_en FROM mensajes WHERE conversacion_id = ? ORDER BY enviado_en ASC",
    [chatId],
  );
  return rows;
}

interface DlpRow extends RowDataPacket {
  id: number;
  categoria: string;
  texto_intentado: string;
  fecha: Date | null;
  revisado_admin: number;
  remitente_nombre: string | null;
}

// Puerto exacto del bloque de intentos DLP dentro de ajax_messages (admin_chats.php:555-566)
// — SIN la columna `liberado` (ver nota de alcance en adminChats.types.ts).
export async function listarDlpDeChat(chatId: number): Promise<DlpRow[]> {
  const [rows] = await pool.query<DlpRow[]>(
    `SELECT d.id, d.categoria, d.texto_intentado, d.fecha, d.revisado_admin, a.nombre AS remitente_nombre
     FROM dlp_intentos d
     LEFT JOIN alumnos a ON d.remitente_id = a.id
     WHERE d.conversacion_id = ?
     ORDER BY d.fecha ASC`,
    [chatId],
  );
  return rows;
}

interface ModeracionRow extends RowDataPacket {
  id: number;
  conversacion_id: number;
  archivo_ruta: string;
  archivo_nombre: string | null;
  archivo_tipo: string | null;
  archivo_peso: number | null;
  enviado_en: Date | null;
  remitente_nombre: string | null;
}

// Puerto exacto de la query de la pestaña Moderación (admin_chats.php:843-850).
export async function listarModeracion(): Promise<ModeracionRow[]> {
  const [rows] = await pool.query<ModeracionRow[]>(
    `SELECT m.id, m.conversacion_id, m.archivo_ruta, m.archivo_nombre, m.archivo_tipo, m.archivo_peso, m.enviado_en, a.nombre AS remitente_nombre
     FROM mensajes m
     JOIN alumnos a ON m.remitente_id = a.id
     WHERE m.visible = 0 AND m.archivo_ruta IS NOT NULL
     ORDER BY m.enviado_en ASC`,
  );
  return rows;
}

// Puerto exacto de "eliminar_chat" / "restaurar_chat" (admin_chats.php:227-241).
export async function alternarEliminadoChat(chatId: number, eliminado: boolean): Promise<boolean> {
  const [res] = await pool.query<ResultSetHeader>("UPDATE conversaciones SET eliminado = ? WHERE id = ?", [eliminado ? 1 : 0, chatId]);
  return res.affectedRows > 0;
}

// Puerto exacto de "marcar_revisado_dlp" (admin_chats.php:243-249).
export async function marcarRevisadoDlp(chatId: number): Promise<boolean> {
  const [res] = await pool.query<ResultSetHeader>("UPDATE dlp_intentos SET revisado_admin = 1 WHERE conversacion_id = ?", [chatId]);
  return res.affectedRows > 0;
}

// Puerto exacto de "aprobar_archivo" (admin_chats.php:328-335).
export async function aprobarArchivo(msgId: number): Promise<boolean> {
  const [res] = await pool.query<ResultSetHeader>("UPDATE mensajes SET visible = 1 WHERE id = ? AND archivo_ruta IS NOT NULL", [msgId]);
  return res.affectedRows > 0;
}
