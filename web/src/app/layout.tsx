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
        <Sidebar phpSiteUrl={phpSiteUrl} usuarioId={sesion?.usuarioId ?? null} />
        <BottomNav phpSiteUrl={phpSiteUrl} usuarioId={sesion?.usuarioId ?? null} />
      </body>
    </html>
  );
}
