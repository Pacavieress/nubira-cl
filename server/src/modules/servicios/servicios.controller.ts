import type { Request, Response } from "express";
import { getUsuarioConRol } from "../auth/auth.repository.js";
import { existeFavorito } from "../favoritos/favoritos.repository.js";
import { mapServicioDetalleRow, mapServicioRow } from "./servicios.mapper.js";
import {
  getContratoActivo,
  getMinutosRespuestaTutor,
  getRecomendaciones,
  getServicioDetalleByIdSinFiltro,
  getValoracionesByServicioId,
  searchServiciosAprobados,
  tutorEstaEnClase,
} from "./servicios.repository.js";

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

  // Puerto exacto de detalle_servicio.php:86-121: el servicio se busca SIN filtrar por
  // estado/visible/bloqueado (a diferencia del listado) — la restricción de acceso se
  // aplica después, en código, porque el dueño (o un admin) SÍ puede ver un servicio no
  // aprobado (banner "En Revisión"/"Publicación Pausada"). Un visitante cualquiera sigue
  // recibiendo 404 igual que antes para cualquier servicio no aprobado.
  const row = await getServicioDetalleByIdSinFiltro(id);
  if (!row) {
    res.status(404).json({ error: "not_found" });
    return;
  }

  const isOwner = req.usuarioId === row.alumno_id;
  const isAuthenticated = req.usuarioId !== undefined;

  let isAdmin = false;
  if (isAuthenticated && !isOwner && row.estado !== "aprobado") {
    const usuario = await getUsuarioConRol(req.usuarioId!);
    isAdmin = usuario?.rol === "admin";
  }

  if (row.estado !== "aprobado" && !isOwner && !isAdmin) {
    res.status(404).json({ error: "not_found" });
    return;
  }

  const [valoraciones, minutosRespuesta, esFavorito, contratoId, recomendaciones, tutorEnClase] = await Promise.all([
    getValoracionesByServicioId(id),
    getMinutosRespuestaTutor(row.alumno_id),
    isAuthenticated ? existeFavorito(req.usuarioId!, id) : Promise.resolve(false),
    isAuthenticated ? getContratoActivo(id, req.usuarioId!) : Promise.resolve(null),
    getRecomendaciones(id, row.categoria),
    tutorEstaEnClase(row.alumno_id),
  ]);

  // req.usuarioId lo pone optionalAuth (servicios.routes.ts) SOLO si había una sesión
  // válida — undefined para un visitante, nunca un valor asumido por defecto.
  res.status(200).json(
    mapServicioDetalleRow(
      row,
      { isAuthenticated, isOwner, esFavorito, contratoId },
      valoraciones,
      minutosRespuesta,
      recomendaciones,
      tutorEnClase,
    ),
  );
}
