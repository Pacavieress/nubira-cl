"use client";

import { useState } from "react";
import { CompartirApunteModal } from "./CompartirApunteModal";

// Puerto de icon('share-outline') (app/iconos.php:45) — el ícono real que usa TANTO el
// disparador de la topbar móvil (ver_apunte.php:478) COMO el botón de escritorio con texto
// (ver_apunte.php:720) — un solo ícono para ambos variants, no uno distinto por cada uno.
function IconoCompartirOutline({ className }: { className: string }) {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor" className={className}>
      <path
        strokeLinecap="round"
        strokeLinejoin="round"
        d="M7.5 8.25H6a2.25 2.25 0 00-2.25 2.25v9A2.25 2.25 0 006 21.75h12A2.25 2.25 0 0020.25 19.5v-9A2.25 2.25 0 0018 8.25h-1.5M12 3v12m0-12L8.25 6.75M12 3l3.75 3.75"
      />
    </svg>
  );
}

// Envoltorio cliente mínimo para poder usar useState dentro de la página server-component
// de detalle de apunte — el botón + modal son lo único interactivo de esa página.
//
// variant "texto" (default): botón con borde + label "Compartir", usado en el rail
// derecho desktop. variant "icono": círculo ícono-solo, puerto exacto del disparador de
// la topbar móvil (ver_apunte.php:475-479) — mismo modal por debajo, ambos disparadores
// son mutuamente excluyentes por breakpoint (uno hidden lg:block, el otro lg:hidden), así
// que dos instancias independientes de este componente nunca compiten por abrir el mismo
// modal a la vez.
export function CompartirApunteBoton({
  apunteId,
  titulo,
  variant = "texto",
}: {
  apunteId: number;
  titulo: string;
  variant?: "texto" | "icono";
}) {
  const [abierto, setAbierto] = useState(false);

  return (
    <>
      {variant === "icono" ? (
        <button
          type="button"
          onClick={() => setAbierto(true)}
          aria-label="Compartir"
          className="flex-shrink-0 w-10 h-10 flex items-center justify-center rounded-full bg-gray-50 text-[#54A6D8] border border-gray-200/60 shadow-sm active:scale-95 transition-all"
        >
          <IconoCompartirOutline className="w-5 h-5" />
        </button>
      ) : (
        <button
          type="button"
          onClick={() => setAbierto(true)}
          className="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-gray-50 text-[#54A6D8] border border-gray-200 text-sm font-bold transition-all shrink-0 hover:bg-[#54A6D8] hover:text-white"
        >
          <IconoCompartirOutline className="w-4 h-4" /> Compartir
        </button>
      )}
      <CompartirApunteModal apunteId={apunteId} titulo={titulo} abierto={abierto} onCerrar={() => setAbierto(false)} />
    </>
  );
}
