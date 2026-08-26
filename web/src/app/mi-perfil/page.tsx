import Link from "next/link";
import { redirect } from "next/navigation";
import { getMiPerfil, type PerfilPropio } from "@/lib/api";
import { getSesion } from "@/lib/sesion";
import { Header } from "@/components/Header";
import { ServicioCard } from "@/components/ServicioCard";
import { ApunteCard } from "@/components/ApunteCard";
import { ResenaCard } from "@/components/ResenaCard";
import { PerfilPropioCard } from "@/components/PerfilPropioCard";
import { ACCESOS_ADMIN } from "@/app/admin/admin-accesos";

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
//   - Servicios y apuntes en 2 grillas separadas, no en un único carrusel cronológico
//     intercalado (perfil.php:376-378) — la API de perfil propio no expone fecha de
//     publicación en ninguna de las 2 formas para poder ordenarlas juntas (mismo motivo ya
//     documentado en tutores/[id]/page.tsx para el caso de visitante).
//   - Reseñas Tutor/Alumno apiladas en 2 grillas en vez del switcher de tabs con scroll
//     horizontal de perfil.php:758-869 (misma simplificación ya usada en tutores/[id]).
//   - Sin subida de foto inline (el botón "Foto" del banner enlaza al sitio PHP real).
export default async function MiPerfilPage() {
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const nextjsSiteUrl = process.env.NEXTJS_SITE_URL ?? "http://nubira.local:3000";
  const sesion = await getSesion();

  // [26/08/2026] Bug real encontrado y corregido: redir ANTES era relativo
  // (encodeURIComponent("/mi-perfil")) — login.php lo resolvía contra SU PROPIO dominio
  // (nubira.local), y como /mi-perfil no existe en .htaccess (es exclusivo de Next.js, sin
  // equivalente PHP), el usuario terminaba en http://nubira.local/mi-perfil, 404 real. Fix:
  // redir ABSOLUTO hacia nextjsSiteUrl — login.php ahora acepta esto vía
  // NEXTJS_TRUSTED_ORIGINS (app/config.php) + nb_redir_es_seguro() (app/helpers/redir_seguro.php).
  if (!sesion) {
    redirect(`${phpSiteUrl}/login?redir=${encodeURIComponent(`${nextjsSiteUrl}/mi-perfil`)}`);
  }

  const perfil = await getMiPerfil();
  if (!perfil) {
    redirect(`${phpSiteUrl}/login?redir=${encodeURIComponent(`${nextjsSiteUrl}/mi-perfil`)}`);
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
                <PanelGestion accesos={perfil.accesos} esAdmin={sesion.esAdmin} phpSiteUrl={phpSiteUrl} />
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
                <PanelGestion accesos={perfil.accesos} esAdmin={sesion.esAdmin} phpSiteUrl={phpSiteUrl} />
              </div>
            </aside>
          )}
        </div>
      </main>
    </>
  );
}

// Puerto EXACTO de panel_gestion.php:111-228 — grid Bento de íconos (no la lista plana
// simplificada que tenía esta pieza antes, ver AskUserQuestion + confirmación explícita de
// Pablo, 26/08/2026: "el espejo se limita a estructura + navegación + gating condicional").
// Se renderiza 2 veces (mobile inline / desktop sticky) igual que el include del PHP real
// — mismas clases Tailwind copiadas 1:1 de cada bloque (tiles de usuario y tiles admin
// tienen tamaños distintos en el PHP real: w-10/w-12 vs w-9/w-10 — no es un descuido acá).
//
// Deliberadamente NO portado (documentado, no olvidado):
//   - Badges de contador en vivo (mensajes no leídos, alertas admin) y el punto rojo
//     animado de "Mi Billetera" con saldo pendiente de datos bancarios — misma decisión ya
//     tomada para /admin (ver admin-accesos.ts): sin badges dinámicos en esta pieza. El
//     caso de "Mi Billetera" además ya tiene su propio aviso, no silencioso: el banner de
//     completitud más arriba en la página (PerfilPropioCard) ya usa
//     completitud.faltaBanco para mostrar el mismo aviso de forma más visible.
//   - Pills "Nuevo" con auto-hide a 14 días (panel_gestion.php:188-193/221-223) — requieren
//     JS de cliente con localStorage; cosmético, fuera del alcance de "estructura +
//     navegación + gating" acordado.
//   - El link "Cerrar Sesión" que panel_gestion.php:230-235 incluye dentro del propio
//     componente: en este puerto ya existe en cada breakpoint por otra vía (Sidebar.tsx
//     desde lg+, BottomNav.tsx debajo de lg) — repetirlo acá sería un 3er logout visible
//     en el rango lg-xl, no una fidelidad real ganada.
function PanelGestion({ accesos, esAdmin, phpSiteUrl }: { accesos: PerfilPropio["accesos"]; esAdmin: boolean; phpSiteUrl: string }) {
  return (
    <div className="w-full space-y-8">
      <div className="grid grid-cols-2 md:grid-cols-3 gap-x-3 gap-y-0.5 md:gap-y-3 w-full">
        {accesos.map((acceso) => (
          <Link
            key={acceso.href}
            href={acceso.href}
            className="group relative flex flex-row md:flex-col items-center gap-2.5 md:gap-3 py-3 px-2 md:p-4 rounded-xl md:rounded-2xl md:bg-white md:border md:border-gray-100 hover:bg-gray-50 md:hover:bg-gray-50/50 md:hover:border-gray-200 md:hover:shadow-[0_2px_8px_rgba(0,0,0,0.06)] active:scale-[0.99] md:hover:scale-[1.01] md:active:scale-[0.98] transition-[transform,border-color,background-color,box-shadow] duration-150 ease-out select-none cursor-pointer md:text-center h-full"
          >
            <div className="w-10 h-10 md:w-12 md:h-12 rounded-lg md:rounded-xl bg-blue-50 text-[#54A6D8] flex items-center justify-center shrink-0 md:group-hover:scale-105 transition-transform duration-200 relative">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
                strokeWidth={1.5}
                stroke="currentColor"
                className="w-5 h-5 md:w-6 md:h-6"
                dangerouslySetInnerHTML={{ __html: acceso.iconoSvg }}
              />
            </div>
            <span className="text-sm md:text-[13px] font-bold text-gray-700 group-hover:text-gray-900 tracking-tight leading-snug md:leading-tight transition-colors duration-200">
              {acceso.titulo}
            </span>
          </Link>
        ))}
      </div>

      {/* Puerto de panel_gestion.php:199-228 — reutiliza ACCESOS_ADMIN tal cual (mismo
          catálogo ya verificado que usa /admin), gated a sesion.esAdmin. */}
      {esAdmin && (
        <div className="pt-6 border-t border-gray-100 flex flex-col">
          <h3 className="text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-4 pl-1">Administración</h3>
          <div className="grid grid-cols-2 md:grid-cols-3 gap-x-3 gap-y-0.5 md:gap-y-3 w-full">
            {ACCESOS_ADMIN.map((acceso) => {
              const claseTile =
                "group relative flex flex-row md:flex-col items-center gap-2 md:gap-2.5 py-2.5 px-2 md:p-3.5 rounded-lg md:rounded-xl md:bg-white md:border md:border-gray-100 hover:bg-gray-50 md:hover:bg-gray-50/50 md:hover:border-gray-200 md:hover:shadow-[0_2px_8px_rgba(0,0,0,0.06)] active:scale-[0.99] md:hover:scale-[1.01] md:active:scale-[0.98] transition-[transform,border-color,background-color,box-shadow] duration-150 ease-out select-none cursor-pointer md:text-center h-full";
              const contenido = (
                <>
                  <div className="w-9 h-9 md:w-10 md:h-10 rounded-md md:rounded-lg bg-gray-50 text-gray-500 flex items-center justify-center shrink-0 md:group-hover:scale-105 transition-transform duration-200 relative">
                    <svg
                      xmlns="http://www.w3.org/2000/svg"
                      fill="none"
                      viewBox="0 0 24 24"
                      strokeWidth={1.5}
                      stroke="currentColor"
                      className="w-[18px] h-[18px] md:w-5 md:h-5"
                      dangerouslySetInnerHTML={{ __html: acceso.iconoSvg }}
                    />
                  </div>
                  <span className="text-[13px] font-semibold text-gray-700 group-hover:text-gray-900 tracking-tight leading-snug md:leading-tight transition-colors duration-200">
                    {acceso.titulo}
                  </span>
                </>
              );

              return acceso.interno ? (
                <Link key={acceso.href} href={acceso.href} className={claseTile}>
                  {contenido}
                </Link>
              ) : (
                <a key={acceso.href} href={`${phpSiteUrl}${acceso.href}`} target="_blank" rel="noopener noreferrer" className={claseTile}>
                  {contenido}
                </a>
              );
            })}
          </div>
        </div>
      )}
    </div>
  );
}
