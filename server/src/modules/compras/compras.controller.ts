import type { Request, Response } from "express";
import { mapApunteCompradoRow, mapServicioContratadoRow } from "./compras.mapper.js";
import { getApuntesCompradosByUsuario, getServiciosContratadosByUsuario } from "./compras.repository.js";
import type { MisComprasPublico } from "./compras.types.js";

// req.usuarioId existe con certeza acá: requireAuth (ver compras.routes.ts) ya cortó con
// 401 si no había sesión — mismo patrón que favoritos.controller.ts.
export async function getMisCompras(req: Request, res: Response): Promise<void> {
  const usuarioId = req.usuarioId as number;

  const [apuntesRows, serviciosRows] = await Promise.all([
    getApuntesCompradosByUsuario(usuarioId),
    getServiciosContratadosByUsuario(usuarioId),
  ]);

  const body: MisComprasPublico = {
    apuntes: apuntesRows.map(mapApunteCompradoRow),
    servicios: serviciosRows.map(mapServicioContratadoRow),
  };
  res.status(200).json(body);
}
