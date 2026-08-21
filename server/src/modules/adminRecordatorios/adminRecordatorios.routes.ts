import { Router } from "express";
import { requireAdmin } from "../auth/auth.middleware.js";
import { getRecordatorios } from "./adminRecordatorios.controller.js";

export const adminRecordatoriosRouter = Router();

adminRecordatoriosRouter.get("/", requireAdmin, getRecordatorios);
