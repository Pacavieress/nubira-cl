import { Router } from "express";
import { requireAuth } from "../auth/auth.middleware.js";
import { getConfirmarRetorno, postCrearPreferencia, postWebhook } from "./pagoContratos.controller.js";

// Rutas autenticadas — montadas en /api/me/pago-contratos (ver app.ts).
export const pagoContratosRouter = Router();
pagoContratosRouter.post("/:contratoId/preferencia", requireAuth, postCrearPreferencia);
pagoContratosRouter.get("/retorno", requireAuth, getConfirmarRetorno);

// Webhook público — MercadoPago no manda cookie de sesión. Montado por separado en
// /api/pago-contratos/webhook (ver app.ts), fuera del prefijo /api/me/*.
export const pagoContratosWebhookRouter = Router();
pagoContratosWebhookRouter.post("/webhook", postWebhook);
