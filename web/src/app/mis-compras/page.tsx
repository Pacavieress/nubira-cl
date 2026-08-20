import { redirect } from "next/navigation";
import { getMisCompras } from "@/lib/api";
import { getSesion } from "@/lib/sesion";
import { Header } from "@/components/Header";
import { ComprasAcordeon } from "@/components/ComprasAcordeon";

// Puerto de app/mis_compras.php — mismo gate (mis_compras.php:9: sin sesión -> /login),
// mismas 2 consultas reales (compras JOIN apuntes, contratos JOIN servicios+alumnos, ver
// server/src/modules/compras/), mismo acordeón cerrado por defecto.
//
// Sin acciones de escritura en esta página en el PHP real tampoco — es puramente
// lectura + 2 links de salida (ver apunte, ir al aula), así que no hace falta portar
// ningún endpoint más allá del GET ya construido.
export default async function MisComprasPage() {
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const sesion = await getSesion();

  if (!sesion) {
    redirect(`${phpSiteUrl}/login`);
  }

  const compras = await getMisCompras();
  const apuntes = compras?.apuntes ?? [];
  const servicios = compras?.servicios ?? [];

  return (
    <>
      <Header titulo="Mis Compras" />
      <main className="pt-20 pb-28 md:pb-16 px-4 md:px-8 lg:ml-64 max-w-[1000px] mx-auto">
        <header className="mb-2">
          <h1 className="text-xl md:text-2xl font-medium text-[#222222] tracking-[-0.01em]">Mis Compras</h1>
          <p className="text-gray-400 text-xs font-medium mt-0.5">Historial de tus adquisiciones en Nubira.</p>
        </header>

        <ComprasAcordeon apuntes={apuntes} servicios={servicios} phpSiteUrl={phpSiteUrl} />
      </main>
    </>
  );
}
