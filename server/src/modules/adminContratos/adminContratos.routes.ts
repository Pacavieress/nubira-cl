import { Router } from "express";
import { requireAdmin } from "../auth/auth.middleware.js";
import { getContratos, postCancelarContrato, postLiberarContrato, postRevertirContrato } from "./adminContratos.controller.js";

export const adminContratosRouter = Router();

adminContratosRouter.get("/", requireAdmin, getContratos);
adminContratosRouter.post("/:id/liberar", requireAdmin, postLiberarContrato);
adminContratosRouter.post("/:id/cancelar", requireAdmin, postCancelarContrato);
adminContratosRouter.post("/:id/revertir", requireAdmin, postRevertirContrato);
