import { mapApunteRow } from "../apuntes/apuntes.mapper.js";
import { mapServicioRow } from "../servicios/servicios.mapper.js";
import type { LandingApuntes, LandingApuntesRaw, LandingClases, LandingClasesRaw, LandingSeo, SeoCategoriaContenidoRow } from "./landings.types.js";
import { FAQS_POR_CATEGORIA } from "./landings.types.js";

// Puerto exacto de landing_categoria.php:124-137 — mismos defaults cuando no hay override
// en seo_categorias_contenido (indexable=false por defecto, opt-in explícito). `tipo`
// generaliza $tipo_palabra/$tipo_servicio (líneas 125-126 del PHP real), antes hardcodeado
// a "clases" acá.
function mapSeo(categoria: string, tipo: "clases" | "apuntes", seoRow: SeoCategoriaContenidoRow | null, total: number): LandingSeo {
  const tipoPalabra = tipo === "clases" ? "Clases" : "Apuntes";
  const tipoServicio = tipo === "clases" ? "clases particulares y tutorías" : "apuntes y resúmenes";

  const titulo = `${tipoPalabra} de ${categoria} universidad Chile | Nubira`;
  const descripcion =
    seoRow?.meta_description ||
    `Encuentra ${tipoServicio} de ${categoria} en universidades chilenas (PUC, USACH, U. de Chile, UNAB y más). Pago protegido con Garantía Nubira.`;
  const h1 = seoRow?.titulo_h1 || `${tipoPalabra} de ${categoria} en Chile`;

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
    seo: mapSeo(raw.categoria, "clases", raw.seoRow, total),
    total,
    servicios: raw.servicios.map(mapServicioRow),
    faqs: FAQS_POR_CATEGORIA[raw.categoria] ?? [],
  };
}

export function mapLandingApuntes(raw: LandingApuntesRaw): LandingApuntes {
  const total = raw.apuntes.length;
  return {
    categoria: raw.categoria,
    seo: mapSeo(raw.categoria, "apuntes", raw.seoRow, total),
    total,
    apuntes: raw.apuntes.map(mapApunteRow),
    faqs: FAQS_POR_CATEGORIA[raw.categoria] ?? [],
  };
}
