import type { NextFunction, Request, Response } from "express";
import { logger } from "./logger.js";

export function notFoundHandler(_req: Request, res: Response): void {
  res.status(404).json({ error: "not_found" });
}

// Express identifica el error handler por su firma de 4 parámetros — _next debe
// existir en la firma aunque no se use, o Express no lo reconoce como error handler.
export function errorHandler(err: unknown, req: Request, res: Response, _next: NextFunction): void {
  logger.error("Error no manejado", {
    path: req.path,
    method: req.method,
    error: err instanceof Error ? err.message : String(err),
  });
  res.status(500).json({ error: "internal_error" });
}
