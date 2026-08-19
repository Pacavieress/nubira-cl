import { env } from "../../config/env.js";
import { resolverFotoTutor, resolverPortadaApunte } from "../../lib/media.js";
import type {
  ApunteDetalleRow,
  ApunteDetallePublico,
  ApuntePublico,
  ApunteRow,
  ViewerContext,
} from "./apuntes.types.js";

// Puerto de cargar_apuntes.php:236 — mb_substr a 120 chars + "...".
function truncarDescripcion(descripcion: string | null): string | null {
  if (!descripcion) return null;
  const chars = Array.from(descripcion);
  return chars.length <= 120 ? descripcion : `${chars.slice(0, 120).join("")}...`;
}

// Puerto de cargar_apuntes.php:237 — "nuevo" si se subió hace menos de 7 días.
function esNuevo(fechaSubida: string): boolean {
  const hace7dias = Date.now() - 7 * 24 * 60 * 60 * 1000;
  return new Date(fechaSubida).getTime() > hace7dias;
}

interface FilasPromo {
  promo_gratis: number;
  promo_limite: number;
  promo_contador: number;
}

// Puerto de cargar_apuntes.php:217-218 — promo flash: gratis mientras no se agote el cupo.
// Tipado por forma estructural (no ApunteRow) para servir también a ApunteDetalleRow, que
// tiene las 3 mismas columnas de promo.
function mapPromo(row: FilasPromo): ApuntePublico["promo"] {
  const activa = row.promo_gratis === 1 && row.promo_contador < row.promo_limite;
  if (!activa) return null;
  return { activa: true, restantes: row.promo_limite - row.promo_contador };
}

export function mapApunteRow(row: ApunteRow): ApuntePublico {
  return {
    id: row.id,
    titulo: row.titulo,
    precio: row.precio,
    descripcionCorta: truncarDescripcion(row.descripcion),
    portadaUrl: resolverPortadaApunte(row.portada, row.archivo, env.assetsBaseUrl),
    institucion: row.institucion,
    ventasTotales: row.ventas_totales,
    esNuevo: esNuevo(row.fecha_subida),
    promo: mapPromo(row),
    url: `/apuntes/${row.id}`,
  };
}

// Puerto de ver_apunte.php:250-256 — hasta 6 tags de ia_keywords (CSV), solo si ia_used.
function mapIaTags(iaUsed: number, iaKeywords: string | null): string[] {
  if (iaUsed !== 1 || !iaKeywords) return [];
  return iaKeywords
    .split(",")
    .map((tag) => tag.trim())
    .filter((tag) => tag !== "")
    .slice(0, 6);
}

export function mapApunteDetalleRow(row: ApunteDetalleRow, viewer: ViewerContext): ApunteDetallePublico {
  return {
    id: row.id,
    titulo: row.titulo,
    precio: row.precio,
    portadaUrl: resolverPortadaApunte(row.portada, row.archivo, env.assetsBaseUrl),
    institucion: row.institucion,
    ventasTotales: row.ventas_totales,
    esNuevo: esNuevo(row.fecha_subida),
    promo: mapPromo(row),
    url: `/apuntes/${row.id}`,
    descripcion: row.descripcion,
    asignatura: row.asignatura,
    materia: row.materia,
    nivelAcademico: row.nivel_academico,
    categoria: row.categoria,
    iaTags: mapIaTags(row.ia_used, row.ia_keywords),
    publicador: {
      nombre: row.publicador_nombre,
      fotoUrl: resolverFotoTutor(row.publicador_foto, row.publicador_nombre, env.assetsBaseUrl),
      institucion: row.institucion,
      // Puerto simplificado de ver_apunte.php:242-243 — esa página también da por
      // verificado a un verificacion_estado NULL con institución no vacía (cuenta legacy
      // pre-sistema-de-verificación). No se replica esa segunda condición acá, mismo
      // criterio ya usado en servicios.mapper.ts (verificado = solo 'aprobado').
      verificado: row.publicador_verificacion_estado === "aprobado",
    },
    viewer,
  };
}
