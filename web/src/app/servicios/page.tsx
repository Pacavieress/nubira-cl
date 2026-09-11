import Link from "next/link";
import { getCategorias, getServicios } from "@/lib/api";
import { GrillaServiciosInfinita } from "@/components/GrillaServiciosInfinita";
import { Header } from "@/components/Header";

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
  // page:1/limit:12 — primer lote para el scroll infinito (paridad con
  // clases_servicios.php:124, `['pagina' => 1, 'limit' => 12]`). Los lotes siguientes los
  // pide GrillaServiciosInfinita.tsx desde el cliente, mismos filtros.
  const [categoriasChips, { data: servicios, meta }] = await Promise.all([
    getCategorias(),
    getServicios({ categoria, q, page: 1, limit: 12 }),
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
      {/* lg:ml-64 (no lg:pl-72): medido en vivo con Playwright (viewports 1024/1025/1280/
          1440/1920) contra este mismo <body class="flex flex-col"> — sin max-w-[1600px], un
          margin-left fijo bajo align-items:stretch desbordaba (~241px, ver historial); CON
          max-w-[1600px] presente, ml-64 no desborda en ningún viewport y además replica el
          <main> real del PHP (app/clases_servicios.php:241: `lg:ml-64 ... max-w-[1600px]
          mx-auto`) — pegado al sidebar, sin el aire simétrico que dejaba w-full+mx-auto+pl-72
          (mainLeft=160px a 1920 vs. mainLeft=256px, igual al PHP, con este fix). */}
      <main className="max-w-[1600px] lg:ml-64 px-4 md:px-8 pt-20 pb-24 lg:pb-8">
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

        {/* key fuerza un remount (estado limpio) cada vez que cambia el filtro — sin esto
            GrillaServiciosInfinita conservaría el array acumulado del filtro anterior. */}
        <GrillaServiciosInfinita
          key={`${categoria ?? ""}|${q ?? ""}`}
          itemsIniciales={servicios}
          hayMasInicial={meta.hayMas}
          filtros={{ categoria, q }}
        />
      </main>
    </>
  );
}
