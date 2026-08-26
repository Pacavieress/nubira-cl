import { Router } from "express";
import { requireAuth } from "../auth/auth.middleware.js";
import { getMiBilletera, getMiDatosBancariosParaEditar, postSolicitarRetiro, putMiDatosBancarios } from "./miBilletera.controller.js";

export const miBilleteraRouter = Router();

miBilleteraRouter.get("/", requireAuth, getMiBilletera);
miBilleteraRouter.get("/datos-bancarios", requireAuth, getMiDatosBancariosParaEditar);
miBilleteraRouter.put("/datos-bancarios", requireAuth, putMiDatosBancarios);
miBilleteraRouter.post("/solicitar-retiro", requireAuth, postSolicitarRetiro);
