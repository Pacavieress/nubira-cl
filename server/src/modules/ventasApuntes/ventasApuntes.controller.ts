import type { Request, Response } from "express";
import { mapVentaApunteRow } from "./ventasApuntes.mapper.js";
import { getVentasApuntesByVendedor } from "./ventasApuntes.repository.js";

export async function getMisVentasApuntes(req: Request, res: Response): Promise<void> {
  const vendedorId = req.usuarioId as number;
  const rows = await getVentasApuntesByVendedor(vendedorId);
  res.status(200).json({ data: rows.map(mapVentaApunteRow) });
}
