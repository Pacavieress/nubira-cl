import { Router } from "express";
import { getImagenApuntePost, getImagenDesafioPost, getImagenDesafioPreguntas, postTrackShareApunte, postTrackShareDesafio } from "./compartir.controller.js";

// Público a propósito, sin requireAuth — ver nota en compartir.controller.ts.
export const compartirRouter = Router();

compartirRouter.get("/desafio/:slug/post", getImagenDesafioPost);
compartirRouter.get("/desafio-preguntas/:ids/history", getImagenDesafioPreguntas);
compartirRouter.post("/desafio/track", postTrackShareDesafio);

compartirRouter.get("/apunte/:id/post", getImagenApuntePost);
compartirRouter.post("/apunte/track", postTrackShareApunte);
