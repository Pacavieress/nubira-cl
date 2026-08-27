import { Router } from "express";
import { requireAdmin } from "../auth/auth.middleware.js";
import { getCupon, getListado, postEnviar } from "./adminDespertarDormidos.controller.js";

export const adminDespertarDormidosRouter = Router();

adminDespertarDormidosRouter.get("/", requireAdmin, getListado);
adminDespertarDormidosRouter.get("/cupon", requireAdmin, getCupon);
adminDespertarDormidosRouter.post("/enviar", requireAdmin, postEnviar);
