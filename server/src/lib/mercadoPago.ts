import { MercadoPagoConfig, Payment, Preference } from "mercadopago";
import type { PaymentResponse } from "mercadopago/dist/clients/payment/commonTypes.js";
import { env } from "../config/env.js";

// Cliente único del SDK real de MercadoPago para Node — mismo token/cuenta que ya usa
// app/config.php (MP_ACCESS_TOKEN) del lado PHP. Puerto de iniciar_pago_servicio.php +
// iniciar_pago_contrato.php (creación de preferencia) + notificaciones_mp.php (lectura de
// pago vía PaymentClient->get()), unificados en un solo formato — ver
// pagoContratos.repository.ts para el porqué de la unificación (los 2 archivos PHP reales
// divergen en el formato de external_reference, lo que rompe al webhook real para el
// camino común — ver hallazgo 26/08/2026).
const config = new MercadoPagoConfig({ accessToken: env.mpAccessToken });
const paymentClient = new Payment(config);
const preferenceClient = new Preference(config);

export interface PreferenciaContratoInput {
  contratoId: number;
  titulo: string;
  monto: number;
  compradorEmail: string;
  compradorNombre: string;
}

// Puerto de iniciar_pago_contrato.php:70-112 (el más completo de los 2 originales: payer,
// binary_mode, notification_url explícito, statement_descriptor) — con external_reference
// SIEMPRE como el número crudo del contrato (nunca con prefijo "CONTRATO_", la causa real
// del bug del webhook) y metadata.contrato_id como respaldo adicional.
export async function crearPreferenciaContrato(input: PreferenciaContratoInput): Promise<{ initPoint: string }> {
  const retornoUrl = `${env.webBaseUrl}/pago/retorno?contratoId=${input.contratoId}`;

  const pref = await preferenceClient.create({
    body: {
      items: [
        {
          id: `CT-${input.contratoId}`,
          title: input.titulo.slice(0, 50),
          description: `Pago en custodia por contrato #${input.contratoId} en Nubira.cl`,
          category_id: "educational_services",
          quantity: 1,
          unit_price: input.monto,
          currency_id: "CLP",
        },
      ],
      payer: {
        email: input.compradorEmail,
        name: input.compradorNombre || "Alumno Nubira",
      },
      back_urls: {
        success: retornoUrl,
        pending: retornoUrl,
        failure: retornoUrl,
      },
      auto_return: "approved",
      binary_mode: true,
      notification_url: `${env.apiPublicUrl}/api/pago-contratos/webhook`,
      external_reference: String(input.contratoId),
      statement_descriptor: "NUBIRA.CL",
      metadata: {
        contrato_id: input.contratoId,
        tipo: "contrato",
      },
    },
  });

  if (!pref.init_point) {
    throw new Error("MercadoPago no devolvió un link de pago válido (init_point vacío).");
  }
  return { initPoint: pref.init_point };
}

export interface PagoVerificado {
  paymentId: string;
  status: string;
  statusDetail: string;
  contratoId: number | null;
  monto: number;
}

// Extrae el contrato_id de un pago YA VERIFICADO contra la API real de MercadoPago —
// metadata.contrato_id primero (formato nuevo, siempre presente en preferencias creadas acá),
// external_reference como respaldo (compatibilidad con pagos creados por el PHP real, que
// puede traer "123" o "CONTRATO_123" según qué archivo lo generó — ver nota de arriba).
export function parsearContratoId(payment: Pick<PaymentResponse, "metadata" | "external_reference">): number | null {
  const metadata = payment.metadata as Record<string, unknown> | undefined;
  const metaId = Number(metadata?.contrato_id);
  if (Number.isInteger(metaId) && metaId > 0) return metaId;

  const ref = String(payment.external_reference ?? "").replace(/^CONTRATO_/, "");
  const parsed = Number(ref);
  return Number.isInteger(parsed) && parsed > 0 ? parsed : null;
}

// Puerto de notificaciones_mp.php:31-40 — SIEMPRE pide el pago real a la API de
// MercadoPago (nunca confía en datos que vengan del webhook o del navegador). Usado tanto
// por el webhook como por la página de retorno — es la única fuente de verdad compartida
// entre los 2 caminos (ver nota de "blindaje simétrico" en pagoContratos.repository.ts).
export async function obtenerPagoVerificado(paymentId: string): Promise<PagoVerificado> {
  const payment = await paymentClient.get({ id: paymentId });
  return {
    paymentId: String(payment.id ?? paymentId),
    status: payment.status ?? "",
    statusDetail: payment.status_detail ?? "",
    contratoId: parsearContratoId(payment),
    monto: Number(payment.transaction_amount ?? 0),
  };
}
