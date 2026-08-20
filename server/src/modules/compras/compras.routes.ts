import { Router } from "express";
import { requireAuth } from "../auth/auth.middleware.js";
import { getMisCompras } from "./compras.controller.js";

export const comprasRouter = Router();

comprasRouter.get("/", requireAuth, getMisCompras);
