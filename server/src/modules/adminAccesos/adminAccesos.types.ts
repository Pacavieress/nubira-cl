// Puerto de admin_accesos_vitrina.php ("Analíticas" — historial de tráfico/actividad).
// Alcance confirmado explícitamente con el usuario antes de construir: se porta COMPLETO,
// incluidas las 2 mutaciones (eliminar selección, purgar bots antiguos) — son DELETE puros
// sobre `historial_actividad` (tabla de log de analítica), mismo nivel de riesgo ya aceptado
// en los toggles de paneles anteriores (no son cuentas de usuario ni datos transaccionales).
//
// Simplificación deliberada y documentada: la geolocalización por IP del PHP real (mapas de
// Google embebidos, tooltip con banderas, hover interactivo) se reduce a un texto
// "Ciudad, País" simple bajo la IP — mismo dato informativo (fetch al mismo endpoint público
// /app/api/geolocalizar_ip.php vía proxy), sin la capa de mapas/tooltips. Es una decisión de
// esfuerzo/fidelidad visual (gap de UI decorativa), no una decisión de producto o alcance.
export type TabAccesos = "trafico" | "bots" | "paginas" | "fallidas";

export interface UsuarioTrafico {
  usuarioId: number;
  ipUsuario: string | null;
  ultimaActividad: string;
  totalAcciones: number;
  ultimaUrl: string | null;
  ultimaAccionTxt: string | null;
  nombre: string | null;
  fotoPerfil: string | null;
  institucion: string | null;
  correo: string | null;
}

export interface ContadoresTrafico {
  alumnos: number;
  invitados: number;
  bots: number;
}

export interface BotFila {
  ipUsuario: string;
  userAgent: string | null;
  totalHits: number;
  urlsUnicas: number;
  ultimaVisita: string;
  primeraVisita: string;
}

export interface StatsBots {
  totalEventos: number;
  ipsUnicas: number;
  botsUnicos: number;
}

export interface PaginaFila {
  url: string;
  hits: number;
  uniques: number;
}

export interface BusquedaFallida {
  termino: string;
  totalIntentos: number;
  ultimaBusqueda: string;
}

export interface AccesosResumen {
  tab: TabAccesos;
  trafico?: { contadores: ContadoresTrafico; usuarios: UsuarioTrafico[] };
  bots?: { stats: StatsBots; bots: BotFila[] };
  paginas?: { totalHits: number; paginas: PaginaFila[] };
  fallidas?: { busquedas: BusquedaFallida[] };
}

export interface EventoHistorial {
  id: number;
  accion: string;
  detalle: string | null;
  url: string | null;
  ipUsuario: string | null;
  fecha: string;
  esBot: boolean;
}

export interface DetalleUsuario {
  usuarioId: number;
  esGuest: boolean;
  ip: string | null;
  nombre: string;
  correo: string | null;
  fotoPerfil: string | null;
  totalEventos: number;
  accionFav: string;
  primeraVisita: string | null;
  ultimaVisita: string | null;
  online: boolean;
  fueBot: boolean;
  urlsUnicas: number;
  diasDesdePrimera: number;
  primerReferrer: string | null;
  primerUtm: string | null;
  primerContacto: string | null;
  primerApunte: string | null;
}

export interface DetalleResumen {
  usuario: DetalleUsuario;
  eventos: EventoHistorial[];
}
