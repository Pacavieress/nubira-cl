"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";

// Puerto de server/src/modules/favoritos — feature nueva (Fase 7 de la migración, sin
// equivalente en el sitio PHP real, ver sql/pendientes/migracion_arquitectura_fase7_favoritos_servicios.sql).
// Solo se renderiza para un viewer autenticado (ver servicios/[id]/page.tsx) — sin manejo
// de redirect a /login acá, evita tener que enhebrar phpSiteUrl hasta este componente.
//
// variant="icono" (detalle de servicio): botón circular superpuesto sobre fondo claro.
// variant="texto" (mis-favoritos): NO se superpone a ServicioCard — se probó con un overlay
// absoluto sobre la imagen y colisionaba con el label de categoría/nombre de tutor que ya
// ocupan las 4 esquinas de OverlayServicio.tsx/TierBadge (confirmado con screenshot real),
// así que acá es un link de texto simple debajo de la card en vez de un ícono flotante.
export function FavoritoToggle({
  servicioId,
  favoritoInicial,
  variant = "icono",
}: {
  servicioId: number;
  favoritoInicial: boolean;
  variant?: "icono" | "texto";
}) {
  const router = useRouter();
  const [favorito, setFavorito] = useState(favoritoInicial);
  const [enviando, setEnviando] = useState(false);

  async function alternar() {
    if (enviando) return;
    const siguiente = !favorito;
    setFavorito(siguiente);
    setEnviando(true);
    try {
      const res = await fetch(`/api/favoritos/${servicioId}`, { method: siguiente ? "PUT" : "DELETE" });
      if (!res.ok) {
        setFavorito(!siguiente);
      } else {
        router.refresh();
      }
    } catch {
      setFavorito(!siguiente);
    } finally {
      setEnviando(false);
    }
  }

  function icono(claseTamano: string) {
    return (
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill={favorito ? "currentColor" : "none"} stroke="currentColor" strokeWidth={1.5} className={claseTamano}>
        <path
          strokeLinecap="round"
          strokeLinejoin="round"
          d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12z"
        />
      </svg>
    );
  }

  if (variant === "texto") {
    return (
      <button
        type="button"
        onClick={alternar}
        disabled={enviando}
        aria-pressed={favorito}
        aria-label={favorito ? "Quitar de favoritos" : "Agregar a favoritos"}
        className={`flex items-center gap-1.5 text-xs font-medium transition-colors disabled:opacity-60 ${favorito ? "text-red-500" : "text-gray-400 hover:text-red-500"}`}
      >
        {icono("w-4 h-4")}
        {favorito ? "Quitar de favoritos" : "Agregar a favoritos"}
      </button>
    );
  }

  return (
    <button
      type="button"
      onClick={alternar}
      disabled={enviando}
      aria-pressed={favorito}
      aria-label={favorito ? "Quitar de favoritos" : "Agregar a favoritos"}
      className={`shrink-0 w-10 h-10 rounded-full border shadow-sm flex items-center justify-center transition-all disabled:opacity-60 ${
        favorito ? "bg-red-50 border-red-100 text-red-500" : "bg-white border-gray-200 text-gray-400 hover:border-gray-300 hover:text-gray-500"
      }`}
    >
      {icono("w-5 h-5")}
    </button>
  );
}
