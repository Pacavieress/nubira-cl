import { Router } from "express";
import { optionalAuth } from "../auth/auth.middleware.js";
import { getHome } from "./home.controller.js";

export const homeRouter = Router();

homeRouter.get("/", optionalAuth, getHome);
