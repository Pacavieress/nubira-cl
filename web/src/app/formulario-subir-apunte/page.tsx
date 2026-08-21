import { redirect } from "next/navigation";
import { getSesion } from "@/lib/sesion";
import { Header } from "@/components/Header";
import { PublicarApunteForm } from "@/components/PublicarApunteForm";

// Puerto de app/formulario_subir_apunte.php — mismo gate (sin sesión -> /login). Ver
// PublicarApunteForm.tsx para el detalle de alcance (sin IA, sin créditos IA, sin escáner
// de cámara, sin selector de página de portada para PDF).
export default async function FormularioSubirApuntePage() {
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const sesion = await getSesion();

  if (!sesion) {
    redirect(`${phpSiteUrl}/login?redir=${encodeURIComponent("/formulario-subir-apunte")}`);
  }

  return (
    <>
      <Header titulo="Publicar Apunte" />
      <main className="pt-20 pb-28 md:pb-16 px-4 md:px-8 lg:ml-64 max-w-[1100px] mx-auto">
        <div className="mb-6">
          <h1 className="text-xl md:text-2xl font-bold text-gray-900 tracking-tight">Publicar Apunte</h1>
          <p className="text-gray-500 text-sm mt-0.5">Comparte y monetiza tu conocimiento.</p>
        </div>
        <PublicarApunteForm />
      </main>
    </>
  );
}
