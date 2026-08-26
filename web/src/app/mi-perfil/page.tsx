import { redirect } from "next/navigation";
import { getMiPerfil, type PerfilPropio } from "@/lib/api";
import { getSesion } from "@/lib/sesion";
import { Header } from "@/components/Header";
import { ServicioCard } from "@/components/ServicioCard";
import { ApunteCard } from "@/components/ApunteCard";
import { ResenaCard } from "@/components/ResenaCard";
import { PerfilPropioCard } from "@/components/PerfilPropioCard";

// Puerto de perfil.php con $es_propio=true — la vista de "mi propio perfil" (distinta de
// /tutores/[id], que ya cubre cómo un visitante ve el perfil de OTRO).
//
// [REHECHO — auditoría de fidelidad] La pasada anterior de este puerto (comentario viejo,
// ya no vigente, lo dejo acá como rastro): aplanaba perfil.php:499 (`grid grid-cols-1
// xl:grid-cols-[1fr_350px]`, max-w-[1600px]) en una sola columna `max-w-[1100px]` — mismo
// patrón de bug que tuvo detalle_servicio.php en su primera pasada. Corregido: el Panel de
// Gestión ahora vive en un <aside> sticky a la derecha en xl+ (perfil.php:1093-1104) y SOLO
// inline en el flujo en pantallas más chicas (perfil.php:749-756, `xl:hidden`) — mismo
// componente reusado dos veces por breakpoint, en vez de duplicar el JSX como hace el PHP
// (ese comentario de perfil.php:1093 ya documenta esa duplicación como deliberada, no bug).
// También se corrigió el ORDEN de contenido: reseñas ANTES de las publicaciones
// (perfil.php:758 antes de :872), no después — se había invertido en la pasada anterior.
//
// Alcance ya confirmado con el usuario (AskUserQuestion, sesión previa) y mantenido igual:
//   - Lista SIMPLE de accesos del Panel de Gestión, no el grid visual de 34 tiles con
//     íconos/colores/badges de panel_gestion.php — es un componente propio, mucho más
//     grande, candidato a pieza aparte.
//   - Servicios y apuntes en 2 grillas separadas, no en un único carrusel cronológico
//     intercalado (perfil.php:376-378) — la API de perfil propio no expone fecha de
//     publicación en ninguna de las 2 formas para poder ordenarlas juntas (mismo motivo ya
//     documentado en tutores/[id]/page.tsx para el caso de visitante).
//   - Reseñas Tutor/Alumno apiladas en 2 grillas en vez del switcher de tabs con scroll
//     horizontal de perfil.php:758-869 (misma simplificación ya usada en tutores/[id]).
//   - Sin subida de foto inline (el botón "Foto" del banner enlaza al sitio PHP real).
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
      {/* lg:pl-64 en vez de lg:ml-64: bajo <body class="flex flex-col"> (web/src/app/layout.tsx),
          un margin-left fijo no se resta del ancho estirado del hijo (align-items:stretch),
          así que el elemento se estira a los 1440px completos del contenedor y el margen lo
          empuja fuera del viewport — mismo bug ya encontrado y corregido en
          servicios/[id]/page.tsx y en apuntes/busqueda/servicios/guias (ver ese archivo para
          el diagnóstico completo). Confirmado con scrollWidth vía CDP: 241px de overflow
          real con lg:ml-64 en esta página también, antes de este fix. */}
      <main className="w-full max-w-[1600px] mx-auto px-4 md:px-8 pt-20 pb-24 lg:pb-16 lg:pl-64">
        <div className="grid grid-cols-1 xl:grid-cols-[1fr_350px] gap-6 md:gap-8 items-start">
          <div className="space-y-6 min-w-0">
            <PerfilPropioCard perfil={perfil} phpSiteUrl={phpSiteUrl} />

            {/* Panel de Gestión — solo en el flujo normal por debajo de xl; en xl+ vive en
                el <aside> sticky de la derecha (ver más abajo). Mismo patrón de doble
                render por breakpoint que panel_gestion.php, documentado como deliberado. */}
            {perfil.accesos.length > 0 && (
              <section className="xl:hidden">
                <PanelGestionLista accesos={perfil.accesos} />
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
          </div>

          {/* Puerto de perfil.php:1093-1104 (<aside class="hidden xl:block">). */}
          {perfil.accesos.length > 0 && (
            <aside className="hidden xl:block">
              <div className="sticky top-24">
                <PanelGestionLista accesos={perfil.accesos} />
              </div>
            </aside>
          )}
        </div>
      </main>
    </>
  );
}

// Puerto de accesos_user (panel_gestion.php) como lista simple — ver nota de alcance en el
// comentario de la página. Extraído a su propio componente porque se renderiza 2 veces
// (mobile inline / desktop sticky), igual que el include de panel_gestion.php en el PHP real.
function PanelGestionLista({ accesos }: { accesos: PerfilPropio["accesos"] }) {
  return (
    <div className="bg-white border border-gray-100 rounded-2xl divide-y divide-gray-50 overflow-hidden">
      {accesos.map((acceso) => (
        <a key={acceso.href} href={acceso.href} className="flex items-center justify-between px-5 py-3.5 hover:bg-gray-50 transition-colors">
          <span className="text-sm font-medium text-[#222222]">{acceso.titulo}</span>
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-4 h-4 text-gray-300">
            <path strokeLinecap="round" strokeLinejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
          </svg>
        </a>
      ))}
    </div>
  );
}
