import { Router } from "express";
import { requireAuth } from "../auth/auth.middleware.js";
import { getMisEvaluaciones } from "./evaluaciones.controller.js";

export const evaluacionesRouter = Router();

evaluacionesRouter.get("/", requireAuth, getMisEvaluaciones);
