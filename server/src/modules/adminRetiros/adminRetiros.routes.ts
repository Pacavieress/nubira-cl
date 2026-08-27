import { Router } from "express";
import { requireAdmin } from "../auth/auth.middleware.js";
import { getAuditoriaRetiro, getListadoRetiros, postAprobarRetiro, postRechazarRetiro, putConfiguracionRetiros } from "./adminRetiros.controller.js";

export const adminRetirosRouter = Router();

adminRetirosRouter.get("/", requireAdmin, getListadoRetiros);
adminRetirosRouter.put("/configuracion", requireAdmin, putConfiguracionRetiros);
adminRetirosRouter.get("/:id/auditoria", requireAdmin, getAuditoriaRetiro);
adminRetirosRouter.post("/:id/aprobar", requireAdmin, postAprobarRetiro);
adminRetirosRouter.post("/:id/rechazar", requireAdmin, postRechazarRetiro);
