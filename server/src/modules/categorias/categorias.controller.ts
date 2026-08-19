import type { Request, Response } from "express";
import { listCategorias } from "./categorias.repository.js";

export async function getCategorias(_req: Request, res: Response): Promise<void> {
  const categorias = await listCategorias();
  res.status(200).json({ data: categorias });
}
