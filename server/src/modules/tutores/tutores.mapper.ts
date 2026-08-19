import { env } from "../../config/env.js";
import { resolverFotoTutor } from "../../lib/media.js";
import { mapApunteRow } from "../apuntes/apuntes.mapper.js";
import type { ApunteRow } from "../apuntes/apuntes.types.js";
import { formatearTiempoRespuesta, mapServicioRow, mapValoracionRow } from "../servicios/servicios.mapper.js";
import type { ServicioRow, TiempoRespuesta, ValoracionRow } from "../servicios/servicios.types.js";
import type { TutorPublico, TutorRow } from "./tutores.types.js";

function toNumberOrNull(value: string | null): number | null {
  return value === null ? null : Number(value);
}

// Puerto exacto de perfil.php:191-200 — subtítulo bajo el nombre. El caso "default"
// (estudiante/alumno/tipo NULL) usa institucion_tutor($inst, false) (app/helpers/institucion.php:40-45):
// institución cruda tal cual, o "Particular" si viene vacía — SIN el diccionario de
// abreviación (ese solo se aplica cuando institucion_tutor() se llama con $abreviar=true,
// que perfil.php no hace acá). Mismo criterio ya usado en ApunteCard/ServicioCard: no se
// porta el diccionario de abreviación de institución.
export function computeSubtitulo(tipo: string | null, institucion: string | null): string {
  const inst = institucion?.trim() || "";
  if (tipo === "egresado") return inst ? `Egresado · ${inst}` : "Egresado";
  if (tipo === "profesor") return "Profesor";
  if (tipo === "particular") return "Tutor Particular";
  return inst || "Particular";
}

// Puerto exacto de perfil.php:316-324 — a diferencia de formatearTiempoRespuesta() a secas
// (que SIEMPRE devuelve "Tutor nuevo" cuando minutos=null), el perfil distingue 2 casos de
// minutos=null: sin ninguna reseña como tutor -> "Tutor nuevo"; CON reseñas como tutor pero
// sin métrica de tiempo -> null (perfil.php OCULTA el bloque entero, no dice "nuevo" para
// alguien que claramente no lo es).
export function mapTiempoRespuestaPerfil(minutos: number | null, votosComoTutor: number): TiempoRespuesta | null {
  if (minutos !== null) return formatearTiempoRespuesta(minutos);
  if (votosComoTutor > 0) return null;
  return { texto: "Tutor nuevo", tono: "gris" };
}

export function mapTutorRow(
  row: TutorRow,
  servicios: ServicioRow[],
  apuntes: ApunteRow[],
  resenasComoTutor: ValoracionRow[],
  resenasComoAlumno: ValoracionRow[],
  minutosRespuesta: number | null,
): TutorPublico {
  return {
    id: row.id,
    nombre: row.nombre,
    bio: row.bio,
    fotoUrl: resolverFotoTutor(row.foto_perfil, row.nombre, env.assetsBaseUrl),
    institucion: row.institucion_maestra,
    verificado: row.verificacion_estado === "aprobado",
    subtitulo: computeSubtitulo(row.tipo, row.institucion_maestra),
    statsAcademicas: {
      universidad: row.universidad,
      anioEgreso: row.anio_egreso,
      aniosExperiencia: row.anios_experiencia,
    },
    tiempoRespuesta: mapTiempoRespuestaPerfil(minutosRespuesta, row.total_votos),
    rating: { promedio: toNumberOrNull(row.rating_promedio), votos: row.total_votos },
    resenasComoTutor: resenasComoTutor.map(mapValoracionRow),
    resenasComoAlumno: resenasComoAlumno.map(mapValoracionRow),
    servicios: servicios.map(mapServicioRow),
    apuntes: apuntes.map(mapApunteRow),
  };
}
