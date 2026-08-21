import { Router } from "express";
import { requireAdmin } from "../auth/auth.middleware.js";
import { getContratos } from "./adminContratos.controller.js";

export const adminContratosRouter = Router();

adminContratosRouter.get("/", requireAdmin, getContratos);
