import { redirect } from "next/navigation";
import { getDatosBancariosParaEditar } from "@/lib/api";
import { getSesion } from "@/lib/sesion";
import { Header } from "@/components/Header";
import { EditarDatosBancariosForm } from "@/components/EditarDatosBancariosForm";

// Puerto de app/editar_datos_bancarios.php — mismo gate (línea 9: sin sesión -> /login).
// Sin el spinner "loader" ni las 2 líneas de "volver" con fallback a /datos_bancarios (JS
// puro de UX, cosmético) — el resto (bancos reales desde la tabla `bancos`, mismas 5
// validaciones, mismo INSERT-o-UPDATE) sí está completo, ver
// server/src/modules/miBilletera/miBilletera.controller.ts::putMiDatosBancarios.
export default async function EditarDatosBancariosPage() {
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const sesion = await getSesion();

  if (!sesion) {
    redirect(`${phpSiteUrl}/login`);
  }

  const data = await getDatosBancariosParaEditar();

  return (
    <>
      <Header titulo="Datos Bancarios" />
      <main className="pt-20 pb-28 md:pb-16 px-4 md:px-8 lg:pl-64 max-w-[800px] mx-auto">
        <header className="mb-6">
          <h1 className="text-xl md:text-2xl font-medium tracking-[-0.01em] text-[#222222]">Datos Bancarios</h1>
          <p className="text-gray-400 text-xs font-medium mt-0.5">Configura dónde recibirás tus ganancias.</p>
        </header>

        <EditarDatosBancariosForm bancos={data?.bancos ?? []} datosIniciales={data?.datos ?? null} />
      </main>
    </>
  );
}
