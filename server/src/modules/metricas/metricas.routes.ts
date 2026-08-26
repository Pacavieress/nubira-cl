import { Router } from "express";
import { requireAuth } from "../auth/auth.middleware.js";
import { getMiMetricaDetalle, getMisMetricas } from "./metricas.controller.js";

export const metricasRouter = Router();

metricasRouter.get("/", requireAuth, getMisMetricas);
metricasRouter.get("/:tipo/:id", requireAuth, getMiMetricaDetalle);
