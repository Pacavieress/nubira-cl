import type { Request, Response } from "express";
import {
  buildFunnel,
  buildVisitasPorDia,
  computeDeltaPct,
  computeDeltaPts,
  computeTendencia,
  mapApunteDetalle,
  mapApunteMetricaRow,
  mapDispositivos,
  mapOrigenes,
  mapServicioDetalle,
  mapServicioMetricaRow,
} from "./metricas.mapper.js";
import {
  getApunteParaDetalleMetrica,
  getApuntesParaMetricas,
  getDispositivosRaw,
  getFunnelChatearon,
  getFunnelCompraron,
  getFunnelContrataron,
  getOrigenesRaw,
  getServicioParaDetalleMetrica,
  getServiciosParaMetricas,
  getStats30d,
  getStatsPeriodoAnterior,
  getUbicaciones,
  getVisitas30d,
  getVisitasIdentificadas30d,
  getVisitasPorDiaRaw,
  getVisitasPrevias30d,
  getVisitasTotalHistorico,
} from "./metricas.repository.js";
import type { MetricaDetalle, PublicacionMetrica, TipoPublicacion } from "./metricas.types.js";

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

// Puerto completo de metricas_detalle.php — mismo gate de ownership (redirect a /metricas
// en el PHP real si no es tuya o no existe; acá 404, más correcto para una API) y misma
// batería de queries sobre `vistas_detalle`, corridas en paralelo donde no dependen entre
// sí (el PHP real las corre secuenciales, una tras otra — acá no hay razón para replicar
// esa serialización, ninguna depende del resultado de otra salvo el funnel, que sí espera
// a `visitasIdentificadas`).
export async function getMiMetricaDetalle(req: Request, res: Response): Promise<void> {
  const alumnoId = req.usuarioId as number;
  const tipo = req.params.tipo as string;
  const id = Number(req.params.id);

  if ((tipo !== "servicio" && tipo !== "apunte") || !Number.isInteger(id) || id <= 0) {
    res.status(400).json({ error: "parametros_invalidos" });
    return;
  }
  const tipoPub = tipo as TipoPublicacion;

  let publicacion;
  if (tipoPub === "servicio") {
    const row = await getServicioParaDetalleMetrica(id, alumnoId);
    if (!row) {
      res.status(404).json({ error: "not_found" });
      return;
    }
    publicacion = mapServicioDetalle(row);
  } else {
    const row = await getApunteParaDetalleMetrica(id, alumnoId);
    if (!row) {
      res.status(404).json({ error: "not_found" });
      return;
    }
    publicacion = mapApunteDetalle(row);
  }

  const [stats30d, statsPrev, visitasTotal, visitasIdentificadas, diaMapa, dispositivosMapa, origenesRaw, ubicaciones] = await Promise.all([
    getStats30d(tipoPub, id),
    getStatsPeriodoAnterior(tipoPub, id),
    getVisitasTotalHistorico(tipoPub, id),
    getVisitasIdentificadas30d(tipoPub, id),
    getVisitasPorDiaRaw(tipoPub, id),
    getDispositivosRaw(tipoPub, id),
    getOrigenesRaw(tipoPub, id),
    getUbicaciones(tipoPub, id),
  ]);

  let chatearon = 0;
  let contrataron = 0;
  let compraron = 0;
  if (visitasIdentificadas > 0) {
    if (tipoPub === "servicio") {
      [chatearon, contrataron] = await Promise.all([getFunnelChatearon(id), getFunnelContrataron(id)]);
    } else {
      compraron = await getFunnelCompraron(id);
    }
  }

  const huboAnterior = statsPrev.total > 0;
  const body: MetricaDetalle = {
    publicacion,
    visitas30d: stats30d.total,
    deltaVisitas: computeDeltaPct(stats30d.total, statsPrev.total),
    tiempoPromedioSegundos: stats30d.tiempo_prom,
    deltaTiempo: computeDeltaPct(stats30d.tiempo_prom, statsPrev.tiempo_prom),
    pctLeyo: stats30d.pct_leyo,
    deltaLeyo: computeDeltaPts(stats30d.pct_leyo, statsPrev.pct_leyo, huboAnterior),
    visitasTotal,
    funnel: buildFunnel(tipoPub, visitasIdentificadas, chatearon, contrataron, compraron),
    visitasPorDia: buildVisitasPorDia(diaMapa),
    dispositivos: mapDispositivos(dispositivosMapa),
    origenes: mapOrigenes(origenesRaw),
    ubicaciones,
  };
  res.status(200).json(body);
}
