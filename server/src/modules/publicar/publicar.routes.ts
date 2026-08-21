import { Router } from "express";
import multer from "multer";
import { requireAuth } from "../auth/auth.middleware.js";
import { crearApunte, crearServicio, eliminarServicioIncompleto, guardarHorarioServicio } from "./publicar.controller.js";

// Memoria (no disco temporal) — el archivo se procesa entero en RAM antes de escribirse
// al destino final. Mismo límite de 40MB que formulario_subir_apunte.php:155 (ahí
// validado recién en el paso 2 tras el envío completo; multer corta ANTES de terminar
// de recibir el body si se excede, evitando gastar ancho de banda de más).
const uploadApunte = multer({ storage: multer.memoryStorage(), limits: { fileSize: 40 * 1024 * 1024 } });

export const publicarRouter = Router();

publicarRouter.post("/servicios", requireAuth, crearServicio);
publicarRouter.post("/servicios/:id/horario", requireAuth, guardarHorarioServicio);
publicarRouter.delete("/servicios/:id/incompleto", requireAuth, eliminarServicioIncompleto);
publicarRouter.post("/apuntes", requireAuth, uploadApunte.single("archivo"), crearApunte);
