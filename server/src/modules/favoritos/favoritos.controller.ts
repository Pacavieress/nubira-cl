import type { Request, Response } from "express";
import { mapServicioRow } from "../servicios/servicios.mapper.js";
import { getServicioById } from "../servicios/servicios.repository.js";
import {
  desmarcarFavorito,
  listarServicioIdsFavoritos,
  marcarFavorito,
} from "./favoritos.repository.js";

function parseServicioId(value: unknown): number | null {
  const n = Number(value);
  return Number.isInteger(n) && n > 0 ? n : null;
}

export async function putFavorito(req: Request, res: Response): Promise<void> {
  const servicioId = parseServicioId(req.params.servicioId);
  if (servicioId === null) {
    res.status(400).json({ error: "invalid_id" });
    return;
  }

  // req.usuarioId existe con certeza acá: requireAuth ya cortó con 401 si no había sesión.
  const usuarioId = req.usuarioId as number;

  const servicio = await getServicioById(servicioId);
  if (!servicio) {
    res.status(404).json({ error: "not_found" });
    return;
  }

  await marcarFavorito(usuarioId, servicioId);
  res.status(204).send();
}

// Asimetría intencional respecto a putFavorito (aprobada explícitamente): sin verificar
// que el servicio exista — un favorito viejo siempre debe poder quitarse, incluso si el
// servicio que apuntaba ya fue dado de baja. DELETE es idempotente por naturaleza.
export async function deleteFavorito(req: Request, res: Response): Promise<void> {
  const servicioId = parseServicioId(req.params.servicioId);
  if (servicioId === null) {
    res.status(400).json({ error: "invalid_id" });
    return;
  }

  const usuarioId = req.usuarioId as number;
  await desmarcarFavorito(usuarioId, servicioId);
  res.status(204).send();
}

export async function getFavoritos(req: Request, res: Response): Promise<void> {
  const usuarioId = req.usuarioId as number;
  const servicioIds = await listarServicioIdsFavoritos(usuarioId);

  // N+1 deliberado por ahora (volumen esperado bajo, decenas de favoritos como mucho) —
  // si esto crece, cambiar a un solo SELECT ... WHERE id IN (...) en servicios.repository.
  const servicios = await Promise.all(servicioIds.map((id) => getServicioById(id)));
  const encontrados = servicios.filter((s): s is NonNullable<typeof s> => s !== null);

  res.status(200).json({ data: encontrados.map(mapServicioRow) });
}
