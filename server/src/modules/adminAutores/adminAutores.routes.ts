import { Router } from "express";
import { requireAdmin } from "../auth/auth.middleware.js";
import { getAutores } from "./adminAutores.controller.js";

export const adminAutoresRouter = Router();

adminAutoresRouter.get("/", requireAdmin, getAutores);
