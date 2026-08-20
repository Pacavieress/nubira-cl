import { env } from "../../config/env.js";
import { resolverPortadaGuia } from "../../lib/media.js";
import { CATEGORIAS_SEO } from "../landings/landings.types.js";
import type {
  ApunteRelacionadoRow,
  ArticuloListado,
  ArticuloListadoRow,
  ArticuloRelacionado,
  ArticuloRelacionadoRow,
  CategoriaHub,
  CategoriaHubRow,
  TutorRelacionado,
  TutorRelacionadoRow,
} from "./guias.types.js";

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

// Puerto exacto de url_servicio() (app/helpers/seo.php:130-135) — mismo criterio ya usado
// en misPublicaciones.mapper.ts, repetido acá (función pura de 1 línea, no vale la pena
// cruzar imports entre 2 módulos no relacionados por esto).
function urlServicio(id: number, slug: string | null): string {
  return slug ? `/servicios/${slug}-${id}` : `/servicios/${id}`;
}

export function mapTutorRelacionadoRow(row: TutorRelacionadoRow): TutorRelacionado {
  return {
    id: row.id,
    url: urlServicio(row.id, row.slug),
    titulo: row.titulo,
    nombreTutor: row.nombre_tutor ?? "Particular",
    fotoUrl: row.foto_perfil ? `${env.assetsBaseUrl}/app/perfil/fotos/${row.foto_perfil}` : null,
    institucion: row.institucion_maestra?.trim() || "Particular",
  };
}

export function mapApunteRelacionadoRow(row: ApunteRelacionadoRow) {
  return { id: row.id, titulo: row.titulo };
}

export function mapArticuloRelacionadoRow(row: ArticuloRelacionadoRow): ArticuloRelacionado {
  return {
    slug: row.slug,
    titulo: row.titulo,
    portadaThumbUrl: resolverPortadaGuia(row.imagen_portada, "thumb", env.assetsBaseUrl),
  };
}

// Puerto exacto de array_flip(nubira_categorias_seo()) en guia_post.php:179 — reutiliza el
// mismo catálogo ya construido para /clases/[cat] (server/src/modules/landings), no un
// segundo mapeo duplicado.
const SLUG_POR_CATEGORIA: Record<string, string> = Object.fromEntries(
  Object.entries(CATEGORIAS_SEO).map(([slug, nombre]) => [nombre, slug]),
);

export function slugSeoParaCategoria(nombreCategoria: string): string | null {
  return SLUG_POR_CATEGORIA[nombreCategoria] ?? null;
}
