import type { Request, Response } from "express";
import { mapServicioDetalleRow, mapServicioRow } from "./servicios.mapper.js";
import { getServicioDetalleById, searchServiciosAprobados } from "./servicios.repository.js";

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

export async function getServiciosList(req: Request, res: Response): Promise<void> {
  const page = parsePage(req.query.page);
  const limit = parseLimit(req.query.limit);

  const { rows, hayMas } = await searchServiciosAprobados({
    categoria: parseStringFilter(req.query.categoria),
    modalidad: parseStringFilter(req.query.modalidad),
    institucion: parseStringFilter(req.query.institucion),
    q: parseStringFilter(req.query.q),
    page,
    limit,
  });

  res.status(200).json({
    data: rows.map(mapServicioRow),
    meta: { page, limit, hayMas },
  });
}

export async function getServicioDetail(req: Request, res: Response): Promise<void> {
  const id = Number(req.params.id);
  if (!Number.isInteger(id) || id <= 0) {
    res.status(400).json({ error: "invalid_id" });
    return;
  }

  const row = await getServicioDetalleById(id);
  if (!row) {
    res.status(404).json({ error: "not_found" });
    return;
  }

  // req.usuarioId lo pone optionalAuth (servicios.routes.ts) SOLO si había una sesión
  // válida — undefined para un visitante, nunca un valor asumido por defecto.
  res.status(200).json(
    mapServicioDetalleRow(row, {
      isAuthenticated: req.usuarioId !== undefined,
      isOwner: req.usuarioId === row.alumno_id,
    }),
  );
}
