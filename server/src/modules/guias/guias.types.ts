// Puerto de app/guias.php (MODO 1: hub general /guias, MODO 2: hub de categoría
// /guias/{slug}) — solo la parte de listado. app/guia_post.php (artículo individual) es
// una pieza aparte, todavía no portada.

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
