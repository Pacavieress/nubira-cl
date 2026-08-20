import { env } from "../../config/env.js";
import { resolverPortadaGuia } from "../../lib/media.js";
import type { ArticuloListado, ArticuloListadoRow, CategoriaHub, CategoriaHubRow } from "./guias.types.js";

export function mapCategoriaHubRow(row: CategoriaHubRow): CategoriaHub {
  return {
    id: row.id,
    nombre: row.nombre,
    slug: row.slug,
    descripcionCorta: row.descripcion_corta,
    totalArticulos: row.total_articulos,
  };
}

export function mapArticuloListadoRow(row: ArticuloListadoRow): ArticuloListado {
  return {
    id: row.id,
    titulo: row.titulo,
    slug: row.slug,
    resumen: row.resumen,
    portadaCardUrl: resolverPortadaGuia(row.imagen_portada, "card", env.assetsBaseUrl),
    fechaPublicacion: row.fecha_publicacion,
  };
}
