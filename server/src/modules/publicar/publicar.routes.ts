import { Router } from "express";
import multer from "multer";
import { requireAuth } from "../auth/auth.middleware.js";
import { crearApunte, crearServicio, eliminarServicioIncompleto, guardarHorarioServicio, subirVideoServicio } from "./publicar.controller.js";

// Memoria (no disco temporal) — el archivo se procesa entero en RAM antes de escribirse
// al destino final. Mismo límite de 40MB que formulario_subir_apunte.php:155 (ahí
// validado recién en el paso 2 tras el envío completo; multer corta ANTES de terminar
// de recibir el body si se excede, evitando gastar ancho de banda de más). El límite
// aplica por archivo — "preview" (el blob de portada renderizado client-side para PDF,
// ver web/src/lib/pdfPreview.ts) nunca se acerca a ese tamaño en la práctica.
const uploadApunte = multer({ storage: multer.memoryStorage(), limits: { fileSize: 40 * 1024 * 1024 } });

// Límite de 30MB — mismo tope que app/subir_video_servicio.php:92 (el thumb, ≤2MB, cabe
// cómodo dentro de este mismo límite compartido; se valida su propio tope aparte en el
// controller, igual que el PHP real).
const uploadVideo = multer({ storage: multer.memoryStorage(), limits: { fileSize: 30 * 1024 * 1024 } });

export const publicarRouter = Router();

publicarRouter.post("/servicios", requireAuth, crearServicio);
publicarRouter.post("/servicios/:id/horario", requireAuth, guardarHorarioServicio);
publicarRouter.delete("/servicios/:id/incompleto", requireAuth, eliminarServicioIncompleto);
publicarRouter.post(
  "/servicios/:id/video",
  requireAuth,
  uploadVideo.fields([
    { name: "video", maxCount: 1 },
    { name: "thumb", maxCount: 1 },
  ]),
  subirVideoServicio,
);
publicarRouter.post(
  "/apuntes",
  requireAuth,
  uploadApunte.fields([
    { name: "archivo", maxCount: 1 },
    { name: "preview", maxCount: 1 },
  ]),
  crearApunte,
);
