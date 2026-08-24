import { redirect } from "next/navigation";
import { getAdminAulas } from "@/lib/api";
import { getSesion } from "@/lib/sesion";
import { Header } from "@/components/Header";
import { AdminAulasPanel } from "@/components/AdminAulasPanel";

// Puerto de admin_chats_aula.php ("Monitor Aulas") — 100% lectura, sin ninguna acción de
// escritura en el PHP real. Ver AdminAulasPanel.tsx para el detalle de la pieza y la nota
// sobre por qué "Monitor Chats" (admin_chats.php, sistema DLP completo) queda fuera de
// esta ronda.
export default async function AdminAulasPage() {
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const sesion = await getSesion();

  if (!sesion || !sesion.esAdmin) {
    redirect(`${phpSiteUrl}/login`);
  }

  const aulas = await getAdminAulas(undefined, "desc");

  return (
    <>
      <Header titulo="Monitor Aulas" />
      <main className="pt-20 pb-6 md:pb-10 px-4 md:px-10 lg:ml-64 max-w-[1400px] mx-auto space-y-4">
        <div>
          <h1 className="text-xl md:text-2xl font-medium text-[#222222] tracking-[-0.01em]">Monitor Aulas</h1>
          <p className="text-sm text-gray-500 mt-1">Auditoría de historial de chat pre-venta + aula virtual.</p>
        </div>

        <AdminAulasPanel aulasIniciales={aulas} ordenInicial="desc" />
      </main>
    </>
  );
}
