import { Router } from "express";
import { optionalAuth } from "../auth/auth.middleware.js";
import { getServicioDetail, getServiciosList } from "./servicios.controller.js";

export const serviciosRouter = Router();

serviciosRouter.get("/", getServiciosList);
// optionalAuth: público siempre (paridad con detalle_servicio.php), solo enriquece
// la respuesta con viewer.isOwner si hay una sesión válida. Nunca bloquea.
serviciosRouter.get("/:id", optionalAuth, getServicioDetail);
