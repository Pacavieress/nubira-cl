// Puerto de app/admin_marketing_cards.php — un solo panel con 2 tabs (?tab=servicios|novedades
// en el PHP real), acá un solo módulo con 2 sub-rutas (/servicios, /novedades) — decisión de
// estructura confirmada explícitamente con el usuario antes de construir (a diferencia de
// Avisos/Despertar Dormidos, que SÍ son 2 páginas de nav separadas en el sitio real).
//
// Tab Servicios: puro curador, cero mutaciones — reutiliza el generador de imagen que YA
// existe en server/src/modules/compartir/compartirServicio.generador.ts (mismo endpoint
// público /api/compartir/servicio/:id/post que ya usa "Compartir servicio" en el sitio real).
// No se genera nada nuevo acá, solo se lista/filtra qué servicios curar.
//
// Tab Novedades: única mutación real de todo el panel — crear una novedad (título+cuerpo)
// dispara un INSERT y la generación bajo demanda de su imagen (POST 4:5 + HISTORY 9:16, motor
// SVG+resvg nuevo en compartirNovedad.generador.ts, mismo patrón que compartirServicio). Sin
// editar ni eliminar en esta fase — mismo criterio que el PHP real, confirmado no es un vacío
// a llenar.
export interface FiltrosServiciosMarketing {
  categoria: string;
  institucion: string;
  conVideo: boolean;
  fechaDesde: string; // 'YYYY-MM-DD' o ''
  fechaHasta: string;
}

export interface ServicioMarketing {
  id: number;
  titulo: string;
  categoria: string;
  institucion: string | null;
  fechaPublicacion: string;
  conVideo: boolean;
  tutorNombre: string;
}

export interface ServiciosMarketingResumen {
  total: number;
  servicios: ServicioMarketing[];
  categoriasDisponibles: string[];
  institucionesDisponibles: string[];
}

export interface NovedadMarketing {
  id: number;
  titulo: string;
  cuerpo: string;
  creadoEn: string;
}

export interface NuevaNovedadInput {
  titulo: string;
  cuerpo: string;
}

export interface NuevaNovedadResultado {
  id: number;
}
