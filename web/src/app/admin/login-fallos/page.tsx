import { redirect } from "next/navigation";
import { getAdminMonitoreo } from "@/lib/api";
import { getSesion } from "@/lib/sesion";
import { Header } from "@/components/Header";
import { AdminLoginFallosPanel } from "@/components/AdminLoginFallosPanel";

// Puerto de admin_login_fallos.php ("Log Fail" / "Centro de Monitoreo"). Ver
// AdminLoginFallosPanel.tsx para la nota de alcance sobre 'eliminar_pendiente', excluido.
export default async function AdminLoginFallosPage() {
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const sesion = await getSesion();

  if (!sesion || !sesion.esAdmin) {
    redirect(`${phpSiteUrl}/login`);
  }

  const resumen = await getAdminMonitoreo("fallos", 1);
  if (!resumen) redirect(`${phpSiteUrl}/login`);

  return (
    <>
      <Header titulo="Log Fail" />
      <main className="pt-20 pb-28 md:pb-16 px-4 md:px-10 lg:ml-64 max-w-[1400px] mx-auto space-y-6">
        <div>
          <h1 className="text-xl md:text-2xl font-medium text-[#222222] tracking-[-0.01em]">Centro de Monitoreo</h1>
          <p className="text-sm text-gray-500 mt-1">Gestión de accesos, seguridad y registros.</p>
        </div>

        <AdminLoginFallosPanel resumenInicial={resumen} phpSiteUrl={phpSiteUrl} />
      </main>
    </>
  );
}
