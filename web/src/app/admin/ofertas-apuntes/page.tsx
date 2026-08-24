import { redirect } from "next/navigation";
import { getAdminOfertasApuntes } from "@/lib/api";
import { getSesion } from "@/lib/sesion";
import { Header } from "@/components/Header";
import { AdminOfertasApuntesPanel } from "@/components/AdminOfertasApuntesPanel";

interface OfertasApuntesPageProps {
  searchParams: Promise<{ tutor?: string }>;
}

// Puerto de admin_ofertas_apuntes.php — "Centro de Promos (Apuntes)". Todas las mutaciones
// se portan completas (ver AdminOfertasApuntesPanel.tsx).
export default async function AdminOfertasApuntesPage({ searchParams }: OfertasApuntesPageProps) {
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const sesion = await getSesion();

  if (!sesion || !sesion.esAdmin) {
    redirect(`${phpSiteUrl}/login`);
  }

  const { tutor = "" } = await searchParams;
  const apuntes = await getAdminOfertasApuntes(tutor || undefined);

  return (
    <>
      <Header titulo="Promo Apuntes" />
      <main className="pt-20 pb-28 md:pb-16 px-4 md:px-10 lg:ml-64 max-w-[1400px] mx-auto space-y-6">
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
          <div>
            <h1 className="text-xl md:text-2xl font-medium text-[#222222] tracking-[-0.01em]">Centro de Promos (Apuntes)</h1>
            <p className="text-sm text-gray-500 mt-1">Regala descargas estratégicas limitadas sin registro.</p>
          </div>
          <form method="get" className="w-full md:w-auto">
            <input
              type="text"
              name="tutor"
              defaultValue={tutor}
              placeholder="Buscar por tutor..."
              className="w-full md:w-64 bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:border-[#54A6D8] focus:bg-white outline-none"
            />
          </form>
        </div>

        <AdminOfertasApuntesPanel apuntes={apuntes} />
      </main>
    </>
  );
}
