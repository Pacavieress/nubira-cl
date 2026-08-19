import type { Request, Response } from "express";
import { searchServiciosAprobados } from "../servicios/servicios.repository.js";
import { mapTutorRow } from "./tutores.mapper.js";
import { getTutorById } from "./tutores.repository.js";

export async function getTutorDetail(req: Request, res: Response): Promise<void> {
  const id = Number(req.params.id);
  if (!Number.isInteger(id) || id <= 0) {
    res.status(400).json({ error: "invalid_id" });
    return;
  }

  const tutor = await getTutorById(id);
  if (!tutor) {
    res.status(404).json({ error: "not_found" });
    return;
  }

  const { rows: servicios } = await searchServiciosAprobados({ alumnoId: id, page: 1, limit: 50 });

  res.status(200).json(mapTutorRow(tutor, servicios));
}
