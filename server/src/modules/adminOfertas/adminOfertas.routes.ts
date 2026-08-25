import { Router } from "express";
import { requireAdmin } from "../auth/auth.middleware.js";
import { getOfertas, postAplicarOferta, postQuitarOferta } from "./adminOfertas.controller.js";

export const adminOfertasRouter = Router();

adminOfertasRouter.get("/", requireAdmin, getOfertas);
adminOfertasRouter.post("/:id/aplicar-oferta", requireAdmin, postAplicarOferta);
adminOfertasRouter.post("/:id/quitar-oferta", requireAdmin, postQuitarOferta);
