"use client";

import { useRef } from "react";

// Puerto de NubiraUI.scrollCarrusel() (vitrina.php:1965-1970) + los botones flecha que
// lo disparan — mismo desplazamiento (300px, smooth), mismos estilos de botón. Los hijos
// (ServicioCard/ApunteCard) se reutilizan tal cual dentro de un contenedor de ancho fijo
// por item (el propio carrusel es lo nuevo, no las cards).
export function Carrusel({ children }: { children: React.ReactNode }) {
  const ref = useRef<HTMLDivElement>(null);

  function scroll(direccion: 1 | -1) {
    ref.current?.scrollBy({ left: direccion * 300, behavior: "smooth" });
  }

  return (
    <div className="relative group">
      <button
        type="button"
        onClick={() => scroll(-1)}
        aria-label="Anterior"
        className="hidden md:flex absolute left-2 opacity-0 group-hover:opacity-100 transition-opacity duration-200 top-[40%] -translate-y-1/2 w-10 h-10 bg-white rounded-full shadow-[0_1px_3px_rgba(0,0,0,0.04)] hover:shadow-[0_2px_8px_rgba(0,0,0,0.06)] items-center justify-center z-10 text-gray-400 hover:text-[#54A6D8] border border-[#f0f0f0]"
      >
        <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
        </svg>
      </button>

      <div
        ref={ref}
        className="flex gap-4 overflow-x-auto snap-x snap-mandatory pb-3 no-scrollbar scroll-smooth pl-4 pr-4 md:pl-10 md:pr-10"
      >
        {children}
      </div>

      <button
        type="button"
        onClick={() => scroll(1)}
        aria-label="Siguiente"
        className="hidden md:flex absolute right-2 opacity-0 group-hover:opacity-100 transition-opacity duration-200 top-[40%] -translate-y-1/2 w-10 h-10 bg-white rounded-full shadow-[0_1px_3px_rgba(0,0,0,0.04)] hover:shadow-[0_2px_8px_rgba(0,0,0,0.06)] items-center justify-center z-10 text-gray-400 hover:text-[#54A6D8] border border-[#f0f0f0]"
      >
        <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
        </svg>
      </button>
    </div>
  );
}
