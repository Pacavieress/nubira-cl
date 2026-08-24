import { Router } from "express";
import { requireAdmin } from "../auth/auth.middleware.js";
import { getComprasApuntes } from "./adminComprasApuntes.controller.js";

export const adminComprasApuntesRouter = Router();

adminComprasApuntesRouter.get("/", requireAdmin, getComprasApuntes);
