export interface ApunteRow {
  id: number;
  titulo: string;
  precio: number;
  descripcion: string | null;
  fecha_subida: string;
  portada: string | null;
  preview: string | null;
  archivo: string | null;
  institucion: string | null;
  ventas_totales: number;
  promo_gratis: number;
  promo_limite: number;
  promo_contador: number;
}

export interface SearchApuntesFilters {
  nivel?: string;
  precio?: "gratis" | "pagado";
  orden?: string;
  q?: string;
  // Agregado para el perfil de tutor (Fase 6+) — mismo patrón que
  // SearchServiciosFilters.alumnoId en servicios.types.ts.
  alumnoId?: number;
  page: number;
  limit: number;
}

export interface SearchApuntesResult {
  rows: ApunteRow[];
  hayMas: boolean;
}

export interface ApuntePublico {
  id: number;
  titulo: string;
  precio: number;
  descripcionCorta: string | null;
  portadaUrl: string;
  institucion: string | null;
  ventasTotales: number;
  esNuevo: boolean;
  promo: { activa: boolean; restantes: number } | null;
  url: string;
}

// ---- Detalle (puerto de app/ver_apunte.php) ----
// Alcance deliberadamente más chico que la página PHP real: SOLO la info pública de
// lectura (igual criterio que ServicioDetallePublico con servicios). Fuera de alcance a
// propósito, no por olvido — todo lo que depende de compras/pagos:
//   - "acceso_completo" (¿el visitante ya pagó/es dueño/es admin?) y el visor de
//     archivo (PDF.js/imagen/preview de páginas) que depende de ese flag.
//   - fileUrl con firma HMAC de descargar_apunte.php (ver_apunte.php:309-317) — exponer
//     eso público sería sensible y de todos modos no hay UI de descarga en web/ aún.
//   - "Quizás te interese" (carrusel de recomendados) — mismo criterio que servicios/[id],
//     que tampoco lo porta hoy.
export interface ApunteDetalleRow {
  id: number;
  titulo: string;
  precio: number;
  descripcion: string | null;
  fecha_subida: string;
  portada: string | null;
  archivo: string | null;
  asignatura: string | null;
  materia: string | null;
  nivel_academico: string | null;
  categoria: string | null;
  institucion: string | null;
  estado: string;
  id_alumno: number;
  ia_used: number;
  ia_keywords: string | null;
  ventas_totales: number;
  promo_gratis: number;
  promo_limite: number;
  promo_contador: number;
  publicador_nombre: string | null;
  publicador_foto: string | null;
  publicador_verificacion_estado: string | null;
}

export interface ViewerContext {
  isAuthenticated: boolean;
  isOwner: boolean;
}

export interface ApunteDetallePublico extends Omit<ApuntePublico, "descripcionCorta"> {
  descripcion: string | null;
  asignatura: string | null;
  materia: string | null;
  nivelAcademico: string | null;
  categoria: string | null;
  iaTags: string[];
  publicador: {
    nombre: string | null;
    fotoUrl: string;
    institucion: string | null;
    verificado: boolean;
  };
  viewer: ViewerContext;
}
