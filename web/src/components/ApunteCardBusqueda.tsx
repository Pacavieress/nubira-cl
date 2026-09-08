import type { ApunteListado } from "@/lib/api";
import { formatoCLP } from "@/lib/formato";
import { abreviarInstitucion } from "@/lib/texto";

// Puerto de la card de apuntes DENTRO de busqueda.php (líneas 835-875) — distinta a
// propósito de ApunteCard.tsx (la de /apuntes, vitrina_apuntes.php): sin descripción,
// badge fijo "Apunte" (nunca "Nuevo"/promo), descargas en vez de "X descargas" con texto.
export function ApunteCardBusqueda({ apunte }: { apunte: ApunteListado }) {
  return (
    <a
      href={apunte.url}
      className="block rounded-xl flex flex-col transition-transform duration-300 hover:-translate-y-1 cursor-pointer w-full sm:max-w-[380px] mx-auto md:max-w-none bg-transparent group h-full"
    >
      <div className="relative overflow-hidden w-full aspect-[3/2] rounded-xl bg-gray-100 border border-[#f0f0f0] shadow-[0_1px_3px_rgba(0,0,0,0.04)]">
        <img
          src={apunte.portadaUrl}
          alt={apunte.titulo}
          className="w-full h-full object-cover transition-transform duration-500 ease-out group-hover:scale-105"
          loading="lazy"
        />
        <div className="absolute top-2.5 left-2.5 z-10">
          <span className="inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-medium bg-white/95 backdrop-blur-sm text-[#222222] border border-[#f0f0f0] shadow-[0_1px_2px_rgba(0,0,0,0.08)]">
            Apunte
          </span>
        </div>
      </div>

      <div className="pl-1 pr-1 pt-3 pb-1 flex flex-col flex-1 text-left min-h-[90px]">
        <h6 className="font-medium text-[14px] leading-[1.3] tracking-[-0.01em] text-[#222222] line-clamp-2 h-[36px] overflow-hidden mb-1">
          {apunte.titulo}
        </h6>

        <div className="text-[13px] text-[#222222] font-normal tracking-[-0.01em] leading-none mb-0.5">
          {apunte.precio > 0 ? formatoCLP(apunte.precio) : "Gratis"}
        </div>

        <div className="flex items-center justify-between pt-1">
          <div className="flex items-center gap-1.5 text-[10px] text-gray-500 font-normal uppercase tracking-[0.01em] truncate max-w-[70%]">
            {apunte.institucion && <span className="truncate">{abreviarInstitucion(apunte.institucion)}</span>}
          </div>
          {apunte.ventasTotales > 0 && (
            <div className="shrink-0 flex items-center gap-1 text-[10px] text-gray-500 font-semibold">
              <svg className="w-3 h-3 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
              </svg>
              {apunte.ventasTotales}
            </div>
          )}
        </div>
      </div>
    </a>
  );
}
