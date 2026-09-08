import Link from "next/link";
import { getApuntes, getCategoriasApuntes } from "@/lib/api";
import { ApunteCard } from "@/components/ApunteCard";
import { Header } from "@/components/Header";

interface ApuntesPageProps {
  searchParams: Promise<{ nivel?: string; precio?: string; orden?: string; q?: string; categoria?: string }>;
}

// Mismos 3 niveles válidos que vitrina_apuntes.php:53 — cualquier otro valor se descarta.
const NIVELES_VALIDOS = new Set(["universitario", "paes", "escolar"]);

function chipClass(activo: boolean): string {
  const base = "shrink-0 px-3.5 py-1.5 text-xs md:text-sm font-bold rounded-full border transition-colors duration-150 ease-out";
  return activo ? `${base} bg-gray-900 text-white border-gray-900` : `${base} bg-white text-gray-700 border-gray-200 hover:border-gray-400`;
}

export default async function ApuntesPage({ searchParams }: ApuntesPageProps) {
  const { nivel, precio, orden, q, categoria } = await searchParams;
  const nivelFiltro = nivel && NIVELES_VALIDOS.has(nivel) ? nivel : undefined;
  const precioFiltro = precio === "gratis" || precio === "pagado" ? precio : undefined;

  // Chips de categoría — puerto de vitrina_apuntes.php:60-79. La categoría activa solo es
  // válida si existe en el universo real de chips (mismo guard que $qs_categoria ahí).
  const categoriasChips = await getCategoriasApuntes();
  const categoriasValidas = new Set(categoriasChips.map((c) => c.categoria));
  const categoriaFiltro = categoria && categoriasValidas.has(categoria) ? categoria : undefined;

  const { data: apuntes } = await getApuntes({
    nivel: nivelFiltro,
    precio: precioFiltro,
    orden,
    q,
    categoria: categoriaFiltro,
  });

  // H1/subtítulo dinámico — calcado de vitrina_apuntes.php:207-225
  let h1Titulo = "Explorar Apuntes";
  let h1Subtitulo = "";
  if (nivelFiltro === "paes") {
    h1Titulo = "Apuntes PAES";
    h1Subtitulo = "Material para la prueba de admisión universitaria";
  } else if (nivelFiltro === "escolar") {
    h1Titulo = "Apuntes Escolares";
    h1Subtitulo = "Material de estudio escolar";
  }

  // Puerto de $qs_sin_categoria (vitrina_apuntes.php:256) — conserva el resto de la
  // query string (nivel/precio/orden/q) al cambiar de chip de categoría.
  const paramsSinCategoria = new URLSearchParams();
  if (nivel) paramsSinCategoria.set("nivel", nivel);
  if (precio) paramsSinCategoria.set("precio", precio);
  if (orden) paramsSinCategoria.set("orden", orden);
  if (q) paramsSinCategoria.set("q", q);

  function hrefChip(cat?: string): string {
    const p = new URLSearchParams(paramsSinCategoria);
    if (cat) p.set("categoria", cat);
    const qs = p.toString();
    return qs ? `/apuntes?${qs}` : "/apuntes";
  }

  return (
    <>
      <Header titulo="Explorar Apuntes" />
      {/* lg:pl-72 en vez de lg:ml-64: bajo <body class="flex flex-col"> (web/src/app/layout.tsx),
          un margin-left fijo no se resta del ancho estirado del hijo (align-items:stretch),
          así que el elemento se estira a los 1440px completos del contenedor y el margen lo
          empuja fuera del viewport — confirmado con scrollWidth vía CDP (overflow real de
          241px). padding sí se absorbe dentro del border-box. Mismo fix aplicado en
          servicios/[id]/page.tsx; ver ese archivo para el diagnóstico completo.
          [08/09/2026] pl-72 (18rem=288px), NO pl-64 (16rem=256px): `pl-*` REEMPLAZA
          padding-left entero, no se suma al `md:px-8` (32px) que ya lo define — a
          diferencia de `ml-64`, que sí se suma al padding existente porque son cajas CSS
          distintas (margin vs. padding). Con pl-64 el área de contenido quedaba 32px más
          ancha que el PHP real (1152px vs. 1120px medido con getComputedStyle), lo que
          hacía las cards de apuntes visiblemente más grandes/anchas que las reales —
          encontrado por Pablo comparando ambas vitrinas lado a lado. 288px = 256px del
          sidebar + 32px del padding real que el PHP sí conserva (`lg:ml-64` + `px-8` no
          compiten entre sí, se suman). */}
      <main className="w-full max-w-[1600px] mx-auto px-4 md:px-8 pt-20 pb-24 lg:pb-8 lg:pl-72">
        <div className="mb-6">
          <h1 className="text-xl md:text-2xl font-bold text-gray-900 tracking-tight">{h1Titulo}</h1>
          {h1Subtitulo && <p className="text-sm text-gray-500 mt-1">{h1Subtitulo}</p>}
          {q && (
            <p className="text-sm text-gray-500 mt-1">
              Resultados para &quot;<span className="font-medium text-gray-800">{q}</span>&quot;
            </p>
          )}

          {categoriasChips.length > 0 && (
            <div
              className="flex flex-nowrap md:flex-wrap overflow-x-auto md:overflow-visible no-scrollbar gap-2 mt-4 pb-1 md:pb-0"
              role="group"
              aria-label="Filtrar por categoría"
            >
              <Link href={hrefChip()} className={chipClass(!categoriaFiltro)}>
                Todos
              </Link>
              {categoriasChips.map((cc) => {
                const activo = categoriaFiltro === cc.categoria;
                return (
                  <Link key={cc.categoria} href={hrefChip(activo ? undefined : cc.categoria)} className={chipClass(activo)}>
                    {cc.categoria} ({cc.total})
                  </Link>
                );
              })}
            </div>
          )}
        </div>

        {apuntes.length === 0 ? (
          // Calcado del estado vacío en cargar_apuntes.php:199
          <div className="flex flex-col items-center justify-center text-center py-12 text-gray-400">
            <svg className="w-10 h-10 mb-3 opacity-50" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth={1.5}
                d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
              />
            </svg>
            <p className="text-sm">No hay apuntes disponibles.</p>
          </div>
        ) : (
          <div className="grid grid-cols-2 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4 md:gap-8 w-full">
            {apuntes.map((apunte) => (
              <ApunteCard key={apunte.id} apunte={apunte} />
            ))}
          </div>
        )}
      </main>
    </>
  );
}
