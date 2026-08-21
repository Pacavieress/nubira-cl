"use client";

import { useState } from "react";
import { CompartirApunteModal } from "./CompartirApunteModal";

function IconoCompartir() {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor" className="w-4 h-4">
      <path strokeLinecap="round" strokeLinejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" />
    </svg>
  );
}

// Envoltorio cliente mínimo para poder usar useState dentro de la página server-component
// de detalle de apunte — el botón + modal son lo único interactivo de esa página.
export function CompartirApunteBoton({ apunteId, titulo }: { apunteId: number; titulo: string }) {
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
      <CompartirApunteModal apunteId={apunteId} titulo={titulo} abierto={abierto} onCerrar={() => setAbierto(false)} />
    </>
  );
}
