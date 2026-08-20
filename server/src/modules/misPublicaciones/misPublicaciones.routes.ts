import { Router } from "express";
import { requireAuth } from "../auth/auth.middleware.js";
import {
  eliminarApuntePublicado,
  eliminarServicioPublicado,
  getMisPublicaciones,
  reactivarServicioPublicado,
} from "./misPublicaciones.controller.js";

export const misPublicacionesRouter = Router();

misPublicacionesRouter.get("/", requireAuth, getMisPublicaciones);
misPublicacionesRouter.delete("/servicios/:id", requireAuth, eliminarServicioPublicado);
misPublicacionesRouter.post("/servicios/:id/reactivar", requireAuth, reactivarServicioPublicado);
misPublicacionesRouter.delete("/apuntes/:id", requireAuth, eliminarApuntePublicado);
