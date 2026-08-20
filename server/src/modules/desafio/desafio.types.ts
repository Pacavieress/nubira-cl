// Puerto de app/desafio.php (UI) + app/cargar_desafio.php (servir preguntas) +
// app/responder_desafio.php (calcular resultado). Sin la función "Compartir" (genera una
// imagen tipo historia vía un pipeline GD separado, app/img_desafio.php — mismo patrón que
// las marketing cards ya documentadas como pendientes en CLAUDE.md, no es parte del juego
// en sí). El puntaje se calcula 100% server-side (respuesta_correcta nunca sale hacia
// cargar_desafio.php) — se replica ese mismo diseño, no un ajuste de seguridad nuevo.

export type TipoPregunta = "alternativas" | "vf" | "completar" | "encuentra_error" | "cual_elegirias" | "que_harias_primero";

export const TIPOS_OPINION: readonly TipoPregunta[] = ["cual_elegirias", "que_harias_primero"];

export interface MateriaRow {
  slug: string;
  nombre: string;
}

export interface PreguntaDesafioRow {
  id: number;
  tipo: TipoPregunta;
  enunciado: string;
  desarrollo: string | null;
  opcion_a: string | null;
  opcion_b: string | null;
  opcion_c: string | null;
  opcion_d: string | null;
  tiempo_limite_segundos: number | null;
  nivel_paes: number;
}

export interface PreguntaPublica {
  id: number;
  tipo: TipoPregunta;
  enunciado: string;
  desarrollo: string | null;
  opciones: Partial<Record<"a" | "b" | "c" | "d", string>>;
  tiempoLimiteSegundos: number | null;
  nivelPaes: boolean;
}

export type OpcionElegida = "a" | "b" | "c" | "d";

export interface RespuestaInput {
  preguntaId: number;
  opcion: string;
}

export interface PreguntaCorreccionRow {
  id: number;
  tipo: TipoPregunta;
  respuesta_correcta: OpcionElegida;
}

export interface ResultadoDesafio {
  materia: string;
  aciertos: number;
  resultado: "bien" | "mal";
  categoriaServicio: string | null;
}
