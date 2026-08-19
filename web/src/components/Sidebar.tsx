"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";

// Puerto de app/componentes/sidebar.php — SOLO los links públicos, sin sesión. Excluidos
// a propósito, mismo criterio que Header.tsx: "Mensajes" (badge + bandeja de entrada) y
// "Mi Perfil"/"Cerrar Sesión" dependen de una sesión que web/ no tiene.
//
// "Inicio" apunta a "/" (hoy un redirect a /servicios, ver web/src/app/page.tsx) en vez de
// a /explorar como el sitio real — mismo alcance que la decisión de mover la grilla de
// servicios a /servicios y dejar "/" reservado para un futuro port de vitrina.php.
//
// "Recursos" (guías) no está construido en web/ — enlaza al sitio PHP real en vez de a
// una ruta interna rota, mismo patrón que el logo/breadcrumb de Header.tsx. PHP_SITE_URL
// es server-only a propósito (ver web/.env) — Sidebar es Client Component (necesita
// usePathname() para el estado activo), así que lo recibe por prop desde layout.tsx en
// vez de leer process.env acá, que solo vería variables NEXT_PUBLIC_*.

interface NavItem {
  href: string;
  label: string;
  activo: (pathname: string) => boolean;
  icono: React.ReactNode;
  externo?: boolean;
}

function construirNavItems(phpSiteUrl: string): NavItem[] {
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
  ];
}

export function Sidebar({ phpSiteUrl }: { phpSiteUrl: string }) {
  const pathname = usePathname();
  const navItems = construirNavItems(phpSiteUrl);

  return (
    <aside className="hidden lg:flex lg:flex-col fixed top-14 left-0 h-[calc(100%-3.5rem)] w-56 bg-white/95 backdrop-blur-sm border-r border-[#f0f0f0]/80 z-40 overflow-y-auto">
      <nav className="px-4 py-5 flex flex-col space-y-0.5">
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
    </aside>
  );
}
