import { redirect } from "next/navigation";
import { getMisVentasApuntes } from "@/lib/api";
import { getSesion } from "@/lib/sesion";
import { Header } from "@/components/Header";
import { VentasApuntesLista } from "@/components/VentasApuntesLista";

// Puerto de lectura de app/ventas_apuntes.php — mismo gate (línea 9: sin sesión -> /login).
// Sin selección múltiple/swipe/"Ocultar" — ver ventasApuntes.types.ts en server/ para el
// porqué (DELETE permanente real, decisión explícita de dejarla para otra sesión).
export default async function VentasApuntesPage() {
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const sesion = await getSesion();

  if (!sesion) {
    redirect(`${phpSiteUrl}/login`);
  }

  const ventas = (await getMisVentasApuntes()) ?? [];

  return (
    <>
      <Header titulo="Ventas de Apuntes" />
      <main className="pt-20 pb-28 md:pb-16 px-4 md:px-8 lg:ml-64 max-w-[1000px] mx-auto">
        <header className="mb-2">
          <h1 className="text-xl md:text-2xl font-medium text-[#222222] tracking-[-0.01em]">Ventas de Apuntes</h1>
          <p className="text-gray-400 text-xs font-medium">Panel operativo de documentos.</p>
        </header>

        <VentasApuntesLista ventas={ventas} phpSiteUrl={phpSiteUrl} />
      </main>
    </>
  );
}
