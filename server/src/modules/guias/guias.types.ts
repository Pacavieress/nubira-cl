// Puerto de app/guias.php (MODO 1: hub general /guias, MODO 2: hub de categoría
// /guias/{slug}) — la parte de listado, y de app/guia_post.php (artículo individual
// /guias/{cat}/{slug}) — más abajo, a partir de ArticuloDetalleRow.

export interface CategoriaHubRow {
  id: number;
  nombre: string;
  slug: string;
  descripcion_corta: string | null;
  total_articulos: number;
}

export interface CategoriaHub {
  id: number;
  nombre: string;
  slug: string;
  descripcionCorta: string | null;
  totalArticulos: number;
}

export interface CategoriaRow {
  id: number;
  nombre: string;
  slug: string;
  descripcion_corta: string | null;
  solo_tutores: number;
  categoria_relacionada: string | null;
  filtro_relacionado: string | null;
}

export interface ArticuloListadoRow {
  id: number;
  titulo: string;
  slug: string;
  resumen: string | null;
  imagen_portada: string | null;
  fecha_publicacion: Date | null;
}

export interface ArticuloListado {
  id: number;
  titulo: string;
  slug: string;
  resumen: string | null;
  portadaCardUrl: string | null;
  fechaPublicacion: Date | null;
}

export interface GuiasHubGeneral {
  modo: "general";
  categorias: CategoriaHub[];
}

export interface GuiasHubCategoria {
  modo: "categoria";
  categoria: { nombre: string; slug: string; descripcionCorta: string | null; soloTutores: boolean };
  articulos: ArticuloListado[];
  noindex: boolean;
}

// ==================== Artículo individual (/guias/{cat}/{slug}) ====================
// Puerto de app/guia_post.php. Sin nb_insertar_tras_primer_h2() (líneas 14-56 del PHP
// real: inserta el CTA de tutores como HTML hermano justo después del primer <h2> del
// cuerpo, vía DOMDocument) — mejora/simplificación documentada: en vez de manipular el
// HTML ya sanitizado para inyectar el CTA a mitad de artículo, se renderiza como bloque
// propio junto a la sección "Tutores y recursos relacionados" (misma información, misma
// función de conversión, posición ligeramente distinta). El único punto de escritura de
// `cuerpo` es admin_guias.php vía nb_sanitizar_html() (allowlist real de tags) — contenido
// de confianza (autor admin), seguro de renderizar como HTML crudo en React.

export interface ArticuloDetalleRow {
  id: number;
  titulo: string;
  slug: string;
  resumen: string | null;
  cuerpo: string;
  imagen_portada: string | null;
  autor_nombre: string;
  meta_description: string | null;
  fecha_publicacion: Date | null;
}

export interface FaqArticuloRow {
  pregunta: string;
  respuesta: string;
}

export interface TutorRelacionadoRow {
  id: number;
  slug: string | null;
  titulo: string;
  nombre_tutor: string | null;
  foto_perfil: string | null;
  institucion_maestra: string | null;
}

export interface ApunteRelacionadoRow {
  id: number;
  titulo: string;
}

export interface ArticuloRelacionadoRow {
  id: number;
  slug: string;
  titulo: string;
  imagen_portada: string | null;
}

export interface TutorRelacionado {
  id: number;
  url: string;
  titulo: string;
  nombreTutor: string;
  fotoUrl: string | null;
  institucion: string;
}

export interface ArticuloRelacionado {
  slug: string;
  titulo: string;
  portadaThumbUrl: string | null;
}

export interface GuiaArticuloDetalle {
  modo: "articulo";
  categoria: { nombre: string; slug: string; soloTutores: boolean };
  articulo: {
    titulo: string;
    resumen: string | null;
    cuerpoHtml: string;
    autorNombre: string;
    fechaPublicacion: Date | null;
    portadaMainUrl: string | null;
    metaDescription: string | null;
  };
  faqs: FaqArticuloRow[];
  tutoresRelacionados: TutorRelacionado[];
  apuntesRelacionados: ApunteRelacionado[];
  linkVerClases: string | null;
  linkVerApuntes: string | null;
  articulosRelacionados: ArticuloRelacionado[];
  mostrarBreadcrumb: boolean;
}

export interface ApunteRelacionado {
  id: number;
  titulo: string;
}
