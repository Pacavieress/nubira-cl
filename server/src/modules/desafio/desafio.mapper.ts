import type { PreguntaDesafioRow, PreguntaPublica } from "./desafio.types.js";

// Puerto exacto de cargar_desafio.php:125-140 — las opciones nulas (preguntas tipo 'vf',
// con solo opcion_a/opcion_b) se omiten del JSON en vez de mandarse como null.
export function mapPreguntaRow(row: PreguntaDesafioRow): PreguntaPublica {
  const opciones: PreguntaPublica["opciones"] = {};
  if (row.opcion_a) opciones.a = row.opcion_a;
  if (row.opcion_b) opciones.b = row.opcion_b;
  if (row.opcion_c) opciones.c = row.opcion_c;
  if (row.opcion_d) opciones.d = row.opcion_d;

  return {
    id: row.id,
    tipo: row.tipo,
    enunciado: row.enunciado,
    desarrollo: row.desarrollo,
    opciones,
    tiempoLimiteSegundos: row.tiempo_limite_segundos,
    nivelPaes: row.nivel_paes === 1,
  };
}
