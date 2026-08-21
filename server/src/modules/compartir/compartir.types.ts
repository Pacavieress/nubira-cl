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
