import { Router } from "express";
import { getCategorias } from "./categorias.controller.js";

export const categoriasRouter = Router();

categoriasRouter.get("/", getCategorias);
