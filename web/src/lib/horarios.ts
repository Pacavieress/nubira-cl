// Puerto de app/helpers/horarios.php (dias_semana_nubira + parsear_horarios_servicio).
// La API ya entrega el JSON crudo parseado (campo `horarios`); esto es solo un reshape
// de datos ya presentes en la respuesta — no requiere ninguna consulta nueva a la BD,
// por eso vive en web/ y no en server/ (a diferencia de tier/ofertaVigente/tiempoRespuesta,
// que sí necesitaban datos que la API todavía no traía).

export const DIAS_SEMANA_NUBIRA = ["Lunes", "Martes", "Miércoles", "Jueves", "Viernes", "Sábado", "Domingo"];

export interface DisponibilidadServicio {
  tieneHorarios: boolean;
  dias: Array<{ dia: string; bloques: string[] }>;
  diaProximo: string | null;
}

export function parsearHorariosServicio(horarios: Record<string, string[]> | null): DisponibilidadServicio {
  const vacio: DisponibilidadServicio = { tieneHorarios: false, dias: [], diaProximo: null };
  if (!horarios) return vacio;

  const dias = DIAS_SEMANA_NUBIRA.filter((dia) => (horarios[dia]?.length ?? 0) > 0).map((dia) => ({
    dia,
    bloques: horarios[dia]!,
  }));

  if (dias.length === 0) return vacio;

  // Mismo cálculo de "día próximo" que PHP: desde hoy (America/Santiago), el primer día
  // de la semana (en orden circular) que tenga algún bloque disponible.
  const hoy = new Date();
  const hoyIndex = (hoy.getDay() + 6) % 7; // JS: 0=Domingo -> convertido a 0=Lunes
  let diaProximo: string | null = null;
  for (let i = 0; i < 7; i++) {
    const candidato = DIAS_SEMANA_NUBIRA[(hoyIndex + i) % 7];
    if (dias.some((d) => d.dia === candidato)) {
      diaProximo = candidato!;
      break;
    }
  }

  return { tieneHorarios: true, dias, diaProximo };
}
