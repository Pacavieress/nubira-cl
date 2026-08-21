// Puerto de app/helpers/horarios.php — validación de horarios_json al GUARDAR. El parseo
// para LECTURA (parsearHorariosServicio del lado servicios/detalle) no vive acá: ese ya
// existe portado en web/src/lib/horarios.ts (consume el JSON crudo tal cual desde el
// cliente); acá solo la mitad de ESCRITURA, que nunca se había portado.
export const DIAS_SEMANA_NUBIRA = ["Lunes", "Martes", "Miércoles", "Jueves", "Viernes", "Sábado", "Domingo"] as const;

export type HorariosJson = Record<(typeof DIAS_SEMANA_NUBIRA)[number], string[]>;

const RE_BLOQUE = /^([01]\d|2[0-3]):[0-5]\d - ([01]\d|2[0-3]):[0-5]\d$/;

// Puerto exacto de validar_horarios_json() — valida ESTRUCTURA (no solo sintaxis JSON)
// antes de guardar. Deliberadamente NO valida solapamiento de bloques dentro de un mismo
// día — el PHP real tampoco lo hace acá (ese chequeo vive solo client-side, en
// serializarHorarioGrilla() de grilla_horarios.php, como ayuda de UX, no como gate real).
export function validarHorariosJson(jsonCrudo: string): string | null {
  let data: unknown;
  try {
    data = JSON.parse(jsonCrudo);
  } catch {
    return "El formato de horarios no es válido.";
  }
  if (data === null || typeof data !== "object" || Array.isArray(data)) {
    return "El formato de horarios no es válido.";
  }

  const recibidas = Object.keys(data).sort();
  const esperadas = [...DIAS_SEMANA_NUBIRA].sort();
  if (JSON.stringify(recibidas) !== JSON.stringify(esperadas)) {
    return "Los días recibidos no coinciden con los 7 días válidos de la semana.";
  }

  const dataObj = data as Record<string, unknown>;
  for (const dia of DIAS_SEMANA_NUBIRA) {
    const bloques = dataObj[dia];
    if (!Array.isArray(bloques)) {
      return `El formato de bloques para ${dia} no es válido.`;
    }
    for (const bloque of bloques) {
      if (typeof bloque !== "string" || !RE_BLOQUE.test(bloque)) {
        return `Formato de horario inválido en ${dia}: "${String(bloque)}". Debe ser HH:MM - HH:MM.`;
      }
      const [desde, hasta] = bloque.split(" - ");
      if (desde! >= hasta!) {
        return `En ${dia}, la hora de inicio (${desde}) debe ser menor a la hora de fin (${hasta}).`;
      }
    }
  }

  return null;
}

export function tieneAlMenosUnBloque(horariosJson: HorariosJson): boolean {
  return DIAS_SEMANA_NUBIRA.some((dia) => horariosJson[dia].length > 0);
}
