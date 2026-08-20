import type { Request, Response } from "express";
import { mapLandingClases } from "./landings.mapper.js";
import { getLandingClasesRaw } from "./landings.repository.js";
import { CATEGORIAS_SEO } from "./landings.types.js";

export async function getLandingClases(req: Request, res: Response): Promise<void> {
  const slug = String(req.params.slug ?? "").toLowerCase().trim();
  const categoria = CATEGORIAS_SEO[slug];
  if (!categoria) {
    res.status(404).json({ error: "not_found" });
    return;
  }

  const raw = await getLandingClasesRaw(categoria);
  res.status(200).json(mapLandingClases(raw));
}
