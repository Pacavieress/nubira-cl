"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";

// Puerto de app/componentes/sidebar.php. "Inicio"/"Clases"/"Apuntes" son iguales para
// visitante y logueado en el PHP real, sin cambios acá. "Mensajes"/"Mi Perfil" y el nuevo
// bloque "Cerrar Sesión" SÍ dependen de sesión (sidebar.php:9 `$is_guest`,
// líneas 101/145/160-171) — antes excluidos porque web/ no sabía quién visitaba; ahora que
// layout.tsx pasa `usuarioId` (vía getSesion(), ver web/src/lib/sesion.ts) sí se portan.
//
// [Auditoría de fidelidad] Contenedor, íconos (32px, mismo <path> SVG), espaciado, colores
// de estado activo y bloque de logout confirmados 1:1 contra sidebar.php — sin el bug de
// aplanado de layout que sí tuvo detalle_servicio.php en su primera pasada. Único ajuste:
// "Mi Perfil" ya deja de ser link externo cuando hay sesión — desde que /mi-perfil existe
// en web/ (pieza portada en esta misma sesión), navega con next/link como ruta interna
// (`externo: esGuest`), no como el resto de comentario abajo sugiere.
//
// Sin badges (no leídos en Mensajes, punto de alerta de perfil incompleto en Mi Perfil) —
// deferred a propósito, mismo criterio que los badges del panel admin: requieren
// endpoints de conteo en vivo que no son parte de este paso, no un olvido. Nota para
// retomar: el segundo (alerta de perfil incompleto) ya tiene su dato real disponible
// (perfil.completitud, ver server/src/modules/perfil/) — faltaría exponerlo desde
// layout.tsx sin pagar el costo de una query extra en cada carga de página, algo que
// amerita su propia decisión, no agregarlo de paso acá.
//
// "Inicio" apunta a "/" (hoy un redirect a /servicios, ver web/src/app/page.tsx) en vez de
// a /explorar como el sitio real — mismo alcance que la decisión de mover la grilla de
// servicios a /servicios y dejar "/" reservado para un futuro port de vitrina.php.
//
// "Recursos"/"Mensajes" (sin ruta propia en web/ todavía) enlazan al sitio PHP real en
// pestaña nueva (target="_blank") — mismo patrón que el logo/breadcrumb de Header.tsx.
// "Cerrar Sesión" es la ÚNICA excepción deliberada: NO usa target="_blank" porque muta la
// sesión (logout.php destruye sesiones_api) — abrirlo en pestaña nueva dejaría la pestaña
// de web/ mostrando un sidebar "logueado" obsoleto hasta la próxima navegación; en la misma
// pestaña, el usuario ve el resultado real (aterriza en el / de PHP ya deslogueado) y si
// vuelve a web/ el layout se re-renderiza reflejando el logout.
//
// PHP_SITE_URL es server-only a propósito (ver web/.env) — Sidebar es Client Component
// (necesita usePathname() para el estado activo), así que lo recibe por prop desde
// layout.tsx en vez de leer process.env acá, que solo vería variables NEXT_PUBLIC_*.

interface NavItem {
  href: string;
  label: string;
  activo: (pathname: string) => boolean;
  icono: React.ReactNode;
  externo?: boolean;
}

function construirNavItems(phpSiteUrl: string, nextjsSiteUrl: string, usuarioId: number | null): NavItem[] {
  const esGuest = usuarioId === null;

  return [
  {
    href: "/",
    label: "Inicio",
    activo: (p) => p === "/",
    icono: (
      <path
        strokeLinecap="round"
        strokeLinejoin="round"
        d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"
      />
    ),
  },
  {
    // "Mensajes" ya tiene página propia en Next.js (Grupo Mensajes/Chat, Pieza 1,
    // 26/08/2026) — mismo criterio que "Mi Perfil" arriba: navega como ruta interna.
    href: esGuest ? `${phpSiteUrl}/login?redir=${encodeURIComponent(`${nextjsSiteUrl}/bandeja-entrada`)}` : "/bandeja-entrada",
    label: "Mensajes",
    activo: (p) => p === "/bandeja-entrada" || p.startsWith("/chat/"),
    externo: esGuest,
    icono: (
      <path
        strokeLinecap="round"
        strokeLinejoin="round"
        d="M12 20.25c4.97 0 9-3.694 9-8.25s-4.03-8.25-9-8.25S3 7.444 3 12c0 2.104.859 4.023 2.273 5.48.432.447.495 1.141.143 1.65-.6.866-1.42 1.586-2.38 2.115 1.576.166 3.09.043 4.41-.33.61-.171 1.256-.123 1.833.125A9.01 9.01 0 0 0 12 20.25Z"
      />
    ),
  },
  {
    href: "/servicios",
    label: "Clases",
    activo: (p) => p.startsWith("/servicios"),
    icono: (
      <path
        strokeLinecap="round"
        strokeLinejoin="round"
        d="M4.26 10.147a60.436 60.436 0 0 0-.491 6.347A48.627 48.627 0 0 1 12 20.904a48.627 48.627 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.57 50.57 0 0 0-2.658-.813A59.905 59.905 0 0 1 12 3.493a59.902 59.902 0 0 1 10.499 5.516 51.55 51.55 0 0 1-2.657.813m-15.482 0A50.923 50.923 0 0 1 12 13.489a50.92 50.92 0 0 1 10.491-3.342"
      />
    ),
  },
  {
    href: "/apuntes",
    label: "Apuntes",
    activo: (p) => p.startsWith("/apuntes"),
    icono: (
      <path
        strokeLinecap="round"
        strokeLinejoin="round"
        d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"
      />
    ),
  },
  {
    href: `${phpSiteUrl}/guias`,
    label: "Recursos",
    activo: () => false,
    externo: true,
    icono: (
      <path
        strokeLinecap="round"
        strokeLinejoin="round"
        d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25"
      />
    ),
  },
  {
    href: esGuest ? `${phpSiteUrl}/login?redir=${encodeURIComponent(`${nextjsSiteUrl}/mi-perfil`)}` : "/mi-perfil",
    label: "Mi Perfil",
    activo: (p) => p === "/mi-perfil",
    externo: esGuest,
    icono: (
      <path
        strokeLinecap="round"
        strokeLinejoin="round"
        d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z"
      />
    ),
  },
  ];
}

export function Sidebar({ phpSiteUrl, nextjsSiteUrl, usuarioId }: { phpSiteUrl: string; nextjsSiteUrl: string; usuarioId: number | null }) {
  const pathname = usePathname();
  const navItems = construirNavItems(phpSiteUrl, nextjsSiteUrl, usuarioId);

  return (
    <aside className="hidden lg:flex lg:flex-col fixed top-14 left-0 h-[calc(100%-3.5rem)] w-56 bg-white/95 backdrop-blur-sm border-r border-[#f0f0f0]/80 z-40 overflow-y-auto">
      <div className="px-4 py-5 flex flex-col h-full">
        <nav className="flex flex-col space-y-0.5 flex-1">
          {navItems.map((item) => {
            const activo = item.activo(pathname);
            const claseLink = `group flex items-center gap-3 px-3 py-2.5 text-[13px] rounded-xl transition-all duration-200 font-medium ${
              activo ? "text-[#54A6D8]" : "text-[#222222] hover:bg-gray-50/80"
            }`;
            const contenido = (
              <>
                <div
                  className={`w-8 h-8 flex items-center justify-center rounded-[10px] shrink-0 transition-all ${
                    activo ? "bg-[#54A6D8]/10" : "group-hover:bg-black/[0.03]"
                  }`}
                >
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor" className="w-[18px] h-[18px]">
                    {item.icono}
                  </svg>
                </div>
                <span className="tracking-[-0.01em]">{item.label}</span>
              </>
            );

            return item.externo ? (
              <a key={item.href} href={item.href} target="_blank" rel="noopener noreferrer" className={claseLink}>
                {contenido}
              </a>
            ) : (
              <Link key={item.href} href={item.href} className={claseLink}>
                {contenido}
              </Link>
            );
          })}
        </nav>

        {usuarioId !== null && (
          <div className="mt-auto border-t border-[#f0f0f0]/70 pt-3.5">
            <a
              href={`${phpSiteUrl}/logout`}
              className="flex items-center gap-3 px-3.5 py-2.5 text-[13px] text-gray-400 hover:text-red-500 hover:bg-red-50/60 rounded-xl transition-all duration-200 group"
            >
              <div className="w-8 h-8 flex items-center justify-center rounded-[10px] shrink-0 group-hover:!bg-red-50">
                <svg
                  xmlns="http://www.w3.org/2000/svg"
                  fill="none"
                  viewBox="0 0 24 24"
                  strokeWidth={1.5}
                  stroke="currentColor"
                  className="w-[18px] h-[18px] group-hover:translate-x-0.5 transition-transform duration-200"
                >
                  <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15M12 9l-3 3m0 0 3 3m-3-3h12.75"
                  />
                </svg>
              </div>
              <span className="font-medium tracking-[-0.01em]">Cerrar Sesión</span>
            </a>
          </div>
        )}
      </div>
    </aside>
  );
}
