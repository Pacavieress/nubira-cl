import { redirect } from "next/navigation";
import { getMisVentasClases } from "@/lib/api";
import { getSesion } from "@/lib/sesion";
import { Header } from "@/components/Header";
import { VentasClasesLista } from "@/components/VentasClasesLista";

// Puerto de lectura de app/ventas_clases.php — mismo gate (línea 9: sin sesión -> /login).
// Sin selección múltiple/"Ocultar" — ver ventasClases.types.ts en server/ para el porqué
// (esa acción real es un DELETE permanente de `contratos`, decisión explícita de dejarla
// para otra sesión aparte).
export default async function ClasesVendidasPage() {
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const sesion = await getSesion();

  if (!sesion) {
    redirect(`${phpSiteUrl}/login`);
  }

  const ventas = (await getMisVentasClases()) ?? [];

  return (
    <>
      <Header titulo="Mis Ganancias" />
      <main className="pt-20 pb-28 md:pb-16 px-4 md:px-8 lg:ml-64 max-w-[1000px] mx-auto">
        <header className="mb-2">
          <h1 className="text-lg md:text-xl font-medium text-[#222222] tracking-[-0.01em]">Mis Ganancias</h1>
        </header>

        <VentasClasesLista ventas={ventas} phpSiteUrl={phpSiteUrl} />
      </main>
    </>
  );
}
