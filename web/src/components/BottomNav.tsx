"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";

// Puerto de app/componentes/nav_bottom.php — mismas 5 posiciones visuales que el real
// (Inicio, Descubrir, Publicar central elevado, Mensajes, Perfil).
//
// Mensajes/Perfil SÍ son session-aware (nav_bottom.php:34-35, mismo criterio ya aplicado
// en Sidebar.tsx): visitante -> /login?redir=..., logueado -> ruta real (/bandeja-entrada,
// /perfil/{hash}). Sin badge de no-leídos ni punto de alerta de perfil incompleto —
// deferred a propósito, mismo criterio que Sidebar.tsx. Tampoco se pinta la foto de
// perfil real en el ícono de Perfil (nav_bottom.php:227-230 la muestra si existe) — hoy
// getSesion() no expone foto_perfil, fuera de alcance de este paso.
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
export function BottomNav({ phpSiteUrl, nextjsSiteUrl, usuarioId }: { phpSiteUrl: string; nextjsSiteUrl: string; usuarioId: number | null }) {
  const pathname = usePathname();
  const loginUrl = `${phpSiteUrl}/login`;
  const esGuest = usuarioId === null;
  const mensajesUrl = esGuest ? `${phpSiteUrl}/login?redir=${encodeURIComponent(`${nextjsSiteUrl}/bandeja-entrada`)}` : "/bandeja-entrada";
  const perfilUrl = esGuest ? `${phpSiteUrl}/login?redir=${encodeURIComponent(`${nextjsSiteUrl}/mi-perfil`)}` : "/mi-perfil";

  const esInicio = pathname === "/";
  const esMensajes = pathname === "/bandeja-entrada" || pathname.startsWith("/chat/");
  const esPerfil = pathname === "/mi-perfil";
  const clsBase = "flex flex-col items-center justify-center gap-1 w-full outline-none select-none";
  const clsActivo = "text-[#54A6D8] font-medium";
  const clsInactivo = "text-gray-400 font-medium";

  return (
    <nav
      className="lg:hidden fixed bottom-0 left-0 right-0 z-[60] bg-white/90 backdrop-blur-xl border-t border-gray-100/80 pb-[env(safe-area-inset-bottom)] pt-2 px-1"
      aria-label="Navegación principal"
    >
      <ul className="grid grid-cols-5 text-[11px] text-center pb-1 items-end relative">
        <li>
          <Link href="/" aria-label="Inicio" className={`${clsBase} ${esInicio ? clsActivo : clsInactivo}`}>
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-6 h-6">
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"
              />
            </svg>
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
          <a href={loginUrl} aria-label="Publicar" className="relative select-none h-full w-full">
            <div className="absolute bottom-3 left-1/2 -translate-x-1/2 w-14 h-14 bg-[#54A6D8] rounded-[18px] flex items-center justify-center text-white shadow-md">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className="w-7 h-7">
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
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-6 h-6">
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  d="M12 20.25c4.97 0 9-3.694 9-8.25s-4.03-8.25-9-8.25S3 7.444 3 12c0 2.104.859 4.023 2.273 5.48.432.447.495 1.141.143 1.65-.6.866-1.42 1.586-2.38 2.115 1.576.166 3.09.043 4.41-.33.61-.171 1.256-.123 1.833.125A9.01 9.01 0 0 0 12 20.25Z"
                />
              </svg>
              <span className="tracking-[0.01em] leading-none mt-0.5">Mensajes</span>
            </Link>
          )}
        </li>

        <li>
          {esGuest ? (
            <a href={perfilUrl} aria-label="Perfil" className={`${clsBase} ${clsInactivo}`}>
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-6 h-6">
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z"
                />
              </svg>
              <span className="tracking-[0.01em] leading-none mt-0.5">Perfil</span>
            </a>
          ) : (
            <Link href={perfilUrl} aria-label="Perfil" className={`${clsBase} ${esPerfil ? clsActivo : clsInactivo}`}>
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-6 h-6">
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z"
                />
              </svg>
              <span className="tracking-[0.01em] leading-none mt-0.5">Perfil</span>
            </Link>
          )}
        </li>
      </ul>
    </nav>
  );
}
