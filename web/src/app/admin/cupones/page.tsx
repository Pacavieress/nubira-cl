import { redirect } from "next/navigation";
import { getAdminCupones } from "@/lib/api";
import { getSesion } from "@/lib/sesion";
import { Header } from "@/components/Header";
import { AdminCuponesPanel } from "@/components/AdminCuponesPanel";

// Puerto de cupones.php ("Bóveda de Becas"). Ver AdminCuponesPanel.tsx para la nota de
// alcance y de la mejora sobre el PHP real (título de servicio real en vez de solo #id).
export default async function AdminCuponesPage() {
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const sesion = await getSesion();

  if (!sesion || !sesion.esAdmin) {
    redirect(`${phpSiteUrl}/login`);
  }

  const { cupones, servicios } = await getAdminCupones();

  return (
    <>
      <Header titulo="Becas / Cupones" />
      <main className="pt-20 pb-28 md:pb-16 px-4 md:px-10 lg:ml-64 max-w-[1100px] mx-auto space-y-6">
        <div>
          <h1 className="text-xl md:text-2xl font-medium text-[#222222] tracking-[-0.01em]">Bóveda de Becas</h1>
          <p className="text-sm text-gray-500 mt-1">Control de beneficios y códigos de descuento.</p>
        </div>

        <AdminCuponesPanel cuponesIniciales={cupones} servicios={servicios} />
      </main>
    </>
  );
}
