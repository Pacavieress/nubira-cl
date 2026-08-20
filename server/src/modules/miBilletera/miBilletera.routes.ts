import { Router } from "express";
import { requireAuth } from "../auth/auth.middleware.js";
import { getMiBilletera } from "./miBilletera.controller.js";

export const miBilleteraRouter = Router();

miBilleteraRouter.get("/", requireAuth, getMiBilletera);
