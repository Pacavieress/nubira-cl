import { Router } from "express";
import { requireAdmin } from "../auth/auth.middleware.js";
import { getVideos } from "./adminVideos.controller.js";

export const adminVideosRouter = Router();

adminVideosRouter.get("/", requireAdmin, getVideos);
