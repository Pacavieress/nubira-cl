import { Router } from "express";
import { requireAdmin } from "../auth/auth.middleware.js";
import { getUsuarios } from "./adminUsuarios.controller.js";

export const adminUsuariosRouter = Router();

adminUsuariosRouter.get("/", requireAdmin, getUsuarios);
