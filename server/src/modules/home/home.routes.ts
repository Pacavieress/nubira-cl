import { Router } from "express";
import { getHome } from "./home.controller.js";

export const homeRouter = Router();

homeRouter.get("/", getHome);
