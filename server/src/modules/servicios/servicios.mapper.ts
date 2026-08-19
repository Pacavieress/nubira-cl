import { env } from "../../config/env.js";
import { resolverFotoTutor, resolverPortada } from "../../lib/media.js";
import type {
  ServicioDetallePublico,
  ServicioDetalleRow,
  ServicioPublico,
  ServicioRow,
  Tier,
  ViewerContext,
} from "./servicios.types.js";

function toNumberOrNull(value: string | null): number | null {
  return value === null ? null : Number(value);
}

// Puerto exacto de la fórmula de app/componentes/card_servicio_grid.php:76-84 — única
// fuente de verdad (PHP la tiene duplicada en 2 archivos; acá vive en uno solo).
export function computeTier(scoreNubira: number, totalVotos: number, ratingPromedio: number | null): Tier {
  const rating = ratingPromedio ?? 0;
  if (scoreNubira >= 100 && totalVotos >= 10 && rating >= 4.7) return "leyenda";
  if (scoreNubira >= 80 && totalVotos >= 3 && rating >= 4.0) return "elite";
  if (scoreNubira >= 80) return "pro";
  if (scoreNubira >= 60) return "top";
  return null;
}

// Puerto exacto de app/helpers/ofertas.php::oferta_vigente() — mismas 4 condiciones,
// mismo orden. Nota: la comparación de fecha usa Date (mysql2 devuelve DATE así por
// defecto), no el string 'Y-m-d' que compara PHP — equivalente en la práctica, con un
// borde teórico de zona horaria a medianoche que no se consideró crítico para esta fase.
export function computeOfertaVigente(row: ServicioRow): boolean {
  if (row.is_subvencionado !== 1) return false;
  if (row.cupos_oferta <= 0) return false;
  if (row.precio_oferta === null || row.precio_oferta === "") return false;
  if (row.oferta_termino !== null) {
    const hoy = new Date();
    hoy.setHours(0, 0, 0, 0);
    if (row.oferta_termino < hoy) return false;
  }
  return true;
}

export function mapServicioRow(row: ServicioRow): ServicioPublico {
  return {
    id: row.id,
    slug: row.slug,
    titulo: row.titulo,
    categoria: row.categoria,
    modalidad: row.modalidad,
    precio: toNumberOrNull(row.precio),
    precioOferta: toNumberOrNull(row.precio_oferta),
    cuposOferta: row.cupos_oferta,
    portada: resolverPortada(row.banco_archivo, row.imagen, env.assetsBaseUrl),
    tutor: {
      id: row.alumno_id,
      nombre: row.nombre_tutor,
      fotoUrl: resolverFotoTutor(row.foto_perfil, row.nombre_tutor, env.assetsBaseUrl),
      institucion: row.institucion_maestra,
    },
    rating: {
      promedio: toNumberOrNull(row.rating_promedio),
      votos: row.total_votos,
    },
    esPaes: row.es_paes === 1,
    videoEstado: row.video_estado,
    tier: computeTier(row.score_nubira, row.total_votos, toNumberOrNull(row.rating_promedio)),
    ofertaVigente: computeOfertaVigente(row),
  };
}

function parseHorarios(horariosJson: string | null): unknown {
  if (!horariosJson) return null;
  try {
    return JSON.parse(horariosJson);
  } catch {
    // Igual que el patrón defensivo ya usado en Fase 3 (errorHandler): un JSON
    // malformado en la BD no debe tumbar el endpoint, solo degradar a null.
    return null;
  }
}

export function mapServicioDetalleRow(row: ServicioDetalleRow, viewer: ViewerContext): ServicioDetallePublico {
  return {
    ...mapServicioRow(row),
    descripcion: row.descripcion,
    ubicacion: row.ubicacion,
    duracionMinutos: row.duracion_minutos,
    horarios: parseHorarios(row.horarios_json),
    nivel: row.nivel,
    materia: row.materia,
    area: row.area,
    asignatura: row.asignatura,
    viewer,
  };
}
