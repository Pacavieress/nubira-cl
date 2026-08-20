import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // web/ vive dentro del repo de Nubira, que tiene su propio package-lock.json en la
  // raíz (del build de Tailwind del sitio PHP) — sin esto, Next detecta 2 lockfiles y
  // adivina mal la raíz del workspace (warning real visto en el primer build).
  turbopack: {
    root: __dirname,
  },
  // Sin esto, Next.js (protección anti DNS-rebinding en dev, por defecto solo permite
  // "localhost") responde 403 a TODOS los assets de /_next/static/* y al WebSocket de HMR
  // cuando se navega por nubira.local:3000 en vez de localhost:3000 — el HTML de SSR se ve
  // completo (los botones existen en el DOM), pero el bundle de React nunca carga, así que
  // nunca hidrata y NINGÚN botón de la página tiene onClick real. sesion.ts ya documenta que
  // hay que visitar web/ por nubira.local (no localhost) para que el navegador reenvíe la
  // cookie PHPSESSID real — sin este allowlist, esa es justamente la forma de uso que rompe.
  allowedDevOrigins: ["nubira.local"],
};

export default nextConfig;
