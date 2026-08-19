import type { Request, Response } from "express";
import { mapApunteDetalleRow, mapApunteRow } from "./apuntes.mapper.js";
import { getApunteDetalleById, searchApuntesPublicos } from "./apuntes.repository.js";

const DEFAULT_LIMIT = 20;
const MAX_LIMIT = 50;

function parsePage(value: unknown): number {
  const n = Number(value);
  return Number.isInteger(n) && n >= 1 ? n : 1;
}

function parseLimit(value: unknown): number {
  const n = Number(value);
  if (!Number.isInteger(n) || n < 1) return DEFAULT_LIMIT;
  return Math.min(n, MAX_LIMIT);
}

function parseStringFilter(value: unknown): string | undefined {
  return typeof value === "string" && value.trim() !== "" ? value.trim() : undefined;
}

function parsePrecioFilter(value: unknown): "gratis" | "pagado" | undefined {
  return value === "gratis" || value === "pagado" ? value : undefined;
}

export async function getApuntesList(req: Request, res: Response): Promise<void> {
  const page = parsePage(req.query.page);
  const limit = parseLimit(req.query.limit);

  const { rows, hayMas } = await searchApuntesPublicos({
    nivel: parseStringFilter(req.query.nivel),
    precio: parsePrecioFilter(req.query.precio),
    orden: parseStringFilter(req.query.orden),
    q: parseStringFilter(req.query.q),
    page,
    limit,
  });

  res.status(200).json({
    data: rows.map(mapApunteRow),
    meta: { page, limit, hayMas },
  });
}

export async function getApunteDetail(req: Request, res: Response): Promise<void> {
  const id = Number(req.params.id);
  if (!Number.isInteger(id) || id <= 0) {
    res.status(400).json({ error: "invalid_id" });
    return;
  }

  const row = await getApunteDetalleById(id);
  if (!row) {
    res.status(404).json({ error: "not_found" });
    return;
  }

  // req.usuarioId lo pone optionalAuth (apuntes.routes.ts) SOLO si había una sesión
  // válida — undefined para un visitante, nunca un valor asumido por defecto.
  res.status(200).json(
    mapApunteDetalleRow(row, {
      isAuthenticated: req.usuarioId !== undefined,
      isOwner: req.usuarioId === row.id_alumno,
    }),
  );
}
