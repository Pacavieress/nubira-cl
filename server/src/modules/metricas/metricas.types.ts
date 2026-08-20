// Puerto de app/metricas.php:21-101 — SOLO la lista (servicios + apuntes aprobados/
// visibles del usuario, LIMIT 60 cada uno, ordenados por fecha DESC), con visitas de los
// últimos 30 días + tendencia contra los 30 días anteriores. La página de detalle por
// publicación (/metricas/:tipo/:id -> app/metricas_detalle.php, 582 líneas, gráficos) NO
// se porta en esta pieza — cada fila enlaza al PHP real para eso, mismo patrón que otras
// piezas de esta migración con un "siguiente nivel" de detalle fuera de alcance.

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
