import { Router } from "express";
import {
  getImagenApuntePost,
  getImagenDesafioPost,
  getImagenDesafioPreguntas,
  getImagenNovedadHistory,
  getImagenNovedadPost,
  getImagenServicioPost,
  postTrackShareApunte,
  postTrackShareDesafio,
  postTrackShareServicio,
} from "./compartir.controller.js";

// Público a propósito, sin requireAuth — ver nota en compartir.controller.ts.
export const compartirRouter = Router();

compartirRouter.get("/desafio/:slug/post", getImagenDesafioPost);
compartirRouter.get("/desafio-preguntas/:ids/history", getImagenDesafioPreguntas);
compartirRouter.post("/desafio/track", postTrackShareDesafio);

compartirRouter.get("/apunte/:id/post", getImagenApuntePost);
compartirRouter.post("/apunte/track", postTrackShareApunte);

compartirRouter.get("/servicio/:id/post", getImagenServicioPost);
compartirRouter.post("/servicio/track", postTrackShareServicio);

// Puerto de app/img_novedad.php — usado por el panel Marketing/Cards (adminMarketingCards),
// no por ningún flujo de compartir de usuario final (a diferencia de los 3 de arriba) — vive
// acá de todos modos porque es arquitectónicamente idéntico (imagen pública cacheada por
// fingerprint, sin sesión) y así no se duplica el patrón cache-en-disco en otro módulo.
compartirRouter.get("/novedad/:id/post", getImagenNovedadPost);
compartirRouter.get("/novedad/:id/history", getImagenNovedadHistory);
