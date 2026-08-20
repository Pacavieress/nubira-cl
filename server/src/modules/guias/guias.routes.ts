import { Router } from "express";
import { optionalAuth } from "../auth/auth.middleware.js";
import { getArticuloDetalle, getHubCategoria, getHubGeneral } from "./guias.controller.js";

export const guiasRouter = Router();

guiasRouter.get("/", getHubGeneral);
guiasRouter.get("/:cat/:slug", optionalAuth, getArticuloDetalle);
guiasRouter.get("/:slug", optionalAuth, getHubCategoria);
