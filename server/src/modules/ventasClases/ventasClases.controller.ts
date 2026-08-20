import type { Request, Response } from "express";
import { mapVentaClaseRow } from "./ventasClases.mapper.js";
import { getVentasClasesByVendedor } from "./ventasClases.repository.js";

// req.usuarioId existe con certeza acá: requireAuth (ver ventasClases.routes.ts) ya cortó
// con 401 si no había sesión.
export async function getMisVentasClases(req: Request, res: Response): Promise<void> {
  const vendedorId = req.usuarioId as number;
  const rows = await getVentasClasesByVendedor(vendedorId);
  res.status(200).json({ data: rows.map(mapVentaClaseRow) });
}
