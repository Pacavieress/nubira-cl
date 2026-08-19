import { env } from "../../config/env.js";
import { resolverFotoTutor } from "../../lib/media.js";
import { mapServicioRow } from "../servicios/servicios.mapper.js";
import type { ServicioRow } from "../servicios/servicios.types.js";
import type { TutorPublico, TutorRow } from "./tutores.types.js";

function toNumberOrNull(value: string | null): number | null {
  return value === null ? null : Number(value);
}

export function mapTutorRow(row: TutorRow, servicios: ServicioRow[]): TutorPublico {
  return {
    id: row.id,
    nombre: row.nombre,
    bio: row.bio,
    fotoUrl: resolverFotoTutor(row.foto_perfil, row.nombre, env.assetsBaseUrl),
    institucion: row.institucion_maestra,
    verificado: row.verificacion_estado === "aprobado",
    rating: { promedio: toNumberOrNull(row.rating_promedio), votos: row.total_votos },
    servicios: servicios.map(mapServicioRow),
  };
}
