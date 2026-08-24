import { Router } from "express";
import { requireAdmin } from "../auth/auth.middleware.js";
import { getAulaMensajes, getAulas } from "./adminAulas.controller.js";

export const adminAulasRouter = Router();

adminAulasRouter.get("/", requireAdmin, getAulas);
adminAulasRouter.get("/:id/mensajes", requireAdmin, getAulaMensajes);
