import { getServicios } from "@/lib/api";
import { ServicioCard } from "@/components/ServicioCard";

export default async function Home() {
  const { data: servicios } = await getServicios();

  return (
    <main className="max-w-[1600px] mx-auto px-4 md:px-8 py-8">
      <h1 className="text-xl md:text-2xl font-bold text-gray-900 tracking-tight mb-4">Servicios</h1>

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
  );
}
