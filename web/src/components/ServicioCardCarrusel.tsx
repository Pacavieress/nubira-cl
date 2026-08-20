import type { ServicioListado } from "@/lib/api";
import { formatoCLP } from "@/lib/formato";
import { abreviarNombre } from "@/lib/texto";
import { OverlayServicio } from "./OverlayServicio";
import { RatingPillCarrusel } from "./RatingPillCarrusel";
import { TierBadge } from "./TierBadge";

// Puerto exacto de la card de servicio inline de app/vitrina.php:943-1015 — DISTINTA de
// ServicioCard.tsx (que porta card_servicio_grid.php, usado por el listado /servicios).
// Son 2 diseños reales de card distintos en el propio PHP (aspect-[4/3] vs aspect-[3/2],
// rounded-2xl vs rounded-xl, sin hover:-translate-y-1 en la carousel), no una
// inconsistencia — cada uno se porta tal cual el original que le corresponde.
function overlayCategoria(categoria: string): { prefijo: string; nombre: string } {
  const prefijo = ["Otros", "Asesoría"].includes(categoria) ? "" : "Clase de";
  const nombre = categoria === "Otros" ? "Clase" : categoria;
  return { prefijo, nombre };
}

export function ServicioCardCarrusel({
  servicio,
  ancho = "lg",
}: {
  servicio: ServicioListado;
  ancho?: "lg" | "sm";
}) {
  const { prefijo, nombre: nombreCategoriaOverlay } = overlayCategoria(servicio.categoria);
  const tutorNombreAbrev = abreviarNombre(servicio.tutor.nombre);

  const pctDescuento =
    servicio.ofertaVigente && servicio.precio && servicio.precio > 0 && servicio.precioOferta !== null
      ? Math.round(((servicio.precio - servicio.precioOferta) / servicio.precio) * 100)
      : 0;

  // Puerto de vitrina.php:883 ($es_basico = score_nubira < 60) — tier ya sale null server-side
  // exactamente en ese mismo caso (ver computeTier() en servicios.mapper.ts), así que es
  // equivalente sin necesitar exponer score_nubira crudo en la API pública.
  const esBasico = servicio.tier === null;
  // lg (recomendadas/nuevas/PAES): 220/240px, imagen 'card'. sm (ofertas): 150/170px,
  // imagen 'thumb' — puerto exacto de vitrina.php:1466 ($portada_url_of = thumb).
  const claseAncho = ancho === "sm" ? "w-[150px] md:w-[170px]" : "w-[220px] md:w-[240px]";
  const portadaUrl = ancho === "sm" ? servicio.portada.thumb : servicio.portada.card;

  return (
    <a
      href={`/servicios/${servicio.id}`}
      className={`block flex flex-col cursor-pointer group snap-center ${claseAncho} flex-shrink-0 bg-transparent h-full ${esBasico ? "opacity-90 grayscale-[15%]" : ""}`}
    >
      <div className="relative w-full aspect-[4/3] bg-gray-100 overflow-hidden rounded-2xl border border-[#f0f0f0] shadow-[0_1px_3px_rgba(0,0,0,0.04)] transition-all">
        <img src={portadaUrl} alt={servicio.titulo} className="w-full h-full object-cover" loading="lazy" />

        <OverlayServicio
          prefijo={prefijo}
          categoria={nombreCategoriaOverlay}
          fotoUrl={servicio.tutor.fotoUrl}
          nombre={tutorNombreAbrev}
        />

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

      <div className="pt-2.5 flex flex-col flex-1 text-left">
        <h3 className="font-medium text-[14px] leading-snug tracking-[-0.01em] text-[#222222] line-clamp-2 mb-1 min-h-[40px]">
          {servicio.titulo}
        </h3>

        {servicio.ofertaVigente && servicio.precioOferta !== null ? (
          <div className="text-[14px] text-[#222222] font-normal tracking-[-0.01em] mt-auto mb-1.5 leading-none">
            <span className="line-through text-gray-400 text-[10px] md:text-xs font-medium mr-1">
              {servicio.precio !== null ? formatoCLP(servicio.precio) : ""}
            </span>
            <span className="text-gray-600 font-normal tracking-tight">{formatoCLP(servicio.precioOferta)}</span>
            {pctDescuento > 0 && (
              <span className="bg-green-600 text-white text-[9px] font-semibold px-1 py-px rounded ml-1.5 leading-none relative -top-0.5">
                -{pctDescuento}%
              </span>
            )}
          </div>
        ) : (
          <div className="text-[14px] text-[#222222] font-normal tracking-[-0.01em] mt-auto mb-1.5 leading-none">
            {servicio.precio !== null && servicio.precio > 0 ? formatoCLP(servicio.precio) : "Gratis"}
          </div>
        )}

        <div className="flex items-center justify-between">
          <div className="flex items-center gap-1.5 text-[10px] font-normal tracking-[0.01em] text-gray-500 uppercase truncate max-w-[65%]">
            {servicio.tutor.institucion && <span className="truncate">{servicio.tutor.institucion}</span>}
          </div>
          <div className="shrink-0 flex items-center gap-1">
            <RatingPillCarrusel promedio={servicio.rating.promedio} votos={servicio.rating.votos} />
          </div>
        </div>
      </div>
    </a>
  );
}
