"use client";

import { useState } from "react";
import { CompartirServicioModal } from "./CompartirServicioModal";

// Puerto exacto de icon('share-outline', ...) en app/iconos.php:45 — arrow-up-tray
// (caja con flecha hacia arriba), no el paper-airplane que había acá antes.
function IconoCompartir() {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor" className="w-4 h-4">
      <path
        strokeLinecap="round"
        strokeLinejoin="round"
        d="M7.5 8.25H6a2.25 2.25 0 00-2.25 2.25v9A2.25 2.25 0 006 21.75h12A2.25 2.25 0 0020.25 19.5v-9A2.25 2.25 0 0018 8.25h-1.5M12 3v12m0-12L8.25 6.75M12 3l3.75 3.75"
      />
    </svg>
  );
}

// Envoltorio cliente mínimo — mismo patrón que CompartirApunteBoton.tsx, para poder usar
// useState dentro de la página server-component de detalle de servicio.
export function CompartirServicioBoton({ servicioId, titulo }: { servicioId: number; titulo: string }) {
  const [abierto, setAbierto] = useState(false);

  return (
    <>
      <button
        type="button"
        onClick={() => setAbierto(true)}
        className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-gray-200 hover:border-[#54A6D8] hover:text-[#54A6D8] text-xs font-medium text-gray-600 transition-colors"
      >
        <IconoCompartir /> Compartir
      </button>
      <CompartirServicioModal servicioId={servicioId} titulo={titulo} abierto={abierto} onCerrar={() => setAbierto(false)} />
    </>
  );
}
