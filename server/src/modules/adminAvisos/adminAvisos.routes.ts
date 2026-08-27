import { Router } from "express";
import { requireAdmin } from "../auth/auth.middleware.js";
import { getAvisos, getBuscarUsuarios, getLectoresDeCampana, postCrearCampana } from "./adminAvisos.controller.js";

export const adminAvisosRouter = Router();

adminAvisosRouter.get("/", requireAdmin, getAvisos);
adminAvisosRouter.post("/", requireAdmin, postCrearCampana);
adminAvisosRouter.get("/buscar-usuarios", requireAdmin, getBuscarUsuarios);
adminAvisosRouter.get("/:id/lectores", requireAdmin, getLectoresDeCampana);
