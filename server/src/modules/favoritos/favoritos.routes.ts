import { Router } from "express";
import { requireAuth } from "../auth/auth.middleware.js";
import { deleteFavorito, getFavoritos, putFavorito } from "./favoritos.controller.js";

export const favoritosRouter = Router();

favoritosRouter.get("/", requireAuth, getFavoritos);
favoritosRouter.put("/:servicioId", requireAuth, putFavorito);
favoritosRouter.delete("/:servicioId", requireAuth, deleteFavorito);
