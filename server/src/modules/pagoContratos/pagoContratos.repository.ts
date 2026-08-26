import type { ResultSetHeader, RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";
import type { PagoVerificado } from "../../lib/mercadoPago.js";
import type { ContratoParaPago, ResultadoProcesarPago } from "./pagoContratos.types.js";

interface ContratoParaPagoRow extends ContratoParaPago, RowDataPacket {}

// Puerto exacto de iniciar_pago_contrato.php:22-37 — mismo join, mismo filtro de dueño
// (comprador_id = ?) para que un usuario no pueda generar una preferencia de pago para el
// contrato de otro.
export async function getContratoParaPago(contratoId: number, compradorId: number): Promise<ContratoParaPago | null> {
  const [rows] = await pool.query<ContratoParaPagoRow[]>(
    `SELECT c.id, c.estado, c.monto, c.servicio_id, c.comprador_id, c.vendedor_id,
            s.titulo AS servicio_titulo, a.nombre AS comprador_nombre, a.correo AS comprador_correo
     FROM contratos c
     JOIN servicios s ON c.servicio_id = s.id
     JOIN alumnos a ON c.comprador_id = a.id
     WHERE c.id = ? AND c.comprador_id = ?
     LIMIT 1`,
    [contratoId, compradorId],
  );
  return rows[0] ?? null;
}

interface ContratoLockRow extends RowDataPacket {
  id: number;
  estado: string;
  servicio_id: number;
}

const ESTADOS_TERMINALES = ["en_progreso", "liberado", "cancelado"];

// Puerto EXACTO (fusionado y corregido) de notificaciones_mp.php:341-367 (rama de
// contrato) + pago_exitoso_contrato.php:65-104 (descuento de cupo). Es la ÚNICA función que
// muta el estado de un contrato por pago — la llaman tanto el webhook como la página de
// retorno, ambos ya con un PagoVerificado real (nunca datos sin verificar). Mismo patrón de
// blindaje que contratos.repository.ts::crearContrato: transacción real, FOR UPDATE, guards
// idempotentes por estado (nunca por affected_rows a ciegas, porque acá SÍ importa poder
// distinguir "ya estaba aprobado" de "se acaba de aprobar ahora" para no reenviar
// notificaciones cuando se conecten en una sesión futura).
//
// NO escribe contratos.estado='rechazado' (el PHP real sí lo hace en el webhook, línea 355)
// — 'rechazado' NO es un valor válido del ENUM real (confirmado contra la BD: mismo patrón
// de bug ya encontrado con 'entregado'/'completado'/'pagado' en otras piezas de esta
// migración). En su lugar, un rechazo revierte a 'pendiente_pago' — exactamente lo que
// pago_error_contrato.php (el lado que SÍ funciona hoy) ya hace de verdad.
//
// NO escribe slots_excepcion.estado='pagado' (tampoco es un valor ENUM válido — mismo
// hallazgo que en contratos.repository.ts::pagarSlotExcepcion) — el guard real ya es
// contrato_id + contratos.estado, sin necesidad de esa columna.
export async function procesarResultadoPago(pago: PagoVerificado): Promise<ResultadoProcesarPago> {
  if (pago.contratoId === null) {
    return { ok: false, error: "contrato_no_identificado" };
  }

  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();

    const [rows] = await conn.query<ContratoLockRow[]>("SELECT id, estado, servicio_id FROM contratos WHERE id = ? LIMIT 1 FOR UPDATE", [
      pago.contratoId,
    ]);
    const contrato = rows[0];
    if (!contrato) {
      await conn.rollback();
      return { ok: false, error: "contrato_no_encontrado" };
    }

    if (pago.status === "approved") {
      if (contrato.estado === "en_progreso") {
        await conn.rollback();
        await registrarEventos(pago, contrato.id, "aprobado_ya_procesado");
        return { ok: true, contratoId: contrato.id, accion: "aprobado_ya_procesado" };
      }
      if (contrato.estado !== "pendiente_pago") {
        await conn.rollback();
        await registrarEventos(pago, contrato.id, "no_aplicable");
        return { ok: false, error: "no_aplicable" };
      }

      await conn.query<ResultSetHeader>("UPDATE contratos SET estado = 'en_progreso', fecha_pago = NOW() WHERE id = ?", [contrato.id]);
      // Descuento de cupo atómico — puerto de pago_exitoso_contrato.php:80-89. 0 filas
      // afectadas para un servicio sin oferta (WHERE lo filtra), no es un error.
      await conn.query<ResultSetHeader>(
        "UPDATE servicios SET cupos_oferta = cupos_oferta - 1 WHERE id = ? AND is_subvencionado = 1 AND cupos_oferta > 0",
        [contrato.servicio_id],
      );

      await conn.commit();
      await registrarEventos(pago, contrato.id, "aprobado");
      return { ok: true, contratoId: contrato.id, accion: "aprobado" };
    }

    if (pago.status === "pending" || pago.status === "in_process") {
      if (!ESTADOS_TERMINALES.includes(contrato.estado)) {
        await conn.query<ResultSetHeader>("UPDATE contratos SET estado = 'pendiente_pago' WHERE id = ?", [contrato.id]);
      }
      await conn.commit();
      await registrarEventos(pago, contrato.id, "pendiente");
      return { ok: true, contratoId: contrato.id, accion: "pendiente" };
    }

    if (pago.status === "rejected") {
      if (!ESTADOS_TERMINALES.includes(contrato.estado)) {
        await conn.query<ResultSetHeader>("UPDATE contratos SET estado = 'pendiente_pago' WHERE id = ?", [contrato.id]);
      }
      await conn.commit();
      await registrarEventos(pago, contrato.id, "rechazado");
      return { ok: true, contratoId: contrato.id, accion: "rechazado" };
    }

    await conn.rollback();
    await registrarEventos(pago, contrato.id, "sin_cambios");
    return { ok: true, contratoId: contrato.id, accion: "sin_cambios" };
  } catch (err) {
    await conn.rollback();
    throw err;
  } finally {
    conn.release();
  }
}

// Puerto de notificaciones_mp.php:320-339 (mp_eventos_log + contrato_eventos) — auditoría,
// no forma parte del blindaje. Errores de logging se tragan a propósito (mismo criterio que
// el resto de la auditoría de esta migración: nunca debe poder tumbar la mutación real, que
// ya se confirmó exitosa antes de llegar acá).
async function registrarEventos(pago: PagoVerificado, contratoId: number, evento: string): Promise<void> {
  try {
    await pool.query(
      "INSERT INTO mp_eventos_log (payment_id, contrato_id, tipo, status, status_detail, payload) VALUES (?, ?, 'payment', ?, ?, ?)",
      [pago.paymentId, contratoId, pago.status, pago.statusDetail, JSON.stringify(pago)],
    );
    await pool.query("INSERT INTO contrato_eventos (contrato_id, usuario_id, evento, detalle) VALUES (?, 0, ?, ?)", [
      contratoId,
      `PAGO_${evento.toUpperCase()}`,
      `payment_id=${pago.paymentId}; status=${pago.status}; monto=${pago.monto}`,
    ]);
  } catch {
    // No bloquear la respuesta al webhook/retorno por un fallo de auditoría.
  }
}
