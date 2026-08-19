import { getApuntes, getServicios } from "@/lib/api";
import { ApunteCard } from "@/components/ApunteCard";
import { Header } from "@/components/Header";
import { ServicioCard } from "@/components/ServicioCard";

interface BusquedaPageProps {
  searchParams: Promise<{ q?: string }>;
}

// Mismo fallback estático de busqueda.php:522-524 (cuando la query de "trending" real no
// devuelve nada). No se porta la query de trending real (busqueda.php:504-513, un UNION ALL
// entre servicios.categoria y apuntes.asignatura ordenado por volumen) — es sugerencia
// cosmética de estado vacío, no resultado de búsqueda; se deja este fallback fijo en vez de
// construir esa agregación para un v1.
const SUGERENCIAS_FALLBACK = ["Matemáticas", "Física", "Programación"];

// Alcance de este v1 — puerto de la vista "Todo" (preview) de busqueda.php, la que se ve
// por defecto sin tocar nada. Fuera de alcance a propósito, no por olvido:
//   - Tabs "Clases y Servicios" / "Apuntes" con paginación completa propia (busqueda.php
//     tiene 3 vistas: todo/clases/apuntes, cada una con su propio LIMIT/OFFSET) — acá se
//     muestran ambas secciones juntas, sin paginar, tal como se ven en el preview "Todo".
//   - Filtros de categoría, rango de precio y "con video" (busqueda.php:538-583) — el
//     algoritmo de búsqueda de texto (lo que de verdad faltaba) ya se portó a
//     construirCondicionTexto()/esBusquedaPaes() en server/, compartido con /api/servicios
//     y /api/apuntes; estos son filtros adicionales de la UI de resultados, no del motor.
//   - Facetas de categorías con resultados reales (busqueda.php:389-404) — dropdown que
//     dependería de esos mismos filtros que no se portaron.
//   - resaltarTermino() (negrita del término buscado dentro del título) — cosmético, tocaría
//     ServicioCard/ApunteCard (componentes compartidos con las vitrinas) solo para este caso.
//   - Registro de "búsqueda fallida" (busqueda.php:456-464, INSERT a busquedas_fallidas) —
//     efecto de escritura, no lectura; mismo criterio que ya se usó para no portar el
//     contador de vistas_perfil del perfil de tutor.
export default async function BusquedaPage({ searchParams }: BusquedaPageProps) {
  const { q: qRaw } = await searchParams;
  const q = (qRaw ?? "").trim();

  if (q.length <= 1) {
    return (
      <>
        <Header titulo="Buscar" />
        <main className="w-full max-w-[1600px] mx-auto px-4 md:px-8 pt-20 pb-8">
          <div className="flex flex-col items-center justify-center py-12 md:py-24 px-4 text-center">
            <div className="w-20 h-20 bg-blue-50 border border-blue-100 rounded-full flex items-center justify-center mb-6">
              <svg className="w-8 h-8 text-[#54A6D8]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeWidth={1.5}
                  d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z"
                />
              </svg>
            </div>
            <h1 className="text-2xl md:text-3xl font-bold text-gray-900 mb-2 tracking-tight">
              ¿Qué quieres aprender hoy?
            </h1>
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

  const [{ data: servicios }, { data: apuntes }] = await Promise.all([
    getServicios({ q }),
    getApuntes({ q }),
  ]);

  const sinResultados = servicios.length === 0 && apuntes.length === 0;

  return (
    <>
      <Header titulo={`Resultados para "${q}"`} />
      <main className="w-full max-w-[1600px] mx-auto px-4 md:px-8 pt-20 pb-8">
        <p className="text-sm text-gray-500 mb-6">
          Resultados para &quot;<span className="font-medium text-gray-800">{q}</span>&quot;
        </p>

        {sinResultados ? (
          // Calcado del estado "cero resultados" de busqueda.php:884-891
          <div className="flex flex-col items-center justify-center py-20 px-4 text-center">
            <div className="w-24 h-24 bg-gray-50 border border-gray-100 rounded-full flex items-center justify-center mb-6">
              <svg className="w-10 h-10 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeWidth={1.5}
                  d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z"
                />
              </svg>
            </div>
            <h2 className="text-2xl font-bold text-gray-900 mb-2 tracking-tight">Cero resultados</h2>
            <p className="text-sm text-gray-500 max-w-md mx-auto">
              No encontramos clases ni apuntes exactos para &quot;<strong>{q}</strong>&quot;.
            </p>
          </div>
        ) : (
          <div className="space-y-12">
            {servicios.length > 0 && (
              <section>
                <h2 className="text-xl font-bold text-gray-900 tracking-tight mb-4">Clases y Servicios</h2>
                <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4 md:gap-6">
                  {servicios.map((servicio) => (
                    <ServicioCard key={servicio.id} servicio={servicio} />
                  ))}
                </div>
              </section>
            )}

            {apuntes.length > 0 && (
              <section>
                <h2 className="text-xl font-bold text-gray-900 tracking-tight mb-4">Apuntes</h2>
                <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4 md:gap-6">
                  {apuntes.map((apunte) => (
                    <ApunteCard key={apunte.id} apunte={apunte} />
                  ))}
                </div>
              </section>
            )}
          </div>
        )}
      </main>
    </>
  );
}
