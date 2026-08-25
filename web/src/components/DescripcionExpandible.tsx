"use client";

import { useState } from "react";

// Puerto de detalle_servicio.php:559-587 (toggle "Leer más"/"Leer menos"). El procesamiento
// de texto (entidades, alternativas aleatorias, truncado a 150 chars) ya viene resuelto desde
// el server en `corta`/`completa` — ver procesarDescripcionServicio() en lib/texto.ts.
export function DescripcionExpandible({ corta, completa, esLarga }: { corta: string; completa: string; esLarga: boolean }) {
  const [expandido, setExpandido] = useState(false);

  return (
    <div>
      <div className="text-gray-600 text-sm whitespace-pre-line font-normal leading-relaxed break-words">
        {expandido ? completa : corta}
      </div>
      {esLarga && (
        <button
          type="button"
          onClick={() => setExpandido((v) => !v)}
          className="text-[#54A6D8] text-[11px] font-bold mt-1.5 hover:underline outline-none tracking-wide uppercase"
        >
          {expandido ? "Leer menos" : "Leer más"}
        </button>
      )}
    </div>
  );
}
