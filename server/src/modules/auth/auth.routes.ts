import { Router } from "express";
import { requireAuth } from "./auth.middleware.js";

export const authRouter = Router();

authRouter.get("/me", requireAuth, (req, res) => {
  res.status(200).json({ usuarioId: req.usuarioId });
});
