// Puerto de app/img_desafio.php + app/helpers/imagen_compartir_desafio.php — SOLO la card
// "invitación por materia" (formato POST, 4:5). Deliberadamente SIN la card "3 preguntas de
// esta sesión" (formato HISTORY, 9:16) — layout mucho más denso (numeración, opciones por
// pregunta, 2 perfiles de tamaño con fallback, zona seguro anti-recorte de Instagram),
// candidato a una pieza aparte. Ver server/src/lib/svgCard.ts para la decisión de motor de
// render (resvg en vez de GD).
export interface MateriaCompartir {
  slug: string;
  nombre: string;
}

export type FormatoShare = "post" | "caption" | "share";
