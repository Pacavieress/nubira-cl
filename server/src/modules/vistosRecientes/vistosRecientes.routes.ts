import { Router } from "express";
import { requireAuth } from "../auth/auth.middleware.js";
import { getVistosRecientes } from "./vistosRecientes.controller.js";

export const vistosRecientesRouter = Router();

vistosRecientesRouter.get("/", requireAuth, getVistosRecientes);
