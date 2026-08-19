import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // web/ vive dentro del repo de Nubira, que tiene su propio package-lock.json en la
  // raíz (del build de Tailwind del sitio PHP) — sin esto, Next detecta 2 lockfiles y
  // adivina mal la raíz del workspace (warning real visto en el primer build).
  turbopack: {
    root: __dirname,
  },
};

export default nextConfig;
