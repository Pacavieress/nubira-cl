import { Router } from "express";
import { requireAdmin } from "../auth/auth.middleware.js";
import { getReportes, putBloqueoUsuario } from "./adminReportesServicios.controller.js";

export const adminReportesServiciosRouter = Router();

adminReportesServiciosRouter.get("/", requireAdmin, getReportes);
adminReportesServiciosRouter.put("/usuarios/:id/bloqueo", requireAdmin, putBloqueoUsuario);
