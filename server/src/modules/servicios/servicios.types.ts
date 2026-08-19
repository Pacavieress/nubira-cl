// Tipos que reflejan las columnas reales seleccionadas por servicios.repository.ts,
// basados en sql/pendientes/migracion_arquitectura_fase0_5_schema_nucleo.sql (Fase 0.5).
//
// Nota de tipos DECIMAL: el pool de mysql2 (server/src/db/pool.ts) NO tiene
// `decimalNumbers: true`, así que precio/precio_oferta/rating_promedio llegan como
// string en runtime (comportamiento por defecto de mysql2), no como number. Se
// mantiene así deliberadamente en esta fase — decidir si se parsean a number es una
// decisión de la Fase 4 (forma final del JSON público), no de esta capa de datos.

export interface ServicioRow {
  id: number;
  slug: string | null;
  titulo: string;
  categoria: string;
  modalidad: string;
  precio: string | null;
  precio_oferta: string | null;
  cupos_oferta: number;
  oferta_termino: Date | null;
  imagen: string | null;
  score_nubira: number;
  video_estado: string;
  es_paes: number;
  institucion_maestra: string | null;
  alumno_id: number;
  nombre_tutor: string | null;
  foto_perfil: string | null;
  banco_archivo: string | null;
  total_votos: number;
  rating_promedio: string | null;
}

// ---- Filtros/paginación del repositorio (Fase 4, + alumnoId en Fase 6) ----

export interface SearchServiciosFilters {
  categoria?: string;
  modalidad?: string;
  institucion?: string;
  q?: string;
  alumnoId?: number;
  page: number;
  limit: number;
}

export interface SearchServiciosResult {
  rows: ServicioRow[];
  hayMas: boolean;
}

// ---- Forma pública del JSON (Fase 4, decisiones 2a-2d ya aprobadas) ----
// camelCase, precio/rating como number (parseados desde el string DECIMAL de ServicioRow
// en servicios.mapper.ts), sin tiers, portada resuelta sin verificar filesystem.

export interface ServicioPublico {
  id: number;
  slug: string | null;
  titulo: string;
  categoria: string;
  modalidad: string;
  precio: number | null;
  precioOferta: number | null;
  cuposOferta: number;
  portada: { thumb: string; card: string; main: string };
  tutor: {
    id: number;
    nombre: string | null;
    fotoUrl: string | null;
    institucion: string | null;
  };
  rating: { promedio: number | null; votos: number };
  esPaes: boolean;
  videoEstado: string;
}

// ---- Detalle completo (Fase 6) — extiende lo anterior, no lo reemplaza ----
// El listado (/api/servicios) sigue devolviendo ServicioPublico (liviano); solo el
// detalle (/api/servicios/:id) trae estos campos adicionales + el contexto del visitante.

export interface ViewerContext {
  isAuthenticated: boolean;
  isOwner: boolean;
}

export interface ServicioDetalleRow extends ServicioRow {
  descripcion: string;
  ubicacion: string | null;
  duracion_minutos: number;
  horarios_json: string | null;
  nivel: string;
  materia: string | null;
  area: string | null;
  asignatura: string | null;
}

export interface ServicioDetallePublico extends ServicioPublico {
  descripcion: string;
  ubicacion: string | null;
  duracionMinutos: number;
  horarios: unknown;
  nivel: string;
  materia: string | null;
  area: string | null;
  asignatura: string | null;
  viewer: ViewerContext;
}
