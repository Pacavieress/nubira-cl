// Puerto de admin_login_fallos.php ("Log Fail" / "Centro de Monitoreo") — 3 tabs: Intentos
// (login_fallos), VIPs (excepciones_email) y Pendientes (alumnos sin confirmar). Se portan
// las mutaciones reversibles (limpiar historial de fallos, autorizar/revocar VIP — un
// simple toggle de `activo`, mismo nivel de riesgo que adminDominios/adminConfigPrecios ya
// portados). Deliberadamente SIN 'eliminar_pendiente' (hard DELETE FROM alumnos, sin
// soft-delete, admin_login_fallos.php:85-89) — esa acción específica del tab Pendientes
// queda excluida y enlaza al sitio PHP real; el resto del tab (lectura) sí se porta.
// 'eliminar_solicitud'/'eliminar_rebote'/'enviar_aviso_rebote' no se portan: no tienen
// ningún tab real que los dispare en el PHP (código huérfano, $valid_tabs solo contempla
// fallos/vips/pendientes) — no hay nada que replicar.
export type MonitoreoTab = "fallos" | "vips" | "pendientes";

export interface LoginFalloItem {
  correo: string;
  ip: string;
  fecha: string;
  esAlumno: boolean;
}

export interface VipItem {
  id: number;
  correo: string;
  fechaCreacion: string;
}

export interface PendienteItem {
  id: number;
  nombre: string;
  correo: string;
  carrera: string | null;
  dominio: string | null;
}

export interface MonitoreoResumen {
  tab: MonitoreoTab;
  page: number;
  limit: number;
  total: number;
  contadores: { fallos: number; vips: number; pendientes: number };
  itemsFallos?: LoginFalloItem[];
  itemsVips?: VipItem[];
  itemsPendientes?: PendienteItem[];
}
