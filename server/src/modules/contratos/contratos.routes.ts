import { Router } from "express";
import { requireAuth } from "../auth/auth.middleware.js";
import {
  getCheckout,
  getSlotExcepcion,
  getSlots,
  postConfirmarCierreVendedor,
  postCrearContrato,
  postFinalizarContrato,
  postGenerarSlotExcepcion,
  postPagarSlotExcepcion,
} from "./contratos.controller.js";

export const contratosRouter = Router();

contratosRouter.get("/checkout/:servicioId", requireAuth, getCheckout);
contratosRouter.get("/slots-disponibles", requireAuth, getSlots);
contratosRouter.post("/", requireAuth, postCrearContrato);
contratosRouter.post("/:id/finalizar", requireAuth, postFinalizarContrato);
contratosRouter.post("/:id/confirmar-cierre", requireAuth, postConfirmarCierreVendedor);
contratosRouter.post("/slots-excepcion", requireAuth, postGenerarSlotExcepcion);
contratosRouter.get("/slots-excepcion/:token", requireAuth, getSlotExcepcion);
contratosRouter.post("/slots-excepcion/:token/pagar", requireAuth, postPagarSlotExcepcion);
