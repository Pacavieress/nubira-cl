import { Router } from "express";
import { requireAuth } from "../auth/auth.middleware.js";
import {
  crearMiTicket,
  eliminarMisTickets,
  getMisTickets,
  marcarMiTicketLeido,
  responderMiTicket,
  resolverMiTicket,
} from "./soporte.controller.js";

export const soporteRouter = Router();

soporteRouter.get("/", requireAuth, getMisTickets);
soporteRouter.post("/", requireAuth, crearMiTicket);
soporteRouter.post("/eliminar", requireAuth, eliminarMisTickets);
soporteRouter.post("/:id/responder", requireAuth, responderMiTicket);
soporteRouter.post("/:id/resolver", requireAuth, resolverMiTicket);
soporteRouter.post("/:id/leido", requireAuth, marcarMiTicketLeido);
