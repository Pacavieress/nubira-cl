import { Router } from "express";
import multer from "multer";
import { requireAuth } from "../auth/auth.middleware.js";
import {
  deletePresenciaAula,
  getArchivoAulaHandler,
  getArchivosAulaHandler,
  getAulaDetalleHandler,
  getEstadoAulaHandler,
  getMensajesAulaHandler,
  getPresenciaAulaHandler,
  postEnviarMensajeAula,
  postPresenciaAula,
  postSubirArchivoAula,
  postTypingAula,
} from "./aula.controller.js";

// Mismo límite (50MB) que entregas_servicio.php — multer corta antes, subirArchivoContrato
// vuelve a validar por si acaso (mismo criterio de doble validación que publicar.routes.ts).
const uploadMaterial = multer({ storage: multer.memoryStorage(), limits: { fileSize: 50 * 1024 * 1024 } });

export const aulaRouter = Router();

aulaRouter.get("/archivo/:archivoId", requireAuth, getArchivoAulaHandler);

aulaRouter.get("/:id", requireAuth, getAulaDetalleHandler);
aulaRouter.get("/:id/estado", requireAuth, getEstadoAulaHandler);
aulaRouter.get("/:id/mensajes", requireAuth, getMensajesAulaHandler);
aulaRouter.post("/:id/mensajes", requireAuth, postEnviarMensajeAula);
aulaRouter.post("/:id/typing", requireAuth, postTypingAula);
aulaRouter.get("/:id/presencia", requireAuth, getPresenciaAulaHandler);
aulaRouter.post("/:id/presencia", requireAuth, postPresenciaAula);
aulaRouter.delete("/:id/presencia", requireAuth, deletePresenciaAula);
aulaRouter.get("/:id/archivos", requireAuth, getArchivosAulaHandler);
aulaRouter.post("/:id/archivos", requireAuth, uploadMaterial.single("archivo"), postSubirArchivoAula);
