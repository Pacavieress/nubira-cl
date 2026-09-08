import { Router } from "express";
import { optionalAuth } from "../auth/auth.middleware.js";
import { getBusqueda } from "./busqueda.controller.js";

export const busquedaRouter = Router();

// optionalAuth: público siempre — solo enriquece el INSERT de busquedas_fallidas con el
// usuario_id real si había sesión (mismo patrón que apuntesRouter/serviciosRouter).
busquedaRouter.get("/", optionalAuth, getBusqueda);
