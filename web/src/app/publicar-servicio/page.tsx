import { redirect } from "next/navigation";
import { getSesion } from "@/lib/sesion";
import { Header } from "@/components/Header";
import { PublicarServicioForm } from "@/components/PublicarServicioForm";

// Puerto de app/publicar_servicio.php — mismo gate (sin sesión -> /login). Ver
// PublicarServicioForm.tsx para el detalle de alcance (sin IA, sin video, sin pago de
// republicación).
export default async function PublicarServicioPage() {
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const sesion = await getSesion();

  if (!sesion) {
    redirect(`${phpSiteUrl}/login?redir=${encodeURIComponent("/publicar-servicio")}`);
  }

  return (
    <>
      <Header titulo="Publicar Servicio" />
      <main className="pt-20 pb-28 md:pb-16 px-4 md:px-8 lg:ml-64 max-w-[1100px] mx-auto">
        <div className="mb-6">
          <h1 className="text-xl md:text-2xl font-bold text-gray-900 tracking-tight">Publicar Servicio</h1>
          <p className="text-gray-500 text-sm mt-0.5">Configura tu oferta académica</p>
        </div>
        <PublicarServicioForm />
      </main>
    </>
  );
}
