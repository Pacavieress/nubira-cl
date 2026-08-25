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
  is_subvencionado: number;
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

// Tier calculado server-side (Fase "diseño idéntico") — única fuente de verdad de la
// fórmula de umbrales, para no crear una tercera copia en Next.js (PHP ya la tiene
// duplicada 2 veces). Ver computeTier() en servicios.mapper.ts.
export type Tier = "leyenda" | "elite" | "pro" | "top" | null;

// ---- Forma pública del JSON (Fase 4, decisiones 2a-2d ya aprobadas) ----
// camelCase, precio/rating como number (parseados desde el string DECIMAL de ServicioRow
// en servicios.mapper.ts), portada resuelta sin verificar filesystem. Tier y ofertaVigente
// se agregaron cuando el primer consumidor real (web/) los necesitó — antes quedaban
// fuera a propósito (Fase 4, decisión 2b) para no exponer algo que nadie consumía.

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
  tier: Tier;
  ofertaVigente: boolean;
}

// ---- Detalle completo (Fase 6) — extiende lo anterior, no lo reemplaza ----
// El listado (/api/servicios) sigue devolviendo ServicioPublico (liviano); solo el
// detalle (/api/servicios/:id) trae estos campos adicionales + el contexto del visitante.

export interface ViewerContext {
  isAuthenticated: boolean;
  isOwner: boolean;
  // Agregado para favoritos (Fase 7 de la migración, server/src/modules/favoritos) — false
  // sin sesión, sin necesidad de tocar la BD (un visitante anónimo nunca tiene favoritos).
  esFavorito: boolean;
  // Agregado para el CTA "Ir al Aula Virtual" (puerto de detalle_servicio.php:191-197) — id
  // del contrato activo/en_progreso del viewer para este servicio, o null si no tiene uno.
  contratoId: number | null;
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
  verificacion_estado: string | null;
  // Agregados para el detalle público completo (antes solo se seleccionaban para el
  // listado, donde WHERE_VISIBLE ya garantiza 'aprobado' — el detalle los necesita crudos
  // porque el propio dueño/admin puede ver un servicio no aprobado, ver servicios.controller.ts).
  estado: string;
  video_path: string | null;
  video_thumb_path: string | null;
}

// ---- Reseñas individuales y tiempo de respuesta (extensión para el detalle) ----
// Puerto exacto de detalle_servicio.php:201 y app/helpers/tiempo_respuesta.php.

export interface ValoracionRow {
  id: number;
  calificacion: number;
  comentario: string | null;
  fecha: Date;
  evaluador_nombre: string | null;
  evaluador_foto: string | null;
}

export interface ValoracionPublica {
  id: number;
  calificacion: number;
  comentario: string | null;
  fecha: Date;
  evaluador: { nombre: string | null; fotoUrl: string };
}

export type TonoRespuesta = "verde" | "azul" | "naranjo" | "gris";

export interface TiempoRespuesta {
  texto: string;
  tono: TonoRespuesta;
}

export interface ServicioDetallePublico extends Omit<ServicioPublico, "tutor"> {
  tutor: ServicioPublico["tutor"] & { verificado: boolean };
  descripcion: string;
  ubicacion: string | null;
  duracionMinutos: number;
  horarios: unknown;
  nivel: string;
  materia: string | null;
  area: string | null;
  asignatura: string | null;
  viewer: ViewerContext;
  valoraciones: ValoracionPublica[];
  tiempoRespuesta: TiempoRespuesta;
  // Puerto de detalle_servicio.php:115-121 — el propio dueño (o un admin) puede ver un
  // servicio no aprobado (banner "En Revisión"/"Publicación Pausada"); cualquier otro
  // visitante recibe 404 antes de llegar a construir esta respuesta (ver el controller).
  estado: string;
  video: { path: string; thumbUrl: string | null } | null;
  // Puerto exacto de detalle_servicio.php:206-271 (motor de recomendación), CON una
  // simplificación deliberada: el PHP real personaliza por afinidad de categoría usando
  // `tracker_intereses` (historial de navegación del visitante, logueado o por huella
  // IP+user-agent) — acá se usa directamente la categoría del propio servicio, que es
  // exactamente el comportamiento de fallback del PHP cuando no hay señal de afinidad.
  // Documentado como gap de personalización, no como bug: el resultado final (7
  // recomendaciones de la misma categoría, excluyendo el propio servicio) es idéntico al
  // caso sin datos de afinidad, que es el caso común para cualquier visitante nuevo.
  recomendaciones: ServicioPublico[];
}
