import { Router } from "express";
import { requireAdmin } from "../auth/auth.middleware.js";
import { getSolicitudes } from "./adminSolicitudes.controller.js";

export const adminSolicitudesRouter = Router();

adminSolicitudesRouter.get("/", requireAdmin, getSolicitudes);
