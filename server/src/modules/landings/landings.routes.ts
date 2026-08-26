import { Router } from "express";
import { getLandingApuntes, getLandingClases } from "./landings.controller.js";

export const landingsRouter = Router();

// Puerto completo de landing_categoria.php, ambos tipos — ver landings.types.ts para el
// historial de por qué tipo=apuntes se dejó fuera antes y por qué esa razón ya no aplica.
landingsRouter.get("/clases/:slug", getLandingClases);
landingsRouter.get("/apuntes/:slug", getLandingApuntes);
