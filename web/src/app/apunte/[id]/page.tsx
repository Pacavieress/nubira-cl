import { notFound } from "next/navigation";
import { getApunteDetalle } from "@/lib/api";
import { formatoCLP } from "@/lib/formato";
import { abreviarNombre, inicial } from "@/lib/texto";
import { Header } from "@/components/Header";
import { CompartirApunteBoton } from "@/components/CompartirApunteBoton";
import { ComprarInvitadoApunteBoton } from "@/components/ComprarInvitadoApunteBoton";
import { VisorApunte } from "@/components/VisorApunte";
import { VolverButton } from "@/components/VolverButton";

interface DetalleProps {
  params: Promise<{ id: string }>;
}

// Iconos puerto de app/iconos.php — mismos paths que icon('publish-doc')/'check-circle'/'building',
// reusados acá para no depender de FontAwesome (que el PHP sí carga vía CDN).
function IconDescargarDoc({ className }: { className: string }) {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" className={className}>
      <path
        fillRule="evenodd"
        clipRule="evenodd"
        d="M5.625 1.5c-1.036 0-1.875.84-1.875 1.875v17.25c0 1.035.84 1.875 1.875 1.875h12.75c1.035 0 1.875-.84 1.875-1.875V12.75A3.75 3.75 0 0016.5 9h-1.875a1.875 1.875 0 01-1.875-1.875V5.25A3.75 3.75 0 009 1.5H5.625zM7.5 15a.75.75 0 01.75-.75h7.5a.75.75 0 010 1.5h-7.5A.75.75 0 017.5 15zm.75 2.25a.75.75 0 000 1.5H12a.75.75 0 000-1.5H8.25z"
      />
      <path d="M12.971 1.816A5.23 5.23 0 0114.25 5.25v1.875c0 .207.168.375.375.375H16.5a5.23 5.23 0 013.434 1.279 9.768 9.768 0 00-6.963-6.963z" />
    </svg>
  );
}

function IconCheckCircle({ className }: { className: string }) {
  return (
    <svg className={className} fill="currentColor" viewBox="0 0 20 20">
      <path
        fillRule="evenodd"
        d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
        clipRule="evenodd"
      />
    </svg>
  );
}

function IconBuilding({ className }: { className: string }) {
  return (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path
        strokeLinecap="round"
        strokeLinejoin="round"
        strokeWidth={1.5}
        d="M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6m-1.5 12V10.332A48.36 48.36 0 0012 9.75c-2.551 0-5.056.2-7.5.582V21M3 21h18M12 6.75h.008v.008H12V6.75z"
      />
    </svg>
  );
}

function IconLock({ className }: { className: string }) {
  return (
    <svg className={className} fill="currentColor" viewBox="0 0 24 24">
      <path
        fillRule="evenodd"
        clipRule="evenodd"
        d="M12 1.5a5.25 5.25 0 00-5.25 5.25v3a3 3 0 00-3 3v6.75a3 3 0 003 3h10.5a3 3 0 003-3v-6.75a3 3 0 00-3-3v-3c0-2.9-2.35-5.25-5.25-5.25zm3.75 8.25v-3a3.75 3.75 0 10-7.5 0v3h7.5z"
      />
    </svg>
  );
}

function IconUser({ className }: { className: string }) {
  return (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={1.5}>
      <path
        strokeLinecap="round"
        strokeLinejoin="round"
        d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"
      />
    </svg>
  );
}

function IconPencil({ className }: { className: string }) {
  return (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={1.5}>
      <path
        strokeLinecap="round"
        strokeLinejoin="round"
        d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L6.832 19.82a4.5 4.5 0 01-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 011.13-1.897L16.863 4.487zm0 0L19.5 7.125"
      />
    </svg>
  );
}

// Alcance: mismo criterio que servicios/[id] — SOLO info pública de lectura, salvo el
// checkout invitado (GET a /iniciar-pago en el sitio PHP, sin procesar nada acá) y el CTA
// de 5/6 ramas, ya portados. Fuera de alcance a propósito (ver apuntes.types.ts en server/):
// el countdown + overlay de bloqueo del visor (msgLocked, mecanismo de conversión — pausado
// a pedido explícito, se decide aparte) y el carrusel de recomendados de ver_apunte.php.
//
// Ruta singular /apunte/[id] (no /apuntes/[id]) — corregido para ser fiel a la ruta real
// (ver_apunte.php vive en /apunte/{hash}). Antes vivía en /apuntes/[id], que colisionaría
// con /apuntes/[cat] (landing SEO por categoría, landing_categoria.php con tipo=apuntes,
// misma ruta plural que el listado) al portar esa landing a web/.
export default async function DetalleApunte({ params }: DetalleProps) {
  const { id } = await params;
  const apunteId = Number(id);
  if (!Number.isInteger(apunteId) || apunteId <= 0) {
    notFound();
  }

  const apunte = await getApunteDetalle(apunteId);
  if (!apunte) {
    notFound();
  }

  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const nextjsSiteUrl = process.env.NEXTJS_SITE_URL ?? "http://nubira.local:3000";

  // Puerto de $login_redir (ver_apunte.php:313): redir de vuelta a esta misma página tras
  // loguearse. Se arma server-side (con el id ya conocido acá) en vez de vía BotonLoginRedir
  // (que necesita un límite <Suspense> propio para leer pathname/query en cliente) — esta
  // ruta no tiene querystring relevante, así que no hace falta ese mecanismo.
  const loginRedirHref = `${phpSiteUrl}/login?redir=${encodeURIComponent(`${nextjsSiteUrl}/apunte/${apunte.id}`)}`;
  // Puerto de $precio_fmt (ver_apunte.php:280) para los textos de botón "Comprar por $X" /
  // "Comprar ahora por $X" — a diferencia del precio mostrado en la card, estos botones
  // SOLO se renderizan en ramas donde la promo NO está activa (el if de promo siempre se
  // evalúa primero), así que acá nunca hace falta la variante con tachado.
  const precioFmtPlano = apunte.precio > 0 ? formatoCLP(apunte.precio) : "Gratis";
  const esDueno = apunte.viewer.isOwner;

  return (
    <>
      <Header titulo={apunte.titulo} />
      {/* Oculta el bottom-nav global del sitio en este detalle — puerto exacto de la regla
          embebida en ver_apunte.php:404-410 (`nav.fixed.bottom-0 { display: none !important }`
          bajo 1024px). La barra fija propia de este apunte (ver más abajo, junto a
          `</main>`) ocupa ese espacio en su lugar, igual que en el PHP real. */}
      <style>{`
        @media (max-width: 1023px) {
          nav.fixed.bottom-0 {
            display: none !important;
          }
        }
      `}</style>
      {/* [12/09/2026] Ancho corregido — puerto exacto de ver_apunte.php:462,482: max-w-full
          en <main> (SIN mx-auto+max-w fijo acá), el max-w-[1400px] real vive en el div interno
          de abajo. `lg:pl-64` (no `lg:ml-64`): mismo fix ya aplicado y documentado en
          servicios/[id]/page.tsx:189-199 — <body> es flex flex-col (layout.tsx), y bajo
          flex-stretch un margin-left fijo no se resta del ancho estirado del hijo (overflow
          real de 256px, confirmado con scrollWidth vía CDP en esa página); padding-left sí se
          absorbe dentro del border-box. Antes este <main> tenía max-w-[1100px]+mx-auto+lg:ml-64
          los 3 a la vez en el mismo elemento — exactamente el combo "roto" que ya identificamos
          en guias.php. Rail derecho (col-4) sin cambios, solo el contenedor que lo envuelve. */}
      <main className="max-w-full mx-auto px-4 md:px-8 pt-20 pb-24 lg:pb-16 lg:pl-64">
        <div className="max-w-[1400px] mx-auto">
          <div className="grid grid-cols-1 lg:grid-cols-12 gap-8">
            <div className="lg:col-span-8 space-y-6">
              {/* Topbar móvil — calcado de ver_apunte.php:466-480: flecha volver (smart-back,
                  anti-bucle con pasarelas de pago) + pill decorativo + compartir ícono-solo.
                  Fallback de "volver": /vitrina-apuntes en el PHP real, que hoy es solo un
                  301 hacia /apuntes (.htaccess:144) — se usa el destino real directamente. */}
              <div className="lg:hidden flex items-center justify-between mb-4 mt-1">
                <VolverButton fallbackHref="/apuntes" evitarBucleDePago />
                <div className="w-10 h-1.5 bg-gray-200 rounded-full" />
                <CompartirApunteBoton apunteId={apunte.id} titulo={apunte.titulo} variant="icono" />
              </div>

              {/* HEADER MÓVIL — calcado de ver_apunte.php:508-540 (block lg:hidden). En desktop
                  el badge/título vive en la card del rail derecho y el publicador en su propia
                  card debajo; acá es el equivalente para cuando esa columna cae bajo el visor
                  en el grid de una sola columna (<lg). */}
              <div className="block lg:hidden space-y-4">
                <div>
                  <span className="px-2.5 py-0.5 rounded text-[10px] font-semibold bg-gray-100 text-gray-700 border border-[#f0f0f0] uppercase tracking-wide inline-block mb-2">
                    Asignatura: {apunte.asignatura ?? apunte.categoria ?? "Apunte"}
                  </span>
                  <h1 className="text-2xl font-medium text-[#222222] leading-tight tracking-[-0.01em]">{apunte.titulo}</h1>
                </div>

                <div className="flex items-center gap-3 py-3 border-y border-[#f0f0f0]">
                  <div className="w-16 h-16 rounded-full border border-[#f0f0f0] bg-white overflow-hidden flex-shrink-0">
                    {apunte.publicador.fotoUrl ? (
                      // eslint-disable-next-line @next/next/no-img-element
                      <img src={apunte.publicador.fotoUrl} alt={apunte.publicador.nombre ?? "Tutor"} className="w-full h-full object-cover" />
                    ) : (
                      <div className="w-full h-full flex items-center justify-center bg-blue-50 text-[#54A6D8] font-bold text-xl">{inicial(apunte.publicador.nombre)}</div>
                    )}
                  </div>
                  <div className="overflow-hidden">
                    <p className="text-[10px] text-gray-400 font-bold uppercase leading-none mb-1">Publicado por</p>
                    <p className="font-medium tracking-[-0.01em] text-[#222222] truncate flex items-center gap-1">
                      {abreviarNombre(apunte.publicador.nombre)}
                      {apunte.publicador.verificado && <IconCheckCircle className="w-2.5 h-2.5 text-[#54A6D8]" />}
                    </p>
                    <p className="text-xs text-gray-500 truncate">{apunte.publicador.institucion || "Estudiante"}</p>
                  </div>
                </div>
              </div>

              {/* Visor — Pieza 4 (Fase 0, solo lectura). accesoCompleto/fileUrl/esPDF/esImagen
                  ya vienen resueltos del lado Node (apuntes.controller.ts); esta página solo
                  los lee, no genera ni firma nada acá. */}
              <VisorApunte
                titulo={apunte.titulo}
                accesoCompleto={apunte.viewer.accesoCompleto}
                fileUrl={apunte.fileUrl}
                esPDF={apunte.esPDF}
                esImagen={apunte.esImagen}
                portadaUrl={apunte.portadaUrl}
                previewPaginasUrls={apunte.previewPaginasUrls}
              />

              <div className="bg-white border border-[#f0f0f0] rounded-2xl p-6 shadow-[0_1px_3px_rgba(0,0,0,0.04)]">
                {/* Etiquetas IA — calcado de ver_apunte.php:649-658 */}
                {apunte.iaTags.length > 0 && (
                  <div className="mt-6 mb-6">
                    <h3 className="font-medium tracking-[-0.01em] text-[#222222] mb-3 text-sm">
                      Etiquetas y materias detectadas
                    </h3>
                    <div className="flex flex-wrap gap-2">
                      {apunte.iaTags.map((tag) => (
                        <span
                          key={tag}
                          className="px-3 py-1.5 bg-gray-50 border border-gray-200 rounded-md text-xs font-medium text-gray-700"
                        >
                          {tag.charAt(0).toUpperCase() + tag.slice(1)}
                        </span>
                      ))}
                    </div>
                  </div>
                )}

                {/* Descripción — calcado de ver_apunte.php:660-672 (sin el toggle "Leer más/menos") */}
                {apunte.descripcion && (
                  <div className="mt-6">
                    <h3 className="font-medium tracking-[-0.01em] text-[#222222] mb-3">Descripción del apunte</h3>
                    <p className="text-sm text-gray-600 leading-relaxed whitespace-pre-line break-words">
                      {apunte.descripcion}
                    </p>
                  </div>
                )}
              </div>
            </div>

            {/* Sidebar derecho — puerto de ver_apunte.php:707-801: card 1 (asignatura + Compartir +
                título, luego precio/descargas + CTA de 5 ramas) y card 2 separada (publicador). */}
            <div className="lg:col-span-4">
              <div className="sticky top-24 space-y-6">
                <div className="bg-white border border-[#f0f0f0] rounded-2xl p-6 shadow-[0_1px_3px_rgba(0,0,0,0.04)]">
                  <div className="hidden lg:block mb-6 pb-6 border-b border-[#f0f0f0]">
                    <div className="flex items-center justify-between gap-3 mb-3">
                      <span className="px-2.5 py-0.5 rounded text-[10px] font-semibold bg-gray-100 text-gray-700 border border-[#f0f0f0] uppercase tracking-wide">
                        Asignatura: {apunte.asignatura ?? apunte.categoria ?? "Apunte"}
                      </span>
                      <CompartirApunteBoton apunteId={apunte.id} titulo={apunte.titulo} />
                    </div>
                    <p className="text-2xl font-medium text-[#222222] leading-tight tracking-[-0.01em]">{apunte.titulo}</p>
                  </div>

                  <div className="flex items-end justify-between mb-6">
                    <div>
                      <p className="text-xs text-gray-500 font-bold uppercase mb-1">Precio</p>
                      {apunte.promo?.activa ? (
                        <div className="flex items-baseline gap-2">
                          <span className="text-sm text-gray-400 line-through font-medium">{formatoCLP(apunte.precio)}</span>
                          <span className="text-4xl font-normal text-[#54A6D8] tracking-[-0.01em] leading-none">¡Gratis!</span>
                        </div>
                      ) : (
                        <span className="text-4xl font-normal text-[#222222] tracking-[-0.01em] leading-none">
                          {apunte.precio > 0 ? formatoCLP(apunte.precio) : "Gratis"}
                        </span>
                      )}
                    </div>
                    <div className="text-right">
                      <p className="text-xs text-gray-500 font-bold uppercase mb-1">Descargas</p>
                      <p className="text-lg font-medium text-gray-700">{apunte.ventasTotales}</p>
                    </div>
                  </div>

                  {/* CTA — puerto exacto de las 5 ramas de ver_apunte.php:739-771. La rama
                      `logueado && !accesoCompleto` (sin promo) NO renderiza nada en el PHP
                      real — se replica ese vacío tal cual, no es un olvido acá. */}
                  {apunte.promo?.activa && !apunte.viewer.accesoCompleto ? (
                    <div className="space-y-3">
                      <a
                        href={`${phpSiteUrl}/app/descargar_promo.php?id=${apunte.id}`}
                        target="_blank"
                        rel="noreferrer"
                        className="block w-full bg-[#54A6D8] text-white font-medium py-3.5 rounded-xl hover:bg-blue-600 transition text-center flex items-center justify-center gap-2"
                      >
                        <IconDescargarDoc className="w-5 h-5" /> Descargar Gratis
                      </a>
                      <p className="text-[10px] font-bold text-center text-gray-500 uppercase tracking-widest mt-2">
                        Promo Limitada: Quedan {apunte.promo.restantes}
                      </p>
                    </div>
                  ) : apunte.viewer.accesoCompleto ? (
                    <div className="space-y-3">
                      {apunte.fileUrl && (
                        <a
                          href={apunte.fileUrl}
                          download
                          className="block w-full bg-[#54A6D8] text-white font-medium py-3.5 rounded-xl hover:bg-blue-600 transition text-center flex items-center justify-center gap-2"
                        >
                          <IconDescargarDoc className="w-5 h-5" /> Descargar Archivo
                        </a>
                      )}
                      {esDueno && (
                        <a
                          href={`${phpSiteUrl}/app/editar_apunte.php?id=${apunte.id}`}
                          className="block w-full bg-gray-100 text-gray-700 font-medium py-3.5 rounded-xl text-center hover:bg-gray-200 transition flex items-center justify-center gap-2"
                        >
                          <IconPencil className="w-4 h-4" /> Editar Apunte
                        </a>
                      )}
                    </div>
                  ) : !apunte.viewer.isAuthenticated && apunte.precio === 0 ? (
                    <a
                      href={loginRedirHref}
                      className="block w-full bg-[#54A6D8] text-white font-medium py-3.5 rounded-xl hover:bg-blue-600 transition flex items-center justify-center gap-2"
                    >
                      <IconDescargarDoc className="w-5 h-5" /> Inicia sesión para descargar
                    </a>
                  ) : !apunte.viewer.isAuthenticated && apunte.precio > 0 ? (
                    <ComprarInvitadoApunteBoton
                      apunteId={apunte.id}
                      precioFmt={precioFmtPlano}
                      loginHref={loginRedirHref}
                      phpSiteUrl={phpSiteUrl}
                      className="block w-full bg-[#54A6D8] text-white font-medium py-3.5 rounded-xl hover:bg-[#4895c2] transition flex items-center justify-center gap-2"
                    >
                      <IconLock className="w-5 h-5" /> Comprar por {precioFmtPlano}
                    </ComprarInvitadoApunteBoton>
                  ) : null}
                </div>

                {/* Publicador (desktop) — puerto de ver_apunte.php:774-801. En mobile su
                    equivalente ya se muestra en el header sobre el visor. */}
                <div className="hidden lg:block bg-gray-50 rounded-2xl p-5 border border-[#f0f0f0]">
                  <div className="flex items-center gap-4">
                    <div className="w-24 h-24 rounded-full border border-[#f0f0f0] bg-white overflow-hidden flex-shrink-0">
                      {apunte.publicador.fotoUrl ? (
                        // eslint-disable-next-line @next/next/no-img-element
                        <img src={apunte.publicador.fotoUrl} alt={apunte.publicador.nombre ?? "Tutor"} className="w-full h-full object-cover" />
                      ) : (
                        <div className="w-full h-full flex items-center justify-center bg-blue-50 text-[#54A6D8] font-bold text-2xl">{inicial(apunte.publicador.nombre)}</div>
                      )}
                    </div>
                    <div className="flex-1 min-w-0">
                      <p className="text-sm font-medium tracking-[-0.01em] text-[#222222] flex items-center gap-1">
                        Publicado por {abreviarNombre(apunte.publicador.nombre)}
                        {apunte.publicador.verificado && <IconCheckCircle className="w-3.5 h-3.5 text-[#54A6D8]" />}
                      </p>
                      <p className="text-xs text-gray-500 flex items-start gap-1 mt-0.5 leading-snug">
                        <span className="mt-0.5 flex-shrink-0">
                          <IconBuilding className="w-3 h-3" />
                        </span>
                        <span className="break-words font-medium uppercase">{apunte.publicador.institucion || "Estudiante"}</span>
                      </p>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div className="h-16 lg:hidden" />
        </div>
      </main>

      {/* Barra CTA fija móvil — puerto exacto de #barra-apunte-movil (ver_apunte.php:960-1006).
          Oculta por completo si es_dueno (el dueño no tiene nada que comprar/descargar acá).
          6 ramas — una más que el sidebar desktop: agrega `logueado && !accesoCompleto &&
          !promo` -> "Desbloquear" (línea 996-1001 del PHP), rama que el sidebar deja vacía.
          Asimetría real del PHP, NO se empareja con el sidebar. */}
      {!esDueno && (
        <div className="lg:hidden fixed bottom-0 left-0 right-0 bg-white/95 backdrop-blur-md border-t border-gray-100 shadow-[0_-4px_12px_rgba(0,0,0,0.04)] z-40 px-4 py-3 pb-[calc(0.75rem+env(safe-area-inset-bottom))]">
          <div className="flex items-center justify-between gap-3">
            <div className="flex flex-col min-w-0 flex-1">
              {apunte.viewer.accesoCompleto ? (
                <span className="text-xs text-gray-400 font-medium">Ya tienes acceso</span>
              ) : apunte.promo?.activa ? (
                <>
                  <span className="text-[10px] text-gray-400 font-bold uppercase tracking-wide leading-none mb-0.5">Precio</span>
                  <span className="flex items-baseline gap-1.5">
                    <span className="text-sm text-gray-400 line-through font-medium">{formatoCLP(apunte.precio)}</span>
                    <span className="text-xl font-extrabold text-[#54A6D8] tracking-tight leading-none">¡Gratis!</span>
                  </span>
                </>
              ) : (
                <>
                  <span className="text-[10px] text-gray-400 font-bold uppercase tracking-wide leading-none mb-0.5">Precio</span>
                  <span className="text-xl font-extrabold text-gray-900 tracking-tight leading-none">{precioFmtPlano}</span>
                </>
              )}
            </div>

            {apunte.promo?.activa && !apunte.viewer.accesoCompleto ? (
              <a
                href={`${phpSiteUrl}/app/descargar_promo.php?id=${apunte.id}`}
                className="bg-[#54A6D8] hover:bg-blue-600 text-white font-bold rounded-xl px-5 py-3 text-sm shadow-md active:scale-95 transition-all whitespace-nowrap flex items-center gap-2"
              >
                <IconDescargarDoc className="w-4 h-4" /> Descargar gratis
              </a>
            ) : apunte.viewer.accesoCompleto ? (
              apunte.fileUrl && (
                <a
                  href={apunte.fileUrl}
                  download
                  className="bg-[#54A6D8] hover:bg-blue-600 text-white font-bold rounded-xl px-5 py-3 text-sm shadow-md active:scale-95 transition-all whitespace-nowrap flex items-center gap-2"
                >
                  <IconDescargarDoc className="w-4 h-4" /> Descargar
                </a>
              )
            ) : !apunte.viewer.isAuthenticated && apunte.precio === 0 ? (
              <a
                href={loginRedirHref}
                className="bg-[#54A6D8] hover:bg-blue-600 text-white font-bold rounded-xl px-5 py-3 text-sm shadow-md active:scale-95 transition-all whitespace-nowrap flex items-center gap-2"
              >
                <IconUser className="w-4 h-4" /> Inicia sesión
              </a>
            ) : !apunte.viewer.isAuthenticated && apunte.precio > 0 ? (
              <ComprarInvitadoApunteBoton
                apunteId={apunte.id}
                precioFmt={precioFmtPlano}
                loginHref={loginRedirHref}
                phpSiteUrl={phpSiteUrl}
                className="bg-[#54A6D8] hover:bg-blue-600 text-white font-bold rounded-xl px-5 py-3 text-sm shadow-md active:scale-95 transition-all whitespace-nowrap flex items-center gap-2"
              >
                <IconLock className="w-4 h-4" /> Comprar
              </ComprarInvitadoApunteBoton>
            ) : apunte.viewer.isAuthenticated && !apunte.viewer.accesoCompleto && !apunte.promo?.activa ? (
              <a
                href={`${phpSiteUrl}/iniciar-pago?id_apunte=${apunte.id}`}
                className="bg-[#54A6D8] hover:bg-blue-600 text-white font-bold rounded-xl px-5 py-3 text-sm shadow-md active:scale-95 transition-all whitespace-nowrap flex items-center gap-2"
              >
                <IconLock className="w-4 h-4" /> Desbloquear
              </a>
            ) : null}
          </div>
        </div>
      )}
    </>
  );
}
