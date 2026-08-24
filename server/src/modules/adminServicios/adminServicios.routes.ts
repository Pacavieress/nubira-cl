import { Router } from "express";
import { requireAdmin } from "../auth/auth.middleware.js";
import { getServiciosAdmin, putVisibilidad } from "./adminServicios.controller.js";

export const adminServiciosRouter = Router();

adminServiciosRouter.get("/", requireAdmin, getServiciosAdmin);
adminServiciosRouter.put("/:id/visibilidad", requireAdmin, putVisibilidad);
