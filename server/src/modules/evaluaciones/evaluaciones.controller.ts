import type { Request, Response } from "express";
import { mapEvaluacionRow } from "./evaluaciones.mapper.js";
import { getEvaluacionesPorRol } from "./evaluaciones.repository.js";
import type { MisEvaluacionesPublico } from "./evaluaciones.types.js";

// req.usuarioId existe con certeza acá: requireAuth (ver evaluaciones.routes.ts) ya cortó
// con 401 si no había sesión — mismo patrón que favoritos/compras.
export async function getMisEvaluaciones(req: Request, res: Response): Promise<void> {
  const usuarioId = req.usuarioId as number;

  const [vendedorRows, compradorRows] = await Promise.all([
    getEvaluacionesPorRol(usuarioId, "vendedor"),
    getEvaluacionesPorRol(usuarioId, "comprador"),
  ]);

  const body: MisEvaluacionesPublico = {
    resenasComoTutor: vendedorRows.map(mapEvaluacionRow),
    resenasComoAlumno: compradorRows.map(mapEvaluacionRow),
  };
  res.status(200).json(body);
}
