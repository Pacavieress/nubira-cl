import { redirect } from "next/navigation";
import { getMisFavoritos } from "@/lib/api";
import { getSesion } from "@/lib/sesion";
import { FavoritoToggle } from "@/components/FavoritoToggle";
import { Header } from "@/components/Header";
import { ServicioCard } from "@/components/ServicioCard";

// Feature nueva (Fase 7 de la migración, sin equivalente en el sitio PHP real — ver
// sql/pendientes/migracion_arquitectura_fase7_favoritos_servicios.sql). Sin gate de "no hay
// PHP que mirar": diseño mínimo consistente con el resto de las páginas "Mis X" ya
// construidas (mis-compras, mis-contratos): gate de sesión + grilla de solo lectura +
// una acción de escritura puntual (acá, quitar de favoritos vía FavoritoToggle).
//
// Sin entrada en Sidebar.tsx a propósito — mismo criterio que mis-compras, mis-contratos,
// ventas-apuntes, etc.: ninguna de esas páginas está enlazada desde la navegación de web/
// todavía (en el sitio real viven como tiles de panel_gestion.php, que no se portó).
export default async function MisFavoritosPage() {
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const sesion = await getSesion();

  if (!sesion) {
    redirect(`${phpSiteUrl}/login`);
  }

  const favoritos = (await getMisFavoritos()) ?? [];

  return (
    <>
      <Header titulo="Mis Favoritos" />
      <main className="pt-20 pb-28 md:pb-16 px-4 md:px-8 lg:ml-64 max-w-[1600px] mx-auto">
        <header className="mb-6">
          <h1 className="text-xl md:text-2xl font-medium text-[#222222] tracking-[-0.01em]">Mis Favoritos</h1>
          <p className="text-gray-400 text-xs font-medium mt-0.5">Servicios que guardaste para revisar después.</p>
        </header>

        {favoritos.length === 0 ? (
          <div className="bg-white border border-gray-100 rounded-2xl p-10 text-center">
            <h3 className="text-base font-bold text-gray-900">Aún no tienes favoritos</h3>
            <p className="text-gray-500 text-sm mt-1">
              Toca el corazón en la página de un servicio para guardarlo acá.
            </p>
          </div>
        ) : (
          <div className="grid grid-cols-2 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4 md:gap-6 w-full">
            {favoritos.map((s) => (
              <div key={s.id} className="flex flex-col gap-1.5">
                <ServicioCard servicio={s} />
                <FavoritoToggle servicioId={s.id} favoritoInicial={true} variant="texto" />
              </div>
            ))}
          </div>
        )}
      </main>
    </>
  );
}
