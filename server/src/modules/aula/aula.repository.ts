import crypto from "node:crypto";
import fs from "node:fs/promises";
import path from "node:path";
import type { ResultSetHeader, RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";
import { env } from "../../config/env.js";
import { verificarDlp } from "../../lib/dlp.js";
import type { ArchivoContrato, EstadoAula, MensajeAula, ResultadoEnviarMensajeAula, ResultadoSalaVideo } from "./aula.types.js";

// ============================================================================
// URLs de sala (video Daily.co + pizarra Excalidraw) — Fase 4. Puerto EXACTO de
// mini_aula.php:192-205: mismos salts ("nubira_secreto_2026", "nubira_pizarra_", "key_"),
// no un detalle de implementación — determinan el nombre real de la sala/pizarra, así que
// un comprador en Next y un vendedor todavía en el PHP viejo (o viceversa, durante la
// transición) deben terminar en la MISMA sala real. Verificado byte a byte contra el PHP
// real (md5+base64url) antes de portar — cambiar cualquiera de estos strings o el cálculo
// del hash rompería esa compatibilidad cruzada de forma silenciosa.
// ============================================================================

const DAILY_DOMINIO = "https://nubira-cl.daily.co/";
const EXCALIDRAW_BASE = "https://excalidraw.com/#room=";

function hashSeguridadSala(contratoId: number): string {
  return crypto
    .createHash("md5")
    .update(`${contratoId}nubira_secreto_2026`)
    .digest("hex")
    .slice(0, 8);
}

function computeSalaVideoUrl(contratoId: number): { nombreSala: string; url: string } {
  const hash = hashSeguridadSala(contratoId);
  const nombreSala = `aula-${contratoId}-${hash}`;
  return { nombreSala, url: `${DAILY_DOMINIO}${nombreSala}` };
}

function computePizarraUrl(contratoId: number): string {
  const hash = hashSeguridadSala(contratoId);
  const roomId = crypto
    .createHash("md5")
    .update(`nubira_pizarra_${contratoId}_${hash}`)
    .digest("hex")
    .slice(0, 20);
  // md5(..., true) de PHP = digest binario crudo (16 bytes); base64_encode + substr(0,22) +
  // strtr(+/= -> -_ sin relleno) es exactamente base64url sin padding sobre esos 16 bytes.
  const key = crypto.createHash("md5").update(`key_${contratoId}_${hash}`).digest("base64url");
  return `${EXCALIDRAW_BASE}${roomId},${key}`;
}

// Puerto de mini_aula.php:207-231 — best-effort a propósito, igual que el PHP real: la
// respuesta de Daily se ignora por completo (si la sala ya existe, la API devuelve error, y
// no importa — lo único que importa es que exista para cuando el usuario haga join()).
async function asegurarSalaDailyExiste(nombreSala: string): Promise<void> {
  if (!env.dailyApiKey) return;
  try {
    await fetch("https://api.daily.co/v1/rooms", {
      method: "POST",
      headers: { "Content-Type": "application/json", Authorization: `Bearer ${env.dailyApiKey}` },
      body: JSON.stringify({
        name: nombreSala,
        privacy: "public",
        properties: {
          exp: Math.floor(Date.now() / 1000) + 86400 * 30,
          enable_prejoin_ui: false,
          enable_network_ui: true,
          enable_screenshare: true,
          enable_chat: false,
        },
      }),
    });
  } catch {
    // Best-effort — igual que el PHP real, que tampoco revisa curl_exec().
  }
}

// Puerto de mini_aula.php:656-699 (iniciarClase(), la parte de servidor) — asegura que la
// sala Daily existe y devuelve la URL real + el nombre a mostrar. A diferencia del PHP (que
// dispara la creación de sala en CADA carga de la página, incluso antes de que el usuario
// haga click en "Entrar a la Sala"), acá se dispara solo cuando el usuario realmente hace
// click — AulaShell.tsx hace poll de getAulaDetalle cada 30s, y llamar a la API de Daily en
// cada uno de esos polls sería un uso real y evitable de su cuota, sin ningún beneficio
// (el join() de todos modos espera esta misma llamada antes de intentar conectar).
export async function ensureSalaVideo(contratoId: number, usuarioId: number, esAdmin: boolean): Promise<ResultadoSalaVideo> {
  const detalle = await getAulaDetalle(contratoId, usuarioId, esAdmin);
  if (!detalle) return { ok: false, error: "sin_acceso" };
  if (!detalle.videoHabilitado) return { ok: false, error: "video_deshabilitado" };

  const [rows] = await pool.query<(RowDataPacket & { nombre: string })[]>("SELECT nombre FROM alumnos WHERE id = ? LIMIT 1", [usuarioId]);
  const userName = rows[0]?.nombre ?? "Usuario";

  const { nombreSala, url } = computeSalaVideoUrl(contratoId);
  await asegurarSalaDailyExiste(nombreSala);

  return { ok: true, roomUrl: url, userName };
}

// ============================================================================
// Detalle del aula (app/mini_aula.php:1-230, sin el bloque de Daily.co/pizarra)
// ============================================================================

interface ContratoAulaRow extends RowDataPacket {
  id: number;
  servicio_id: number;
  comprador_id: number;
  vendedor_id: number;
  estado: string;
  finalizado_comprador: number;
  finalizado_vendedor: number;
  servicio_titulo: string;
  nombre_vendedor: string | null;
  nombre_comprador: string | null;
}

interface SlotAulaRow extends RowDataPacket {
  fecha_clase: string;
  duracion_minutos: number;
  clase_fin: string;
  ventana_apertura: string;
  fin_gracia: string;
  tope_duro: string;
}

const GRACIA_POST_CLASE_MIN = 60;
// Puerto de mini_aula.php:108-110 — extensión de la gracia fija por actividad real
// (Fase 3, ver sala_presencia más abajo).
const HEARTBEAT_VENTANA_MIN = 10;
const TOPE_DURO_POST_CLASE_MIN = 90;

export async function getAulaDetalle(contratoId: number, usuarioId: number, esAdminSesion: boolean) {
  const sql = `SELECT c.id, c.servicio_id, c.comprador_id, c.vendedor_id, c.estado,
                      c.finalizado_comprador, c.finalizado_vendedor,
                      s.titulo AS servicio_titulo,
                      v.nombre AS nombre_vendedor, a.nombre AS nombre_comprador
               FROM contratos c
               JOIN servicios s ON s.id = c.servicio_id
               LEFT JOIN alumnos v ON v.id = c.vendedor_id
               LEFT JOIN alumnos a ON a.id = c.comprador_id
               WHERE c.id = ?${esAdminSesion ? "" : " AND (c.comprador_id = ? OR c.vendedor_id = ?)"}`;
  const params = esAdminSesion ? [contratoId] : [contratoId, usuarioId, usuarioId];
  const [rows] = await pool.query<ContratoAulaRow[]>(sql, params);
  const contrato = rows[0];
  if (!contrato) return null;

  // Puerto de mini_aula.php:54-70 — marcar leídos los mensajes del chat PRE-contrato
  // ligados a este mismo servicio/par comprador-vendedor (distinto de chat_aula).
  await pool.query(
    `UPDATE mensajes m
     INNER JOIN conversaciones c ON m.conversacion_id = c.id
     SET m.leido = 1
     WHERE c.comprador_id = ? AND c.vendedor_id = ? AND c.servicio_id = ?
       AND m.remitente_id != ? AND m.leido = 0`,
    [contrato.comprador_id, contrato.vendedor_id, contrato.servicio_id, usuarioId],
  );

  // Puerto de mini_aula.php:75-126 — toda la aritmética de fechas se hace en MySQL
  // (DATE_ADD/DATE_SUB, que sí sabe cruzar medianoche/mes/año) y se compara como strings
  // 'YYYY-MM-DD HH:mm:ss' (ordenan igual que fechas reales) — nunca vía Date de JS ni
  // epoch: mismo criterio anti-ambigüedad de zona horaria que contratos.repository.ts
  // (el proceso Node puede correr en una zona horaria distinta a la que asume la BD).
  const BUFFER_ANTES_MIN = 5;
  const [slotRows] = await pool.query<SlotAulaRow[]>(
    `SELECT DATE_FORMAT(fecha_clase, '%Y-%m-%d %H:%i:%s') AS fecha_clase,
            duracion_minutos,
            DATE_FORMAT(DATE_ADD(fecha_clase, INTERVAL duracion_minutos MINUTE), '%Y-%m-%d %H:%i:%s') AS clase_fin,
            DATE_FORMAT(DATE_SUB(fecha_clase, INTERVAL ${BUFFER_ANTES_MIN} MINUTE), '%Y-%m-%d %H:%i:%s') AS ventana_apertura,
            DATE_FORMAT(DATE_ADD(DATE_ADD(fecha_clase, INTERVAL duracion_minutos MINUTE), INTERVAL ${GRACIA_POST_CLASE_MIN} MINUTE), '%Y-%m-%d %H:%i:%s') AS fin_gracia,
            DATE_FORMAT(DATE_ADD(DATE_ADD(fecha_clase, INTERVAL duracion_minutos MINUTE), INTERVAL ${TOPE_DURO_POST_CLASE_MIN} MINUTE), '%Y-%m-%d %H:%i:%s') AS tope_duro
     FROM reservas_slots WHERE contrato_id = ? LIMIT 1`,
    [contratoId],
  );
  const slot = slotRows[0] ?? null;
  const tieneReserva = !!slot;

  const [[ahoraRow]] = await pool.query<(RowDataPacket & { ahora: string })[][]>("SELECT DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s') AS ahora");
  const ahora = (ahoraRow as unknown as { ahora: string }).ahora;

  let esPreClase: boolean;
  let esAulaActiva: boolean;
  let esPostClase: boolean;
  let videoHabilitado: boolean;
  let claseIniTs: string;
  let claseFinTs: string;
  let ventanaAperturaTs: string;
  let finGraciaTs: string;

  if (slot) {
    claseIniTs = slot.fecha_clase;
    claseFinTs = slot.clase_fin;
    ventanaAperturaTs = slot.ventana_apertura;
    finGraciaTs = slot.fin_gracia;
    esPreClase = ahora < ventanaAperturaTs;
    esAulaActiva = ahora >= ventanaAperturaTs && ahora <= claseFinTs;
    esPostClase = ahora > claseFinTs;

    // Puerto de mini_aula.php:103-126 (hallazgo #2 INFORME-MINI-AULA.md) — Fase 3: ahora que
    // sala_presencia reemplaza sala_activa_<id>.txt, se restaura la extensión real que
    // Pieza 2 había simplificado a solo la gracia fija: heartbeat reciente (<10 min) en la
    // sala extiende el acceso más allá de los 60 min fijos, hasta un tope duro de 90 min.
    const enGraciaPorHorario = esPostClase && ahora <= finGraciaTs;
    const enGraciaPorActividad = esPostClase && ahora <= slot.tope_duro && (await huboActividadRecienteEnSala(contratoId));
    videoHabilitado = esAulaActiva || enGraciaPorHorario || enGraciaPorActividad;
  } else {
    // Sin reserva -> sin ventana horaria que respetar (mismo efecto que el rango de 365
    // días del PHP real: siempre "activa", nunca pre/post clase).
    claseIniTs = ahora;
    claseFinTs = ahora;
    ventanaAperturaTs = ahora;
    finGraciaTs = ahora;
    esPreClase = false;
    esAulaActiva = true;
    esPostClase = false;
    videoHabilitado = true;
  }

  const esAdmin = esAdminSesion;
  if (esAdmin) {
    esPreClase = false;
    esAulaActiva = true;
    esPostClase = false;
    videoHabilitado = true;
  }

  let fechaAmigable: string | null = null;
  if (tieneReserva && slot) {
    const [fechaParte, horaParte] = slot.fecha_clase.split(" ");
    const [anio, mes, dia] = fechaParte!.split("-").map(Number);
    // Construcción con componentes numéricos explícitos — nunca parseando el string ISO
    // completo: día-de-semana/fecha extraídos así son correctos sin importar la zona
    // horaria del proceso (mismo criterio que diaEsDeFecha() en contratos.repository.ts).
    const d = new Date(anio!, mes! - 1, dia!);
    const diasEs = ["domingo", "lunes", "martes", "miércoles", "jueves", "viernes", "sábado"];
    const mesesEs = ["enero", "febrero", "marzo", "abril", "mayo", "junio", "julio", "agosto", "septiembre", "octubre", "noviembre", "diciembre"];
    const diaNombre = diasEs[d.getDay()]!;
    const horaMin = horaParte!.slice(0, 5);
    fechaAmigable = `${diaNombre.charAt(0).toUpperCase()}${diaNombre.slice(1)} ${dia} de ${mesesEs[mes! - 1]} a las ${horaMin}`;
  }

  const esVendedorReal = usuarioId === contrato.vendedor_id;
  const esCompradorReal = usuarioId === contrato.comprador_id;
  const otroNombreCrudo = esVendedorReal ? contrato.nombre_comprador : contrato.nombre_vendedor;
  const otroNombre = (otroNombreCrudo ?? "el otro participante").split(" ")[0]!;

  // Ver nota de alcance arriba — 'finalizado' no existe en el ENUM real, esto replica
  // el comportamiento actual (siempre false), no lo "arregla".
  const esFinalizado = (contrato.estado as string) === "finalizado";
  const esActivo = contrato.estado === "activo" || contrato.estado === "en_progreso";

  return {
    id: contrato.id,
    servicioId: contrato.servicio_id,
    servicioTitulo: contrato.servicio_titulo,
    esVendedor: esVendedorReal,
    esComprador: esCompradorReal,
    esAdmin,
    otroNombre,
    estado: contrato.estado,
    tieneReserva,
    fechaAmigable,
    claseIniTs,
    claseFinTs,
    ventanaAperturaTs,
    finGraciaTs,
    esPreClase,
    esAulaActiva,
    esPostClase,
    videoHabilitado,
    // Puerto de mini_aula.php:417-422 — la pestaña Pizarra (y por lo tanto la URL) es
    // visible SOLO para el vendedor real, ni siquiera para el admin en bypass (a diferencia
    // del resto de los campos "de admin", $es_vendedor_real nunca se fuerza a true) — misma
    // restricción de producto del PHP real, no una omisión a corregir acá.
    pizarraUrl: esVendedorReal ? computePizarraUrl(contrato.id) : null,
    esFinalizado,
    finalizadoComprador: !!contrato.finalizado_comprador,
    finalizadoVendedor: !!contrato.finalizado_vendedor,
    compradorPuedeFinalizar: (esCompradorReal || esAdmin) && esActivo && !contrato.finalizado_comprador,
    compradorEsperandoInicio: (esCompradorReal || esAdmin) && !esActivo && !contrato.finalizado_comprador,
    vendedorEsperandoAlumno: esVendedorReal && !contrato.finalizado_comprador,
    vendedorPuedeConfirmar: esVendedorReal && !!contrato.finalizado_comprador && !contrato.finalizado_vendedor,
  };
}

async function puedeAccederAula(contratoId: number, usuarioId: number, esAdmin: boolean): Promise<ContratoAulaRow | null> {
  const sql = `SELECT c.id, c.servicio_id, c.comprador_id, c.vendedor_id, c.estado, c.finalizado_comprador, c.finalizado_vendedor,
                      '' AS servicio_titulo, NULL AS nombre_vendedor, NULL AS nombre_comprador
               FROM contratos c WHERE c.id = ?${esAdmin ? "" : " AND (c.comprador_id = ? OR c.vendedor_id = ?)"} LIMIT 1`;
  const params = esAdmin ? [contratoId] : [contratoId, usuarioId, usuarioId];
  const [rows] = await pool.query<ContratoAulaRow[]>(sql, params);
  return rows[0] ?? null;
}

// ============================================================================
// Presencia en la sala (Fase 3 — reemplaza app/ping_reunion.php y
// sala_activa_<id>.txt, un único archivo plano por contrato que solo guardaba el
// último usuario que hizo ping). A diferencia del archivo (un solo "slot", el ping
// de un usuario pisaba el del otro), la tabla trackea un heartbeat POR usuario —
// corrección natural al dejar de depender de un archivo de una sola ranura, no una
// decisión de producto aparte. El propio PHP (ping_reunion.php) sigue usando el
// archivo — no se modifica un sistema legacy que la migración va a reemplazar por
// completo, mismo criterio que el resto de esta migración con el código PHP viejo.
// ============================================================================

const VENTANA_ACTIVO_SEGUNDOS = 25;

let tablaSalaPresenciaVerificada = false;
async function asegurarTablaSalaPresencia(): Promise<void> {
  if (tablaSalaPresenciaVerificada) return;
  await pool.query(
    `CREATE TABLE IF NOT EXISTS sala_presencia (
      contrato_id INT NOT NULL,
      usuario_id INT NOT NULL,
      ultimo_ping DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (contrato_id, usuario_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  );
  tablaSalaPresenciaVerificada = true;
}

async function huboActividadRecienteEnSala(contratoId: number): Promise<boolean> {
  await asegurarTablaSalaPresencia();
  const [rows] = await pool.query<RowDataPacket[]>(
    `SELECT 1 FROM sala_presencia WHERE contrato_id = ? AND ultimo_ping > (NOW() - INTERVAL ${HEARTBEAT_VENTANA_MIN} MINUTE) LIMIT 1`,
    [contratoId],
  );
  return rows.length > 0;
}

// Puerto de las acciones 'entrar'/'ping' de ping_reunion.php — mismo efecto (registra
// timestamp), unificadas en una sola operación porque en el PHP real ya hacían
// exactamente lo mismo (solo se distinguían por nombre, nunca por comportamiento).
export async function registrarPresenciaSala(usuarioId: number, contratoId: number, esAdmin: boolean): Promise<boolean> {
  const contrato = await puedeAccederAula(contratoId, usuarioId, esAdmin);
  if (!contrato) return false;
  await asegurarTablaSalaPresencia();
  await pool.query("INSERT INTO sala_presencia (contrato_id, usuario_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE ultimo_ping = CURRENT_TIMESTAMP", [
    contratoId,
    usuarioId,
  ]);
  return true;
}

// Puerto de la acción 'salir' — a diferencia del archivo (unlink inmediato), acá basta con
// borrar la fila propia; el resto de participantes deja de verla "activa" de inmediato en
// vez de esperar los 25s de la ventana (mismo efecto que buscaba el unlink() real).
export async function salirDeSala(usuarioId: number, contratoId: number, esAdmin: boolean): Promise<boolean> {
  const contrato = await puedeAccederAula(contratoId, usuarioId, esAdmin);
  if (!contrato) return false;
  await asegurarTablaSalaPresencia();
  await pool.query("DELETE FROM sala_presencia WHERE contrato_id = ? AND usuario_id = ?", [contratoId, usuarioId]);
  return true;
}

// Puerto de la acción 'estado' — CORRECCIÓN DELIBERADA vs. el PHP real: ping_reunion.php
// devuelve el último usuario_id que pingeó tal cual (aunque sea uno mismo) y deja que el
// JS del cliente compare "data.usuario_id != mi_id" para decidir si mostrar el badge
// (mini_aula.php:811). Acá el filtro "no soy yo" se hace en el servidor — es la fuente de
// verdad, no un detalle que cada consumidor del endpoint tenga que recordar replicar.
export async function getEstadoPresenciaSala(contratoId: number, usuarioId: number, esAdmin: boolean): Promise<{ activo: boolean; usuarioId: number | null } | null> {
  const contrato = await puedeAccederAula(contratoId, usuarioId, esAdmin);
  if (!contrato) return null;
  await asegurarTablaSalaPresencia();
  const [rows] = await pool.query<(RowDataPacket & { usuario_id: number })[]>(
    `SELECT usuario_id FROM sala_presencia
     WHERE contrato_id = ? AND usuario_id != ? AND ultimo_ping > (NOW() - INTERVAL ${VENTANA_ACTIVO_SEGUNDOS} SECOND)
     ORDER BY ultimo_ping DESC LIMIT 1`,
    [contratoId, usuarioId],
  );
  const row = rows[0];
  return { activo: !!row, usuarioId: row ? row.usuario_id : null };
}

// ============================================================================
// Chat del aula (app/chat_mini_aula.php + cargar/enviar/typing _mini_aula)
// ============================================================================

interface ChatAulaRow extends RowDataPacket {
  id: number;
  remitente_id: number;
  mensaje: string;
  fecha: string;
  visto: number;
}

export async function getMensajesAula(contratoId: number, usuarioId: number, esAdmin: boolean): Promise<{ mensajes: MensajeAula[]; otroEscribiendo: boolean } | null> {
  const contrato = await puedeAccederAula(contratoId, usuarioId, esAdmin);
  if (!contrato) return null;

  // Puerto de cargar_mensajes_chat_mini_aula.php:47-51 — el admin NO marca leído (solo
  // observa), mismo criterio que el resto de la migración para el rol admin de solo lectura.
  if (!esAdmin) {
    await pool.query("UPDATE chat_aula SET visto = 1 WHERE contrato_id = ? AND remitente_id != ? AND visto = 0", [contratoId, usuarioId]);
  }

  await asegurarTablaTypingAula();
  const [typingRows] = await pool.query<RowDataPacket[]>(
    "SELECT 1 FROM chat_typing_aula WHERE contrato_id = ? AND usuario_id != ? AND ultima_actividad > (NOW() - INTERVAL 4 SECOND) LIMIT 1",
    [contratoId, usuarioId],
  );

  const [msgRows] = await pool.query<ChatAulaRow[]>("SELECT id, remitente_id, mensaje, fecha, visto FROM chat_aula WHERE contrato_id = ? ORDER BY fecha ASC", [
    contratoId,
  ]);

  return {
    mensajes: msgRows.map((r) => ({ id: r.id, remitenteId: r.remitente_id, mensaje: r.mensaje, fecha: r.fecha, visto: !!r.visto })),
    otroEscribiendo: typingRows.length > 0,
  };
}

// Puerto exacto de enviar_mensajes_chat_mini_aula.php — mismo bloqueo por suspensión del
// REMITENTE (asimétrico: no bloquea si el otro está suspendido), misma DLP (vía lib/dlp.ts
// compartida, ver nota de corrección en aula.types.ts), mismo chequeo de estado del
// contrato antes de insertar.
export async function enviarMensajeAula(usuarioId: number, contratoId: number, mensajeCrudo: string): Promise<ResultadoEnviarMensajeAula> {
  const mensaje = mensajeCrudo.trim();
  if (contratoId <= 0 || !mensaje) {
    return { ok: false, error: { tipo: "datos_invalidos" } };
  }

  const [suspRows] = await pool.query<(RowDataPacket & { bloqueado: number })[]>("SELECT bloqueado FROM alumnos WHERE id = ? LIMIT 1", [usuarioId]);
  if (suspRows[0]?.bloqueado) {
    return { ok: false, error: { tipo: "suspendido", mensaje: "Tu cuenta está suspendida temporalmente y no puede enviar mensajes." } };
  }

  const [previosRows] = await pool.query<(RowDataPacket & { mensaje: string })[]>(
    "SELECT mensaje FROM chat_aula WHERE contrato_id = ? AND remitente_id = ? AND fecha > (NOW() - INTERVAL 5 MINUTE) ORDER BY id DESC LIMIT 5",
    [contratoId, usuarioId],
  );
  const previos = previosRows.map((r) => r.mensaje).reverse();
  const resultadoDlp = verificarDlp(mensaje, previos);
  if (resultadoDlp.bloqueado) {
    await registrarIntentoDlpAula(contratoId, usuarioId, mensaje, resultadoDlp.categoria!, resultadoDlp.patronDescripcion!);
    return { ok: false, error: { tipo: "dlp", mensaje: resultadoDlp.mensajeUsuario! } };
  }

  const [contratoRows] = await pool.query<(RowDataPacket & { estado: string })[]>(
    "SELECT estado FROM contratos WHERE id = ? AND (comprador_id = ? OR vendedor_id = ?) LIMIT 1",
    [contratoId, usuarioId, usuarioId],
  );
  const contrato = contratoRows[0];
  if (!contrato) {
    return { ok: false, error: { tipo: "sin_acceso", mensaje: "Sin permiso en este chat" } };
  }
  if (["cancelado", "finalizado", "disputa"].includes(contrato.estado)) {
    return { ok: false, error: { tipo: "aula_cerrada", mensaje: "El aula está cerrada." } };
  }

  await pool.query("INSERT INTO chat_aula (contrato_id, remitente_id, mensaje, fecha, visto) VALUES (?, ?, ?, NOW(), 0)", [contratoId, usuarioId, mensaje]);
  return { ok: true };
}

async function registrarIntentoDlpAula(contratoId: number, usuarioId: number, mensaje: string, categoria: string, patronDescripcion: string): Promise<void> {
  try {
    await pool.query(
      "INSERT INTO dlp_intentos (conversacion_id, remitente_id, categoria, patron_matched, texto_intentado) VALUES (?, ?, ?, ?, ?)",
      [contratoId, usuarioId, categoria, patronDescripcion.slice(0, 200), mensaje],
    );
  } catch {
    // No debe bloquear la respuesta al usuario.
  }
}

let tablaTypingAulaVerificada = false;
// Puerto exacto de typing_set_mini_aula.php:76-81 — auto-migración perezosa, nunca asumir
// que la tabla ya existe (confirmado que NO existía en la BD local antes de este puerto).
async function asegurarTablaTypingAula(): Promise<void> {
  if (tablaTypingAulaVerificada) return;
  await pool.query(
    `CREATE TABLE IF NOT EXISTS chat_typing_aula (
      contrato_id INT NOT NULL,
      usuario_id INT NOT NULL,
      ultima_actividad DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (contrato_id, usuario_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  );
  tablaTypingAulaVerificada = true;
}

export async function setTypingAula(usuarioId: number, contratoId: number): Promise<boolean> {
  const [rows] = await pool.query<RowDataPacket[]>("SELECT id FROM contratos WHERE id = ? AND (comprador_id = ? OR vendedor_id = ?) LIMIT 1", [
    contratoId,
    usuarioId,
    usuarioId,
  ]);
  if (rows.length === 0) return false;

  await asegurarTablaTypingAula();
  await pool.query("INSERT INTO chat_typing_aula (contrato_id, usuario_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE ultima_actividad = CURRENT_TIMESTAMP", [
    contratoId,
    usuarioId,
  ]);
  return true;
}

// ============================================================================
// Estado combinado para badges (fusiona notificaciones_chat_mini_aula.php + count_files.php)
// ============================================================================

export async function getEstadoAula(contratoId: number, usuarioId: number): Promise<EstadoAula | null> {
  const contrato = await puedeAccederAula(contratoId, usuarioId, false);
  if (!contrato) return null;

  const [[chatRow]] = await pool.query<(RowDataPacket & { total: number })[][]>(
    "SELECT COUNT(*) AS total FROM chat_aula WHERE contrato_id = ? AND remitente_id != ? AND visto = 0",
    [contratoId, usuarioId],
  );
  const [[archivosRow]] = await pool.query<(RowDataPacket & { total: number })[][]>(
    "SELECT COUNT(*) AS total FROM contrato_archivos WHERE contrato_id = ?",
    [contratoId],
  );

  return {
    chatNoLeidos: (chatRow as unknown as { total: number }).total,
    totalArchivos: (archivosRow as unknown as { total: number }).total,
  };
}

// ============================================================================
// Materiales (app/entregas_servicio.php)
// ============================================================================

const EXTS_PERMITIDAS = new Set(["pdf", "doc", "docx", "xls", "xlsx", "ppt", "pptx", "jpg", "jpeg", "png", "zip", "rar", "7z", "txt", "mp4", "mov"]);
const PESO_MAX_BYTES = 50 * 1024 * 1024;

interface ArchivoRow extends RowDataPacket {
  id: number;
  usuario_id: number;
  nombre_original: string;
  ruta_archivo: string;
  peso_kb: number;
  fecha: string;
  subido_por_nombre: string | null;
}

export async function getArchivosContrato(contratoId: number, usuarioId: number, esAdmin: boolean): Promise<ArchivoContrato[] | null> {
  const contrato = await puedeAccederAula(contratoId, usuarioId, esAdmin);
  if (!contrato) return null;

  const [rows] = await pool.query<ArchivoRow[]>(
    `SELECT f.id, f.usuario_id, f.nombre_original, f.ruta_archivo, f.peso_kb, f.fecha, u.nombre AS subido_por_nombre
     FROM contrato_archivos f LEFT JOIN alumnos u ON f.usuario_id = u.id
     WHERE f.contrato_id = ? ORDER BY f.fecha DESC`,
    [contratoId],
  );

  return rows.map((r) => ({
    id: r.id,
    nombreOriginal: r.nombre_original,
    pesoKb: r.peso_kb,
    fecha: r.fecha,
    esMio: r.usuario_id === usuarioId,
    subidoPor: r.subido_por_nombre ?? "Usuario",
    url: `/api/me/aula/archivo/${r.id}`,
  }));
}

// Sniffing por magic bytes — igual criterio que publicar.controller.ts (equivalente a
// finfo_file() de PHP), pero el PHP real de esta pieza (entregas_servicio.php) NO lo tenía
// — solo validaba la extensión declarada. Se agrega acá porque construirlo bien no cuesta
// más que construirlo mal, mismo criterio de "corregir mientras se porta" del resto de la
// migración. Cubre los formatos con firma real conocida; 'txt' no tiene firma (cualquier
// contenido es válido como texto), así que no se sniffea — no hay nada real que verificar.
type ExtensionSoportada = "pdf" | "docx" | "xlsx" | "pptx" | "zip" | "doc" | "xls" | "ppt" | "jpg" | "png" | "mp4" | "mov" | "rar" | "7z";

function detectarTipoReal(buffer: Buffer): ExtensionSoportada | null {
  if (buffer.length < 8) return null;
  if (buffer.toString("ascii", 0, 4) === "%PDF") return "pdf";
  if (buffer[0] === 0xff && buffer[1] === 0xd8 && buffer[2] === 0xff) return "jpg";
  if (buffer[0] === 0x89 && buffer[1] === 0x50 && buffer[2] === 0x4e && buffer[3] === 0x47) return "png";
  // OLE Compound File — .doc/.xls/.ppt legado (mismo header para los 3, no se puede
  // distinguir el tipo exacto solo por bytes sin parsear el stream completo).
  if (buffer[0] === 0xd0 && buffer[1] === 0xcf && buffer[2] === 0x11 && buffer[3] === 0xe0) return "doc";
  // ZIP (también cubre docx/xlsx/pptx, que son contenedores ZIP — Office Open XML).
  if (buffer[0] === 0x50 && buffer[1] === 0x4b && (buffer[2] === 0x03 || buffer[2] === 0x05 || buffer[2] === 0x07)) return "zip";
  if (buffer.toString("ascii", 0, 4) === "Rar!") return "rar";
  if (buffer[0] === 0x37 && buffer[1] === 0x7a && buffer[2] === 0xbc && buffer[3] === 0xaf) return "7z";
  // MP4/MOV: ambos son contenedores ISO-BMFF — firma real está en el box 'ftyp', no al
  // byte 0 (los primeros 4 bytes son el tamaño del box, variable).
  if (buffer.length >= 12 && buffer.toString("ascii", 4, 8) === "ftyp") return "mp4";
  return null;
}

const GRUPO_TIPO_REAL: Record<string, ExtensionSoportada[]> = {
  pdf: ["pdf"],
  jpg: ["jpg"],
  jpeg: ["jpg"],
  png: ["png"],
  doc: ["doc"],
  xls: ["doc"],
  ppt: ["doc"],
  docx: ["zip"],
  xlsx: ["zip"],
  pptx: ["zip"],
  zip: ["zip"],
  rar: ["rar"],
  "7z": ["7z"],
  mp4: ["mp4"],
  mov: ["mp4"],
};

export type SubirArchivoError = { tipo: "sin_acceso" } | { tipo: "aula_cerrada" } | { tipo: "peso" } | { tipo: "extension" } | { tipo: "contenido" };

export async function subirArchivoContrato(
  usuarioId: number,
  contratoId: number,
  archivo: { originalname: string; buffer: Buffer; size: number; mimetype: string },
): Promise<{ ok: true; archivoId: number } | { ok: false; error: SubirArchivoError }> {
  const [contratoRows] = await pool.query<(RowDataPacket & { estado: string })[]>(
    "SELECT estado FROM contratos WHERE id = ? AND (comprador_id = ? OR vendedor_id = ?) LIMIT 1",
    [contratoId, usuarioId, usuarioId],
  );
  const contrato = contratoRows[0];
  if (!contrato) return { ok: false, error: { tipo: "sin_acceso" } };
  if (["cancelado", "finalizado"].includes(contrato.estado)) return { ok: false, error: { tipo: "aula_cerrada" } };

  if (archivo.size > PESO_MAX_BYTES || archivo.size <= 0) return { ok: false, error: { tipo: "peso" } };

  const ext = (archivo.originalname.split(".").pop() ?? "").toLowerCase();
  if (!EXTS_PERMITIDAS.has(ext)) return { ok: false, error: { tipo: "extension" } };

  if (ext !== "txt") {
    const tipoReal = detectarTipoReal(archivo.buffer);
    const gruposValidos = GRUPO_TIPO_REAL[ext] ?? [];
    if (!tipoReal || !gruposValidos.includes(tipoReal)) {
      return { ok: false, error: { tipo: "contenido" } };
    }
  }

  const nombreSeguro = `${contratoId}_${Date.now()}_${Math.random().toString(36).slice(2, 10)}.${ext}`;
  await fs.mkdir(env.materialesAulaDir, { recursive: true });
  await fs.writeFile(path.join(env.materialesAulaDir, nombreSeguro), archivo.buffer);

  const pesoKb = Math.round(archivo.size / 1024);
  const [ins] = await pool.query<ResultSetHeader>(
    "INSERT INTO contrato_archivos (contrato_id, usuario_id, nombre_original, ruta_archivo, tipo_mime, peso_kb, fecha) VALUES (?, ?, ?, ?, ?, ?, NOW())",
    [contratoId, usuarioId, archivo.originalname.slice(0, 255), nombreSeguro, archivo.mimetype.slice(0, 100), pesoKb],
  );
  return { ok: true, archivoId: ins.insertId };
}

export interface ArchivoContratoInfo {
  rutaRelativa: string;
  nombre: string;
  mime: string;
}

export async function getArchivoContratoInfo(archivoId: number, usuarioId: number, esAdmin: boolean): Promise<ArchivoContratoInfo | null> {
  const [rows] = await pool.query<(RowDataPacket & { contrato_id: number; nombre_original: string; ruta_archivo: string; tipo_mime: string | null })[]>(
    "SELECT contrato_id, nombre_original, ruta_archivo, tipo_mime FROM contrato_archivos WHERE id = ? LIMIT 1",
    [archivoId],
  );
  const row = rows[0];
  if (!row) return null;

  const contrato = await puedeAccederAula(row.contrato_id, usuarioId, esAdmin);
  if (!contrato) return null;

  return { rutaRelativa: row.ruta_archivo, nombre: row.nombre_original, mime: row.tipo_mime || "application/octet-stream" };
}
