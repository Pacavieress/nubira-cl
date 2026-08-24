import { Router } from "express";
import { requireAdmin } from "../auth/auth.middleware.js";
import { getAvisos, getLectoresDeCampana } from "./adminAvisos.controller.js";

export const adminAvisosRouter = Router();

adminAvisosRouter.get("/", requireAdmin, getAvisos);
adminAvisosRouter.get("/:id/lectores", requireAdmin, getLectoresDeCampana);
