import { Router } from "express";
import { requireAdmin } from "../auth/auth.middleware.js";
import { getAccesos, getDetalle, getExportar, postEliminar, postPurgarBots } from "./adminAccesos.controller.js";

export const adminAccesosRouter = Router();

adminAccesosRouter.get("/", requireAdmin, getAccesos);
adminAccesosRouter.get("/detalle", requireAdmin, getDetalle);
adminAccesosRouter.get("/exportar", requireAdmin, getExportar);
adminAccesosRouter.post("/eliminar", requireAdmin, postEliminar);
adminAccesosRouter.post("/purgar-bots", requireAdmin, postPurgarBots);
