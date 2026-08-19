import { Router } from "express";
import { optionalAuth } from "../auth/auth.middleware.js";
import { getApunteDetail, getApuntesList } from "./apuntes.controller.js";

export const apuntesRouter = Router();

apuntesRouter.get("/", getApuntesList);
// optionalAuth: público siempre (paridad con ver_apunte.php), solo enriquece la
// respuesta con viewer.isOwner si hay una sesión válida. Nunca bloquea.
apuntesRouter.get("/:id", optionalAuth, getApunteDetail);
