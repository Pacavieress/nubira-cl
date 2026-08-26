// Puerto de app/metricas.php:21-101 — la lista (servicios + apuntes aprobados/visibles
// del usuario, LIMIT 60 cada uno, ordenados por fecha DESC), con visitas de los últimos 30
// días + tendencia contra los 30 días anteriores.
//
// [26/08/2026] Grupo C — se agregó también app/metricas_detalle.php (582 líneas, la
// página de detalle por publicación /metricas/:tipo/:id): funnel de conversión, gráfico de
// visitas por día, dispositivos, orígenes y ubicación, todo sobre la misma tabla
// `vistas_detalle` que ya se consultaba acá para el resumen — ver los tipos DetalleMetrica*
// más abajo.

export type TipoPublicacion = "servicio" | "apunte";

export interface ServicioMetricaRow {
  id: number;
  titulo: string;
  precio: string | null;
  imagen: string | null;
  imagen_banco_id: number | null;
  banco_archivo: string | null;
  fecha_publicacion: Date | null;
}

export interface ApunteMetricaRow {
  id: number;
  titulo: string;
  precio: string | null;
  portada: string | null;
  archivo: string | null;
  fecha_subida: Date | null;
}

export type Tendencia = "up" | "down" | null;

export interface PublicacionMetrica {
  id: number;
  tipo: TipoPublicacion;
  titulo: string;
  precio: number | null;
  imagenUrl: string;
  fechaOrden: Date;
  visitas30d: number;
  tendencia: Tendencia;
}

// --- Detalle por publicación (metricas_detalle.php) ---

// Fila de propiedad — puerto exacto de metricas_detalle.php:31/39 (mismo SELECT, mismo
// WHERE de ownership + estado='aprobado').
export interface ServicioDetalleRow {
  id: number;
  titulo: string;
  precio: string | null;
  imagen: string | null;
  imagen_banco_id: number | null;
  banco_archivo: string | null;
}

export interface ApunteDetalleMetricaRow {
  id: number;
  titulo: string;
  precio: string | null;
  portada: string | null;
  archivo: string | null;
}

// Puerto exacto de metricas_detalle.php:54-70 (stats de los últimos 30 días) y :84-102
// (mismo cálculo, período anterior 30-60 días atrás — mismas 3 columnas, se reusa esta
// interfaz para ambos).
export interface StatsVentanaRow {
  total: number;
  tiempo_prom: number;
  pct_leyo: number;
}

export type DeltaDireccion = "up" | "down" | "flat";

export interface Delta {
  dir: DeltaDireccion;
  label: string;
}

export interface FunnelEtapa {
  label: string;
  valor: number;
}

export interface OrigenStat {
  origen: string;
  total: number;
}

export interface UbicacionRow {
  ciudad: string | null;
  pais: string | null;
  visitas: number;
}

export interface DispositivosStats {
  movil: number;
  tablet: number;
  desktop: number;
}

export interface DetalleMetricaPublicacion {
  id: number;
  tipo: TipoPublicacion;
  titulo: string;
  precio: number | null;
  imagenUrl: string;
  editarHref: string;
}

// Puerto completo de metricas_detalle.php (582 líneas) — respuesta única de
// GET /api/me/metricas/:tipo/:id.
export interface MetricaDetalle {
  publicacion: DetalleMetricaPublicacion;
  visitas30d: number;
  deltaVisitas: Delta | null;
  tiempoPromedioSegundos: number;
  deltaTiempo: Delta | null;
  pctLeyo: number;
  deltaLeyo: Delta | null;
  visitasTotal: number;
  funnel: FunnelEtapa[];
  visitasPorDia: number[];
  dispositivos: DispositivosStats;
  origenes: OrigenStat[];
  ubicaciones: UbicacionRow[];
}
