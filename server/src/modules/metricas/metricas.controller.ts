import type { Request, Response } from "express";
import { computeTendencia, mapApunteMetricaRow, mapServicioMetricaRow } from "./metricas.mapper.js";
import {
  getApuntesParaMetricas,
  getServiciosParaMetricas,
  getVisitas30d,
  getVisitasPrevias30d,
} from "./metricas.repository.js";
import type { PublicacionMetrica } from "./metricas.types.js";

export async function getMisMetricas(req: Request, res: Response): Promise<void> {
  const alumnoId = req.usuarioId as number;

  const [serviciosRows, apuntesRows] = await Promise.all([
    getServiciosParaMetricas(alumnoId),
    getApuntesParaMetricas(alumnoId),
  ]);

  const publicaciones: PublicacionMetrica[] = [
    ...serviciosRows.map(mapServicioMetricaRow),
    ...apuntesRows.map(mapApunteMetricaRow),
  ];
  // Puerto exacto de metricas.php:51 (usort por fecha_orden descendente, servicios y
  // apuntes mezclados en una sola lista cronológica).
  publicaciones.sort((a, b) => b.fechaOrden.getTime() - a.fechaOrden.getTime());

  const serviciosIds = serviciosRows.map((r) => r.id);
  const apuntesIds = apuntesRows.map((r) => r.id);

  const [visitasServicios, visitasServiciosPrev, visitasApuntes, visitasApuntesPrev] = await Promise.all([
    getVisitas30d("servicio", serviciosIds),
    getVisitasPrevias30d("servicio", serviciosIds),
    getVisitas30d("apunte", apuntesIds),
    getVisitasPrevias30d("apunte", apuntesIds),
  ]);

  for (const pub of publicaciones) {
    const [mapaActual, mapaPrevio] = pub.tipo === "servicio" ? [visitasServicios, visitasServiciosPrev] : [visitasApuntes, visitasApuntesPrev];
    const actual = mapaActual.get(pub.id) ?? 0;
    const anterior = mapaPrevio.get(pub.id) ?? 0;
    pub.visitas30d = actual;
    pub.tendencia = computeTendencia(actual, anterior);
  }

  res.status(200).json({ data: publicaciones });
}
