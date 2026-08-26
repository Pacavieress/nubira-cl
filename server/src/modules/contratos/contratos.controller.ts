import type { Request, Response } from "express";
import { mapServicioCheckout, mapSlotExcepcionPublico, validarCuponPreview } from "./contratos.mapper.js";
import {
  confirmarCierreVendedor,
  crearContrato,
  finalizarServicioComprador,
  generarSlotExcepcion,
  getCuponPorCodigo,
  getServicioCheckout,
  getSlotExcepcionPorToken,
  getSlotsDisponibles,
  pagarSlotExcepcion,
} from "./contratos.repository.js";
import type { CrearContratoInput, GenerarSlotExcepcionInput } from "./contratos.types.js";

// Puerto de contratar_servicio.php:52-151 (GET) — servicio + oferta + previsualización de
// cupón (si viene ?codigoBeca=). NO incluye horarios/disponibilidad — eso es el endpoint
// de slots-disponibles, separado, igual que en el PHP real (2 requests distintos).
export async function getCheckout(req: Request, res: Response): Promise<void> {
  const usuarioId = req.usuarioId as number;
  const servicioId = Number(req.params.servicioId);
  if (!Number.isInteger(servicioId) || servicioId <= 0) {
    res.status(400).json({ error: "servicio_invalido" });
    return;
  }

  const row = await getServicioCheckout(servicioId);
  if (!row) {
    res.status(404).json({ error: "servicio_no_encontrado", mensaje: "Este servicio ya no está disponible." });
    return;
  }
  if (row.alumno_id === usuarioId) {
    res.status(403).json({ error: "propio_servicio", mensaje: "No puedes contratar tu propio servicio." });
    return;
  }

  const servicio = mapServicioCheckout(row);
  const codigoBeca = typeof req.query.codigoBeca === "string" ? req.query.codigoBeca.toUpperCase().trim() : "";

  let cupon = null;
  if (codigoBeca && servicio.montoInicial > 0) {
    const cuponRow = await getCuponPorCodigo(codigoBeca);
    cupon = validarCuponPreview(cuponRow, servicioId, servicio.montoInicial);
  }

  res.status(200).json({ servicio, cupon });
}

// Puerto de app/api/slots_disponibles.php.
export async function getSlots(req: Request, res: Response): Promise<void> {
  const servicioId = Number(req.query.servicioId);
  const fecha = typeof req.query.fecha === "string" ? req.query.fecha : "";

  if (!Number.isInteger(servicioId) || servicioId <= 0 || !/^\d{4}-\d{2}-\d{2}$/.test(fecha)) {
    res.status(400).json({ error: "parametros_invalidos" });
    return;
  }

  const hoy = new Date().toLocaleDateString("en-CA", { timeZone: "America/Santiago" });
  if (fecha < hoy) {
    res.status(200).json({ fecha, slots: [], motivo: "fecha_pasada" });
    return;
  }

  const resultado = await getSlotsDisponibles(servicioId, fecha);
  if (!resultado.ok) {
    res.status(200).json({ fecha, slots: [], motivo: resultado.motivo });
    return;
  }
  res.status(200).json({ fecha, duracion: resultado.duracion, slots: resultado.slots });
}

const MENSAJES_ERROR_CONTRATO: Record<string, string> = {
  servicio_no_encontrado: "Servicio no encontrado.",
  precio_cambio: "El precio del servicio cambió o la beca caducó mientras estabas en pantalla. Por favor, vuelve a intentarlo.",
  sin_reservas_online: "Este servicio no acepta reservas en línea. Coordina con el tutor por chat.",
  dia_no_disponible: "El tutor no tiene disponibilidad publicada para ese día.",
  horario_ocupado: "Lo sentimos, alguien acaba de reservar esa hora. Por favor elige otra.",
  error_db: "No se pudo crear el contrato.",
};

// Puerto de crear_contrato.php:44-372 — SIN la sección 5 (correos/push), diferida a
// propósito (ver contratos.types.ts). Mismas validaciones/mensajes, mismo bloqueo
// pesimista en cascada (servicio -> cupón -> solape de horario).
export async function postCrearContrato(req: Request, res: Response): Promise<void> {
  const compradorId = req.usuarioId as number;
  const body = req.body as Record<string, unknown>;

  const servicioId = Number(body.servicioId);
  const vendedorId = Number(body.vendedorId);
  const fechaClaseInput = typeof body.fechaClase === "string" ? body.fechaClase.trim() : "";
  const notas = typeof body.notas === "string" ? body.notas.trim() : "";
  const codigoBeca = typeof body.codigoBeca === "string" ? body.codigoBeca.toUpperCase().trim() : "";
  const precioEsperadoUsuario = Number(body.monto ?? body.precioOriginal ?? 0);

  if (!/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(fechaClaseInput)) {
    res.status(400).json({ error: "fecha_invalida", mensaje: "Debes seleccionar una fecha y hora para tu clase." });
    return;
  }
  // Comparación de string 'YYYY-MM-DD HH:mm:ss' vs "ahora" de Chile — mismo criterio que
  // el resto de este módulo (evitar Date de JS para comparar contra la hora del servidor).
  const ahoraChile = new Date()
    .toLocaleString("sv-SE", { timeZone: "America/Santiago" })
    .replace(" ", " ");
  if (fechaClaseInput < ahoraChile) {
    res.status(400).json({ error: "fecha_invalida", mensaje: "La fecha seleccionada no es válida o ya pasó." });
    return;
  }

  if (!Number.isInteger(servicioId) || servicioId <= 0 || !Number.isInteger(vendedorId) || vendedorId <= 0) {
    res.status(400).json({ error: "datos_faltantes", mensaje: "Faltan datos para procesar la solicitud." });
    return;
  }
  if (compradorId === vendedorId) {
    res.status(403).json({ error: "propio_servicio", mensaje: "No puedes contratar tu propio servicio." });
    return;
  }

  const input: CrearContratoInput = {
    servicioId,
    vendedorId,
    fechaClase: fechaClaseInput,
    notas,
    codigoBeca: codigoBeca || null,
    precioEsperadoUsuario: Number.isFinite(precioEsperadoUsuario) ? precioEsperadoUsuario : 0,
  };

  const resultado = await crearContrato(compradorId, input);
  if (!resultado.ok) {
    const mensaje =
      resultado.error.tipo === "cupon_invalido" ? resultado.error.mensaje : MENSAJES_ERROR_CONTRATO[resultado.error.tipo] ?? "No se pudo crear el contrato.";
    res.status(400).json({ error: resultado.error.tipo, mensaje });
    return;
  }

  res.status(200).json({ ok: true, contratoId: resultado.contratoId, montoFinal: resultado.montoFinal });
}

const MENSAJES_ERROR_FINALIZAR: Record<string, string> = {
  no_encontrado: "Contrato no encontrado.",
  sin_permiso: "No tienes permiso para esta acción.",
  debe_esperar_comprador: "Debes esperar a que el alumno libere el pago primero.",
};

// Puerto de finalizar_servicio.php — botón "Finalizar y Pagar" del comprador en
// mini_aula.php (ver nota de reemplazo en contratos.repository.ts).
export async function postFinalizarContrato(req: Request, res: Response): Promise<void> {
  const usuarioId = req.usuarioId as number;
  const contratoId = Number(req.params.id);
  if (!Number.isInteger(contratoId) || contratoId <= 0) {
    res.status(400).json({ error: "contrato_invalido" });
    return;
  }
  const resultado = await finalizarServicioComprador(contratoId, usuarioId);
  if (!resultado.ok) {
    res.status(400).json({ error: resultado.error.tipo, mensaje: MENSAJES_ERROR_FINALIZAR[resultado.error.tipo] });
    return;
  }
  res.status(200).json({ ok: true });
}

// Puerto de finalizar_servicio_tutor.php — botón "Confirmar Cierre" del vendedor.
export async function postConfirmarCierreVendedor(req: Request, res: Response): Promise<void> {
  const usuarioId = req.usuarioId as number;
  const contratoId = Number(req.params.id);
  if (!Number.isInteger(contratoId) || contratoId <= 0) {
    res.status(400).json({ error: "contrato_invalido" });
    return;
  }
  const resultado = await confirmarCierreVendedor(contratoId, usuarioId);
  if (!resultado.ok) {
    res.status(400).json({ error: resultado.error.tipo, mensaje: MENSAJES_ERROR_FINALIZAR[resultado.error.tipo] });
    return;
  }
  res.status(200).json({ ok: true });
}

const MENSAJES_ERROR_SLOT_EXCEPCION: Record<string, string> = {
  hora_muy_temprano: "La hora de la reserva debe ser desde las 07:00.",
  muy_pronto: "La reserva debe ser con al menos 1 hora de anticipación.",
  muy_lejos: "No puedes generar reservas a más de 30 días.",
  conversacion_no_encontrada: "Conversación no encontrada.",
  no_autorizado: "Solo el tutor puede generar reservas.",
  servicio_no_disponible: "Servicio no disponible.",
};

// Puerto de generar_slot_excepcion.php (tutor propone fecha/hora desde el chat).
export async function postGenerarSlotExcepcion(req: Request, res: Response): Promise<void> {
  const tutorId = req.usuarioId as number;
  const body = req.body as Record<string, unknown>;

  const conversacionId = Number(body.conversacionId);
  const fecha = typeof body.fecha === "string" ? body.fecha.trim() : "";
  const hora = typeof body.hora === "string" ? body.hora.trim() : "";

  if (!Number.isInteger(conversacionId) || conversacionId <= 0 || !/^\d{4}-\d{2}-\d{2}$/.test(fecha) || !/^\d{2}:\d{2}$/.test(hora)) {
    res.status(400).json({ error: "datos_invalidos", mensaje: "Faltan datos obligatorios." });
    return;
  }

  const input: GenerarSlotExcepcionInput = { conversacionId, fecha, hora };
  const resultado = await generarSlotExcepcion(tutorId, input);
  if (!resultado.ok) {
    res.status(400).json({ error: resultado.error.tipo, mensaje: MENSAJES_ERROR_SLOT_EXCEPCION[resultado.error.tipo] ?? "No se pudo generar la reserva." });
    return;
  }
  res.status(200).json({ ok: true });
}

// Puerto de pagar_slot_excepcion.php (rama GET) — datos de display, requiere sesión igual
// que el PHP real (redirige a /login si no hay usuario).
export async function getSlotExcepcion(req: Request, res: Response): Promise<void> {
  const usuarioId = req.usuarioId as number;
  const token = String(req.params.token ?? "");
  if (!/^[0-9a-f]{64}$/.test(token)) {
    res.status(400).json({ error: "token_invalido" });
    return;
  }

  const row = await getSlotExcepcionPorToken(token);
  if (!row) {
    res.status(404).json({ error: "token_invalido" });
    return;
  }
  if (row.alumno_id !== usuarioId) {
    res.status(403).json({ error: "sin_acceso" });
    return;
  }

  res.status(200).json({
    slot: mapSlotExcepcionPublico(row),
    estado: row.estado,
    contratoId: row.contrato_id,
    servicioEstado: row.servicio_estado,
  });
}

const MENSAJES_ERROR_PAGAR_SLOT: Record<string, string> = {
  token_invalido: "Este enlace de reserva no es válido.",
  ya_pagado: "Esta reserva ya fue procesada.",
  no_disponible: "Esta reserva ya no está disponible.",
  expirado: "Este enlace expiró. El tutor puede generar una nueva reserva desde el chat.",
  sin_acceso: "Este enlace no corresponde a tu cuenta.",
  servicio_no_disponible: "El servicio ya no está disponible.",
  horario_ocupado: "Lo sentimos, ese horario ya no está disponible. El tutor puede proponer una nueva reserva desde el chat.",
};

// Puerto de pagar_slot_excepcion.php (rama POST).
export async function postPagarSlotExcepcion(req: Request, res: Response): Promise<void> {
  const usuarioId = req.usuarioId as number;
  const token = String(req.params.token ?? "");
  if (!/^[0-9a-f]{64}$/.test(token)) {
    res.status(400).json({ error: "token_invalido", mensaje: MENSAJES_ERROR_PAGAR_SLOT.token_invalido });
    return;
  }

  const resultado = await pagarSlotExcepcion(usuarioId, token);
  if (!resultado.ok) {
    res.status(400).json({ error: resultado.error.tipo, mensaje: MENSAJES_ERROR_PAGAR_SLOT[resultado.error.tipo] ?? "No se pudo procesar la reserva." });
    return;
  }
  res.status(200).json({ ok: true, contratoId: resultado.contratoId });
}
