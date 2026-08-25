import { redirect } from "next/navigation";
import { getAdminAccesos } from "@/lib/api";
import { getSesion } from "@/lib/sesion";
import { Header } from "@/components/Header";
import { AdminAccesosPanel } from "@/components/AdminAccesosPanel";

// Puerto de admin_accesos_vitrina.php ("Analíticas") — 4 tabs (Tráfico Real, Bots/Crawlers,
// Top Páginas, Búsquedas Fallidas) + vista de detalle por usuario/invitado + exportación CSV.
// Portado completo, incluidas sus 2 mutaciones (eliminar selección de eventos, purgar bots
// antiguos) — confirmado explícitamente con el usuario antes de construir: son DELETE puros
// sobre `historial_actividad` (log de analítica), mismo nivel de riesgo ya aceptado en los
// toggles de paneles anteriores. Ver AdminAccesosPanel.tsx para la simplificación deliberada
// de la geolocalización (sin mapas/tooltips) y server/.../adminAccesos.types.ts para el resto
// de notas de fidelidad.
export default async function AdminAccesosPage() {
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const sesion = await getSesion();

  if (!sesion || !sesion.esAdmin) {
    redirect(`${phpSiteUrl}/login`);
  }

  const resumenInicial = await getAdminAccesos("trafico");

  return (
    <>
      <Header titulo="Accesos" />
      <main className="pt-20 pb-28 md:pb-16 px-4 md:px-10 lg:ml-64 max-w-[1400px] mx-auto space-y-6">
        <div>
          <h1 className="text-xl md:text-2xl font-medium text-[#222222] tracking-[-0.01em]">Analíticas</h1>
          <p className="text-sm text-gray-500 mt-1">Auditoría, tráfico y demandas de contenido.</p>
        </div>

        <AdminAccesosPanel resumenInicial={resumenInicial} phpSiteUrl={phpSiteUrl} />
      </main>
    </>
  );
}
