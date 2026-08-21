import { Router } from "express";
import { requireAdmin } from "../auth/auth.middleware.js";
import { deleteDominio, getDominios, postDominio, putDominio } from "./adminDominios.controller.js";

export const adminDominiosRouter = Router();

adminDominiosRouter.get("/", requireAdmin, getDominios);
adminDominiosRouter.post("/", requireAdmin, postDominio);
adminDominiosRouter.put("/:id", requireAdmin, putDominio);
adminDominiosRouter.delete("/:id", requireAdmin, deleteDominio);
