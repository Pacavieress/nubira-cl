import type { Metadata } from "next";
import { Inter } from "next/font/google";
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

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html lang="es" className={`${inter.variable} h-full antialiased`}>
      <body className="min-h-full flex flex-col">{children}</body>
    </html>
  );
}
