import { Router } from "express";
import { requireAuth } from "../auth/auth.middleware.js";
import { getMisVentasApuntes } from "./ventasApuntes.controller.js";

export const ventasApuntesRouter = Router();

ventasApuntesRouter.get("/", requireAuth, getMisVentasApuntes);
