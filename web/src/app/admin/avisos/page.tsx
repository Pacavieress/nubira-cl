import { redirect } from "next/navigation";
import { getAdminAvisos } from "@/lib/api";
import { getSesion } from "@/lib/sesion";
import { Header } from "@/components/Header";
import { AdminAvisosPanel } from "@/components/AdminAvisosPanel";

// Puerto de admin_avisos.php — métricas globales + historial de campañas + detalle de
// lectores. Ver AdminAvisosPanel.tsx para la nota de alcance sobre crear/enviar/eliminar/
// duplicar campaña, excluidos.
export default async function AdminAvisosPage() {
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const sesion = await getSesion();

  if (!sesion || !sesion.esAdmin) {
    redirect(`${phpSiteUrl}/login`);
  }

  const resumen = await getAdminAvisos();
  const campanas = resumen?.campanas ?? [];

  return (
    <>
      <Header titulo="Avisos" />
      <main className="pt-20 pb-28 md:pb-16 px-4 md:px-10 lg:ml-64 max-w-[1100px] mx-auto space-y-6">
        <div>
          <h1 className="text-xl md:text-2xl font-medium text-[#222222] tracking-[-0.01em]">Avisos a usuarios</h1>
          <p className="text-sm text-gray-500 mt-1">Historial de campañas enviadas (últimas 50).</p>
        </div>

        <div className="grid grid-cols-2 gap-3">
          <div className="bg-white rounded-2xl border border-gray-100 p-5">
            <p className="text-[11px] font-semibold uppercase tracking-wider text-gray-500 mb-1">Campañas enviadas</p>
            <p className="text-2xl font-bold text-[#222222]">{resumen?.totalCampanas ?? 0}</p>
          </div>
          <div className="bg-white rounded-2xl border border-gray-100 p-5">
            <p className="text-[11px] font-semibold uppercase tracking-wider text-gray-500 mb-1">Total destinatarios</p>
            <p className="text-2xl font-bold text-[#222222]">{resumen?.totalDestinatarios ?? 0}</p>
          </div>
        </div>

        <AdminAvisosPanel campanas={campanas} phpSiteUrl={phpSiteUrl} />
      </main>
    </>
  );
}
