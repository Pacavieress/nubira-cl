import { Router } from "express";
import { requireAuth } from "../auth/auth.middleware.js";
import { actualizarMiPerfilCuenta, getMiPerfilCuenta } from "./configurarCuenta.controller.js";

export const configurarCuentaRouter = Router();

configurarCuentaRouter.get("/", requireAuth, getMiPerfilCuenta);
configurarCuentaRouter.put("/", requireAuth, actualizarMiPerfilCuenta);
