import Link from "next/link";
import { getCategorias, getServicios } from "@/lib/api";
import { Header } from "@/components/Header";
import { ServicioCard } from "@/components/ServicioCard";

interface ServiciosPageProps {
  searchParams: Promise<{ categoria?: string; q?: string }>;
}

function chipClass(activo: boolean): string {
  const base = "shrink-0 px-3.5 py-1.5 text-xs md:text-sm font-bold rounded-full border transition-colors duration-150 ease-out";
  return activo ? `${base} bg-gray-900 text-white border-gray-900` : `${base} bg-white text-gray-700 border-gray-200 hover:border-gray-400`;
}

// Puerto de app/clases_servicios.php + app/cargar_servicios.php ("Explorar Clases y
// Servicios", ruta real /servicios) — NO de app/vitrina.php (home real, / y /explorar,
// con banners y carruseles). Esta página vivía antes en la raíz "/" de web/ por
// simplicidad del piloto; se movió acá para no confundir 2 páginas reales distintas del
// sitio PHP bajo una sola ruta. "/" queda libre para un futuro port de vitrina.php.
export default async function ServiciosPage({ searchParams }: ServiciosPageProps) {
  const { categoria, q } = await searchParams;

  // Chips de categoría — puerto de clases_servicios.php:71-91. La categoría activa solo
  // es válida si existe en el universo real de chips (mismo guard que $qs_categoria ahí).
  // [08/09/2026] Reemplaza el selector <select> de FiltrosBar.tsx: el PHP real no tiene
  // ningún control de "modalidad" en esta página (solo chips de categoría) — el selector
  // de modalidad era una adición sin equivalente en el PHP, encontrada al comparar
  // screenshots contra nubira.local/servicios.
  const [categoriasChips, { data: servicios }] = await Promise.all([
    getCategorias(),
    getServicios({ categoria, q }),
  ]);
  const categoriasValidas = new Set(categoriasChips.map((c) => c.categoria));
  const categoriaFiltro = categoria && categoriasValidas.has(categoria) ? categoria : undefined;

  function hrefChip(cat?: string): string {
    const p = new URLSearchParams();
    if (q) p.set("q", q);
    if (cat) p.set("categoria", cat);
    const qs = p.toString();
    return qs ? `/servicios?${qs}` : "/servicios";
  }

  return (
    <>
      <Header titulo="Explorar Clases y Servicios" />
      {/* lg:pl-72 (no lg:pl-64) en vez de lg:ml-64 — overflow horizontal bajo <body flex
          flex-col>, ver web/src/app/apuntes/page.tsx para el diagnóstico completo. */}
      <main className="w-full max-w-[1600px] mx-auto px-4 md:px-8 pt-20 pb-24 lg:pb-8 lg:pl-72">
        <div className="mb-4">
          <h1 className="text-xl md:text-2xl font-bold text-gray-900 tracking-tight">Explorar Clases y Servicios</h1>
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

        {servicios.length === 0 ? (
          // Calcado de app/cargar_servicios.php:166 (estado vacío en la página real).
          <div className="flex flex-col items-center justify-center text-center py-12 text-gray-400">
            <svg className="w-10 h-10 mb-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth={1.5}
                d="M4 4h16v10.5a2 2 0 01-2 2H6a2 2 0 01-2-2V4zM4 14.5h4l1.5 2h5l1.5-2h4"
              />
            </svg>
            <p className="text-sm">No encontramos servicios con estos filtros.</p>
          </div>
        ) : (
          <div className="grid grid-cols-2 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4 md:gap-6 w-full">
            {servicios.map((servicio) => (
              <ServicioCard key={servicio.id} servicio={servicio} />
            ))}
          </div>
        )}
      </main>
    </>
  );
}
