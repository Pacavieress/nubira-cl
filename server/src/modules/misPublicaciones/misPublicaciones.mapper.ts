import { env } from "../../config/env.js";
import type {
  ApuntePublicado,
  ApuntePublicadoRow,
  MisPublicacionesPublico,
  ServicioPublicado,
  ServicioPublicadoRow,
} from "./misPublicaciones.types.js";

const NB_SERVICIOS_WEB = "/upload/servicios/";
const NB_PLACEHOLDER_CLASES = "/img/portadas/servicios/clases.webp";

// Puerto exacto de mis_servicios.php:195 — mismo patrón que ventasClases.mapper.ts
// (imagen única, sin el pipeline de 3 tamaños de resolverPortada en media.ts, porque
// tampoco lo usa esta página real).
function resolverImagenServicio(imagen: string | null): string {
  if (!imagen) return `${env.assetsBaseUrl}${NB_PLACEHOLDER_CLASES}`;
  const nombreArchivo = imagen.split(/[\\/]/).pop() ?? imagen;
  return `${env.assetsBaseUrl}${NB_SERVICIOS_WEB}${nombreArchivo}`;
}

// Puerto exacto de url_servicio() (app/helpers/seo.php:130-135).
function urlServicio(id: number, slug: string | null): string {
  return slug ? `/servicios/${slug}-${id}` : `/servicios/${id}`;
}

export function mapServicioPublicadoRow(row: ServicioPublicadoRow): ServicioPublicado {
  return {
    id: row.id,
    titulo: row.titulo ?? "Sin título",
    imagenUrl: resolverImagenServicio(row.imagen),
    estado: row.estado ?? "pendiente",
    modalidad: row.modalidad || "Online",
    precio: row.precio === null ? null : Number(row.precio),
    url: urlServicio(row.id, row.slug),
  };
}

export function mapApuntePublicadoRow(row: ApuntePublicadoRow): ApuntePublicado {
  return {
    id: row.id,
    titulo: row.titulo ?? "Sin título",
    archivo: row.archivo,
    precio: row.precio === null ? null : Number(row.precio),
    esPublico: row.publico === 1,
  };
}

export function mapMisPublicaciones(
  serviciosRows: ServicioPublicadoRow[],
  apuntesRows: ApuntePublicadoRow[],
): MisPublicacionesPublico {
  return {
    servicios: serviciosRows.map(mapServicioPublicadoRow),
    apuntes: apuntesRows.map(mapApuntePublicadoRow),
  };
}
