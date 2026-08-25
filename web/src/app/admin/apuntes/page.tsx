import { redirect } from "next/navigation";
import { getAdminApuntes } from "@/lib/api";
import { getSesion } from "@/lib/sesion";
import { Header } from "@/components/Header";
import { AdminApuntesPanel } from "@/components/AdminApuntesPanel";

interface ApuntesPageProps {
  searchParams: Promise<{ q?: string }>;
}

// Puerto de admin_apuntes.php ("Gestión de Apuntes") — listado + búsqueda + UNA sola mutación
// (alternar visibilidad, en AdminApuntesPanel.tsx). Alcance confirmado explícitamente con el
// usuario antes de construir: aprobar/rechazar/eliminar/censura de miniatura quedan excluidos
// (efectos de filesystem y/o borrado de `compras` real) — ver la nota completa en
// server/src/modules/adminApuntes/adminApuntes.types.ts.
export default async function AdminApuntesPage({ searchParams }: ApuntesPageProps) {
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const sesion = await getSesion();

  if (!sesion || !sesion.esAdmin) {
    redirect(`${phpSiteUrl}/login`);
  }

  const { q: qParam } = await searchParams;
  const q = (qParam ?? "").trim();
  const { apuntes } = await getAdminApuntes(q);

  return (
    <>
      <Header titulo="Apuntes" />
      <main className="pt-20 pb-28 md:pb-16 px-4 md:px-10 lg:ml-64 max-w-[1600px] mx-auto space-y-6">
        <div className="flex flex-col md:flex-row md:items-end justify-between gap-4">
          <div>
            <h1 className="text-xl md:text-2xl font-medium text-[#222222] tracking-[-0.01em]">Gestión de Apuntes</h1>
            <p className="text-sm text-gray-500 mt-1">Mostrando los últimos 100 apuntes.</p>
          </div>
          <form method="get" className="flex gap-2 w-full md:w-auto">
            <input
              type="text"
              name="q"
              defaultValue={q}
              placeholder="Buscar título, autor o ramo..."
              className="w-full md:w-64 bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-[#54A6D8] outline-none shadow-sm"
            />
            <button type="submit" className="bg-[#54A6D8] hover:bg-blue-600 text-white font-bold px-5 py-2.5 rounded-xl text-sm shadow-sm shrink-0">
              Buscar
            </button>
          </form>
        </div>

        <AdminApuntesPanel apuntesIniciales={apuntes} phpSiteUrl={phpSiteUrl} />
      </main>
    </>
  );
}
