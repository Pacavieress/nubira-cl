import { Router } from "express";
import { getUsuarioConRol } from "./auth.repository.js";
import { requireAuth } from "./auth.middleware.js";

export const authRouter = Router();

// req.usuarioId siempre está poblado acá (requireAuth ya lo garantizó o cortó con 401
// antes de llegar). rol/esAdmin se agregan para que web/ pueda decidir acceso a /admin
// sin necesitar un segundo roundtrip — mismo criterio "fresco, no cacheado" que
// requireAdmin (ver auth.repository.ts::getUsuarioConRol).
authRouter.get("/me", requireAuth, async (req, res) => {
  const usuario = await getUsuarioConRol(req.usuarioId!);
  const esAdmin = usuario !== null && usuario.rol === "admin" && usuario.visible === 1 && usuario.bloqueado === 0;
  res.status(200).json({ usuarioId: req.usuarioId, rol: usuario?.rol ?? null, esAdmin });
});
