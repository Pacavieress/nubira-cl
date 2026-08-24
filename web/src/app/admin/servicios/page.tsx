import { redirect } from "next/navigation";
import { getAdminServicios } from "@/lib/api";
import { getSesion } from "@/lib/sesion";
import { Header } from "@/components/Header";
import { AdminServiciosPanel } from "@/components/AdminServiciosPanel";

interface ServiciosPageProps {
  searchParams: Promise<{ q?: string }>;
}

// Puerto de admin_servicios.php — listado + búsqueda + toggle de visibilidad. Ver
// AdminServiciosPanel.tsx para la nota de alcance sobre aprobar/rechazar/eliminar/censura
// de imagen excluidos.
export default async function AdminServiciosPage({ searchParams }: ServiciosPageProps) {
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const sesion = await getSesion();

  if (!sesion || !sesion.esAdmin) {
    redirect(`${phpSiteUrl}/login`);
  }

  const { q = "" } = await searchParams;
  const servicios = await getAdminServicios(q || undefined);

  return (
    <>
      <Header titulo="Servicios" />
      <main className="pt-20 pb-28 md:pb-16 px-4 md:px-10 lg:ml-64 max-w-[1400px] mx-auto space-y-6">
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
          <div>
            <h1 className="text-xl md:text-2xl font-medium text-[#222222] tracking-[-0.01em]">Servicios</h1>
            <p className="text-sm text-gray-500 mt-1">Auditoría y moderación (últimos 100).</p>
          </div>
          <form method="get" className="w-full md:w-auto">
            <input
              type="text"
              name="q"
              defaultValue={q}
              placeholder="Buscar título u oferente..."
              className="w-full md:w-64 bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:border-[#54A6D8] focus:bg-white outline-none"
            />
          </form>
        </div>

        <AdminServiciosPanel servicios={servicios} phpSiteUrl={phpSiteUrl} />
      </main>
    </>
  );
}
