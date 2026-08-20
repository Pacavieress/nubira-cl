import { env } from "../../config/env.js";
import { resolverPortada, resolverPortadaApunte } from "../../lib/media.js";
import type { ApunteMetricaRow, PublicacionMetrica, ServicioMetricaRow, Tendencia } from "./metricas.types.js";

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
