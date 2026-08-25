import { Router } from "express";
import { requireAdmin } from "../auth/auth.middleware.js";
import {
  getChatDetalle,
  getChats,
  getContadores,
  getModeracion,
  postAprobarArchivo,
  postEliminarChat,
  postMarcarRevisadoDlp,
  postRestaurarChat,
} from "./adminChats.controller.js";

export const adminChatsRouter = Router();

adminChatsRouter.get("/contadores", requireAdmin, getContadores);
adminChatsRouter.get("/moderacion", requireAdmin, getModeracion);
adminChatsRouter.get("/:id", requireAdmin, getChatDetalle);
adminChatsRouter.get("/", requireAdmin, getChats);
adminChatsRouter.post("/:id/eliminar", requireAdmin, postEliminarChat);
adminChatsRouter.post("/:id/restaurar", requireAdmin, postRestaurarChat);
adminChatsRouter.post("/:id/marcar-revisado-dlp", requireAdmin, postMarcarRevisadoDlp);
adminChatsRouter.post("/moderacion/:msgId/aprobar", requireAdmin, postAprobarArchivo);
