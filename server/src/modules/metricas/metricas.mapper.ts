import { env } from "../../config/env.js";
import { resolverPortada, resolverPortadaApunte } from "../../lib/media.js";
import type {
  ApunteDetalleMetricaRow,
  ApunteMetricaRow,
  Delta,
  DetalleMetricaPublicacion,
  DispositivosStats,
  FunnelEtapa,
  OrigenStat,
  PublicacionMetrica,
  ServicioDetalleRow,
  ServicioMetricaRow,
  Tendencia,
} from "./metricas.types.js";

export function mapServicioMetricaRow(row: ServicioMetricaRow): PublicacionMetrica {
  return {
    id: row.id,
    tipo: "servicio",
    titulo: row.titulo,
    precio: row.precio === null ? null : Number(row.precio),
    imagenUrl: resolverPortada(row.banco_archivo, row.imagen, env.assetsBaseUrl).main,
    fechaOrden: row.fecha_publicacion ?? new Date(),
    visitas30d: 0,
    tendencia: null,
  };
}

// Reutiliza resolverPortadaApunte() (ya existente en media.ts, con el fix real de
// portada="{id}.webp" -> /upload/preview/) en vez de portear obtenerMiniaturaApunte() de
// portada_helper.php entero — ese helper agrega capas extra de fallback que dependen de
// is_file() contra el filesystem (legacy id.jpg/id.png/id.jpeg, portada buscada en 2
// carpetas distintas), fuera del alcance ya aceptado en media.ts: Node no valida
// existencia de archivos, construye la URL esperada de forma determinística. Mismo riesgo
// aceptado documentado ahí, no uno nuevo de esta pieza.
export function mapApunteMetricaRow(row: ApunteMetricaRow): PublicacionMetrica {
  return {
    id: row.id,
    tipo: "apunte",
    titulo: row.titulo,
    precio: row.precio === null ? null : Number(row.precio),
    imagenUrl: resolverPortadaApunte(row.id, row.portada, row.archivo, env.assetsBaseUrl),
    fechaOrden: row.fecha_subida ?? new Date(),
    visitas30d: 0,
    tendencia: null,
  };
}

// Puerto exacto de metricas.php:98-100: igual (incluido 0 vs 0) -> sin flecha, "no
// inventar movimiento".
export function computeTendencia(actual: number, anterior: number): Tendencia {
  if (actual > anterior) return "up";
  if (actual < anterior) return "down";
  return null;
}

// ============================================================================
// Detalle por publicación — puerto de metricas_detalle.php
// ============================================================================

export function mapServicioDetalle(row: ServicioDetalleRow): DetalleMetricaPublicacion {
  return {
    id: row.id,
    tipo: "servicio",
    titulo: row.titulo,
    precio: row.precio === null ? null : Number(row.precio),
    imagenUrl: resolverPortada(row.banco_archivo, row.imagen, env.assetsBaseUrl).main,
    // Relativo a propósito (no absoluto con phpSiteUrl) — mismo patrón que
    // MisPublicacionesLista.tsx/PerfilPropioCard.tsx, que arman la URL completa recién en
    // el cliente con su propio process.env.PHP_SITE_URL.
    editarHref: `/app/editar_servicio.php?id=${row.id}`,
  };
}

export function mapApunteDetalle(row: ApunteDetalleMetricaRow): DetalleMetricaPublicacion {
  return {
    id: row.id,
    tipo: "apunte",
    titulo: row.titulo,
    precio: row.precio === null ? null : Number(row.precio),
    imagenUrl: resolverPortadaApunte(row.id, row.portada, row.archivo, env.assetsBaseUrl),
    editarHref: `/app/editar_apunte.php?id=${row.id}`,
  };
}

// Puerto exacto de metricas_detalle.php:249-255 (det_delta_pct) — sin base matemática real
// cuando anterior<=0, se marca "Nuevo" en vez de forzar un porcentaje engañoso.
export function computeDeltaPct(actual: number, anterior: number): Delta | null {
  if (anterior <= 0) return actual > 0 ? { dir: "up", label: "Nuevo" } : null;
  const pct = Math.round(((actual - anterior) / anterior) * 100);
  if (pct === 0) return { dir: "flat", label: "0%" };
  return { dir: pct > 0 ? "up" : "down", label: `${pct > 0 ? "+" : ""}${pct}%` };
}

// Puerto exacto de metricas_detalle.php:259-264 (det_delta_pts) — delta en PUNTOS
// porcentuales (para comparar un valor que YA es un %, ej. pctLeyo), no un % relativo.
// `huboAnterior` replica el flag explícito del PHP real (no alcanza con anterior=0: un
// período anterior sin NINGÚN dato es distinto de un período con 0% de lectura completa).
export function computeDeltaPts(actual: number, anterior: number, huboAnterior: boolean): Delta | null {
  if (!huboAnterior) return null;
  const diff = Math.round((actual - anterior) * 10) / 10;
  if (diff === 0) return { dir: "flat", label: "0 pts" };
  return { dir: diff > 0 ? "up" : "down", label: `${diff > 0 ? "+" : ""}${diff} pts` };
}

// Puerto exacto de metricas_detalle.php:328-338 — 3 etapas para servicio (visitas
// identificadas -> chatearon -> contrataron), 2 para apunte (visitas identificadas ->
// compraron). Array vacío si no hay ninguna visita identificada (mismo criterio que
// `empty($funnel_etapas)` del PHP real).
export function buildFunnel(
  tipo: "servicio" | "apunte",
  visitasIdentificadas: number,
  chatearon: number,
  contrataron: number,
  compraron: number,
): FunnelEtapa[] {
  if (visitasIdentificadas <= 0) return [];
  const etapas: FunnelEtapa[] = [{ label: "Visitas identificadas", valor: visitasIdentificadas }];
  if (tipo === "servicio") {
    etapas.push({ label: "Iniciaron chat", valor: chatearon });
    etapas.push({ label: "Contrataron", valor: contrataron });
  } else {
    etapas.push({ label: "Compraron", valor: compraron });
  }
  return etapas;
}

// Puerto exacto de metricas_detalle.php:180-184 — rellena los 30 días consecutivos
// (incluidos los que no tuvieron ninguna visita, como 0) a partir del mapa disperso que
// devuelve la query GROUP BY. Mismo `for ($i = 29; $i >= 0; $i--)` del PHP real, orden más
// antiguo -> hoy.
export function buildVisitasPorDia(mapaPorDia: Map<string, number>): number[] {
  const valores: number[] = [];
  for (let i = 29; i >= 0; i--) {
    const fecha = new Date();
    fecha.setDate(fecha.getDate() - i);
    const clave = `${fecha.getFullYear()}-${String(fecha.getMonth() + 1).padStart(2, "0")}-${String(fecha.getDate()).padStart(2, "0")}`;
    valores.push(mapaPorDia.get(clave) ?? 0);
  }
  return valores;
}

export function mapDispositivos(mapa: Map<string, number>): DispositivosStats {
  return {
    movil: mapa.get("movil") ?? 0,
    tablet: mapa.get("tablet") ?? 0,
    desktop: mapa.get("desktop") ?? 0,
  };
}

// Puerto exacto de metricas_detalle.php:282-286 (det_parse_origen) — extrae el host de una
// URL de referrer y le saca el prefijo "www."; sin origen real, "Directo".
export function parseOrigen(origenCrudo: string): string {
  try {
    const host = new URL(origenCrudo).hostname;
    return host.replace(/^www\./, "");
  } catch {
    return origenCrudo || "Directo";
  }
}

export function mapOrigenes(rows: OrigenStat[]): OrigenStat[] {
  return rows.map((r) => ({ origen: parseOrigen(r.origen), total: r.total }));
}
