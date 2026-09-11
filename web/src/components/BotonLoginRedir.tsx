"use client";

import { Suspense } from "react";
import { usePathname, useSearchParams } from "next/navigation";

interface BotonLoginRedirProps {
  phpSiteUrl: string;
  nextjsSiteUrl: string;
  className?: string;
  title?: string;
  children: React.ReactNode;
}

// Puerto liviano del patrón redir ya usado en Sidebar.tsx:73,122 / BottomNav.tsx:49-50, para
// los 3 puntos de Header.tsx que hoy enlazan a `${phpSiteUrl}/login` sin redir (avatar de
// invitado, Publicar Apunte, Publicar Clase — ver Header.tsx:25-28). Header es Server
// Component (usa getSesion()/cookies()), así que no puede leer pathname/searchParams — este
// wrapper cliente resuelve solo eso. Path + querystring (no solo path) para que un filtro
// activo (ej. /apuntes?categoria=Historia) sobreviva al viaje por login.php.
function BotonLoginRedirInner({ phpSiteUrl, nextjsSiteUrl, className, title, children }: BotonLoginRedirProps) {
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const query = searchParams.toString();
  const rutaOrigen = query ? `${pathname}?${query}` : pathname;
  const href = `${phpSiteUrl}/login?redir=${encodeURIComponent(`${nextjsSiteUrl}${rutaOrigen}`)}`;

  return (
    <a href={href} className={className} title={title}>
      {children}
    </a>
  );
}

// useSearchParams() exige un límite <Suspense> propio — si no, Next.js fuerza CSR de todo el
// árbol hasta el límite más cercano (builds de producción: falla el build en una ruta
// estática sin ese límite). Se resuelve acá adentro para que Header.tsx no tenga que
// acordarse de envolver cada uno de los 3 usos. Fallback: el mismo <a> pero sin redir —
// exactamente el comportamiento que había antes de este fix, nunca deja el botón roto
// mientras se resuelve pathname/searchParams.
export function BotonLoginRedir(props: BotonLoginRedirProps) {
  const { phpSiteUrl, className, title, children } = props;
  return (
    <Suspense
      fallback={
        <a href={`${phpSiteUrl}/login`} className={className} title={title}>
          {children}
        </a>
      }
    >
      <BotonLoginRedirInner {...props} />
    </Suspense>
  );
}
