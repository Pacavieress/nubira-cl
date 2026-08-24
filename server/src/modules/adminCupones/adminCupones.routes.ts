import { Router } from "express";
import { requireAdmin } from "../auth/auth.middleware.js";
import { deleteCupon, getCupones, postCupon } from "./adminCupones.controller.js";

export const adminCuponesRouter = Router();

adminCuponesRouter.get("/", requireAdmin, getCupones);
adminCuponesRouter.post("/", requireAdmin, postCupon);
adminCuponesRouter.delete("/:id", requireAdmin, deleteCupon);
