"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";

// Puerto de app/componentes/nav_bottom.php — mismas 5 posiciones visuales que el real
// (Inicio, Descubrir, Publicar central elevado, Mensajes, Perfil).
//
// Mensajes/Perfil SÍ son session-aware (nav_bottom.php:34-35, mismo criterio ya aplicado
// en Sidebar.tsx): visitante -> /login?redir=..., logueado -> ruta real (/bandeja-entrada,
// /perfil/{hash}). Sin badge de no-leídos ni punto de alerta de perfil incompleto —
// deferred a propósito, mismo criterio que Sidebar.tsx. La foto de perfil real en el
// ícono de Perfil (nav_bottom.php:227-230) SÍ se pinta acá vía la prop `fotoPerfil`
// (layout.tsx la reenvía desde sesion.fotoPerfil) — mismo fallback que el PHP real
// cuando no hay foto: el ícono SVG genérico, NO iniciales (a diferencia de Header.tsx).
//
// Publicar SIGUE siendo siempre /login, sin importar sesión — decisión explícita, no
// descuido: el botón real logueado no es un link (es un <button> sin href que dispara un
// modal de elección servicio/apunte no construido en web/); portar eso es trabajo aparte.
//
// Logout NO se agrega acá — confirmado con el usuario que nav_bottom.php real no tiene
// ningún ítem de logout (solo 5 slots fijos), a diferencia de sidebar.php que sí lo tiene
// en desktop. Agregar uno acá sería inventar UI que el sitio real no tiene.
//
// [26/08/2026] El comentario que vivía acá decía que nunca se arma un `redir` de vuelta a
// una ruta de web/ porque "son 2 apps distintas, no tendría a dónde volver" — eso ya no es
// cierto: login.php ahora acepta `redir` absoluto hacia NEXTJS_TRUSTED_ORIGINS (ver
// app/helpers/redir_seguro.php del lado PHP), justo para esto. perfilUrl y mensajesUrl
// arman ambos un redir absoluto hacia nextjsSiteUrl porque /mi-perfil y /bandeja-entrada
// viven en Next.js — /bandeja-entrada se portó en el Grupo Mensajes/Chat, Pieza 1.
//
// "Descubrir" reemplaza al modal_explora.php real (no construido en web/) por un link
// directo a /busqueda — mismo ícono, propósito equivalente ("buscar/descubrir"),
// simplificado de modal a navegación de página completa.
export function BottomNav({
  phpSiteUrl,
  nextjsSiteUrl,
  usuarioId,
  fotoPerfil,
}: {
  phpSiteUrl: string;
  nextjsSiteUrl: string;
  usuarioId: number | null;
  fotoPerfil: string | null;
}) {
  const pathname = usePathname();
  const loginUrl = `${phpSiteUrl}/login`;
  const esGuest = usuarioId === null;
  const mensajesUrl = esGuest ? `${phpSiteUrl}/login?redir=${encodeURIComponent(`${nextjsSiteUrl}/bandeja-entrada`)}` : "/bandeja-entrada";
  const perfilUrl = esGuest ? `${phpSiteUrl}/login?redir=${encodeURIComponent(`${nextjsSiteUrl}/mi-perfil`)}` : "/mi-perfil";

  const esInicio = pathname === "/";
  const esMensajes = pathname === "/bandeja-entrada" || pathname.startsWith("/chat/");
  const esPerfil = pathname === "/mi-perfil";
  const clsBase =
    "flex flex-col items-center justify-center gap-1 w-full outline-none select-none transition-transform duration-150 active:scale-[0.92] relative";
  const clsActivo = "text-[#54A6D8] font-medium";
  const clsInactivo = "text-gray-400 font-medium";

  return (
    <nav
      className="nav-native-feel lg:hidden fixed bottom-0 left-0 right-0 z-[60] bg-white/90 backdrop-blur-xl border-t border-gray-100/80 pb-[env(safe-area-inset-bottom)] pt-2 px-1"
      aria-label="Navegación principal"
    >
      <ul className="grid grid-cols-5 text-[11px] text-center pb-1 items-end relative">
        <li>
          <Link href="/" aria-label="Inicio" className={`${clsBase} ${esInicio ? clsActivo : clsInactivo}`}>
            {esInicio ? (
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" className="w-6 h-6">
                <path d="M11.47 3.84a.75.75 0 011.06 0l8.99 9a.75.75 0 11-1.06 1.06l-1.46-1.46V21a.75.75 0 01-.75.75h-4.5a.75.75 0 01-.75-.75v-4.5a.75.75 0 00-.75-.75h-2.25a.75.75 0 00-.75.75V21a.75.75 0 01-.75.75H4.5A.75.75 0 013.75 21v-8.56l-1.46 1.46a.75.75 0 01-1.06-1.06l8.99-9z" />
              </svg>
            ) : (
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-6 h-6">
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"
                />
              </svg>
            )}
            <span className="tracking-[0.01em] leading-none mt-0.5">Inicio</span>
          </Link>
        </li>

        <li>
          <Link href="/busqueda" aria-label="Descubrir" className={`${clsBase} ${clsInactivo}`}>
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-6 h-6">
              <path strokeLinecap="round" strokeLinejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
            </svg>
            <span className="tracking-[0.01em] leading-none mt-0.5">Descubrir</span>
          </Link>
        </li>

        <li className="relative w-full h-full flex justify-center">
          <a
            href={loginUrl}
            aria-label="Publicar"
            className="outline-none relative transition-transform duration-150 active:scale-[0.88] select-none h-full w-full"
          >
            <div className="absolute bottom-3 left-1/2 -translate-x-1/2 w-14 h-14 bg-[#54A6D8] rounded-[18px] flex items-center justify-center text-white z-10 overflow-hidden shadow-md">
              <div
                className="absolute top-0 -left-[100%] w-full h-full bg-gradient-to-r from-transparent via-white/30 to-transparent -skew-x-12 animate-shine"
                aria-hidden="true"
              />
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className="w-7 h-7 relative z-20">
                <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
              </svg>
            </div>
          </a>
        </li>

        <li>
          {esGuest ? (
            <a href={mensajesUrl} aria-label="Mensajes" className={`${clsBase} ${clsInactivo}`}>
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-6 h-6">
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  d="M12 20.25c4.97 0 9-3.694 9-8.25s-4.03-8.25-9-8.25S3 7.444 3 12c0 2.104.859 4.023 2.273 5.48.432.447.495 1.141.143 1.65-.6.866-1.42 1.586-2.38 2.115 1.576.166 3.09.043 4.41-.33.61-.171 1.256-.123 1.833.125A9.01 9.01 0 0 0 12 20.25Z"
                />
              </svg>
              <span className="tracking-[0.01em] leading-none mt-0.5">Mensajes</span>
            </a>
          ) : (
            <Link href={mensajesUrl} aria-label="Mensajes" className={`${clsBase} ${esMensajes ? clsActivo : clsInactivo}`}>
              {esMensajes ? (
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" className="w-6 h-6">
                  <path
                    fillRule="evenodd"
                    clipRule="evenodd"
                    d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75c1.173 0 2.298-.207 3.344-.582l3.785 1.514a.75.75 0 00.99-.99l-1.514-3.785A9.715 9.715 0 0021.75 12c0-5.385-4.365-9.75-9.75-9.75z"
                  />
                </svg>
              ) : (
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-6 h-6">
                  <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    d="M12 20.25c4.97 0 9-3.694 9-8.25s-4.03-8.25-9-8.25S3 7.444 3 12c0 2.104.859 4.023 2.273 5.48.432.447.495 1.141.143 1.65-.6.866-1.42 1.586-2.38 2.115 1.576.166 3.09.043 4.41-.33.61-.171 1.256-.123 1.833.125A9.01 9.01 0 0 0 12 20.25Z"
                  />
                </svg>
              )}
              <span className="tracking-[0.01em] leading-none mt-0.5">Mensajes</span>
            </Link>
          )}
        </li>

        <li>
          {esGuest ? (
            <a href={perfilUrl} aria-label="Perfil" className={`${clsBase} ${clsInactivo}`}>
              <div className="w-6 h-6 flex items-center justify-center relative shrink-0 aspect-square">
                {fotoPerfil ? (
                  <div className="w-6 h-6 rounded-full overflow-hidden shrink-0 border-2 border-transparent transition-all">
                    <img
                      src={`${phpSiteUrl}/app/perfil/fotos/${encodeURIComponent(fotoPerfil)}`}
                      alt=""
                      width={24}
                      height={24}
                      decoding="async"
                      loading="lazy"
                      className="w-full h-full object-cover"
                    />
                  </div>
                ) : (
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-6 h-6">
                    <path
                      strokeLinecap="round"
                      strokeLinejoin="round"
                      d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z"
                    />
                  </svg>
                )}
              </div>
              <span className="tracking-[0.01em] leading-none mt-0.5">Perfil</span>
            </a>
          ) : (
            <Link href={perfilUrl} aria-label="Perfil" className={`${clsBase} ${esPerfil ? clsActivo : clsInactivo}`}>
              <div className="w-6 h-6 flex items-center justify-center relative shrink-0 aspect-square">
                {fotoPerfil ? (
                  <div
                    className={`w-6 h-6 rounded-full overflow-hidden shrink-0 transition-all ${esPerfil ? "border-2 border-[#54A6D8]" : "border-2 border-transparent"}`}
                  >
                    <img
                      src={`${phpSiteUrl}/app/perfil/fotos/${encodeURIComponent(fotoPerfil)}`}
                      alt=""
                      width={24}
                      height={24}
                      decoding="async"
                      loading="lazy"
                      className="w-full h-full object-cover"
                    />
                  </div>
                ) : esPerfil ? (
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" className="w-6 h-6">
                    <path
                      fillRule="evenodd"
                      clipRule="evenodd"
                      d="M7.5 6a4.5 4.5 0 119 0 4.5 4.5 0 01-9 0zM3.751 20.105a8.25 8.25 0 0116.498 0 .75.75 0 01-.437.695A18.683 18.683 0 0112 22.5c-2.786 0-5.433-.608-7.812-1.7a.75.75 0 01-.437-.695z"
                    />
                  </svg>
                ) : (
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-6 h-6">
                    <path
                      strokeLinecap="round"
                      strokeLinejoin="round"
                      d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z"
                    />
                  </svg>
                )}
              </div>
              <span className="tracking-[0.01em] leading-none mt-0.5">Perfil</span>
            </Link>
          )}
        </li>
      </ul>
    </nav>
  );
}
