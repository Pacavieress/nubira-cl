import { Router } from "express";
import { requireAdmin } from "../auth/auth.middleware.js";
import { getApuntes, postAlternar } from "./adminApuntes.controller.js";

export const adminApuntesRouter = Router();

adminApuntesRouter.get("/", requireAdmin, getApuntes);
adminApuntesRouter.post("/:id/alternar", requireAdmin, postAlternar);
