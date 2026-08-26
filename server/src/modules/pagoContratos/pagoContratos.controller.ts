import type { Request, Response } from "express";
import { crearPreferenciaContrato, obtenerPagoVerificado } from "../../lib/mercadoPago.js";
import { getContratoParaPago, procesarResultadoPago } from "./pagoContratos.repository.js";
import { pool } from "../../db/pool.js";

// Puerto de iniciar_pago_servicio.php + iniciar_pago_contrato.php (unificados). Mismo atajo
// para servicios gratuitos ($0, ej. cupón de 100%): activa el contrato directo, sin pasar
// por MercadoPago, en vez de crear una preferencia de $0 que la pasarela rechazaría.
export async function postCrearPreferencia(req: Request, res: Response): Promise<void> {
  const compradorId = req.usuarioId as number;
  const contratoId = Number(req.params.contratoId);
  if (!Number.isInteger(contratoId) || contratoId <= 0) {
    res.status(400).json({ error: "contrato_invalido" });
    return;
  }

  const contrato = await getContratoParaPago(contratoId, compradorId);
  if (!contrato) {
    res.status(404).json({ error: "contrato_no_encontrado" });
    return;
  }

  if (contrato.estado !== "pendiente_pago") {
    // Ya pagado / en progreso / cerrado — nada que pagar, el cliente redirige al aula.
    res.status(200).json({ ok: true, yaProcesado: true, contratoId: contrato.id });
    return;
  }

  if (contrato.monto <= 0) {
    await pool.query("UPDATE contratos SET estado = 'en_progreso' WHERE id = ? AND estado = 'pendiente_pago'", [contrato.id]);
    res.status(200).json({ ok: true, yaProcesado: true, contratoId: contrato.id, gratis: true });
    return;
  }

  try {
    const { initPoint } = await crearPreferenciaContrato({
      contratoId: contrato.id,
      titulo: contrato.servicio_titulo,
      monto: contrato.monto,
      compradorEmail: contrato.comprador_correo,
      compradorNombre: contrato.comprador_nombre,
    });
    await pool.query("INSERT INTO contrato_eventos (contrato_id, usuario_id, evento, detalle) VALUES (?, ?, 'INTENTO_PAGO', 'Checkout creado en MercadoPago')", [
      contrato.id,
      compradorId,
    ]);
    res.status(200).json({ ok: true, yaProcesado: false, initPoint });
  } catch (err) {
    res.status(502).json({
      error: "error_pasarela",
      mensaje: "No pudimos generar el enlace de pago seguro en este momento. Tu solicitud de servicio está guardada, puedes intentar pagar más tarde.",
    });
  }
}

// Puerto de notificaciones_mp.php (rama de contrato) — endpoint PÚBLICO, sin sesión:
// MercadoPago llama esto server-to-server. Responde 200 siempre (mismo requisito real que
// documenta el PHP: MP reintenta agresivamente ante cualquier respuesta que no sea 2xx) —
// los errores se registran pero nunca se propagan como código de error HTTP.
export async function postWebhook(req: Request, res: Response): Promise<void> {
  res.status(200).end();

  try {
    const body = req.body as { type?: string; action?: string; data?: { id?: string | number } };
    const paymentId = body?.data?.id;
    if (!paymentId) return;

    const pago = await obtenerPagoVerificado(String(paymentId));
    await procesarResultadoPago(pago);
  } catch {
    // Silencioso a propósito, mismo criterio que notificaciones_mp.php — ya respondimos 200.
  }
}

// Puerto fusionado de pago_exitoso_contrato.php + pago_error_contrato.php +
// pago_pendiente_contrato.php — llamado desde la ÚNICA página de retorno de web/
// (/pago/retorno). A diferencia del PHP real, NUNCA confía en collection_status del query
// string: re-verifica el pago contra la API real de MercadoPago (mismo camino que el
// webhook, misma función de mutación) antes de decidir qué mostrarle al comprador.
export async function getConfirmarRetorno(req: Request, res: Response): Promise<void> {
  const usuarioId = req.usuarioId as number;
  const paymentId = typeof req.query.paymentId === "string" ? req.query.paymentId : "";
  if (!paymentId) {
    res.status(400).json({ error: "payment_id_faltante" });
    return;
  }

  let pago;
  try {
    pago = await obtenerPagoVerificado(paymentId);
  } catch {
    res.status(502).json({ error: "error_verificacion", mensaje: "No pudimos confirmar tu pago con MercadoPago. Si el cargo se realizó, se procesará en unos minutos." });
    return;
  }

  const resultado = await procesarResultadoPago(pago);
  if (!resultado.ok) {
    res.status(200).json({ ok: false, error: resultado.error, status: pago.status });
    return;
  }

  // Gate de privacidad: la mutación ya es correcta e idempotente sin importar quién la
  // dispare (mismo contrato_id verificado que usaría el webhook), pero los DATOS de
  // respuesta (monto, título) solo se muestran al comprador real.
  const contrato = await getContratoParaPago(resultado.contratoId, usuarioId);
  if (!contrato) {
    res.status(403).json({ error: "sin_acceso" });
    return;
  }

  res.status(200).json({
    ok: true,
    accion: resultado.accion,
    status: pago.status,
    contrato: {
      id: contrato.id,
      estado: contrato.estado,
      monto: contrato.monto,
      servicioTitulo: contrato.servicio_titulo,
    },
  });
}
