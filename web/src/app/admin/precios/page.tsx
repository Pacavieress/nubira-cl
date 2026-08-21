import { redirect } from "next/navigation";
import { getAdminConfigPrecios } from "@/lib/api";
import { getSesion } from "@/lib/sesion";
import { Header } from "@/components/Header";
import { AdminConfigPreciosPanel } from "@/components/AdminConfigPreciosPanel";

// Puerto de admin_config_precios.php — configuración global de monetización (tabla config,
// key-value): precio base de desbloqueo de contacto + promo "Costo Cero" con fecha de
// término. Sin acciones destructivas, solo 2 UPDATE. Mismo gate que el resto de /admin/*.
export default async function AdminPreciosPage() {
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const sesion = await getSesion();

  if (!sesion || !sesion.esAdmin) {
    redirect(`${phpSiteUrl}/login`);
  }

  const config = await getAdminConfigPrecios();
  if (!config) {
    redirect(`${phpSiteUrl}/login`);
  }

  return (
    <>
      <Header titulo="Finanzas y Pricing" />
      <main className="pt-20 pb-28 md:pb-16 px-4 md:px-10 lg:ml-64 max-w-[1200px] mx-auto">
        <header className="mb-6">
          <h1 className="text-xl md:text-2xl font-medium text-[#222222] tracking-[-0.01em]">Finanzas y Pricing</h1>
          <p className="text-sm text-gray-500 mt-1">Ajusta los valores de monetización global.</p>
        </header>

        <AdminConfigPreciosPanel configInicial={config} />
      </main>
    </>
  );
}
