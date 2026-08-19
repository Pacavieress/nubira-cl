import type { ApunteListado } from "@/lib/api";
import { abreviarConteo, formatoCLP } from "@/lib/formato";

// Puerto del branch NO-compacto de cargar_apuntes.php:330-356 (el único que usa
// vitrina_apuntes.php — nunca pasa ?compacto=1 en su fetch inicial). El branch
// compacto no se porta acá por no tener consumidor real en web/ todavía.
export function ApunteCard({ apunte }: { apunte: ApunteListado }) {
  return (
    <a
      href={apunte.url}
      className="block rounded-xl flex flex-col mb-2 transition-transform duration-300 hover:-translate-y-1 cursor-pointer w-full sm:max-w-[380px] mx-auto md:max-w-none bg-transparent group"
    >
      <div className="relative bg-gray-100 rounded-xl overflow-hidden w-full border border-[#f0f0f0] shadow-[0_1px_3px_rgba(0,0,0,0.04)] aspect-[3/2]">
        <img
          src={apunte.portadaUrl}
          alt={apunte.titulo}
          className="w-full h-full object-cover object-top transition-transform duration-500 group-hover:scale-105"
          loading="lazy"
        />

        {/* Badge: promo flash tiene prioridad sobre "Nuevo" — mismo elseif de cargar_apuntes.php:288-294 */}
        <div className="absolute top-2.5 left-2.5 flex flex-wrap gap-2 z-10">
          {apunte.promo?.activa ? (
            <span className="inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-semibold bg-amber-100 text-amber-900 border border-amber-200">
              Quedan {apunte.promo.restantes}
            </span>
          ) : apunte.esNuevo ? (
            <span className="inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-medium bg-white/95 backdrop-blur-sm text-[#222222] border border-[#f0f0f0] shadow-[0_1px_2px_rgba(0,0,0,0.08)]">
              Nuevo
            </span>
          ) : null}
        </div>
      </div>

      <div className="pl-1 pr-2 pt-2 pb-2 flex flex-col gap-0 flex-1 text-left">
        <h6 className="font-medium text-[15px] leading-snug tracking-[-0.01em] text-[#222222] line-clamp-2 h-[40px] overflow-hidden mb-1">
          {apunte.titulo}
        </h6>

        {/* Siempre renderizado (altura fija), igual que el <p> incondicional de PHP */}
        <p className="text-[13px] text-gray-600 line-clamp-2 h-[36px] overflow-hidden mb-1.5">
          {apunte.descripcionCorta ?? ""}
        </p>

        <div className="text-[14px] leading-none mb-0">
          {apunte.promo?.activa ? (
            <span className="flex items-center">
              <span className="line-through text-gray-400 text-[10px] font-medium mr-1">{formatoCLP(apunte.precio)}</span>
              <span className="text-[#54A6D8] font-normal tracking-[-0.01em]">¡Gratis!</span>
            </span>
          ) : (
            <span className="text-[#222222] font-normal tracking-[-0.01em]">
              {apunte.precio > 0 ? formatoCLP(apunte.precio) : "Gratis"}
            </span>
          )}
        </div>

        <div className="flex items-center justify-between mt-1 pt-0">
          <div className="flex items-center gap-1.5 text-[10px] text-gray-500 font-normal uppercase tracking-[0.01em] truncate max-w-[70%]">
            {apunte.institucion && (
              <>
                <svg className="w-3 h-3 text-gray-300 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24">
                  <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z" />
                </svg>
                <span className="truncate">{apunte.institucion}</span>
              </>
            )}
          </div>
          <div className="flex items-center gap-1 text-[11px] text-gray-500">
            {apunte.ventasTotales > 0 && `${abreviarConteo(apunte.ventasTotales)} descargas`}
          </div>
        </div>
      </div>
    </a>
  );
}
