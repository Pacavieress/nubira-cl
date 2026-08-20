import { redirect } from "next/navigation";
import { getMisEvaluaciones } from "@/lib/api";
import { getSesion } from "@/lib/sesion";
import { Header } from "@/components/Header";
import { EvaluacionesTabs } from "@/components/EvaluacionesTabs";

// Puerto de app/mis_evaluaciones.php — mismo gate (línea 29: sin sesión -> /login), mismas
// 2 consultas por rol_evaluado (ver server/src/modules/evaluaciones/ para el hallazgo real
// sobre por qué nunca hay apellido/foto de evaluador — comportamiento replicado a
// propósito, no un límite de este puerto). Página puramente de lectura, sin acciones.
export default async function MisEvaluacionesPage() {
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const sesion = await getSesion();

  if (!sesion) {
    redirect(`${phpSiteUrl}/login`);
  }

  const evaluaciones = await getMisEvaluaciones();
  const resenasComoTutor = evaluaciones?.resenasComoTutor ?? [];
  const resenasComoAlumno = evaluaciones?.resenasComoAlumno ?? [];

  return (
    <>
      <Header titulo="Mis Evaluaciones" />
      <main className="pt-16 pb-28 md:pb-16 lg:ml-64 max-w-[1000px] mx-auto">
        <div className="sticky top-14 bg-white/95 backdrop-blur-sm z-30 border-b border-gray-100 px-4 md:px-6 py-4">
          <h1 className="text-xl md:text-2xl font-medium text-[#222222] tracking-[-0.01em]">Mis Evaluaciones</h1>
          <p className="text-gray-400 text-xs font-medium">Historial de reputación y comentarios.</p>
        </div>

        <div className="md:px-6">
          <EvaluacionesTabs resenasComoTutor={resenasComoTutor} resenasComoAlumno={resenasComoAlumno} />
        </div>
      </main>
    </>
  );
}
