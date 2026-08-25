import { Router } from "express";
import { optionalAuth } from "../auth/auth.middleware.js";
import { postVista } from "./vistasDetalle.controller.js";

export const vistasDetalleRouter = Router();

// optionalAuth: público siempre (paridad con track_vista.php, que lee $_SESSION['usuario_id']
// si existe pero nunca bloquea sin sesión) — solo enriquece user_id cuando hay sesión válida.
vistasDetalleRouter.post("/", optionalAuth, postVista);
