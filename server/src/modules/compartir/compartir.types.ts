// Puerto de app/img_desafio.php + app/helpers/imagen_compartir_desafio.php. Cubre ambas
// cards: "invitación por materia" (POST 4:5) y "3 preguntas de esta sesión" (HISTORY 9:16).
// Ver server/src/lib/svgCard.ts para la decisión de motor de render (resvg en vez de GD).
export interface MateriaCompartir {
  slug: string;
  nombre: string;
}

export type FormatoShare = "post" | "caption" | "share" | "preguntas";

// Puerto de la fila que arma nb_obtener_imagen_apunte() (imagen_compartir_apunte.php:395-424).
export interface ApunteCompartir {
  id: number;
  titulo: string;
  precio: number;
  portada: string | null;
  archivo: string | null;
  asignatura: string | null;
  promoGratis: boolean;
  promoLimite: number;
  promoContador: number;
  descargas: number;
  nombreAlumno: string | null;
  fotoPerfil: string | null;
  institucionMaestra: string | null;
}

// Puerto de la fila que arma nb_obtener_imagen_compartir() (imagen_compartir.php:925-975) —
// incluye rating_prom/rating_votos ya calculados server-side (mismas subqueries sobre
// valoraciones), igual que el SQL real.
export interface ServicioCompartir {
  id: number;
  titulo: string;
  categoria: string | null;
  precio: number;
  precioOferta: number | null;
  isSubvencionado: boolean;
  // mysql2 devuelve las columnas DATE como Date (sin dateStrings configurado) — mismo
  // criterio que ServicioRow.oferta_termino en servicios.types.ts, no un string 'Y-m-d'.
  ofertaTermino: Date | null;
  nombreAlumno: string | null;
  fotoPerfil: string | null;
  institucionMaestra: string | null;
  ratingProm: number;
  ratingVotos: number;
}

export type OpcionLetra = "a" | "b" | "c" | "d";

export interface PreguntaCompartir {
  id: number;
  tipo: string;
  enunciado: string;
  opciones: Partial<Record<OpcionLetra, string>>;
}

export interface DatosPreguntasCompartir {
  materia: MateriaCompartir;
  preguntas: PreguntaCompartir[];
}

// Puerto de la fila que arma nb_obtener_imagen_novedad() (imagen_compartir.php:847-878) —
// solo título+cuerpo, sin ningún dato de usuario (ver nota de alcance en
// compartirNovedad.generador.ts).
export interface NovedadCompartir {
  id: number;
  titulo: string;
  cuerpo: string;
}
