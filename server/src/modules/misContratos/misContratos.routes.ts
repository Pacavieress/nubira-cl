import { Router } from "express";
import { requireAuth } from "../auth/auth.middleware.js";
import { getMisContratos } from "./misContratos.controller.js";

export const misContratosRouter = Router();

misContratosRouter.get("/", requireAuth, getMisContratos);
