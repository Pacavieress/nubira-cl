import { Router } from "express";
import { requireAdmin } from "../auth/auth.middleware.js";
import { deleteHard, getReclamos, postAccionLote, postPapelera, postResolver, postResponder, postRestaurar } from "./adminReclamos.controller.js";

export const adminReclamosRouter = Router();

adminReclamosRouter.get("/", requireAdmin, getReclamos);
adminReclamosRouter.post("/:id/responder", requireAdmin, postResponder);
adminReclamosRouter.post("/:id/resolver", requireAdmin, postResolver);
adminReclamosRouter.post("/:id/papelera", requireAdmin, postPapelera);
adminReclamosRouter.post("/:id/restaurar", requireAdmin, postRestaurar);
adminReclamosRouter.delete("/:id", requireAdmin, deleteHard);
adminReclamosRouter.post("/bulk", requireAdmin, postAccionLote);
