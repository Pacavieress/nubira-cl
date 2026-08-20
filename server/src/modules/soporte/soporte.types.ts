// Puerto de app/reclamos_sugerencias.php ("Centro de Ayuda", tile "Soporte" en
// panel_gestion.php). Todas las mutaciones son soft-state, ownership-scoped por
// usuario_id (nunca un DELETE real) — mismo nivel de riesgo que /mis-publicaciones, no el
// de /clases-vendidas.
//
// Deliberadamente NO portado: la notificación push a admin al crear un ticket
// (reclamos_sugerencias.php:97-101, enviar_push_nubira() -> OneSignal, requiere API key
// de un servicio de terceros). Es una notificación interna hacia el admin, no algo que el
// usuario que abre el ticket experimente — el ticket se crea y funciona igual sin ella.
// Cablear una integración nueva con OneSignal desde Node no es "portar lo que ya existe",
// es una decisión de infraestructura nueva que no se tomó acá.

export const CATEGORIAS_VALIDAS = ["tecnico", "chat", "pago", "apunte", "cuenta", "sugerencia", "otro"] as const;
export type CategoriaTicket = (typeof CATEGORIAS_VALIDAS)[number];

export interface TicketMaestroRow {
  id: number;
  fecha_creacion: Date;
  categoria: string;
  mensaje: string;
  respuesta: string | null;
  estado: string;
  revisado_usuario: number;
}

export interface MensajeHiloRow {
  id: number;
  reclamo_id: number;
  remitente: "usuario" | "admin";
  mensaje: string;
  fecha: Date;
}

export interface MensajeHilo {
  remitente: "usuario" | "admin";
  mensaje: string;
  fecha: Date;
}

export interface Ticket {
  id: number;
  fechaCreacion: Date;
  categoria: CategoriaTicket;
  estado: string;
  revisadoUsuario: boolean;
  asunto: string;
  hilo: MensajeHilo[];
  tieneRespuestaNueva: boolean;
}

export interface MisTicketsPublico {
  tickets: Ticket[];
  contadores: { total: number; activos: number; resueltos: number; noLeidos: number };
}

export interface CrearTicketInput {
  asunto: string;
  mensaje: string;
  categoria: string;
}
