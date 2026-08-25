// Puerto de admin_reclamos.php ("Gestión de Reclamos") — bandeja de soporte tipo ledger con
// hilos de conversación. Deliberadamente completo (no solo lectura): responder/resolver/
// papelera/restaurar/eliminar_hard son UPDATE/INSERT/DELETE puros sobre reclamos_sugerencias
// y reclamos_mensajes, sin correo ni push (a diferencia de admin_videos.php) — mismo nivel de
// riesgo ya aceptado para Ofertas/Promo Apuntes.
export type EstadoFiltro = "activos" | "resuelto" | "todos" | "eliminado";

export type AccionLote = "papelera" | "restaurar" | "eliminar_hard";

export interface MensajeHilo {
  remitente: "usuario" | "admin";
  mensaje: string;
  fecha: string;
}

export interface Ticket {
  id: number;
  fecha: string;
  texto: string;
  estado: string;
  respuestaAdmin: string | null;
  usuarioNombre: string;
  fotoPerfil: string | null;
  chatThread: MensajeHilo[];
  urgente: boolean;
}

export interface Contadores {
  activos: number;
  resuelto: number;
  eliminado: number;
  todos: number;
}

export interface ReclamosResumen {
  estado: EstadoFiltro;
  contadores: Contadores;
  tickets: Ticket[];
}
