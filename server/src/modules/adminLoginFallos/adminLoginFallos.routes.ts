import { Router } from "express";
import { requireAdmin } from "../auth/auth.middleware.js";
import { deleteFallos, getMonitoreo, postAutorizarVip, postRevocarVip } from "./adminLoginFallos.controller.js";

export const adminLoginFallosRouter = Router();

adminLoginFallosRouter.get("/", requireAdmin, getMonitoreo);
adminLoginFallosRouter.delete("/fallos", requireAdmin, deleteFallos);
adminLoginFallosRouter.post("/vips", requireAdmin, postAutorizarVip);
adminLoginFallosRouter.post("/vips/revocar", requireAdmin, postRevocarVip);
