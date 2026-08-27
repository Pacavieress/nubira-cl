import { redirect } from "next/navigation";
import { getAdminDespertarDormidos } from "@/lib/api";
import { getSesion } from "@/lib/sesion";
import { Header } from "@/components/Header";
import { AdminDespertarDormidosPanel } from "@/components/AdminDespertarDormidosPanel";

// Puerto de app/enviar_despertar_dormidos.php (modo WEB) — panel "Despertar Dormidos",
// autorizado explícitamente por el usuario con envío real de correo. Ver
// server/src/modules/adminDespertarDormidos/adminDespertarDormidos.types.ts para las 3
// correcciones deliberadas vs. el PHP real (unsubscribe real, tope de destinatarios,
// guard anti-doble-submit).
export default async function AdminDespertarDormidosPage() {
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const sesion = await getSesion();

  if (!sesion || !sesion.esAdmin) {
    redirect(`${phpSiteUrl}/login`);
  }

  const resumen = await getAdminDespertarDormidos();

  return (
    <>
      <Header titulo="Despertar Dormidos" />
      <main className="pt-20 pb-28 md:pb-16 px-4 md:px-10 lg:ml-64 max-w-[1400px] mx-auto space-y-6">
        <div>
          <h1 className="text-xl md:text-2xl font-medium text-[#222222] tracking-[-0.01em]">Campaña: Despertar Dormidos</h1>
          <p className="text-sm text-gray-500 mt-1">Usuarios confirmados que nunca publicaron ni contrataron.</p>
        </div>

        <AdminDespertarDormidosPanel resumenInicial={resumen ?? { usuarios: [], stats: { total: 0, enviados: 0, pendientes: 0, fallidos: 0 } }} />
      </main>
    </>
  );
}
