import Link from "next/link";
import { getApuntes, getCategoriasApuntes } from "@/lib/api";
import { GrillaApuntesInfinita } from "@/components/GrillaApuntesInfinita";
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

  // page:1/limit:12 — primer lote para el scroll infinito (paridad con vitrina_apuntes.php,
  // mismo criterio que servicios/page.tsx). Los lotes siguientes los pide
  // GrillaApuntesInfinita.tsx desde el cliente, mismos filtros.
  const { data: apuntes, meta } = await getApuntes({
    nivel: nivelFiltro,
    precio: precioFiltro,
    orden,
    q,
    categoria: categoriaFiltro,
    page: 1,
    limit: 12,
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
      {/* [11/09/2026] Vuelta a lg:ml-64 (revierte el lg:pl-72 de más arriba en el historial de
          este comentario): medido en vivo con Playwright (viewports 1024/1025/1280/1440/1920)
          contra este mismo <body class="flex flex-col"> — el overflow de 241px que motivó
          pl-72 ocurría con ml-64 SIN max-w-[1600px] presente (un margin-left fijo bajo
          align-items:stretch, sin ningún max-width que tope el ancho estirado). CON
          max-w-[1600px] ya en la clase (como está acá desde antes), ml-64 no desborda en
          ningún viewport probado — y sí replica el <main> real del PHP
          (app/vitrina_apuntes.php: `lg:ml-64 ... max-w-[1600px] mx-auto`), pegado al sidebar
          en vez del aire simétrico que dejaba w-full+mx-auto+pl-72 (mainLeft=256px, igual al
          PHP, contra 160px del centrado flexbox anterior). */}
      <main className="max-w-[1600px] lg:ml-64 px-4 md:px-8 pt-20 pb-24 lg:pb-8">
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

        {/* key fuerza un remount (estado limpio) cada vez que cambia algún filtro — sin
            esto GrillaApuntesInfinita conservaría el array acumulado del filtro anterior. */}
        <GrillaApuntesInfinita
          key={`${nivelFiltro ?? ""}|${precioFiltro ?? ""}|${orden ?? ""}|${q ?? ""}|${categoriaFiltro ?? ""}`}
          itemsIniciales={apuntes}
          hayMasInicial={meta.hayMas}
          filtros={{ nivel: nivelFiltro, precio: precioFiltro, orden, q, categoria: categoriaFiltro }}
        />
      </main>
    </>
  );
}
