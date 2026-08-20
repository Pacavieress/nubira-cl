import type { Request, Response } from "express";
import { mapMisContratos } from "./misContratos.mapper.js";
import { getContratosComoComprador, getContratosComoVendedor } from "./misContratos.repository.js";

export async function getMisContratos(req: Request, res: Response): Promise<void> {
  const usuarioId = req.usuarioId as number;
  const [compradorRows, vendedorRows] = await Promise.all([
    getContratosComoComprador(usuarioId),
    getContratosComoVendedor(usuarioId),
  ]);
  res.status(200).json(mapMisContratos(compradorRows, vendedorRows));
}
