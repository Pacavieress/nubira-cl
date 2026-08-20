import { Router } from "express";
import { requireAuth } from "../auth/auth.middleware.js";
import { getMaterias, getPreguntas, responder } from "./desafio.controller.js";

// Puerto de app/desafio.php: la página real exige sesión ANTES de renderizar nada (no
// solo en las llamadas AJAX) — mismo criterio acá, los 3 endpoints van detrás de
// requireAuth, incluido el listado de materias (no revela nada sensible, pero tampoco lo
// hace el PHP real fuera de una sesión iniciada).
export const desafioRouter = Router();

desafioRouter.get("/materias", requireAuth, getMaterias);
desafioRouter.get("/preguntas", requireAuth, getPreguntas);
desafioRouter.post("/responder", requireAuth, responder);
