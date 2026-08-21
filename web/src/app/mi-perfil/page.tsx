import { redirect } from "next/navigation";
import { getMiPerfil } from "@/lib/api";
import { getSesion } from "@/lib/sesion";
import { Header } from "@/components/Header";
import { ServicioCard } from "@/components/ServicioCard";
import { ApunteCard } from "@/components/ApunteCard";
import { ResenaCard } from "@/components/ResenaCard";
import { PerfilPropioCard } from "@/components/PerfilPropioCard";

// Puerto de perfil.php con $es_propio=true — la vista de "mi propio perfil" (distinta de
// /tutores/[id], que ya cubre cómo un visitante ve el perfil de OTRO). Alcance confirmado
// con el usuario (AskUserQuestion): header + banner de completitud + bio editable +
// gamificación "Tu Nivel de Tutor" + lista SIMPLE de accesos (sin el grid visual de 34
// tiles de panel_gestion.php, sin badges de contador — pieza aparte, más grande).
export default async function MiPerfilPage() {
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const sesion = await getSesion();

  if (!sesion) {
    redirect(`${phpSiteUrl}/login?redir=${encodeURIComponent("/mi-perfil")}`);
  }

  const perfil = await getMiPerfil();
  if (!perfil) {
    redirect(`${phpSiteUrl}/login?redir=${encodeURIComponent("/mi-perfil")}`);
  }

  return (
    <>
      <Header titulo="Mi Perfil" />
      <main className="w-full max-w-[1100px] mx-auto px-4 md:px-8 pt-20 pb-24 lg:pb-16 lg:ml-64 space-y-6">
        <PerfilPropioCard perfil={perfil} phpSiteUrl={phpSiteUrl} />

        {/* Puerto de accesos_user (panel_gestion.php) como lista simple — ver nota de alcance arriba. */}
        {perfil.accesos.length > 0 && (
          <section>
            <h2 className="text-lg font-medium tracking-[-0.01em] text-[#222222] mb-4">Panel de gestión</h2>
            <div className="bg-white border border-gray-100 rounded-2xl divide-y divide-gray-50 overflow-hidden">
              {perfil.accesos.map((acceso) => (
                <a
                  key={acceso.href}
                  href={acceso.href}
                  className="flex items-center justify-between px-5 py-3.5 hover:bg-gray-50 transition-colors"
                >
                  <span className="text-sm font-medium text-[#222222]">{acceso.titulo}</span>
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-4 h-4 text-gray-300">
                    <path strokeLinecap="round" strokeLinejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                  </svg>
                </a>
              ))}
            </div>
          </section>
        )}

        {perfil.servicios.length > 0 && (
          <section>
            <h2 className="text-lg font-medium tracking-[-0.01em] text-[#222222] mb-4">Servicios</h2>
            <div className="grid grid-cols-2 sm:grid-cols-2 md:grid-cols-3 gap-4 md:gap-6">
              {perfil.servicios.map((servicio) => (
                <ServicioCard key={servicio.id} servicio={servicio} />
              ))}
            </div>
          </section>
        )}

        {perfil.apuntes.length > 0 && (
          <section>
            <h2 className="text-lg font-medium tracking-[-0.01em] text-[#222222] mb-4">Apuntes</h2>
            <div className="grid grid-cols-2 sm:grid-cols-2 md:grid-cols-3 gap-4 md:gap-6">
              {perfil.apuntes.map((apunte) => (
                <ApunteCard key={apunte.id} apunte={apunte} />
              ))}
            </div>
          </section>
        )}

        {perfil.resenasComoTutor.length > 0 && (
          <section>
            <h2 className="text-lg font-medium tracking-[-0.01em] text-[#222222] mb-4">
              Reseñas como tutor ({perfil.rating.votos})
            </h2>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              {perfil.resenasComoTutor.map((r) => (
                <ResenaCard key={r.id} resena={r} />
              ))}
            </div>
          </section>
        )}

        {perfil.resenasComoAlumno.length > 0 && (
          <section>
            <h2 className="text-lg font-medium tracking-[-0.01em] text-[#222222] mb-4">
              Reseñas como alumno ({perfil.resenasComoAlumno.length})
            </h2>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              {perfil.resenasComoAlumno.map((r) => (
                <ResenaCard key={r.id} resena={r} />
              ))}
            </div>
          </section>
        )}
      </main>
    </>
  );
}
