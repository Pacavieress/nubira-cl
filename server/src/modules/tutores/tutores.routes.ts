import { Router } from "express";
import { getTutorDetail } from "./tutores.controller.js";

export const tutoresRouter = Router();

tutoresRouter.get("/:id", getTutorDetail);
