import type { NextFunction, Request, Response } from "express";
import { findUsuarioIdBySessionId, getUsuarioConRol } from "./auth.repository.js";

declare global {
  namespace Express {
    interface Request {
      usuarioId?: number;
    }
  }
}

// Falla cerrado en 2 capas: sin cookie -> 401 sin tocar la BD; con cookie pero sin fila
// vigente en sesiones_api -> 401. req.usuarioId SOLO se asigna en el único camino donde
// ambas condiciones pasaron. No hay ningún default ni fallback a un usuario asumido.
export async function requireAuth(req: Request, res: Response, next: NextFunction): Promise<void> {
  const sessionId = req.cookies?.PHPSESSID;

  if (typeof sessionId !== "string" || sessionId === "") {
    res.status(401).json({ error: "no_autenticado" });
    return;
  }

  const usuarioId = await findUsuarioIdBySessionId(sessionId);

  if (usuarioId === null) {
    res.status(401).json({ error: "no_autenticado" });
    return;
  }

  req.usuarioId = usuarioId;
  next();
}

// Puerto de admin_panel.php:3 ($_SESSION['rol'] !== 'admin' -> redirect a /login.php),
// pero consultando alumnos.rol FRESCO en vez de un valor cacheado en sesión — ver
// getUsuarioConRol() en auth.repository.ts para el porqué. Falla cerrado en 3 capas:
// sin cookie o cookie inválida -> 401 (mismo criterio que requireAuth); cookie válida
// pero rol!=='admin' o cuenta invisible/bloqueada -> 403. req.usuarioId solo se asigna
// cuando las 3 condiciones pasan.
export async function requireAdmin(req: Request, res: Response, next: NextFunction): Promise<void> {
  const sessionId = req.cookies?.PHPSESSID;

  if (typeof sessionId !== "string" || sessionId === "") {
    res.status(401).json({ error: "no_autenticado" });
    return;
  }

  const usuarioId = await findUsuarioIdBySessionId(sessionId);
  if (usuarioId === null) {
    res.status(401).json({ error: "no_autenticado" });
    return;
  }

  const usuario = await getUsuarioConRol(usuarioId);
  if (!usuario || usuario.rol !== "admin" || usuario.visible !== 1 || usuario.bloqueado !== 0) {
    res.status(403).json({ error: "no_autorizado" });
    return;
  }

  req.usuarioId = usuarioId;
  next();
}

// Variante NO bloqueante para endpoints públicos (detalle de servicio, perfil de tutor)
// que solo quieren enriquecer la respuesta si hay sesión, sin exigirla — a diferencia de
// requireAuth: no existe NINGÚN res.status(...) en esta función, ni ningún "return" sin
// haber llamado antes a next(). next() se llama exactamente una vez, al final, sin
// importar qué rama se haya tomado. Peor caso posible si algo falla: la request sigue
// como visitante anónimo (req.usuarioId queda undefined) — nunca puede bloquear a nadie.
export async function optionalAuth(req: Request, _res: Response, next: NextFunction): Promise<void> {
  const sessionId = req.cookies?.PHPSESSID;

  if (typeof sessionId === "string" && sessionId !== "") {
    const usuarioId = await findUsuarioIdBySessionId(sessionId);
    if (usuarioId !== null) {
      req.usuarioId = usuarioId;
    }
  }

  next();
}
