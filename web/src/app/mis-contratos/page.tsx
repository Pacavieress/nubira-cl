import { redirect } from "next/navigation";
import { getMisContratos } from "@/lib/api";
import { getSesion } from "@/lib/sesion";
import { Header } from "@/components/Header";
import { MisContratosTabs } from "@/components/MisContratosTabs";

// Puerto de app/mis_contratos.php — mismo gate (línea 9: sin sesión -> /login), mismas 2
// queries reales (soy comprador / soy vendedor). Página 100% de lectura en el PHP real
// también (confirmado con grep, sin $_POST/UPDATE/DELETE/INSERT) — sin acciones que portar.
export default async function MisContratosPage() {
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const sesion = await getSesion();

  if (!sesion) {
    redirect(`${phpSiteUrl}/login`);
  }

  const contratos = await getMisContratos();

  return (
    <>
      <Header titulo="Mis Contratos" />
      <main className="pt-20 pb-28 md:pb-16 px-4 md:px-8 lg:ml-64 max-w-[1600px] mx-auto">
        <header className="mb-6">
          <h1 className="text-2xl font-medium text-[#222222] tracking-[-0.01em]">Mis Contratos</h1>
          <p className="text-gray-500 text-sm mt-0.5">Gestiona el progreso de tus clases y servicios.</p>
        </header>

        <MisContratosTabs
          comoComprador={contratos?.comoComprador ?? []}
          comoVendedor={contratos?.comoVendedor ?? []}
          phpSiteUrl={phpSiteUrl}
        />
      </main>
    </>
  );
}
