import { Router } from "express";
import { requireAuth } from "../auth/auth.middleware.js";
import { getMiPerfil, putMiPerfilBio } from "./perfil.controller.js";

export const perfilRouter = Router();

perfilRouter.get("/", requireAuth, getMiPerfil);
perfilRouter.put("/bio", requireAuth, putMiPerfilBio);
