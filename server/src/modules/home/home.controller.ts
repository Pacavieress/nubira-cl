import type { Request, Response } from "express";
import { viewerCalificaDemo } from "../../lib/demoTutorVisibility.js";
import { mapHomeData } from "./home.mapper.js";
import { getHomeDataRaw } from "./home.repository.js";

export async function getHome(req: Request, res: Response): Promise<void> {
  const viewerCalifica = await viewerCalificaDemo(req.usuarioId);
  const raw = await getHomeDataRaw(viewerCalifica);
  res.status(200).json(mapHomeData(raw));
}
