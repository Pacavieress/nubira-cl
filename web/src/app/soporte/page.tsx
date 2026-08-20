import { redirect } from "next/navigation";
import { getMisTickets } from "@/lib/api";
import { getSesion } from "@/lib/sesion";
import { Header } from "@/components/Header";
import { SoporteLista } from "@/components/SoporteLista";

// Puerto de app/reclamos_sugerencias.php ("Centro de Ayuda") — mismo gate (línea 10: sin
// sesión -> /login). Ver server/src/modules/soporte/ y SoporteLista.tsx para las
// simplificaciones deliberadas documentadas (sin push a admin, sin selección múltiple por
// long-press).
export default async function SoportePage() {
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const sesion = await getSesion();

  if (!sesion) {
    redirect(`${phpSiteUrl}/login`);
  }

  const data = await getMisTickets();

  return (
    <>
      <Header titulo="Centro de Ayuda" />
      <main className="pt-20 pb-28 md:pb-16 px-4 md:px-8 lg:ml-64 max-w-4xl mx-auto">
        <SoporteLista ticketsIniciales={data?.tickets ?? []} />
      </main>
    </>
  );
}
