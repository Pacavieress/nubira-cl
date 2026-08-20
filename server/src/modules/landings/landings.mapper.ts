import { mapServicioRow } from "../servicios/servicios.mapper.js";
import type { LandingClases, LandingClasesRaw, LandingSeo } from "./landings.types.js";
import { FAQS_POR_CATEGORIA } from "./landings.types.js";

// Puerto exacto de landing_categoria.php:124-137 — mismos defaults cuando no hay override
// en seo_categorias_contenido (indexable=false por defecto, opt-in explícito).
function mapSeo(categoria: string, seoRow: LandingClasesRaw["seoRow"], total: number): LandingSeo {
  const titulo = `Clases de ${categoria} universidad Chile | Nubira`;
  const descripcion =
    seoRow?.meta_description ||
    `Encuentra clases particulares y tutorías de ${categoria} en universidades chilenas (PUC, USACH, U. de Chile, UNAB y más). Pago protegido con Garantía Nubira.`;
  const h1 = seoRow?.titulo_h1 || `Clases de ${categoria} en Chile`;

  // Sin fallback genérico para PAES a propósito (landing_categoria.php:133-136): todas
  // las demás categorías sí conservan el fallback genérico.
  const intro =
    seoRow?.parrafo_intro || (categoria === "PAES" ? null : `Próximamente más información sobre ${categoria} en Nubira.`);

  const indexable = seoRow?.indexable === 1;
  const noindex = total < 3 || !indexable;

  return { titulo, descripcion, h1, intro, noindex };
}

export function mapLandingClases(raw: LandingClasesRaw): LandingClases {
  const total = raw.servicios.length;
  return {
    categoria: raw.categoria,
    seo: mapSeo(raw.categoria, raw.seoRow, total),
    total,
    servicios: raw.servicios.map(mapServicioRow),
    faqs: FAQS_POR_CATEGORIA[raw.categoria] ?? [],
  };
}
