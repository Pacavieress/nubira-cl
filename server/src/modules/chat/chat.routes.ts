import { Router } from "express";
import { requireAuth } from "../auth/auth.middleware.js";
import {
  getArchivoChat,
  getBandejaHandler,
  getChatDetalleHandler,
  getMensajesHandler,
  postEliminarChats,
  postEnviarMensaje,
  postIniciarChat,
  postTyping,
} from "./chat.controller.js";

export const chatRouter = Router();

// Rutas literales primero (bandeja, iniciar, archivo) — antes de los patrones /:id, para
// que ningún segmento literal pueda confundirse con un id de chat.
chatRouter.get("/bandeja", requireAuth, getBandejaHandler);
chatRouter.post("/bandeja/eliminar", requireAuth, postEliminarChats);
chatRouter.post("/iniciar", requireAuth, postIniciarChat);
chatRouter.get("/archivo/:mensajeId", requireAuth, getArchivoChat);

chatRouter.get("/:id", requireAuth, getChatDetalleHandler);
chatRouter.get("/:id/mensajes", requireAuth, getMensajesHandler);
chatRouter.post("/:id/mensajes", requireAuth, postEnviarMensaje);
chatRouter.post("/:id/typing", requireAuth, postTyping);
