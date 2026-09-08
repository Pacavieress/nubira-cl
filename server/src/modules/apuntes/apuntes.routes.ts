import { Router } from "express";
import { optionalAuth } from "../auth/auth.middleware.js";
import { getApunteDetail, getApuntesCategoriasList, getApuntesList } from "./apuntes.controller.js";

export const apuntesRouter = Router();

apuntesRouter.get("/", getApuntesList);
// Antes de "/:id" — de lo contrario Express la capturaría como getApunteDetail(id="categorias").
apuntesRouter.get("/categorias", getApuntesCategoriasList);
// optionalAuth: público siempre (paridad con ver_apunte.php), solo enriquece la
// respuesta con viewer.isOwner si hay una sesión válida. Nunca bloquea.
apuntesRouter.get("/:id", optionalAuth, getApunteDetail);
