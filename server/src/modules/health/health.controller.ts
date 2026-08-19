import type { Request, Response } from "express";
import { checkDbConnection } from "../../db/pool.js";

export async function getHealth(_req: Request, res: Response): Promise<void> {
  const dbOk = await checkDbConnection();
  res.status(200).json({
    status: "ok",
    uptime: process.uptime(),
    db: dbOk ? "ok" : "error",
  });
}
