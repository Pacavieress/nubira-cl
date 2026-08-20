import { Router } from "express";
import { requireAuth } from "../auth/auth.middleware.js";
import { getMisVentasClases } from "./ventasClases.controller.js";

export const ventasClasesRouter = Router();

ventasClasesRouter.get("/", requireAuth, getMisVentasClases);
