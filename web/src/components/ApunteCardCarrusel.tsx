import type { ApunteListado } from "@/lib/api";
import { abreviarConteo, formatoCLP } from "@/lib/formato";

// Puerto exacto de la card de apunte inline de app/vitrina.php:1298-1337 — DISTINTA de
// ApunteCard.tsx (que porta el branch no-compacto de cargar_apuntes.php, usado por
// /apuntes). Son 2 diseños reales distintos (150/170px vs ancho de grilla, aspect-[4/3]
// vs aspect-[3/2], sin ícono de institución, "¡Gratis!" en gris no celeste, SIN badge de
// "Nuevo" — vitrina.php calcula $es_nuevo_ap pero nunca lo usa en el render, confirmado
// con grep: es código muerto en el PHP real, así que tampoco se porta acá).
export function ApunteCardCarrusel({ apunte }: { apunte: ApunteListado }) {
  return (
    <a
      href={apunte.url}
      className="block flex flex-col cursor-pointer group snap-center w-[150px] md:w-[170px] flex-shrink-0 bg-transparent h-full"
    >
      <div className="relative w-full aspect-[4/3] bg-gray-100 overflow-hidden rounded-2xl border border-[#f0f0f0] shadow-[0_1px_3px_rgba(0,0,0,0.04)] transition-all">
        <img src={apunte.portadaUrl} alt={apunte.titulo} className="w-full h-full object-cover" loading="lazy" />

        {apunte.promo?.activa && (
          <div className="absolute top-2.5 left-2.5 z-10">
            <span className="inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-semibold bg-amber-100 text-amber-900 border border-amber-200">
              Quedan {apunte.promo.restantes}
            </span>
          </div>
        )}
      </div>

      <div className="pt-2.5 flex flex-col flex-1 text-left">
        <h3 className="font-medium text-[14px] leading-snug tracking-[-0.01em] text-[#222222] line-clamp-2 mb-1 min-h-[40px]">
          {apunte.titulo}
        </h3>

        {apunte.promo?.activa ? (
          <div className="text-[14px] text-[#222222] font-normal tracking-[-0.01em] flex items-center mt-auto mb-1.5 leading-none">
            <span className="line-through text-gray-400 text-[10px] md:text-xs font-medium mr-1">{formatoCLP(apunte.precio)}</span>
            <span className="text-gray-600 font-normal tracking-tight">¡Gratis!</span>
          </div>
        ) : (
          <div className="text-[14px] text-[#222222] font-normal tracking-[-0.01em] mt-auto mb-1.5 leading-none">
            {apunte.precio > 0 ? formatoCLP(apunte.precio) : "Gratis"}
          </div>
        )}

        <div className="flex items-center justify-between">
          <div className="flex items-center gap-1.5 text-[10px] font-normal tracking-[0.01em] text-gray-500 uppercase truncate max-w-[65%]">
            {apunte.institucion && <span className="truncate">{apunte.institucion}</span>}
          </div>
          {apunte.ventasTotales > 0 && (
            <div className="shrink-0 flex items-center">
              <span className="text-[10px] font-light tracking-[0.01em] text-gray-500 leading-none">
                {abreviarConteo(apunte.ventasTotales)} descargas
              </span>
            </div>
          )}
        </div>
      </div>
    </a>
  );
}
