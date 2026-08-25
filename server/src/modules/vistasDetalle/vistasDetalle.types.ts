// Puerto de track_vista.php ("tracker" de engagement, INSERT ... ON DUPLICATE KEY UPDATE en
// `vistas_detalle`). Fire-and-forget, solo analítica — sin mutación de datos del usuario ni
// efecto externo. Incluido explícitamente a pedido del usuario (2026-08-25): sin esto las
// estadísticas de vistas quedarían desalineadas entre nubira.local y web/.
export type TipoPublicacion = "servicio" | "apunte";
export type Dispositivo = "movil" | "tablet" | "desktop";

export interface VistaDetalleInput {
  tipo: TipoPublicacion;
  publicacionId: number;
  sessionId: string;
  tiempoSegundos: number;
  scrollMaxPct: number;
  leyoCompleto: boolean;
  dispositivo: Dispositivo | null;
  origen: string | null;
  usuarioId: number | null;
  ip: string;
}
