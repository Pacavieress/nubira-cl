import { Router } from "express";
import { requireAdmin } from "../auth/auth.middleware.js";
import { getCuentas } from "./adminCuentas.controller.js";

export const adminCuentasRouter = Router();

adminCuentasRouter.get("/", requireAdmin, getCuentas);
