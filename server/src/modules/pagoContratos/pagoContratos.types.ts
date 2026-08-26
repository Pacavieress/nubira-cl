// Puerto de app/iniciar_pago_servicio.php + app/iniciar_pago_contrato.php (creación de
// preferencia, unificadas en un solo endpoint) + app/notificaciones_mp.php (webhook) +
// app/pago_exitoso_contrato.php + app/pago_error_contrato.php + app/pago_pendiente_contrato.php
// (página de retorno, unificadas en una sola porque las 3 necesitan el mismo trabajo real:
// re-verificar el pago contra la API de MercadoPago antes de tocar el contrato — Checkpoint 2
// "Pago", 26/08/2026).
//
// 2 hallazgos reales corregidos al portar (ver pagoContratos.repository.ts para el detalle):
// (A) el webhook real NO puede identificar el contrato en el camino común — bug de casting
// de PHP en notificaciones_mp.php:40, no solo asimetría de diseño; (B) la página de retorno
// real confía en collection_status del navegador sin verificar contra MercadoPago — riesgo
// real de que un comprador libere su propio contrato sin pagar. Ambos corregidos acá: el
// contrato_id se resuelve SIEMPRE desde metadata/external_reference de un pago ya verificado
// contra la API real (nunca desde un query param del navegador), y ambos caminos (webhook y
// retorno) llaman a la MISMA función de mutación — un solo lugar hace el trabajo real.
//
// Correos de confirmación y push notifications quedan diferidos a propósito, mismo criterio
// que el resto de esta migración (server/ no tiene infraestructura de envío todavía).

export type EstadoPago = "approved" | "pending" | "in_process" | "rejected";

export type AccionProcesarPago = "aprobado" | "aprobado_ya_procesado" | "pendiente" | "rechazado" | "sin_cambios";

export type ResultadoProcesarPago =
  | { ok: true; contratoId: number; accion: AccionProcesarPago }
  | { ok: false; error: "contrato_no_identificado" | "contrato_no_encontrado" | "no_aplicable" };

export interface ContratoParaPago {
  id: number;
  estado: string;
  monto: number;
  servicio_id: number;
  comprador_id: number;
  vendedor_id: number;
  servicio_titulo: string;
  comprador_nombre: string;
  comprador_correo: string;
}
