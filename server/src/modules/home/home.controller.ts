import type { Request, Response } from "express";
import { mapHomeData } from "./home.mapper.js";
import { getHomeDataRaw } from "./home.repository.js";

export async function getHome(_req: Request, res: Response): Promise<void> {
  const raw = await getHomeDataRaw();
  res.status(200).json(mapHomeData(raw));
}
