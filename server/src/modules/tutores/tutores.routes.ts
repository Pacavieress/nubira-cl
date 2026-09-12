import { Router } from "express";
import { optionalAuth } from "../auth/auth.middleware.js";
import { getTutorDetail } from "./tutores.controller.js";

export const tutoresRouter = Router();

// optionalAuth: necesario para el gate de la cuenta demo (contacto@nubira.cl) y para
// que el propio dueño pueda ver su perfil aunque sea esa cuenta — nunca bloquea.
tutoresRouter.get("/:id", optionalAuth, getTutorDetail);
