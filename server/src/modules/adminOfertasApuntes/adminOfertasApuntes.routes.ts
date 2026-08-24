import { Router } from "express";
import { requireAdmin } from "../auth/auth.middleware.js";
import { getOfertasApuntes, postAplicarPromo, postQuitarPromo, putPrecio } from "./adminOfertasApuntes.controller.js";

export const adminOfertasApuntesRouter = Router();

adminOfertasApuntesRouter.get("/", requireAdmin, getOfertasApuntes);
adminOfertasApuntesRouter.put("/:id/precio", requireAdmin, putPrecio);
adminOfertasApuntesRouter.post("/:id/aplicar-promo", requireAdmin, postAplicarPromo);
adminOfertasApuntesRouter.post("/:id/quitar-promo", requireAdmin, postQuitarPromo);
