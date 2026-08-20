import { redirect } from "next/navigation";
import { getDesafioMaterias } from "@/lib/api";
import { getSesion } from "@/lib/sesion";
import { Header } from "@/components/Header";
import { DesafioJuego } from "@/components/DesafioJuego";

// Puerto de app/desafio.php ("Desafío Nubira de hoy") — mismo gate (línea 11: sin sesión ->
// /login?redir=/desafio). Ver DesafioJuego.tsx para el detalle de las 3 pantallas
// (materia -> preguntas con timer -> resultado) y las simplificaciones deliberadas
// documentadas ahí (sin "Compartir").
export default async function DesafioPage() {
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const sesion = await getSesion();

  if (!sesion) {
    redirect(`${phpSiteUrl}/login?redir=${encodeURIComponent("/desafio")}`);
  }

  const materias = await getDesafioMaterias();

  return (
    <>
      <Header titulo="Desafío Nubira de hoy" />
      <main className="pt-20 pb-28 md:pb-16 px-4 md:px-8 lg:ml-64 max-w-full">
        <div className="max-w-[640px] mx-auto mb-6">
          <h1 className="text-xl md:text-2xl font-medium tracking-[-0.01em] text-[#222222]">Desafío Nubira de hoy</h1>
          <p className="text-gray-400 text-xs font-medium mt-0.5">3 preguntas rápidas de tu ramo.</p>
        </div>
        <DesafioJuego materias={materias} />
      </main>
    </>
  );
}
