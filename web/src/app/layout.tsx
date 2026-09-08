import type { Metadata } from "next";
import { Inter } from "next/font/google";
import { BottomNav } from "@/components/BottomNav";
import { Sidebar } from "@/components/Sidebar";
import { getSesion } from "@/lib/sesion";
import "./globals.css";

// Misma fuente que el sitio PHP (vitrina.php:730-731, Inter 400-900 vía Google Fonts) —
// next/font la autohospeda y elimina el salto visual de carga, mejor que el preload+swap
// manual que PHP usa para el mismo problema (documentado como pendiente sin resolver del
// todo en CLAUDE.md).
const inter = Inter({
  variable: "--font-inter",
  subsets: ["latin"],
  weight: ["400", "500", "600", "700", "800", "900"],
});

export const metadata: Metadata = {
  title: "Nubira — Servicios (piloto Next.js)",
  description: "Página mínima de validación del patrón Next.js + API Node, Fase 10 acotada.",
};

export default async function RootLayout({ children }: LayoutProps<"/">) {
  // Sidebar/BottomNav viven acá (no por página, a diferencia de sidebar.php/nav_bottom.php
  // en PHP, incluidos por cada vista) — layout.tsx es el lugar idiomático de Next.js para
  // chrome persistente que no cambia entre páginas, y evita repetir el include 7 veces.
  // Header.tsx sigue siendo por-página: necesita el `titulo` de cada vista, que no calza
  // con este layout compartido sin agregar un mecanismo aparte (fuera de alcance acá).
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  // Dominio propio de esta app — necesario para armar `redir` ABSOLUTO hacia login.php
  // (dominio PHP) en páginas 100% exclusivas de Next.js, sin equivalente en .htaccess. Sin
  // esto, login.php interpretaba la ruta relativa contra SU PROPIO dominio y 404'ba (bug
  // real en /mi-perfil, corregido 26/08/2026 — ver app/helpers/redir_seguro.php y
  // NEXTJS_TRUSTED_ORIGINS en app/config.php del lado PHP, que debe incluir este mismo origen).
  const nextjsSiteUrl = process.env.NEXTJS_SITE_URL ?? "http://nubira.local:3000";

  // getSesion() corta antes del fetch a server/ si no hay cookie PHPSESSID (visitante
  // anónimo, el caso común) — sin costo de red extra para ese caso. Para un visitante CON
  // sesión sí agrega un roundtrip Next.js->server/ en cada navegación (layout.tsx envuelve
  // TODAS las páginas): tradeoff deliberado, no descuido — no se cachea porque el resto de
  // esta migración ya decidió "fresco, no cacheado" para todo lo relacionado a sesión (ver
  // getUsuarioConRol en server/), y BottomNav/Sidebar necesitan reflejar login/logout sin
  // esperar a un TTL.
  const sesion = await getSesion();

  return (
    <html lang="es" className={`${inter.variable} h-full antialiased`}>
      <body className="min-h-full flex flex-col">
        {children}
        <Sidebar phpSiteUrl={phpSiteUrl} nextjsSiteUrl={nextjsSiteUrl} usuarioId={sesion?.usuarioId ?? null} />
        <BottomNav phpSiteUrl={phpSiteUrl} nextjsSiteUrl={nextjsSiteUrl} usuarioId={sesion?.usuarioId ?? null} />
      </body>
    </html>
  );
}
