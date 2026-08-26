import type { ResultSetHeader, RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";
import { getUsuarioConRol } from "../auth/auth.repository.js";
import type {
  CrearContratoInput,
  CuponRow,
  GenerarSlotExcepcionInput,
  ServicioCheckoutRow,
  SlotDisponible,
  SlotExcepcionRow,
} from "./contratos.types.js";

interface ServicioCheckoutDbRow extends ServicioCheckoutRow, RowDataPacket {}
interface CuponDbRow extends CuponRow, RowDataPacket {}
interface SlotExcepcionDbRow extends SlotExcepcionRow, RowDataPacket {}

// ============================================================================
// Utilidades de fecha/hora SIN pasar por Date de JS — puerto exacto de
// app/api/slots_disponibles.php:85-99. mysql2 devuelve DATETIME como objeto Date en la
// zona horaria del proceso Node, que puede no coincidir con la del servidor MySQL (mismo
// problema real ya encontrado y corregido en metricas.repository.ts con DATE_FORMAT) — acá
// se evita por completo: toda la aritmética de horarios es sobre strings 'YYYY-MM-DD
// HH:mm:ss' y minutos-desde-medianoche, nunca un objeto Date. 'YYYY-MM-DD HH:mm:ss' ordena
// igual como string que como fecha real, así que comparar con < / > es seguro.
// ============================================================================

function aMinutos(hhmm: string): number {
  const [h, m] = hhmm.split(":").map(Number);
  return (h ?? 0) * 60 + (m ?? 0);
}

function formatearHora(minutosDesdeMedianoche: number): string {
  const h = Math.floor(minutosDesdeMedianoche / 60) % 24;
  const m = minutosDesdeMedianoche % 60;
  return `${String(h).padStart(2, "0")}:${String(m).padStart(2, "0")}`;
}

function sumarMinutosAFechaHora(fechaHora: string, minutos: number): string {
  const [fecha, hora] = fechaHora.split(" ");
  const totalMin = aMinutos(hora!.slice(0, 5)) + minutos;
  return `${fecha} ${formatearHora(totalMin)}:00`;
}

// Puerto exacto de dias_semana_nubira()/el mapa de slots_disponibles.php:68-76 — nombre en
// español (con tildes) del día de semana de una fecha 'YYYY-MM-DD'. Construido con
// componentes numéricos explícitos (new Date(y, m-1, d)), NO parseando el string ISO
// completo: esa forma del constructor de Date usa SIEMPRE la hora local del proceso para
// el resultado, pero el día de la semana que arroja para un Y/M/D dado es el mismo sin
// importar en qué zona horaria corra el proceso — a diferencia de new Date('YYYY-MM-DD'),
// que sí puede correrse un día según la zona horaria de quien lo interpreta.
const DIAS_ES = ["Domingo", "Lunes", "Martes", "Miércoles", "Jueves", "Viernes", "Sábado"];
export function diaEsDeFecha(fechaYMD: string): string {
  const [y, m, d] = fechaYMD.split("-").map(Number);
  return DIAS_ES[new Date(y!, m! - 1, d!).getDay()]!;
}

// ============================================================================
// Checkout (GET contratar_servicio.php)
// ============================================================================

// Puerto exacto de contratar_servicio.php:53-58.
export async function getServicioCheckout(servicioId: number): Promise<ServicioCheckoutRow | null> {
  const [rows] = await pool.query<ServicioCheckoutDbRow[]>(
    `SELECT s.id, s.titulo, s.alumno_id, s.precio, s.precio_oferta, s.cupos_oferta, s.is_subvencionado,
            s.modalidad, s.categoria, s.imagen, s.imagen_banco_id, s.horarios_json,
            a.nombre as nombre_vendedor, a.institucion, bi.archivo AS banco_archivo
     FROM servicios s
     JOIN alumnos a ON s.alumno_id = a.id
     LEFT JOIN banco_imagenes bi ON bi.id = s.imagen_banco_id
     WHERE s.id = ? AND s.estado = 'aprobado' LIMIT 1`,
    [servicioId],
  );
  return rows[0] ?? null;
}

// Puerto exacto de contratar_servicio.php:103 (lectura simple, NO bloqueante — la
// validación real y con FOR UPDATE ocurre recién en crearContrato). Solo para la
// previsualización del descuento en la página de checkout.
export async function getCuponPorCodigo(codigo: string): Promise<CuponRow | null> {
  const [rows] = await pool.query<CuponDbRow[]>(
    "SELECT id, porcentaje_descuento, usos_actuales, usos_maximos, fecha_expiracion, servicio_id FROM cupones WHERE codigo = ? LIMIT 1",
    [codigo],
  );
  return rows[0] ?? null;
}

// ============================================================================
// Slots disponibles (puerto de app/api/slots_disponibles.php)
// ============================================================================

interface ServicioSlotsRow extends RowDataPacket {
  tutor_id: number;
  horarios_json: string | null;
  duracion_minutos: number;
}
interface OcupadoRow extends RowDataPacket {
  fecha_clase: string;
  duracion_minutos: number;
}
interface AhoraRow extends RowDataPacket {
  ahora: string;
}

export async function getSlotsDisponibles(
  servicioId: number,
  fecha: string,
): Promise<
  | { ok: true; duracion: number; slots: SlotDisponible[] }
  | { ok: false; motivo: "servicio_no_encontrado" | "sin_horarios" | "dia_no_disponible" | "sin_slots_validos" }
> {
  const [servRows] = await pool.query<ServicioSlotsRow[]>(
    "SELECT alumno_id AS tutor_id, horarios_json, duracion_minutos FROM servicios WHERE id = ? AND estado = 'aprobado' LIMIT 1",
    [servicioId],
  );
  const serv = servRows[0];
  if (!serv) return { ok: false, motivo: "servicio_no_encontrado" };

  const tutorId = serv.tutor_id;
  const duracion = serv.duracion_minutos || 60;
  let horarios: Record<string, string[]> = {};
  try {
    horarios = serv.horarios_json ? JSON.parse(serv.horarios_json) : {};
  } catch {
    horarios = {};
  }
  if (!horarios || Object.keys(horarios).length === 0) return { ok: false, motivo: "sin_horarios" };

  const diaEs = diaEsDeFecha(fecha);
  const bloques = horarios[diaEs];
  if (!bloques || bloques.length === 0) return { ok: false, motivo: "dia_no_disponible" };

  // Puerto exacto de slots_disponibles.php:85-99 — slots de 30min dentro de cada bloque,
  // pura aritmética de minutos (sin Date).
  const paso = 30;
  const candidatos: string[] = [];
  for (const bloque of bloques) {
    const m = /^(\d{2}:\d{2})\s*-\s*(\d{2}:\d{2})$/.exec(bloque);
    if (!m) continue;
    const inicioMin = aMinutos(m[1]!);
    const finMin = aMinutos(m[2]!);
    for (let t = inicioMin; t + duracion <= finMin; t += paso) {
      candidatos.push(`${fecha} ${formatearHora(t)}:00`);
    }
  }
  if (candidatos.length === 0) return { ok: false, motivo: "sin_slots_validos" };

  const iniDia = `${fecha} 00:00:00`;
  const finDia = `${fecha} 23:59:59`;

  // Puerto exacto de slots_disponibles.php:106-145 — reservas confirmadas + slots de
  // excepción pendientes/pagados, ambos cuentan como "ocupado" (anti-doble booking).
  const [reservas] = await pool.query<OcupadoRow[]>(
    `SELECT DATE_FORMAT(fecha_clase, '%Y-%m-%d %H:%i:%s') AS fecha_clase, duracion_minutos
     FROM reservas_slots
     WHERE tutor_id = ? AND estado IN ('reservado','en_curso') AND fecha_clase BETWEEN ? AND ?`,
    [tutorId, iniDia, finDia],
  );
  const [excepciones] = await pool.query<OcupadoRow[]>(
    `SELECT DATE_FORMAT(se.fecha_clase, '%Y-%m-%d %H:%i:%s') AS fecha_clase, s.duracion_minutos
     FROM slots_excepcion se
     JOIN servicios s ON se.servicio_id = s.id
     WHERE se.tutor_id = ? AND se.estado IN ('pendiente','en_proceso') AND se.expira_en > NOW()
       AND se.fecha_clase BETWEEN ? AND ?`,
    [tutorId, iniDia, finDia],
  );
  const ocupados = [...reservas, ...excepciones].map((r) => ({
    ini: r.fecha_clase,
    fin: sumarMinutosAFechaHora(r.fecha_clase, r.duracion_minutos),
  }));

  // "Ahora + 30min" calculado por MySQL (no por Date de JS) — mismo string 'YYYY-MM-DD
  // HH:mm:ss' comparable de forma segura.
  const [[ahoraRow]] = await pool.query<AhoraRow[][]>(
    "SELECT DATE_FORMAT(DATE_ADD(NOW(), INTERVAL 30 MINUTE), '%Y-%m-%d %H:%i:%s') AS ahora",
  );
  const ahoraBuffer = (ahoraRow as unknown as AhoraRow).ahora;

  const slots: SlotDisponible[] = candidatos.map((slotIni) => {
    const slotFin = sumarMinutosAFechaHora(slotIni, duracion);
    let disponible = true;
    let motivo: SlotDisponible["motivo"] = null;

    if (slotIni < ahoraBuffer) {
      disponible = false;
      motivo = "pasado";
    }
    if (disponible) {
      for (const oc of ocupados) {
        if (slotIni < oc.fin && slotFin > oc.ini) {
          disponible = false;
          motivo = "ocupado";
          break;
        }
      }
    }
    return { datetime: slotIni, hora: slotIni.slice(11, 16), disponible, motivo };
  });

  return { ok: true, duracion, slots };
}

// ============================================================================
// Crear contrato (puerto de app/crear_contrato.php)
// ============================================================================

interface ServicioLockRow extends RowDataPacket {
  titulo: string;
  precio: number;
  precio_oferta: number | null;
  cupos_oferta: number | null;
  is_subvencionado: number;
  duracion_minutos: number | null;
  horarios_json: string | null;
}
interface CuponLockRow extends RowDataPacket {
  id: number;
  porcentaje_descuento: number;
  usos_actuales: number;
  usos_maximos: number;
  fecha_expiracion: string | null;
  servicio_id: number | null;
}

export type CrearContratoError =
  | { tipo: "servicio_no_encontrado" }
  | { tipo: "cupon_invalido"; mensaje: string }
  | { tipo: "precio_cambio" }
  | { tipo: "sin_reservas_online" }
  | { tipo: "dia_no_disponible" }
  | { tipo: "horario_ocupado" }
  | { tipo: "error_db" };

// Puerto EXACTO de crear_contrato.php:90-296 (sin la sección 5 de correos/push — ver nota
// de alcance en contratos.types.ts). Misma secuencia de bloqueos pesimistas: servicio
// FOR UPDATE -> cupón FOR UPDATE -> solape de horario FOR UPDATE, todo en una transacción.
export async function crearContrato(
  compradorId: number,
  input: CrearContratoInput,
): Promise<{ ok: true; contratoId: number; montoFinal: number } | { ok: false; error: CrearContratoError }> {
  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();

    const [servRows] = await conn.query<ServicioLockRow[]>(
      "SELECT titulo, precio, precio_oferta, cupos_oferta, is_subvencionado, duracion_minutos, horarios_json FROM servicios WHERE id = ? LIMIT 1 FOR UPDATE",
      [input.servicioId],
    );
    const serv = servRows[0];
    if (!serv) {
      await conn.rollback();
      return { ok: false, error: { tipo: "servicio_no_encontrado" } };
    }

    // DECIMAL -> string sin decimalNumbers:true en el pool (mismo cast que el resto del
    // codebase); sin esto, `montoFinal + montoSubsidio` más abajo concatena en vez de sumar.
    const precioReal = Number(serv.precio);
    const precioOfertaReal = serv.precio_oferta === null ? null : Number(serv.precio_oferta);
    const esOfertaDb = serv.is_subvencionado === 1 && (serv.cupos_oferta ?? 0) > 0;

    const [[comisionRow]] = await conn.query<(RowDataPacket & { valor: string })[][]>(
      "SELECT valor FROM configuracion WHERE clave = 'comision_plataforma'",
    );
    const porcentajeComision = comisionRow ? parseInt((comisionRow as unknown as { valor: string }).valor, 10) : 0;

    let montoFinal: number;
    let montoSubsidio = 0;
    if (esOfertaDb) {
      montoFinal = precioOfertaReal ?? 0;
      montoSubsidio += precioReal - montoFinal;
    } else {
      montoFinal = precioReal;
    }

    let cuponId: number | null = null;
    if (input.codigoBeca) {
      const [cupRows] = await conn.query<CuponLockRow[]>(
        "SELECT id, porcentaje_descuento, usos_actuales, usos_maximos, fecha_expiracion, servicio_id FROM cupones WHERE codigo = ? LIMIT 1 FOR UPDATE",
        [input.codigoBeca],
      );
      const c = cupRows[0];
      if (!c) {
        await conn.rollback();
        return { ok: false, error: { tipo: "cupon_invalido", mensaje: "Código de beca inválido o inexistente." } };
      }
      if (c.usos_maximos > 0 && c.usos_actuales >= c.usos_maximos) {
        await conn.rollback();
        return { ok: false, error: { tipo: "cupon_invalido", mensaje: "La beca ingresada ya alcanzó su límite máximo de usos." } };
      }
      if (c.fecha_expiracion) {
        const [[hoyRow]] = await conn.query<(RowDataPacket & { hoy: string })[][]>(
          "SELECT DATE_FORMAT(CONVERT_TZ(NOW(), @@session.time_zone, 'America/Santiago'), '%Y-%m-%d') AS hoy",
        );
        const hoy = (hoyRow as unknown as { hoy: string }).hoy;
        const expira = c.fecha_expiracion.slice(0, 10);
        if (hoy > expira) {
          await conn.rollback();
          return { ok: false, error: { tipo: "cupon_invalido", mensaje: "La beca ingresada ha expirado." } };
        }
      }
      const esGlobal = c.servicio_id === null || c.servicio_id === 0;
      if (!esGlobal && c.servicio_id !== input.servicioId) {
        await conn.rollback();
        return { ok: false, error: { tipo: "cupon_invalido", mensaje: "La beca no aplica para este servicio." } };
      }

      cuponId = c.id;
      const descuentoAplicado = Math.trunc((montoFinal * c.porcentaje_descuento) / 100);
      montoFinal = Math.max(0, montoFinal - descuentoAplicado);
      montoSubsidio += descuentoAplicado;

      await conn.query("UPDATE cupones SET usos_actuales = usos_actuales + 1 WHERE id = ?", [cuponId]);
    }

    if (input.precioEsperadoUsuario < montoFinal) {
      await conn.rollback();
      return { ok: false, error: { tipo: "precio_cambio" } };
    }

    const valorTotalClase = montoFinal + montoSubsidio;
    const montoComision = Math.trunc((valorTotalClase * porcentajeComision) / 100);
    const estado = montoFinal === 0 ? "en_progreso" : "pendiente_pago";
    const montoAceptado = 0;

    const duracionMinutos = serv.duracion_minutos || 60;
    const slotFin = sumarMinutosAFechaHora(input.fechaClase, duracionMinutos);

    if (!serv.horarios_json) {
      await conn.rollback();
      return { ok: false, error: { tipo: "sin_reservas_online" } };
    }
    let horariosValidar: Record<string, string[]> = {};
    try {
      horariosValidar = JSON.parse(serv.horarios_json);
    } catch {
      horariosValidar = {};
    }
    const diaSolicitado = diaEsDeFecha(input.fechaClase.slice(0, 10));
    if (!horariosValidar[diaSolicitado] || horariosValidar[diaSolicitado]!.length === 0) {
      await conn.rollback();
      return { ok: false, error: { tipo: "dia_no_disponible" } };
    }

    const [solapeRows] = await conn.query<RowDataPacket[]>(
      `SELECT id FROM reservas_slots
       WHERE tutor_id = ? AND estado IN ('reservado','en_curso')
         AND fecha_clase < ? AND DATE_ADD(fecha_clase, INTERVAL duracion_minutos MINUTE) > ?
       LIMIT 1 FOR UPDATE`,
      [input.vendedorId, slotFin, input.fechaClase],
    );
    if (solapeRows.length > 0) {
      await conn.rollback();
      return { ok: false, error: { tipo: "horario_ocupado" } };
    }

    const [insContrato] = await conn.query<ResultSetHeader>(
      `INSERT INTO contratos (servicio_id, comprador_id, vendedor_id, monto, monto_subsidio, monto_comision, monto_aceptado, fecha_estimada, notas, estado, fecha_creacion)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())`,
      [input.servicioId, compradorId, input.vendedorId, montoFinal, montoSubsidio, montoComision, montoAceptado, input.fechaClase, input.notas, estado],
    );
    const contratoId = insContrato.insertId;

    await conn.query(
      "INSERT INTO reservas_slots (contrato_id, servicio_id, tutor_id, alumno_id, fecha_clase, duracion_minutos, estado) VALUES (?, ?, ?, ?, ?, ?, 'reservado')",
      [contratoId, input.servicioId, input.vendedorId, compradorId, input.fechaClase, duracionMinutos],
    );

    const [chatRows] = await conn.query<(RowDataPacket & { id: number })[]>(
      `SELECT id FROM conversaciones WHERE servicio_id=? AND ((comprador_id=? AND vendedor_id=?) OR (comprador_id=? AND vendedor_id=?)) LIMIT 1`,
      [input.servicioId, compradorId, input.vendedorId, input.vendedorId, compradorId],
    );
    let chatId: number;
    if (chatRows.length === 0) {
      const [insChat] = await conn.query<ResultSetHeader>(
        "INSERT INTO conversaciones (servicio_id, comprador_id, vendedor_id, contrato_id, creado_en, estado) VALUES (?, ?, ?, ?, NOW(), 'activa')",
        [input.servicioId, compradorId, input.vendedorId, contratoId],
      );
      chatId = insChat.insertId;
    } else {
      chatId = chatRows[0]!.id;
      await conn.query("UPDATE conversaciones SET contrato_id = ? WHERE id = ?", [contratoId, chatId]);
    }
    await conn.query("UPDATE contratos SET conversacion_id = ? WHERE id = ?", [chatId, contratoId]);

    const msg = `Hola, he solicitado este servicio (${montoFinal === 0 ? "Gratis" : `$${montoFinal.toLocaleString("es-CL")}`}).${input.notas ? `\n\nNota: ${input.notas}` : ""}`;
    await conn.query("INSERT INTO mensajes (conversacion_id, contrato_id, remitente_id, mensaje, enviado_en) VALUES (?, ?, ?, ?, NOW())", [
      chatId,
      contratoId,
      compradorId,
      msg,
    ]);

    await conn.commit();
    return { ok: true, contratoId, montoFinal };
  } catch (err) {
    await conn.rollback();
    throw err;
  } finally {
    conn.release();
  }
}

// ============================================================================
// Slots de excepción — reserva propuesta por chat (puerto de generar_slot_excepcion.php /
// pagar_slot_excepcion.php)
// ============================================================================

interface ConversacionParaExcepcionRow extends RowDataPacket {
  comprador_id: number;
  vendedor_id: number;
  servicio_id: number;
  nombre_vendedor: string;
}
interface ServicioParaExcepcionRow extends RowDataPacket {
  precio: number;
  precio_oferta: number | null;
  is_subvencionado: number;
  cupos_oferta: number | null;
  duracion_minutos: number | null;
}

export type GenerarSlotExcepcionError =
  | { tipo: "hora_muy_temprano" }
  | { tipo: "muy_pronto" }
  | { tipo: "muy_lejos" }
  | { tipo: "conversacion_no_encontrada" }
  | { tipo: "no_autorizado" }
  | { tipo: "servicio_no_disponible" };

// Puerto exacto de generar_slot_excepcion.php:57-228 — mismas validaciones de rango horario
// (>=07:00, entre 1h y 30 días de anticipación), mismo cálculo de monto desde el precio
// PUBLICADO del servicio (esta vía NO acepta un monto negociado — el tutor propone fecha,
// no precio, ver el hallazgo #5 de la auditoría: monto_propuesto/monto_aceptado son
// código muerto, no se portan).
export async function generarSlotExcepcion(
  tutorId: number,
  input: GenerarSlotExcepcionInput,
): Promise<{ ok: true } | { ok: false; error: GenerarSlotExcepcionError }> {
  const fechaClaseSql = `${input.fecha} ${input.hora}:00`;
  const horaMin = aMinutos(input.hora);
  if (horaMin < aMinutos("07:00")) return { ok: false, error: { tipo: "hora_muy_temprano" } };

  const [[ventanaRow]] = await pool.query<(RowDataPacket & { min_permitido: string; max_permitido: string })[][]>(
    `SELECT DATE_FORMAT(DATE_ADD(NOW(), INTERVAL 1 HOUR), '%Y-%m-%d %H:%i:%s') AS min_permitido,
            DATE_FORMAT(DATE_ADD(NOW(), INTERVAL 30 DAY), '%Y-%m-%d %H:%i:%s') AS max_permitido`,
  );
  const { min_permitido, max_permitido } = ventanaRow as unknown as { min_permitido: string; max_permitido: string };
  if (fechaClaseSql < min_permitido) return { ok: false, error: { tipo: "muy_pronto" } };
  if (fechaClaseSql > max_permitido) return { ok: false, error: { tipo: "muy_lejos" } };

  const [convRows] = await pool.query<ConversacionParaExcepcionRow[]>(
    `SELECT c.comprador_id, c.vendedor_id, c.servicio_id, v.nombre AS nombre_vendedor
     FROM conversaciones c JOIN alumnos v ON c.vendedor_id = v.id WHERE c.id = ? LIMIT 1`,
    [input.conversacionId],
  );
  const conv = convRows[0];
  if (!conv) return { ok: false, error: { tipo: "conversacion_no_encontrada" } };
  if (conv.vendedor_id !== tutorId) return { ok: false, error: { tipo: "no_autorizado" } };

  const [servRows] = await pool.query<ServicioParaExcepcionRow[]>(
    "SELECT precio, precio_oferta, is_subvencionado, cupos_oferta, duracion_minutos FROM servicios WHERE id = ? AND estado = 'aprobado' LIMIT 1",
    [conv.servicio_id],
  );
  const serv = servRows[0];
  if (!serv) return { ok: false, error: { tipo: "servicio_no_disponible" } };

  const esOferta = serv.is_subvencionado === 1 && (serv.cupos_oferta ?? 0) > 0;
  // Mismo cast DECIMAL->number que crearContrato — ver nota ahí.
  const montoFinal = esOferta ? Number(serv.precio_oferta ?? 0) : Number(serv.precio);

  const token = (await import("node:crypto")).randomBytes(32).toString("hex");

  await pool.query(
    `INSERT INTO slots_excepcion (token, servicio_id, tutor_id, alumno_id, conversacion_id, fecha_clase, monto, expira_en)
     VALUES (?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 DAY))`,
    [token, conv.servicio_id, tutorId, conv.comprador_id, input.conversacionId, fechaClaseSql, montoFinal],
  );

  const urlPago = `/app/pagar_slot_excepcion.php?token=${token}`;
  const cardHtml = `Reserva propuesta por ${conv.nombre_vendedor.split(" ")[0]} — ${urlPago}`;
  await pool.query("INSERT INTO mensajes (conversacion_id, remitente_id, mensaje, enviado_en, leido) VALUES (?, 0, ?, NOW(), 0)", [
    input.conversacionId,
    cardHtml,
  ]);
  await pool.query(
    "UPDATE conversaciones SET ultima_interaccion = NOW(), oculto_comprador = 0, oculto_vendedor = 0 WHERE id = ?",
    [input.conversacionId],
  );

  return { ok: true };
}

// Puerto exacto de pagar_slot_excepcion.php:240-331 (rama GET) — validación + datos de
// display, sin mutar nada.
export async function getSlotExcepcionPorToken(token: string): Promise<SlotExcepcionRow | null> {
  const [rows] = await pool.query<SlotExcepcionDbRow[]>(
    `SELECT se.id, se.servicio_id, se.tutor_id, se.alumno_id, se.conversacion_id,
            DATE_FORMAT(se.fecha_clase, '%Y-%m-%d %H:%i:%s') AS fecha_clase, se.monto,
            DATE_FORMAT(se.expira_en, '%Y-%m-%d %H:%i:%s') AS expira_en, se.estado, se.contrato_id,
            s.titulo AS servicio_titulo, s.duracion_minutos, s.estado AS servicio_estado,
            a.nombre AS tutor_nombre
     FROM slots_excepcion se
     JOIN servicios s ON se.servicio_id = s.id
     JOIN alumnos a ON se.tutor_id = a.id
     WHERE se.token = ? LIMIT 1`,
    [token],
  );
  return rows[0] ?? null;
}

export type PagarSlotExcepcionError =
  | { tipo: "token_invalido" }
  | { tipo: "ya_pagado" }
  | { tipo: "no_disponible" }
  | { tipo: "expirado" }
  | { tipo: "sin_acceso" }
  | { tipo: "servicio_no_disponible" }
  | { tipo: "horario_ocupado" };

// Puerto exacto de pagar_slot_excepcion.php:67-227 (rama POST) — mismo bloqueo pesimista
// FOR UPDATE sobre el slot, misma bifurcación reusar-contrato-existente vs. crear uno
// nuevo, mismo chequeo de solape FOR UPDATE que crearContrato. NO escribe
// slots_excepcion.estado='pagado' (ver nota de alcance: ese valor no es parte del ENUM
// real de la columna — confirmado contra la BD, sql_mode sin STRICT — el PHP real lo
// escribe pero MySQL lo silencia a '' sin avisar; el guard que de verdad funciona hoy es
// slots_excepcion.contrato_id + contratos.estado, que es lo que esta función deja armado).
export async function pagarSlotExcepcion(
  usuarioId: number,
  token: string,
): Promise<{ ok: true; contratoId: number } | { ok: false; error: PagarSlotExcepcionError }> {
  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();

    const [slotRows] = await conn.query<SlotExcepcionDbRow[]>(
      `SELECT se.id, se.servicio_id, se.tutor_id, se.alumno_id, se.conversacion_id,
              DATE_FORMAT(se.fecha_clase, '%Y-%m-%d %H:%i:%s') AS fecha_clase, se.monto,
              DATE_FORMAT(se.expira_en, '%Y-%m-%d %H:%i:%s') AS expira_en, se.estado, se.contrato_id,
              s.titulo AS servicio_titulo, s.duracion_minutos, s.estado AS servicio_estado
       FROM slots_excepcion se JOIN servicios s ON se.servicio_id = s.id
       WHERE se.token = ? LIMIT 1 FOR UPDATE`,
      [token],
    );
    const slot = slotRows[0];
    if (!slot) {
      await conn.rollback();
      return { ok: false, error: { tipo: "token_invalido" } };
    }
    if (slot.estado !== "pendiente" && slot.estado !== "en_proceso") {
      await conn.rollback();
      return { ok: false, error: { tipo: "no_disponible" } };
    }
    const [[ahoraRow]] = await conn.query<(RowDataPacket & { ahora: string })[][]>(
      "SELECT DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s') AS ahora",
    );
    if (slot.expira_en < (ahoraRow as unknown as { ahora: string }).ahora) {
      await conn.rollback();
      return { ok: false, error: { tipo: "expirado" } };
    }
    if (slot.alumno_id !== usuarioId) {
      await conn.rollback();
      return { ok: false, error: { tipo: "sin_acceso" } };
    }
    if (slot.servicio_estado !== "aprobado") {
      await conn.rollback();
      return { ok: false, error: { tipo: "servicio_no_disponible" } };
    }

    let contratoId: number;

    if (slot.contrato_id !== null) {
      const [[estadoRow]] = await conn.query<(RowDataPacket & { estado: string })[][]>(
        "SELECT estado FROM contratos WHERE id = ? LIMIT 1",
        [slot.contrato_id],
      );
      const estadoC = (estadoRow as unknown as { estado: string } | undefined)?.estado;
      if (estadoC === "pendiente_pago") {
        contratoId = slot.contrato_id;
      } else {
        await conn.rollback();
        return { ok: false, error: { tipo: "ya_pagado" } };
      }
    } else {
      const duracion = slot.duracion_minutos || 60;
      const slotFin = sumarMinutosAFechaHora(slot.fecha_clase, duracion);
      const [solapeRows] = await conn.query<RowDataPacket[]>(
        `SELECT id FROM reservas_slots
         WHERE tutor_id = ? AND estado IN ('reservado','en_curso')
           AND fecha_clase < ? AND DATE_ADD(fecha_clase, INTERVAL duracion_minutos MINUTE) > ?
         LIMIT 1 FOR UPDATE`,
        [slot.tutor_id, slotFin, slot.fecha_clase],
      );
      if (solapeRows.length > 0) {
        await conn.rollback();
        return { ok: false, error: { tipo: "horario_ocupado" } };
      }

      const [[comisionRow]] = await conn.query<(RowDataPacket & { valor: string })[][]>(
        "SELECT valor FROM configuracion WHERE clave = 'comision_plataforma'",
      );
      const porcentajeComision = comisionRow ? parseInt((comisionRow as unknown as { valor: string }).valor, 10) : 0;
      const montoSlot = Number(slot.monto); // DECIMAL -> string, mismo cast que crearContrato
      const montoComision = Math.trunc((montoSlot * porcentajeComision) / 100);

      const [insContrato] = await conn.query<ResultSetHeader>(
        `INSERT INTO contratos (servicio_id, comprador_id, vendedor_id, monto, monto_subsidio, monto_comision, monto_aceptado, fecha_estimada, notas, estado, fecha_creacion)
         VALUES (?, ?, ?, ?, 0, ?, 0, ?, '', 'pendiente_pago', NOW())`,
        [slot.servicio_id, usuarioId, slot.tutor_id, montoSlot, montoComision, slot.fecha_clase],
      );
      contratoId = insContrato.insertId;

      await conn.query(
        "INSERT INTO reservas_slots (contrato_id, servicio_id, tutor_id, alumno_id, fecha_clase, duracion_minutos, estado) VALUES (?, ?, ?, ?, ?, ?, 'reservado')",
        [contratoId, slot.servicio_id, slot.tutor_id, usuarioId, slot.fecha_clase, duracion],
      );
      await conn.query("UPDATE conversaciones SET contrato_id = ? WHERE id = ?", [contratoId, slot.conversacion_id]);
      await conn.query("UPDATE contratos SET conversacion_id = ? WHERE id = ?", [slot.conversacion_id, contratoId]);
      await conn.query("UPDATE slots_excepcion SET contrato_id = ? WHERE id = ?", [contratoId, slot.id]);
    }

    await conn.commit();
    return { ok: true, contratoId };
  } catch (err) {
    await conn.rollback();
    throw err;
  } finally {
    conn.release();
  }
}

// ============================================================================
// Ciclo de vida (finalizar/confirmar-cierre — el resto, cancelar/liberar/revertir, es
// admin-only y vive en adminContratos)
// ============================================================================
//
// [26/08/2026] Reemplaza un puerto anterior de app/finalizar_contrato.php
// (finalizarContratoVendedor) que resultó ser código muerto: cero referencias en todo el
// repo (ni <form>, ni fetch, ni cron) y escribía un valor de estado ('entregado') que ni
// siquiera existe en el ENUM real. El mecanismo REAL y activo vive dentro de
// app/mini_aula.php (la sala de clase) vía 2 endpoints distintos, confirmados por sus
// propios callers reales: confirmarFinalizacion() (botón "Finalizar y Pagar", comprador) ->
// app/finalizar_servicio.php, y confirmarVendedor() (botón "Confirmar Cierre", vendedor) ->
// app/finalizar_servicio_tutor.php. Ambos escriben `estado='liberado'` (valor ENUM real —
// los propios archivos PHP traen el comentario "[NUBIRA 2.0] Estado unificado a
// 'liberado'", confirmando que reemplazaron un valor viejo). mini_aula.php en sí (video
// Daily.co, chat, entregas de archivo) NO se porta en este checkpoint — el puente a PHP
// real que ya usan mis_contratos/admin_contratos ("Ir al Aula Virtual") sigue cubriendo la
// UI real de estos 2 botones tal cual hoy. Estas 2 funciones quedan listas para cuando esa
// página se porte en una sesión futura.

export type FinalizarServicioError = { tipo: "no_encontrado" } | { tipo: "sin_permiso" } | { tipo: "debe_esperar_comprador" };

interface ContratoParaFinalizarRow extends RowDataPacket {
  id: number;
  estado: string;
  comprador_id: number;
}

// Puerto exacto de finalizar_servicio.php:18-53. El comprador (o admin) libera el pago en
// UN solo paso — no hay estado intermedio de "esperando aprobación": estado pasa
// directo a 'liberado' + finalizado_comprador=1. Si el contrato ya no está
// activo/en_progreso (recarga tardía, doble click), el PHP real igual redirige a
// evaluar_servicio.php sin volver a escribir nada — acá eso se refleja devolviendo
// ok:true sin UPDATE (idempotente).
export async function finalizarServicioComprador(
  contratoId: number,
  usuarioId: number,
): Promise<{ ok: true } | { ok: false; error: FinalizarServicioError }> {
  const [rows] = await pool.query<ContratoParaFinalizarRow[]>("SELECT id, estado, comprador_id FROM contratos WHERE id = ? LIMIT 1", [contratoId]);
  const contrato = rows[0];
  if (!contrato) return { ok: false, error: { tipo: "no_encontrado" } };

  const usuario = await getUsuarioConRol(usuarioId);
  const esAdmin = usuario?.rol === "admin";
  if (contrato.comprador_id !== usuarioId && !esAdmin) {
    return { ok: false, error: { tipo: "sin_permiso" } };
  }

  if (contrato.estado === "activo" || contrato.estado === "en_progreso") {
    await pool.query<ResultSetHeader>("UPDATE contratos SET estado = 'liberado', finalizado_comprador = 1 WHERE id = ?", [contratoId]);
  }
  return { ok: true };
}

interface ContratoParaConfirmarCierreRow extends RowDataPacket {
  id: number;
  estado: string;
  vendedor_id: number;
  finalizado_comprador: number;
}

// Puerto exacto de finalizar_servicio_tutor.php:24-57. Requiere finalizado_comprador ya en
// 1 (salvo admin forzando) — el vendedor NO puede cerrar antes que el comprador libere.
// También escribe estado='liberado' (ya lo estaba) + finalizado_vendedor=1: es un cierre
// de cortesía/registro, no lo que gatilla el pago.
export async function confirmarCierreVendedor(
  contratoId: number,
  usuarioId: number,
): Promise<{ ok: true } | { ok: false; error: FinalizarServicioError }> {
  const [rows] = await pool.query<ContratoParaConfirmarCierreRow[]>(
    "SELECT id, estado, vendedor_id, finalizado_comprador FROM contratos WHERE id = ? LIMIT 1",
    [contratoId],
  );
  const contrato = rows[0];
  if (!contrato) return { ok: false, error: { tipo: "no_encontrado" } };

  const usuario = await getUsuarioConRol(usuarioId);
  const esAdmin = usuario?.rol === "admin";
  if (contrato.vendedor_id !== usuarioId && !esAdmin) {
    return { ok: false, error: { tipo: "sin_permiso" } };
  }
  if (!contrato.finalizado_comprador && !esAdmin) {
    return { ok: false, error: { tipo: "debe_esperar_comprador" } };
  }

  await pool.query<ResultSetHeader>("UPDATE contratos SET estado = 'liberado', finalizado_vendedor = 1 WHERE id = ?", [contratoId]);
  return { ok: true };
}
