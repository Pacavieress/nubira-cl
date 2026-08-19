import { resolverFotoTutor, resolverPortada } from "../../lib/media.js";
import type {
  ServicioDetallePublico,
  ServicioDetalleRow,
  ServicioPublico,
  ServicioRow,
  ViewerContext,
} from "./servicios.types.js";

function toNumberOrNull(value: string | null): number | null {
  return value === null ? null : Number(value);
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
    portada: resolverPortada(row.banco_archivo, row.imagen),
    tutor: {
      id: row.alumno_id,
      nombre: row.nombre_tutor,
      fotoUrl: resolverFotoTutor(row.foto_perfil, row.nombre_tutor),
      institucion: row.institucion_maestra,
    },
    rating: {
      promedio: toNumberOrNull(row.rating_promedio),
      votos: row.total_votos,
    },
    esPaes: row.es_paes === 1,
    videoEstado: row.video_estado,
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
