import { Router } from "express";
import { getImagenDesafioPost, postTrackShareDesafio } from "./compartir.controller.js";

// Público a propósito, sin requireAuth — ver nota en compartir.controller.ts.
export const compartirRouter = Router();

compartirRouter.get("/desafio/:slug/post", getImagenDesafioPost);
compartirRouter.post("/desafio/track", postTrackShareDesafio);
