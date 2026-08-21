import { Router } from "express";
import { requireAdmin } from "../auth/auth.middleware.js";
import { getConfigPrecios, putOfertaGratisHasta, putPrecioDesbloqueo } from "./adminConfigPrecios.controller.js";

export const adminConfigPreciosRouter = Router();

adminConfigPreciosRouter.get("/", requireAdmin, getConfigPrecios);
adminConfigPreciosRouter.put("/precio", requireAdmin, putPrecioDesbloqueo);
adminConfigPreciosRouter.put("/oferta", requireAdmin, putOfertaGratisHasta);
