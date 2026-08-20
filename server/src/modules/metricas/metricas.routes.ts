import { Router } from "express";
import { requireAuth } from "../auth/auth.middleware.js";
import { getMisMetricas } from "./metricas.controller.js";

export const metricasRouter = Router();

metricasRouter.get("/", requireAuth, getMisMetricas);
