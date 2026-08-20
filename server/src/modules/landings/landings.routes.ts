import { Router } from "express";
import { getLandingClases } from "./landings.controller.js";

export const landingsRouter = Router();

// Puerto de landing_categoria.php con tipo=clases únicamente — ver landings.types.ts
// para por qué tipo=apuntes queda fuera de esta pieza.
landingsRouter.get("/clases/:slug", getLandingClases);
