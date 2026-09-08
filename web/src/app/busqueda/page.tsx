import Link from "next/link";
import { getBusqueda } from "@/lib/api";
import { ApunteCardBusqueda } from "@/components/ApunteCardBusqueda";
import { BusquedaFiltrosBar } from "@/components/BusquedaFiltrosBar";
import { Header } from "@/components/Header";
import { ServicioCard } from "@/components/ServicioCard";

interface BusquedaPageProps {
  searchParams: Promise<{
    q?: string;
    orden?: string;
    categoria?: string;
    precio_min?: string;
    precio_max?: string;
    video?: string;
    tipo?: string;
    pagina?: string;
  }>;
}

// Puerto de busqueda.php:545-547 (fallback fijo cuando la query de "trending" real no
// devuelve nada) — la query de trending real (líneas 525-543, un UNION ALL entre
// servicios.categoria y apuntes.asignatura) sigue sin portarse, fuera de alcance a
// propósito (sugerencia cosmética de estado vacío, no resultado de búsqueda).
const SUGERENCIAS_FALLBACK = ["Matemáticas", "Física", "Programación"];

const PREVIEW = 8;

function VideoIcon({ className }: { className?: string }) {
  return (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={1.5}>
      <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 10.5l4.72-4.72a.75.75 0 011.28.53v11.38a.75.75 0 01-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 002.25-2.25v-9a2.25 2.25 0 00-2.25-2.25h-9A2.25 2.25 0 002.25 7.5v9a2.25 2.25 0 002.25 2.25z" />
    </svg>
  );
}

function CheckIcon({ className }: { className?: string }) {
  return (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={3}>
      <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" />
    </svg>
  );
}

function ArrowRightIcon({ className }: { className?: string }) {
  return (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2}>
      <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
    </svg>
  );
}

function SearchIcon({ className }: { className?: string }) {
  return (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={1.5}>
      <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z" />
    </svg>
  );
}

export default async function BusquedaPage({ searchParams }: BusquedaPageProps) {
  const sp = await searchParams;
  const q = (sp.q ?? "").trim();
  const orden = sp.orden ?? "";
  const categoriaFiltro = sp.categoria ?? "";
  const precioMin = sp.precio_min !== undefined && sp.precio_min !== "" ? Math.max(0, parseInt(sp.precio_min, 10) || 0) : null;
  const precioMax = sp.precio_max !== undefined && sp.precio_max !== "" ? Math.max(0, parseInt(sp.precio_max, 10) || 0) : null;
  const video = sp.video === "1";
  const tabActivo = sp.tipo === "clases" || sp.tipo === "apuntes" ? sp.tipo : "todo";
  const pagina = Math.max(1, parseInt(sp.pagina ?? "1", 10) || 1);

  // Puerto exacto de busqueda.php:188/220 — mismo criterio para decidir si hay algo que
  // buscar (dispara la ejecución de queries) vs. el estado de "trending" fijo (solo si NO
  // hay ningún filtro y q está vacío — no alcanza con longitud <= 1 como el gate de queries).
  const hayFiltrosActivos = categoriaFiltro !== "" || precioMin !== null || precioMax !== null || video;
  const mostrarTrending = q === "" && !hayFiltrosActivos;

  // Puerto exacto de busqueda.php:490-492 — base de query string preservada al construir
  // los links de tabs/video/paginación (todo excepto "pagina", que cada helper resetea o
  // fija explícitamente).
  const base: Record<string, string> = {};
  if (q) base.q = q;
  if (orden) base.orden = orden;
  if (categoriaFiltro) base.categoria = categoriaFiltro;
  if (precioMin !== null) base.precio_min = String(precioMin);
  if (precioMax !== null) base.precio_max = String(precioMax);
  if (video) base.video = "1";

  function hrefBase(extra: Record<string, string>): string {
    const p = new URLSearchParams({ ...base, ...extra });
    const qs = p.toString();
    return qs ? `/busqueda?${qs}` : "/busqueda";
  }

  function urlTab(tab: string): string {
    return hrefBase(tab === "todo" ? {} : { tipo: tab });
  }

  function urlToggleVideo(): string {
    const extra: Record<string, string> = {};
    if (tabActivo !== "todo") extra.tipo = tabActivo;
    const p = new URLSearchParams({ ...base, ...extra });
    if (video) p.delete("video");
    else p.set("video", "1");
    const qs = p.toString();
    return qs ? `/busqueda?${qs}` : "/busqueda";
  }

  function urlPagina(nuevaPagina: number): string {
    return hrefBase({ ...(tabActivo !== "todo" ? { tipo: tabActivo } : {}), pagina: String(nuevaPagina) });
  }

  if (mostrarTrending) {
    return (
      <>
        <Header titulo="Vitrina" />
        {/* lg:pl-72 (no lg:pl-64) — pl-* reemplaza el padding-left entero en vez de sumarse
            al md:px-8, así que hace falta 256px(sidebar)+32px(px-8)=288px=pl-72. Ver el
            diagnóstico completo en web/src/app/apuntes/page.tsx. */}
        <main className="w-full max-w-[1600px] mx-auto px-4 md:px-8 pt-20 pb-24 lg:pb-8 lg:pl-72">
          <div className="flex flex-col items-center justify-center py-12 md:py-24 px-4 text-center">
            <div className="w-20 h-20 bg-blue-50 border border-blue-100 rounded-full flex items-center justify-center mb-6">
              <SearchIcon className="w-8 h-8 text-[#54A6D8]" />
            </div>
            <h1 className="text-2xl md:text-3xl font-bold text-gray-900 mb-2 tracking-tight">¿Qué quieres aprender hoy?</h1>
            <p className="text-sm text-gray-500 mb-8 max-w-md">
              Busca clases, tutorías o apuntes. Aquí te dejamos lo más popular del momento en Nubira:
            </p>
            <div className="flex flex-wrap justify-center gap-3 max-w-2xl">
              {SUGERENCIAS_FALLBACK.map((t) => (
                <a
                  key={t}
                  href={`/busqueda?q=${encodeURIComponent(t)}`}
                  className="px-4 py-2 bg-white border border-gray-200 hover:border-[#54A6D8] hover:text-[#54A6D8] text-gray-700 text-sm font-bold rounded-full transition-colors"
                >
                  {t}
                </a>
              ))}
            </div>
          </div>
        </main>
      </>
    );
  }

  // Puerto exacto de busqueda.php:220 — sin q real ni filtros no se ejecuta ninguna query
  // (caso de borde: q de 1 solo carácter sin filtros cae acá, no en mostrarTrending).
  const ejecutarBusqueda = q.length > 1 || hayFiltrosActivos;
  const resultado = ejecutarBusqueda
    ? await getBusqueda({
        q,
        orden: orden || undefined,
        categoria: categoriaFiltro || undefined,
        precioMin: precioMin ?? undefined,
        precioMax: precioMax ?? undefined,
        video,
        tab: tabActivo,
        pagina,
      })
    : {
        servicios: [],
        apuntes: [],
        totalServicios: 0,
        totalApuntes: 0,
        categoriasConResultados: [] as string[],
        tab: tabActivo,
        pagina: 1,
        totalPaginas: 1,
      };

  const { servicios, apuntes, totalServicios, totalApuntes, categoriasConResultados, totalPaginas } = resultado;

  // Puerto exacto de busqueda.php:393-410 — estado vacío consciente del tab activo.
  let mostrarVacio = false;
  let vacioOtroTipo: "clases" | "apuntes" | null = null;
  let vacioOtroTotal = 0;
  if (q.length > 0) {
    if (tabActivo === "todo" && totalServicios === 0 && totalApuntes === 0) {
      mostrarVacio = true;
    } else if (tabActivo === "clases" && totalServicios === 0) {
      mostrarVacio = true;
      if (totalApuntes > 0) {
        vacioOtroTipo = "apuntes";
        vacioOtroTotal = totalApuntes;
      }
    } else if (tabActivo === "apuntes" && totalApuntes === 0) {
      mostrarVacio = true;
      if (totalServicios > 0) {
        vacioOtroTipo = "clases";
        vacioOtroTotal = totalServicios;
      }
    }
  }

  return (
    <>
      <Header titulo="Vitrina" q={q} />
      {/* lg:pl-72 (no lg:pl-64) — ver web/src/app/apuntes/page.tsx para el diagnóstico completo. */}
      <main className="w-full max-w-[1600px] mx-auto px-4 md:px-8 pt-20 pb-24 lg:pb-8 lg:pl-72">
        <div className="mb-3 md:mb-4 border-b border-gray-100 pb-2">
          <div className="flex items-center gap-2 overflow-x-auto no-scrollbar py-1 -mx-4 px-4 md:mx-0 md:px-0">
            <BusquedaFiltrosBar categorias={categoriasConResultados} />

            <a
              href={urlToggleVideo()}
              className={`shrink-0 px-3 py-1.5 bg-white border rounded-full text-xs font-bold whitespace-nowrap transition-colors flex items-center gap-1.5 ${
                video ? "border-gray-900 text-gray-900 bg-gray-50" : "border-gray-200 text-gray-600 hover:border-gray-300"
              }`}
            >
              <VideoIcon className="w-3 h-3" /> Con video
            </a>

            <div className="h-4 w-px bg-gray-200 shrink-0 mx-0.5" />

            <form method="GET" action="/busqueda" className="flex items-center gap-1.5 shrink-0">
              <input type="hidden" name="q" value={q} />
              {orden && <input type="hidden" name="orden" value={orden} />}
              {categoriaFiltro && <input type="hidden" name="categoria" value={categoriaFiltro} />}
              {video && <input type="hidden" name="video" value="1" />}
              {tabActivo !== "todo" && <input type="hidden" name="tipo" value={tabActivo} />}
              <input
                type="number"
                name="precio_min"
                min={0}
                step={1000}
                placeholder="Mín $"
                defaultValue={precioMin !== null ? precioMin : ""}
                className="w-[72px] px-2 py-1.5 text-xs font-bold bg-white border border-gray-200 rounded-full outline-none focus:ring-2 focus:ring-gray-300 transition-all"
              />
              <span className="text-gray-300 text-xs">–</span>
              <input
                type="number"
                name="precio_max"
                min={0}
                step={1000}
                placeholder="Máx $"
                defaultValue={precioMax !== null ? precioMax : ""}
                className="w-[72px] px-2 py-1.5 text-xs font-bold bg-white border border-gray-200 rounded-full outline-none focus:ring-2 focus:ring-gray-300 transition-all"
              />
              <button
                type="submit"
                aria-label="Aplicar rango de precio"
                className="shrink-0 w-7 h-7 flex items-center justify-center bg-gray-900 hover:bg-[#54A6D8] text-white rounded-full transition-colors"
              >
                <CheckIcon className="w-2.5 h-2.5" />
              </button>
            </form>
          </div>

          <div className="flex items-center gap-1 mt-3 border-b border-gray-100">
            <Link
              href={urlTab("todo")}
              className={`px-4 py-2.5 text-sm font-bold border-b-2 -mb-px transition-colors ${
                tabActivo === "todo" ? "border-gray-900 text-gray-900" : "border-transparent text-gray-400 hover:text-gray-600"
              }`}
            >
              Todo
            </Link>
            <Link
              href={urlTab("clases")}
              className={`px-4 py-2.5 text-sm font-bold border-b-2 -mb-px transition-colors ${
                tabActivo === "clases" ? "border-gray-900 text-gray-900" : "border-transparent text-gray-400 hover:text-gray-600"
              }`}
            >
              <span className="md:hidden">Clases</span>
              <span className="hidden md:inline">Clases y Servicios</span> ({totalServicios})
            </Link>
            <Link
              href={urlTab("apuntes")}
              className={`px-4 py-2.5 text-sm font-bold border-b-2 -mb-px transition-colors ${
                tabActivo === "apuntes" ? "border-gray-900 text-gray-900" : "border-transparent text-gray-400 hover:text-gray-600"
              }`}
            >
              Apuntes ({totalApuntes})
            </Link>
          </div>
        </div>

        {(tabActivo === "todo" || tabActivo === "clases") && totalServicios > 0 && (
          <section className="mb-12">
            <div className="flex items-center justify-between mb-4">
              <h2 className="text-xl font-extrabold text-gray-900 tracking-tight">Clases y Servicios</h2>
              {tabActivo === "todo" && totalServicios > PREVIEW && (
                <Link
                  href={urlTab("clases")}
                  className="text-xs font-medium text-[#54A6D8] hover:underline hover:bg-[#eef6fb] transition-colors duration-150 ease-out bg-gray-50 px-3 py-1.5 rounded-2xl border border-[#f0f0f0] flex items-center gap-1 shrink-0"
                >
                  Ver todos ({totalServicios}) <ArrowRightIcon className="w-3 h-3" />
                </Link>
              )}
            </div>
            <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4 md:gap-6">
              {servicios.map((servicio) => (
                <ServicioCard key={servicio.id} servicio={servicio} />
              ))}
            </div>
            {tabActivo === "clases" && totalPaginas > 1 && (
              <div className="flex items-center justify-center gap-3 mt-8">
                {pagina > 1 ? (
                  <Link href={urlPagina(pagina - 1)} className="px-4 py-2 bg-gray-900 hover:bg-[#54A6D8] text-white text-xs font-bold rounded-full transition-colors">
                    Anterior
                  </Link>
                ) : (
                  <span className="px-4 py-2 bg-gray-100 text-gray-300 text-xs font-bold rounded-full cursor-not-allowed">Anterior</span>
                )}
                <span className="text-xs font-bold text-gray-500">
                  Página {pagina} de {totalPaginas}
                </span>
                {pagina < totalPaginas ? (
                  <Link href={urlPagina(pagina + 1)} className="px-4 py-2 bg-gray-900 hover:bg-[#54A6D8] text-white text-xs font-bold rounded-full transition-colors">
                    Siguiente
                  </Link>
                ) : (
                  <span className="px-4 py-2 bg-gray-100 text-gray-300 text-xs font-bold rounded-full cursor-not-allowed">Siguiente</span>
                )}
              </div>
            )}
          </section>
        )}

        {(tabActivo === "todo" || tabActivo === "apuntes") && totalApuntes > 0 && (
          <section className="mb-12">
            <div className="flex items-center justify-between mb-4">
              <h2 className="text-xl font-extrabold text-gray-900 tracking-tight">Apuntes</h2>
              {tabActivo === "todo" && totalApuntes > PREVIEW && (
                <Link
                  href={urlTab("apuntes")}
                  className="text-xs font-medium text-[#54A6D8] hover:underline hover:bg-[#eef6fb] transition-colors duration-150 ease-out bg-gray-50 px-3 py-1.5 rounded-2xl border border-[#f0f0f0] flex items-center gap-1 shrink-0"
                >
                  Ver todos ({totalApuntes}) <ArrowRightIcon className="w-3 h-3" />
                </Link>
              )}
            </div>
            <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4 md:gap-6">
              {apuntes.map((apunte) => (
                <ApunteCardBusqueda key={apunte.id} apunte={apunte} />
              ))}
            </div>
            {tabActivo === "apuntes" && totalPaginas > 1 && (
              <div className="flex items-center justify-center gap-3 mt-8">
                {pagina > 1 ? (
                  <Link href={urlPagina(pagina - 1)} className="px-4 py-2 bg-gray-900 hover:bg-[#54A6D8] text-white text-xs font-bold rounded-full transition-colors">
                    Anterior
                  </Link>
                ) : (
                  <span className="px-4 py-2 bg-gray-100 text-gray-300 text-xs font-bold rounded-full cursor-not-allowed">Anterior</span>
                )}
                <span className="text-xs font-bold text-gray-500">
                  Página {pagina} de {totalPaginas}
                </span>
                {pagina < totalPaginas ? (
                  <Link href={urlPagina(pagina + 1)} className="px-4 py-2 bg-gray-900 hover:bg-[#54A6D8] text-white text-xs font-bold rounded-full transition-colors">
                    Siguiente
                  </Link>
                ) : (
                  <span className="px-4 py-2 bg-gray-100 text-gray-300 text-xs font-bold rounded-full cursor-not-allowed">Siguiente</span>
                )}
              </div>
            )}
          </section>
        )}

        {mostrarVacio && (
          <div className="flex flex-col items-center justify-center py-20 px-4 text-center">
            <div className="w-24 h-24 bg-gray-50 border border-gray-100 rounded-full flex items-center justify-center mb-6">
              <SearchIcon className="w-10 h-10 text-gray-300" />
            </div>
            {vacioOtroTipo ? (
              <>
                <h2 className="text-2xl font-bold text-gray-900 mb-2 tracking-tight">
                  Sin resultados en {tabActivo === "clases" ? "Clases" : "Apuntes"}
                </h2>
                <p className="text-sm text-gray-500 max-w-md mx-auto mb-6">
                  No encontramos {tabActivo === "clases" ? "clases" : "apuntes"} para &quot;<strong>{q}</strong>&quot;, pero sí hay{" "}
                  {vacioOtroTotal} {vacioOtroTipo === "apuntes" ? (vacioOtroTotal === 1 ? "apunte" : "apuntes") : vacioOtroTotal === 1 ? "clase" : "clases"}.
                </p>
                <Link
                  href={urlTab(vacioOtroTipo)}
                  className="px-5 py-2.5 bg-gray-900 hover:bg-[#54A6D8] text-white text-xs font-bold rounded-xl transition-colors flex items-center gap-2"
                >
                  <ArrowRightIcon className="w-3.5 h-3.5" /> Ver {vacioOtroTipo === "apuntes" ? "apuntes" : "clases"}
                </Link>
              </>
            ) : (
              <>
                <h2 className="text-2xl font-bold text-gray-900 mb-2 tracking-tight">Cero resultados</h2>
                <p className="text-sm text-gray-500 max-w-md mx-auto mb-6">
                  No encontramos clases ni apuntes exactos para &quot;<strong>{q}</strong>&quot;. <br />
                  Ya le enviamos una alerta a nuestros tutores para que lo agreguen pronto.
                </p>
                <a
                  href="/busqueda"
                  className="px-5 py-2.5 bg-gray-900 hover:bg-[#54A6D8] text-white text-xs font-bold rounded-xl transition-colors flex items-center gap-2"
                >
                  Ver lo más popular
                </a>
              </>
            )}
          </div>
        )}
      </main>
    </>
  );
}
