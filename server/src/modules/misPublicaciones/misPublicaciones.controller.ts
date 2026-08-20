import type { Request, Response } from "express";
import { mapMisPublicaciones } from "./misPublicaciones.mapper.js";
import {
  getApuntesPublicadosByAlumno,
  getServiciosPublicadosByAlumno,
  ocultarApunte,
  ocultarServicio,
  reactivarServicio,
} from "./misPublicaciones.repository.js";

function parseId(value: unknown): number | null {
  const n = Number(value);
  return Number.isInteger(n) && n > 0 ? n : null;
}

export async function getMisPublicaciones(req: Request, res: Response): Promise<void> {
  const alumnoId = req.usuarioId as number;
  const [serviciosRows, apuntesRows] = await Promise.all([
    getServiciosPublicadosByAlumno(alumnoId),
    getApuntesPublicadosByAlumno(alumnoId),
  ]);
  res.status(200).json(mapMisPublicaciones(serviciosRows, apuntesRows));
}

// Sin distinción 404 vs "no es tuyo" — mismo comportamiento silencioso del PHP real (ver
// misPublicaciones.repository.ts::ocultarServicio). 204 siempre que el UPDATE no lance
// excepción, sin importar si afectó 0 o 1 filas.
export async function eliminarServicioPublicado(req: Request, res: Response): Promise<void> {
  const id = parseId(req.params.id);
  if (id === null) {
    res.status(400).json({ error: "invalid_id" });
    return;
  }
  await ocultarServicio(id, req.usuarioId as number);
  res.status(204).send();
}

export async function reactivarServicioPublicado(req: Request, res: Response): Promise<void> {
  const id = parseId(req.params.id);
  if (id === null) {
    res.status(400).json({ error: "invalid_id" });
    return;
  }
  await reactivarServicio(id, req.usuarioId as number);
  res.status(204).send();
}

export async function eliminarApuntePublicado(req: Request, res: Response): Promise<void> {
  const id = parseId(req.params.id);
  if (id === null) {
    res.status(400).json({ error: "invalid_id" });
    return;
  }
  await ocultarApunte(id, req.usuarioId as number);
  res.status(204).send();
}
