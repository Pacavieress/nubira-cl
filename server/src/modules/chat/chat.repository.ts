import type { ResultSetHeader, RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";
import { env } from "../../config/env.js";
import { verificarDlp } from "../../lib/dlp.js";
import type { ChatBandejaItem, ChatDetalle, EnviarMensajeError, MensajeChat, ResultadoEnviarMensaje } from "./chat.types.js";

function resolverFotoChat(fotoPerfil: string | null): string | null {
  if (!fotoPerfil) return null;
  return `${env.assetsBaseUrl}/app/perfil/fotos/${fotoPerfil}`;
}

// Puerto exacto de formatear_nombre_corto()/obtener_iniciales() (bandeja_entrada.php:108-135)
// y de la sección "ANONIMATO" de chat_previo_contrato.php:87-98 — mismo criterio en ambos:
// primer nombre + inicial del apellido.
export function formatearNombreCorto(nombreCompleto: string | null): string {
  const limpio = (nombreCompleto ?? "").trim();
  if (!limpio) return "Usuario";
  const partes = limpio.split(/\s+/);
  const primerNombre = partes[0]!.charAt(0).toUpperCase() + partes[0]!.slice(1).toLowerCase();
  if (partes.length > 1) {
    return `${primerNombre} ${partes[1]!.charAt(0).toUpperCase()}.`;
  }
  return primerNombre;
}

// ============================================================================
// Bandeja (app/bandeja_entrada.php)
// ============================================================================

interface BandejaRow extends RowDataPacket {
  id: number;
  tipo: "negociacion" | "aula";
  fecha_sort: string | null;
  servicio_titulo: string | null;
  otro_foto: string | null;
  otro_nombre: string | null;
  otro_id: number;
  ultimo_mensaje: string | null;
  sin_leer: number;
}

// Puerto exacto de bandeja_entrada.php:23-91 — 2 queries (negociaciones + aulas) fusionadas
// y ordenadas en JS por fecha_sort DESC (mismo criterio que el usort() real).
export async function getBandeja(usuarioId: number): Promise<ChatBandejaItem[]> {
  const [negociaciones] = await pool.query<BandejaRow[]>(
    `SELECT c.id, 'negociacion' AS tipo,
            COALESCE(c.ultima_interaccion, c.creado_en) AS fecha_sort,
            COALESCE(s.titulo, 'Servicio no disponible') AS servicio_titulo,
            CASE WHEN c.comprador_id = ? THEN v.foto_perfil ELSE a.foto_perfil END AS otro_foto,
            CASE WHEN c.comprador_id = ? THEN v.nombre ELSE a.nombre END AS otro_nombre,
            CASE WHEN c.comprador_id = ? THEN v.id ELSE a.id END AS otro_id,
            (SELECT mensaje FROM mensajes WHERE conversacion_id = c.id ORDER BY enviado_en DESC LIMIT 1) AS ultimo_mensaje,
            (SELECT COUNT(*) FROM mensajes WHERE conversacion_id = c.id AND remitente_id != ? AND leido = 0) AS sin_leer
     FROM conversaciones c
     LEFT JOIN servicios s ON c.servicio_id = s.id
     LEFT JOIN alumnos a ON c.comprador_id = a.id
     LEFT JOIN alumnos v ON c.vendedor_id = v.id
     WHERE ((c.comprador_id = ? AND c.oculto_comprador = 0) OR (c.vendedor_id = ? AND c.oculto_vendedor = 0))`,
    [usuarioId, usuarioId, usuarioId, usuarioId, usuarioId, usuarioId],
  );

  const [aulas] = await pool.query<BandejaRow[]>(
    `SELECT k.id, 'aula' AS tipo,
            k.fecha_creacion AS fecha_sort,
            COALESCE(s.titulo, 'Clase agendada') AS servicio_titulo,
            CASE WHEN k.comprador_id = ? THEN v.foto_perfil ELSE a.foto_perfil END AS otro_foto,
            CASE WHEN k.comprador_id = ? THEN v.nombre ELSE a.nombre END AS otro_nombre,
            CASE WHEN k.comprador_id = ? THEN v.id ELSE a.id END AS otro_id,
            (SELECT mensaje FROM chat_aula WHERE contrato_id = k.id ORDER BY fecha DESC LIMIT 1) AS ultimo_mensaje,
            (SELECT COUNT(*) FROM chat_aula WHERE contrato_id = k.id AND remitente_id != ? AND visto = 0) AS sin_leer
     FROM contratos k
     LEFT JOIN servicios s ON k.servicio_id = s.id
     LEFT JOIN alumnos a ON k.comprador_id = a.id
     LEFT JOIN alumnos v ON k.vendedor_id = v.id
     WHERE ((k.comprador_id = ? AND k.oculto_comprador = 0) OR (k.vendedor_id = ? AND k.oculto_vendedor = 0))
       AND k.estado IN ('en_progreso', 'liberado')`,
    [usuarioId, usuarioId, usuarioId, usuarioId, usuarioId, usuarioId],
  );

  const todos = [...negociaciones, ...aulas].map((row) => ({
    id: row.id,
    tipo: row.tipo,
    fechaSort: row.fecha_sort ?? "",
    servicioTitulo: row.servicio_titulo ?? "Servicio",
    otroId: row.otro_id,
    otroNombre: formatearNombreCorto(row.otro_nombre),
    otroFotoUrl: resolverFotoChat(row.otro_foto),
    ultimoMensaje: row.ultimo_mensaje,
    sinLeer: row.sin_leer,
  }));

  todos.sort((a, b) => {
    const ta = a.fechaSort ? new Date(a.fechaSort).getTime() : 0;
    const tb = b.fechaSort ? new Date(b.fechaSort).getTime() : 0;
    return tb - ta;
  });

  return todos;
}

// Puerto exacto de eliminar_conversacion.php — soft-hide individual por lado, para
// conversaciones Y contratos/aulas (a diferencia de accion_chat.php, que en 'aula' era
// un no-op — acá SÍ funciona para ambos tipos, como la BD ya lo soporta).
export async function eliminarChats(usuarioId: number, items: { tipo: "negociacion" | "aula"; id: number }[]): Promise<number> {
  let eliminados = 0;
  for (const item of items) {
    const tabla = item.tipo === "negociacion" ? "conversaciones" : "contratos";
    const [result] = await pool.query<ResultSetHeader>(
      `UPDATE ${tabla}
       SET oculto_comprador = IF(comprador_id = ?, 1, oculto_comprador),
           oculto_vendedor  = IF(vendedor_id = ?, 1, oculto_vendedor)
       WHERE id = ? AND (comprador_id = ? OR vendedor_id = ?)`,
      [usuarioId, usuarioId, item.id, usuarioId, usuarioId],
    );
    if (result.affectedRows > 0) eliminados++;
  }
  return eliminados;
}

// ============================================================================
// Iniciar chat (app/iniciar_chat.php)
// ============================================================================

interface ServicioParaChatRow extends RowDataPacket {
  id: number;
  vendedor_id: number;
}

interface ChatExistenteRow extends RowDataPacket {
  id: number;
  ultimo_mensaje: string | null;
}

export type IniciarChatResultado = { ok: true; chatId: number } | { ok: false; error: "servicio_no_encontrado" | "propio_servicio" };

// Puerto exacto de iniciar_chat.php:40-132 — idempotente (reusa un chat existente salvo que
// el último mensaje real tenga 7+ días, mismo umbral), CON UNA CORRECCIÓN: el mensaje
// inicial pasa por enviarMensaje() (con DLP) en vez de un INSERT directo — ver nota de
// alcance en chat.types.ts.
export async function iniciarChat(compradorId: number, servicioId: number, mensajeInicial: string): Promise<IniciarChatResultado> {
  const [servRows] = await pool.query<ServicioParaChatRow[]>("SELECT id, alumno_id AS vendedor_id FROM servicios WHERE id = ? LIMIT 1", [servicioId]);
  const servicio = servRows[0];
  if (!servicio) return { ok: false, error: "servicio_no_encontrado" };

  const vendedorId = servicio.vendedor_id;
  if (compradorId === vendedorId) return { ok: false, error: "propio_servicio" };

  const [existenteRows] = await pool.query<ChatExistenteRow[]>(
    `SELECT c.id, (SELECT MAX(m.enviado_en) FROM mensajes m WHERE m.conversacion_id = c.id) AS ultimo_mensaje
     FROM conversaciones c
     WHERE c.servicio_id = ? AND c.comprador_id = ? AND c.vendedor_id = ?
     LIMIT 1`,
    [servicioId, compradorId, vendedorId],
  );
  const existente = existenteRows[0];

  let conversacionVencida = false;
  if (existente?.ultimo_mensaje) {
    const diasDesdeUltimo = (Date.now() - new Date(existente.ultimo_mensaje).getTime()) / 86_400_000;
    conversacionVencida = diasDesdeUltimo >= 7;
  }

  let chatId: number;
  if (existente && !conversacionVencida) {
    chatId = existente.id;
  } else {
    const [ins] = await pool.query<ResultSetHeader>("INSERT INTO conversaciones (servicio_id, comprador_id, vendedor_id) VALUES (?, ?, ?)", [
      servicioId,
      compradorId,
      vendedorId,
    ]);
    chatId = ins.insertId;
  }

  if (mensajeInicial.trim()) {
    await enviarMensaje(compradorId, chatId, mensajeInicial);
  }

  return { ok: true, chatId };
}

// ============================================================================
// Detalle del chat (app/chat_previo_contrato.php)
// ============================================================================

interface ChatDetalleRow extends RowDataPacket {
  id: number;
  servicio_id: number;
  contrato_id: number | null;
  comprador_id: number;
  vendedor_id: number;
  servicio_titulo: string;
  categoria: string | null;
  precio: number;
  precio_oferta: number | null;
  is_subvencionado: number;
  cupos_oferta: number | null;
  duracion_minutos: number | null;
  nombre_vendedor: string;
  foto_vendedor: string | null;
  nombre_comprador: string;
  foto_comprador: string | null;
}

// Puerto exacto de chat_previo_contrato.php:34-181 (menos el efecto de sesión PHP de
// "ultimo_interes_categoria", ver nota de alcance) — incluye el side-effect real de marcar
// leídos los mensajes ajenos (línea 176-180 del PHP), igual que el original.
export async function getChatDetalle(chatId: number, usuarioId: number): Promise<ChatDetalle | null> {
  const [rows] = await pool.query<ChatDetalleRow[]>(
    `SELECT c.id, c.servicio_id, c.contrato_id, c.comprador_id, c.vendedor_id,
            s.titulo AS servicio_titulo, s.categoria, s.precio, s.precio_oferta, s.is_subvencionado, s.cupos_oferta, s.duracion_minutos,
            v.nombre AS nombre_vendedor, v.foto_perfil AS foto_vendedor,
            a.nombre AS nombre_comprador, a.foto_perfil AS foto_comprador
     FROM conversaciones c
     JOIN servicios s ON c.servicio_id = s.id
     JOIN alumnos a ON c.comprador_id = a.id
     JOIN alumnos v ON c.vendedor_id = v.id
     WHERE c.id = ? AND (c.comprador_id = ? OR c.vendedor_id = ?)
     LIMIT 1`,
    [chatId, usuarioId, usuarioId],
  );
  const chat = rows[0];
  if (!chat) return null;

  const esVendedor = chat.vendedor_id === usuarioId;
  const otroId = esVendedor ? chat.comprador_id : chat.vendedor_id;
  const otroNombreCrudo = esVendedor ? chat.nombre_comprador : chat.nombre_vendedor;
  const otroFotoCruda = esVendedor ? chat.foto_comprador : chat.foto_vendedor;

  const [otroRows] = await pool.query<(RowDataPacket & { ultima_sesion: string | null; bloqueado: number })[]>(
    "SELECT ultima_sesion, bloqueado FROM alumnos WHERE id = ? LIMIT 1",
    [otroId],
  );
  const otroRow = otroRows[0];
  const otroOnline = !!otroRow?.ultima_sesion && Date.now() - new Date(otroRow.ultima_sesion).getTime() < 300_000;
  const destinatarioSuspendido = !!otroRow?.bloqueado;

  // Puerto de chat_previo_contrato.php:134-157 — inactividad del tutor: 48h desde el último
  // mensaje, SOLO si el último mensaje lo mandé yo (soy comprador) y sigue sin respuesta.
  let tutorInactivo = false;
  if (!esVendedor) {
    const [ultimoRows] = await pool.query<(RowDataPacket & { remitente_id: number; enviado_en: string })[]>(
      "SELECT remitente_id, enviado_en FROM mensajes WHERE conversacion_id = ? ORDER BY id DESC LIMIT 1",
      [chatId],
    );
    const ultimo = ultimoRows[0];
    if (ultimo && ultimo.remitente_id === usuarioId) {
      const horasInactivo = (Date.now() - new Date(ultimo.enviado_en).getTime()) / 3_600_000;
      tutorInactivo = horasInactivo >= 48;
    }
  }

  // Puerto de chat_previo_contrato.php:160-173 — límite de 6 mensajes antes de contratar.
  let limiteMensajesAlcanzado = false;
  if (!chat.contrato_id) {
    const [cntRows] = await pool.query<(RowDataPacket & { total: number })[]>(
      "SELECT COUNT(*) AS total FROM mensajes WHERE conversacion_id = ? AND visible = 1",
      [chatId],
    );
    limiteMensajesAlcanzado = (cntRows[0]?.total ?? 0) >= 6;
  }

  // Puerto de chat_previo_contrato.php:175-181 — marcar leídos los mensajes ajenos.
  await pool.query("UPDATE mensajes SET leido = 1 WHERE conversacion_id = ? AND remitente_id != ?", [chatId, usuarioId]);

  const esOferta = chat.is_subvencionado === 1 && (chat.cupos_oferta ?? 0) > 0;

  return {
    id: chat.id,
    servicioId: chat.servicio_id,
    servicioTitulo: chat.servicio_titulo,
    esVendedor,
    otroId,
    otroNombre: formatearNombreCorto(otroNombreCrudo),
    otroFotoUrl: resolverFotoChat(otroFotoCruda),
    otroOnline,
    destinatarioSuspendido,
    tutorInactivo,
    limiteMensajesAlcanzado,
    contratoId: chat.contrato_id,
    servicio: {
      precio: Number(chat.precio),
      precioOferta: esOferta ? Number(chat.precio_oferta) : null,
      esOferta,
      duracionMinutos: chat.duracion_minutos || 60,
    },
  };
}

// ============================================================================
// Mensajes (app/cargar_mensajes.php + app/render_mensajes.php)
// ============================================================================

interface MensajeRow extends RowDataPacket {
  id: number;
  remitente_id: number;
  mensaje: string;
  enviado_en: string;
  leido: number;
  archivo_nombre: string | null;
  archivo_ruta: string | null;
  archivo_tipo: string | null;
  archivo_peso: number | null;
}

function mapMensaje(row: MensajeRow): MensajeChat {
  return {
    id: row.id,
    remitenteId: row.remitente_id,
    esSistema: row.remitente_id === 0,
    mensaje: row.mensaje,
    enviadoEn: row.enviado_en,
    leido: !!row.leido,
    archivo: row.archivo_ruta
      ? { nombre: row.archivo_nombre ?? "archivo", tipo: row.archivo_tipo ?? "application/octet-stream", peso: row.archivo_peso ?? 0, url: `/api/me/chat/archivo/${row.id}` }
      : null,
  };
}

// Puerto exacto de cargar_mensajes.php:50-107 (contexto='conversacion' únicamente — el
// contexto 'aula' es la pieza Mini Aula, todavía no portada) — marca leídos + detecta
// "escribiendo" + trae la lista completa. Devuelve JSON (no HTML) porque el consumidor acá
// es un componente React, no un fragmento inyectado por innerHTML.
export async function getMensajes(chatId: number, usuarioId: number): Promise<{ mensajes: MensajeChat[]; otroEscribiendo: boolean } | null> {
  const [autorizado] = await pool.query<RowDataPacket[]>(
    "SELECT id FROM conversaciones WHERE id = ? AND (comprador_id = ? OR vendedor_id = ?) LIMIT 1",
    [chatId, usuarioId, usuarioId],
  );
  if (autorizado.length === 0) return null;

  await pool.query("UPDATE mensajes SET leido = 1 WHERE conversacion_id = ? AND remitente_id != ?", [chatId, usuarioId]);

  const [typingRows] = await pool.query<RowDataPacket[]>(
    "SELECT 1 FROM chat_typing WHERE conversacion_id = ? AND usuario_id != ? AND ultima_actividad > (NOW() - INTERVAL 4 SECOND) LIMIT 1",
    [chatId, usuarioId],
  );
  const otroEscribiendo = typingRows.length > 0;

  const [msgRows] = await pool.query<MensajeRow[]>(
    `SELECT id, remitente_id, mensaje, enviado_en, leido, archivo_nombre, archivo_ruta, archivo_tipo, archivo_peso
     FROM mensajes WHERE conversacion_id = ? AND (visible = 1 OR remitente_id = ?) ORDER BY enviado_en ASC`,
    [chatId, usuarioId],
  );

  return { mensajes: msgRows.map(mapMensaje), otroEscribiendo };
}

// ============================================================================
// Enviar mensaje (app/enviar_mensaje.php)
// ============================================================================

interface ConversacionEnvioRow extends RowDataPacket {
  comprador_id: number;
  vendedor_id: number;
  contrato_id: number | null;
}

// Puerto exacto de enviar_mensaje.php:20-425, MENOS el bloque de notificación push/correo
// (paso 8, líneas 347-424 — diferido, ver nota de alcance en chat.types.ts) y MENOS el
// escáner de materias (líneas 213-234 — mismo motivo). Todo el resto — gate express, límite
// de 6 mensajes, las 7 categorías DLP + 3 reglas contextuales, chequeo de permisos, chequeo
// de destinatario bloqueado, inserción, "resucitar" conversación oculta, tracker de tiempo
// de respuesta — se replica exacto.
export async function enviarMensaje(usuarioId: number, chatId: number, mensajeCrudo: string): Promise<ResultadoEnviarMensaje> {
  const mensaje = mensajeCrudo.trim();
  if (chatId <= 0 || !mensaje) {
    return { ok: false, error: { tipo: "datos_invalidos", mensaje: "Escribe un mensaje válido." } };
  }

  const [convRows] = await pool.query<ConversacionEnvioRow[]>("SELECT comprador_id, vendedor_id, contrato_id FROM conversaciones WHERE id = ? LIMIT 1", [
    chatId,
  ]);
  const conv = convRows[0];
  if (!conv || (conv.comprador_id !== usuarioId && conv.vendedor_id !== usuarioId)) {
    return { ok: false, error: { tipo: "sin_acceso", mensaje: "Acceso no autorizado." } };
  }

  // Gate express — puerto de enviar_mensaje.php:33-47, pero derivado de la BD
  // (alumnos.cuenta_express) en vez de $_SESSION['cuenta_express'] — server/ no tiene sesión
  // PHP compartida, y la columna real ya expresa lo mismo de forma consultable.
  const [expressRows] = await pool.query<(RowDataPacket & { cuenta_express: number })[]>("SELECT cuenta_express FROM alumnos WHERE id = ? LIMIT 1", [
    usuarioId,
  ]);
  const esExpress = !!expressRows[0]?.cuenta_express;
  let cntExpress = 0;
  if (esExpress) {
    const [cntRows] = await pool.query<(RowDataPacket & { n: number })[]>(
      "SELECT COUNT(*) AS n FROM mensajes WHERE remitente_id = ? AND conversacion_id = ?",
      [usuarioId, chatId],
    );
    cntExpress = cntRows[0]?.n ?? 0;
    if (cntExpress >= 2) {
      return { ok: false, error: { tipo: "requiere_completar" } };
    }
  }

  // Límite de 6 mensajes antes de contratar — puerto de enviar_mensaje.php:54-77.
  if (!conv.contrato_id) {
    const [totalRows] = await pool.query<(RowDataPacket & { total: number })[]>(
      "SELECT COUNT(*) AS total FROM mensajes WHERE conversacion_id = ? AND visible = 1",
      [chatId],
    );
    if ((totalRows[0]?.total ?? 0) >= 6) {
      return {
        ok: false,
        error: {
          tipo: "limite_alcanzado",
          mensaje: "Llegaste al límite de mensajes antes de contratar. Si quieres seguir conversando, avanza con la contratación del servicio.",
        },
      };
    }
  }

  // DLP — incluida la variante 5d (teléfono fraccionado), que necesita los últimos 5
  // mensajes del MISMO remitente en los últimos 5 minutos.
  const [previosRows] = await pool.query<(RowDataPacket & { mensaje: string })[]>(
    "SELECT mensaje FROM mensajes WHERE conversacion_id = ? AND remitente_id = ? AND enviado_en > (NOW() - INTERVAL 5 MINUTE) ORDER BY id DESC LIMIT 5",
    [chatId, usuarioId],
  );
  const previos = previosRows.map((r) => r.mensaje).reverse();
  const resultadoDlp = verificarDlp(mensaje, previos);
  if (resultadoDlp.bloqueado) {
    await registrarIntentoDlp(chatId, usuarioId, mensaje, resultadoDlp.categoria!, resultadoDlp.patronDescripcion!);
    return { ok: false, error: { tipo: "dlp", mensaje: resultadoDlp.mensajeUsuario! } };
  }

  // Bloqueo por suspensión del destinatario — puerto de enviar_mensaje.php:254-276.
  const idDestinatario = conv.comprador_id === usuarioId ? conv.vendedor_id : conv.comprador_id;
  const [destRows] = await pool.query<(RowDataPacket & { bloqueado: number })[]>("SELECT bloqueado FROM alumnos WHERE id = ? LIMIT 1", [idDestinatario]);
  if (destRows[0]?.bloqueado) {
    return { ok: false, error: { tipo: "destinatario_no_disponible", mensaje: "Esta persona no está disponible temporalmente." } };
  }

  // Inserción + resucitar conversación oculta + tracker de tiempo de respuesta — mismo
  // orden que el PHP real (líneas 278-345), en una transacción (el PHP no la tenía, pero
  // ninguno de estos pasos puede fallar de forma que valga la pena revertir los anteriores
  // — se mantiene fuera de transacción explícita para no desviarse sin necesidad real).
  await pool.query("INSERT INTO mensajes (conversacion_id, remitente_id, mensaje, enviado_en, leido) VALUES (?, ?, ?, NOW(), 0)", [
    chatId,
    usuarioId,
    mensaje,
  ]);

  await pool.query("UPDATE conversaciones SET oculto_comprador = 0, oculto_vendedor = 0, ultima_interaccion = NOW() WHERE id = ?", [chatId]);

  // Tracker de tiempo de respuesta — puerto de enviar_mensaje.php:296-345 (solo si quien
  // envía es el vendedor, respondiendo a un mensaje real del comprador, dentro de 24h).
  if (conv.vendedor_id === usuarioId) {
    const [ultMsgRows] = await pool.query<(RowDataPacket & { enviado_en: string })[]>(
      "SELECT enviado_en FROM mensajes WHERE conversacion_id = ? AND remitente_id = ? ORDER BY id DESC LIMIT 1",
      [chatId, conv.comprador_id],
    );
    const ultMsg = ultMsgRows[0];
    if (ultMsg) {
      const minutos = Math.floor((Date.now() - new Date(ultMsg.enviado_en).getTime()) / 60_000);
      if (minutos >= 0 && minutos <= 1440) {
        await pool.query("INSERT INTO respuestas_tutor (tutor_id, conversacion_id, minutos_respuesta) VALUES (?, ?, ?)", [usuarioId, chatId, minutos]);
      }
    }
  }

  return { ok: true, mostrarBannerExpress: esExpress && cntExpress === 1 };
}

async function registrarIntentoDlp(chatId: number, usuarioId: number, mensaje: string, categoria: string, patronDescripcion: string): Promise<void> {
  try {
    await pool.query(
      "INSERT INTO dlp_intentos (conversacion_id, remitente_id, categoria, patron_matched, texto_intentado) VALUES (?, ?, ?, ?, ?)",
      [chatId, usuarioId, categoria, patronDescripcion.slice(0, 200), mensaje],
    );
  } catch {
    // No debe bloquear la respuesta al usuario — mismo criterio que nb_dlp_bloquear() real.
  }
}

// ============================================================================
// Typing (app/typing_set.php)
// ============================================================================

export async function setTyping(usuarioId: number, chatId: number): Promise<boolean> {
  const [autorizado] = await pool.query<RowDataPacket[]>(
    "SELECT id FROM conversaciones WHERE id = ? AND (comprador_id = ? OR vendedor_id = ?) LIMIT 1",
    [chatId, usuarioId, usuarioId],
  );
  if (autorizado.length === 0) return false;

  await pool.query("INSERT INTO chat_typing (conversacion_id, usuario_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE ultima_actividad = CURRENT_TIMESTAMP", [
    chatId,
    usuarioId,
  ]);
  return true;
}

// ============================================================================
// Archivo de chat (app/ver_archivo_chat.php) — solo lectura, ver nota de alcance arriba.
// ============================================================================

export interface ArchivoChatInfo {
  rutaRelativa: string;
  mime: string;
  nombre: string;
}

export async function getArchivoChatInfo(mensajeId: number, usuarioId: number): Promise<ArchivoChatInfo | null> {
  const [rows] = await pool.query<(RowDataPacket & { archivo_nombre: string | null; archivo_ruta: string | null; archivo_tipo: string | null })[]>(
    `SELECT m.archivo_nombre, m.archivo_ruta, m.archivo_tipo
     FROM mensajes m
     JOIN conversaciones c ON c.id = m.conversacion_id
     WHERE m.id = ? AND m.archivo_ruta IS NOT NULL
       AND (c.comprador_id = ? OR c.vendedor_id = ?)
       AND (m.visible = 1 OR m.remitente_id = ?)
     LIMIT 1`,
    [mensajeId, usuarioId, usuarioId, usuarioId],
  );
  const row = rows[0];
  if (!row || !row.archivo_ruta) return null;
  return { rutaRelativa: row.archivo_ruta, mime: row.archivo_tipo ?? "application/octet-stream", nombre: row.archivo_nombre ?? "archivo" };
}
