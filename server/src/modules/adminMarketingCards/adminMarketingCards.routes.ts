import { Router } from "express";
import { requireAdmin } from "../auth/auth.middleware.js";
import { getNovedades, getServiciosMarketing, postCrearNovedad } from "./adminMarketingCards.controller.js";

export const adminMarketingCardsRouter = Router();

adminMarketingCardsRouter.get("/servicios", requireAdmin, getServiciosMarketing);
adminMarketingCardsRouter.get("/novedades", requireAdmin, getNovedades);
adminMarketingCardsRouter.post("/novedades", requireAdmin, postCrearNovedad);
