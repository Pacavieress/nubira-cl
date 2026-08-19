import { getApuntes } from "@/lib/api";
import { ApunteCard } from "@/components/ApunteCard";
import { Header } from "@/components/Header";

interface ApuntesPageProps {
  searchParams: Promise<{ nivel?: string; precio?: string; orden?: string; q?: string }>;
}

// Mismos 3 niveles válidos que vitrina_apuntes.php:53 — cualquier otro valor se descarta.
const NIVELES_VALIDOS = new Set(["universitario", "paes", "escolar"]);

export default async function ApuntesPage({ searchParams }: ApuntesPageProps) {
  const { nivel, precio, orden, q } = await searchParams;
  const nivelFiltro = nivel && NIVELES_VALIDOS.has(nivel) ? nivel : undefined;
  const precioFiltro = precio === "gratis" || precio === "pagado" ? precio : undefined;

  const { data: apuntes } = await getApuntes({ nivel: nivelFiltro, precio: precioFiltro, orden, q });

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

  return (
    <>
      <Header titulo="Apuntes" />
      <main className="w-full max-w-[1600px] mx-auto px-4 md:px-8 pt-20 pb-8">
        <div className="mb-6">
          <h1 className="text-xl md:text-2xl font-bold text-gray-900 tracking-tight">{h1Titulo}</h1>
          {h1Subtitulo && <p className="text-sm text-gray-500 mt-1">{h1Subtitulo}</p>}
          {q && (
            <p className="text-sm text-gray-500 mt-1">
              Resultados para &quot;<span className="font-medium text-gray-800">{q}</span>&quot;
            </p>
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
