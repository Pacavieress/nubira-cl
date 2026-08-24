// Puerto de admin_reportes_servicios.php ("Reportes") — listado + bloquear/desbloquear
// usuario reportado (UPDATE puro sobre alumnos.bloqueado, reversible, sin efecto externo).
// Deliberadamente SIN 'marcar_revisado' (admin_reportes_servicios.php:62-128): además del
// UPDATE de estado envía 2 correos reales (al reportado y al reportante) — mismo criterio
// que el resto de esta ronda de paneles admin, acciones con efecto externo real quedan
// fuera, documentadas, enlazan al sitio PHP real.
export type EstadoReporte = "pendientes" | "revisados" | "todos";

export interface ReporteServicio {
  id: number;
  servicioId: number;
  tituloServicio: string;
  motivo: string;
  mensaje: string | null;
  fecha: string;
  revisado: boolean;
  usuarioReporta: { nombre: string; correo: string };
  usuarioReportado: { id: number; nombre: string; correo: string; bloqueado: boolean };
}

export interface ReportesResumen {
  estado: EstadoReporte;
  countPendientes: number;
  reportes: ReporteServicio[];
}
