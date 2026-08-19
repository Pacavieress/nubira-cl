import type { ServicioListado } from "@/lib/api";
import { formatoCLP } from "@/lib/formato";
import { abreviarNombre } from "@/lib/texto";
import { RatingPill } from "./RatingPill";
import { TierBadge } from "./TierBadge";

// Puerto de app/componentes/card_servicio_grid.php:38-40 — mismo criterio de prefijo.
function overlayCategoria(categoria: string): { prefijo: string; nombre: string } {
  const prefijo = ["Otros", "Asesoría"].includes(categoria) ? "" : "Clase de";
  const nombre = categoria === "Otros" ? "Clase" : categoria;
  return { prefijo, nombre };
}

// La página de detalle ya existe en web/ (app/servicios/[id]) — enlaza ahí en vez de al
// sitio PHP real.
function urlDetalle(servicio: ServicioListado): string {
  return `/servicios/${servicio.id}`;
}

export function ServicioCard({ servicio }: { servicio: ServicioListado }) {
  const { prefijo, nombre: nombreCategoriaOverlay } = overlayCategoria(servicio.categoria);
  const tutorNombreAbrev = abreviarNombre(servicio.tutor.nombre);

  const pctDescuento =
    servicio.ofertaVigente && servicio.precio && servicio.precio > 0 && servicio.precioOferta !== null
      ? Math.round(((servicio.precio - servicio.precioOferta) / servicio.precio) * 100)
      : 0;

  return (
    <a
      href={urlDetalle(servicio)}
      className="block rounded-xl flex flex-col transition-transform duration-300 hover:-translate-y-1 cursor-pointer w-full sm:max-w-[380px] mx-auto md:max-w-none bg-transparent group h-full"
    >
      <div className="relative overflow-hidden w-full aspect-[3/2] rounded-xl bg-gray-100 border border-[#f0f0f0] shadow-[0_1px_3px_rgba(0,0,0,0.04)]">
        <img
          src={servicio.portada.main}
          alt={servicio.titulo}
          className="w-full h-full object-cover transition-transform duration-500 ease-out group-hover:scale-105"
          loading="lazy"
        />

        {/* Overlay: gradiente + categoría + tutor — calcado de overlay_card_servicio.php */}
        <div
          className="absolute inset-0 z-[5] pointer-events-none"
          style={{
            background:
              "linear-gradient(to bottom, rgba(0,0,0,0.42) 0%, rgba(0,0,0,0.08) 32%, rgba(0,0,0,0) 52%, rgba(0,0,0,0.10) 70%, rgba(0,0,0,0.48) 100%)",
          }}
        />
        <div className="absolute top-3 left-3 z-10 pr-2 leading-tight" style={{ maxWidth: "70%" }}>
          {prefijo && (
            <div
              className="text-white text-xs md:text-sm font-medium opacity-90"
              style={{ textShadow: "0 1px 2px rgba(0,0,0,0.5)" }}
            >
              {prefijo}
            </div>
          )}
          <div
            className="text-white text-base md:text-lg font-bold"
            style={{ textShadow: "0 1px 3px rgba(0,0,0,0.6)" }}
          >
            {nombreCategoriaOverlay}
          </div>
        </div>
        <div
          className="absolute bottom-3 left-3 z-10 pr-2 flex items-center gap-2 text-white text-base md:text-lg font-bold"
          style={{ maxWidth: "80%", textShadow: "0 1px 3px rgba(0,0,0,0.6)" }}
        >
          {servicio.tutor.fotoUrl && (
            <img
              src={servicio.tutor.fotoUrl}
              alt={tutorNombreAbrev}
              className="w-10 h-10 md:w-12 md:h-12 rounded-full object-cover ring-1 ring-white/40 shadow-[0_1px_3px_rgba(0,0,0,0.15)]"
              loading="lazy"
            />
          )}
          <span className="truncate min-w-0">{tutorNombreAbrev}</span>
        </div>

        {/* Badge derecha: tier (oculto en ofertas) o cupos (si hay oferta vigente) */}
        {!servicio.ofertaVigente && servicio.tier && (
          <div className="absolute top-1 right-1 z-10">
            <TierBadge tier={servicio.tier} />
          </div>
        )}
        {servicio.ofertaVigente && (
          <div className="absolute top-1 right-1 z-10">
            <span className="inline-flex items-center px-1.5 py-0 md:px-2 md:py-0.5 rounded-full text-[9px] md:text-[10px] font-medium bg-amber-100 text-amber-900 border border-amber-200">
              {servicio.cuposOferta} {servicio.cuposOferta === 1 ? "cupo" : "cupos"}
            </span>
          </div>
        )}
      </div>

      <div className="pl-1 pr-1 pt-3 pb-1 flex flex-col flex-1 text-left min-h-[90px]">
        <h6 className="font-medium text-[14px] leading-[1.3] tracking-[-0.01em] text-[#222222] line-clamp-2 h-[36px] overflow-hidden mb-1">
          {servicio.titulo}
        </h6>

        {servicio.ofertaVigente && servicio.precioOferta !== null ? (
          <div className="flex items-baseline gap-1.5 mb-0.5">
            <span className="text-[11px] text-gray-400 line-through font-medium leading-none">
              {servicio.precio !== null ? formatoCLP(servicio.precio) : ""}
            </span>
            <span className="text-[15px] text-[#222222] font-semibold tracking-[-0.01em] leading-none">
              {formatoCLP(servicio.precioOferta)}
            </span>
            {pctDescuento > 0 && (
              <span className="bg-green-600 text-white text-[9px] font-semibold px-1 py-px rounded ml-1.5 leading-none relative -top-0.5">
                -{pctDescuento}%
              </span>
            )}
          </div>
        ) : (
          <div className="text-[15px] text-[#222222] font-semibold tracking-[-0.01em] leading-none mb-0.5">
            {servicio.precio !== null && servicio.precio > 0 ? formatoCLP(servicio.precio) : "Gratis"}
          </div>
        )}

        <div className="flex items-center justify-between pt-1">
          <div className="flex items-center gap-1.5 text-[10px] text-gray-500 font-normal uppercase tracking-[0.01em] truncate max-w-[70%]">
            {servicio.tutor.institucion && <span className="truncate">{servicio.tutor.institucion}</span>}
          </div>
          <div className="shrink-0">
            <RatingPill promedio={servicio.rating.promedio} votos={servicio.rating.votos} />
          </div>
        </div>
      </div>
    </a>
  );
}
