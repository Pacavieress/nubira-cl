import type { ServicioPublico, ServicioRow } from "../servicios/servicios.types.js";

// Puerto de app/landing_categoria.php — SOLO tipo=clases (tipo=apuntes se deja fuera a
// propósito: server/src/modules/landings NO tiene ninguna fila configurada en
// seo_categorias_contenido para tipo='apuntes' hoy — confirmado contra la BD real, cero
// contenido SEO real que portar — y además /apuntes/[cat] colisionaría con la ruta de
// listado /apuntes ya construida en web/, a diferencia de /clases/[cat] que no choca con
// nada existente).

// Puerto exacto de nubira_categorias_seo() en app/helpers/seo.php:81-100 — mapeo fijo
// slug → nombre canónico de categoría. Only 7 de estos 16 tienen fila en
// seo_categorias_contenido hoy (confirmado contra la BD real); el resto simplemente
// nunca hace match con nada en esa tabla y cae al fallback genérico de copy, igual que
// hace el PHP real.
export const CATEGORIAS_SEO: Record<string, string> = {
  matematicas: "Matemáticas",
  quimica: "Química",
  fisica: "Física",
  biologia: "Biología",
  programacion: "Programación",
  idiomas: "Idiomas",
  historia: "Historia",
  lenguaje: "Lenguaje",
  economia: "Economía",
  diseno: "Diseño",
  derecho: "Derecho",
  asesoria: "Asesoría",
  calculo: "Cálculo",
  ingles: "Inglés",
  tesis: "Tesis",
  paes: "PAES",
};

export interface SeoCategoriaContenidoRow {
  titulo_h1: string | null;
  parrafo_intro: string | null;
  meta_description: string | null;
  filtro_titulo: string | null;
  indexable: number;
}

export interface LandingFaq {
  pregunta: string;
  respuesta: string;
}

// Puerto exacto de $FAQS_POR_CATEGORIA en landing_categoria.php:140-151 — "Fase
// quick-win: solo Tesis por ahora", confirmado que sigue siendo el único caso real.
export const FAQS_POR_CATEGORIA: Record<string, LandingFaq[]> = {
  Tesis: [
    {
      pregunta: "¿Cuánto cuesta una asesoría de tesis en Chile?",
      respuesta:
        "El precio varía según el alcance (revisión metodológica, corrección de estilo, apoyo estadístico, etc.) y lo define cada tutor en su perfil. En Nubira puedes comparar precios y elegir la asesoría que se ajuste a tu presupuesto, siempre con pago protegido.",
    },
    {
      pregunta: "¿Qué incluye una asesoría de tesis en Nubira?",
      respuesta:
        "Acompañamiento académico: revisión de metodología, corrección de redacción y estilo, apoyo en análisis estadístico y orientación en la estructura del trabajo. El estudiante mantiene siempre la autoría de su tesis.",
    },
    {
      pregunta: "¿Asesoría de tesis o tesis por encargo?",
      respuesta:
        "Nubira no permite ni promueve la elaboración de tesis por encargo. Los tutores ofrecen acompañamiento, corrección y orientación metodológica; la investigación y redacción final son siempre responsabilidad del estudiante.",
    },
    {
      pregunta: "¿Es seguro pagar por una asesoría de tesis en Nubira?",
      respuesta:
        "Sí. Todos los pagos quedan protegidos con la Garantía Nubira: el dinero se libera al tutor solo cuando confirmas que recibiste el servicio acordado.",
    },
  ],
};

export interface LandingClasesRaw {
  categoria: string;
  seoRow: SeoCategoriaContenidoRow | null;
  servicios: ServicioRow[];
}

export interface LandingSeo {
  titulo: string;
  descripcion: string;
  h1: string;
  intro: string | null;
  noindex: boolean;
}

export interface LandingClases {
  categoria: string;
  seo: LandingSeo;
  total: number;
  servicios: ServicioPublico[];
  faqs: LandingFaq[];
}
