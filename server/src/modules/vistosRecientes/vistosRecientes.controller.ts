import type { Request, Response } from "express";
import { mapVistosRecientes } from "./vistosRecientes.mapper.js";
import { fetchVistosRecientes } from "./vistosRecientes.repository.js";

export async function getVistosRecientes(req: Request, res: Response): Promise<void> {
  // req.usuarioId existe con certeza acá: requireAuth ya cortó con 401 si no había sesión.
  const usuarioId = req.usuarioId as number;
  const items = await fetchVistosRecientes(usuarioId);
  res.status(200).json({ data: mapVistosRecientes(items) });
}
