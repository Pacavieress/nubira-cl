import { notFound } from "next/navigation";
import { getServicioDetalle } from "@/lib/api";
import { formatoCLP } from "@/lib/formato";
import { parsearHorariosServicio } from "@/lib/horarios";
import { abreviarNombre, inicial } from "@/lib/texto";
import { Header } from "@/components/Header";
import { TiempoRespuestaPill } from "@/components/TiempoRespuestaPill";

interface DetalleProps {
  params: Promise<{ id: string }>;
}

export default async function DetalleServicio({ params }: DetalleProps) {
  const { id } = await params;
  const servicioId = Number(id);
  if (!Number.isInteger(servicioId) || servicioId <= 0) {
    notFound();
  }

  const servicio = await getServicioDetalle(servicioId);
  if (!servicio) {
    notFound();
  }

  const disponibilidad = parsearHorariosServicio(servicio.horarios);
  const pctDescuento =
    servicio.ofertaVigente && servicio.precio && servicio.precio > 0 && servicio.precioOferta !== null
      ? Math.round(((servicio.precio - servicio.precioOferta) / servicio.precio) * 100)
      : 0;

  return (
    <>
      <Header titulo={servicio.titulo} />
      <main className="w-full max-w-[1100px] mx-auto px-4 md:px-8 pt-20 pb-16">
        <div className="bg-white border border-[#f0f0f0] rounded-2xl p-6 shadow-[0_1px_3px_rgba(0,0,0,0.04)]">
          {/* Categoría — calcado de detalle_servicio.php:484-487 */}
          <span className="px-2.5 py-0.5 rounded text-[10px] font-semibold bg-gray-100 text-gray-700 border border-[#f0f0f0] uppercase tracking-wide">
            {servicio.categoria}
          </span>

          <h1 className="text-3xl md:text-4xl font-medium text-[#222222] leading-tight mb-6 mt-3 tracking-[-0.01em]">
            {servicio.titulo}
          </h1>

          {/* Bloque tutor — calcado de detalle_servicio.php:498-544 */}
          <div className="flex items-center gap-4 pb-6 border-b border-[#f0f0f0] w-full">
            <div className="w-24 h-24 rounded-full border border-[#f0f0f0] bg-white overflow-hidden shadow-[0_1px_3px_rgba(0,0,0,0.04)] flex-shrink-0">
              {servicio.tutor.fotoUrl ? (
                <img src={servicio.tutor.fotoUrl} alt={servicio.tutor.nombre ?? "Tutor"} className="w-full h-full object-cover" />
              ) : (
                <div className="w-full h-full flex items-center justify-center bg-blue-50 text-[#54A6D8] font-bold text-2xl">
                  {inicial(servicio.tutor.nombre)}
                </div>
              )}
            </div>
            <div>
              <div className="flex items-center gap-1.5">
                <p className="text-sm font-medium tracking-[-0.01em] text-[#222222]">
                  Publicado por {abreviarNombre(servicio.tutor.nombre)}
                </p>
                {servicio.tutor.verificado && (
                  <svg className="w-3.5 h-3.5 text-[#54A6D8]" fill="currentColor" viewBox="0 0 20 20">
                    <path
                      fillRule="evenodd"
                      d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                      clipRule="evenodd"
                    />
                  </svg>
                )}
              </div>
              {servicio.tutor.institucion && (
                <div className="flex items-center gap-1.5 mt-0.5">
                  <span className="text-gray-400 text-xs">🏛</span>
                  <p className="text-xs text-gray-500 font-normal tracking-[0.01em]">{servicio.tutor.institucion}</p>
                </div>
              )}
              <div className="mt-1.5">
                <TiempoRespuestaPill
                  tono={servicio.tiempoRespuesta.tono}
                  texto={servicio.tiempoRespuesta.texto}
                  ratingPromedio={servicio.rating.promedio}
                  votos={servicio.rating.votos}
                />
              </div>
            </div>
          </div>

          {/* Precio — calcado de detalle_servicio.php:849-862 (sin el CTA de contratar) */}
          <div className="my-6 border-b border-gray-100 pb-6">
            <p className="text-xs text-gray-500 font-bold uppercase mb-1">Inversión total</p>
            {servicio.ofertaVigente && servicio.precioOferta !== null ? (
              <div className="flex flex-col gap-0.5">
                <span className="text-sm text-gray-400 line-through font-medium">
                  Normal {servicio.precio !== null ? formatoCLP(servicio.precio) : ""}
                </span>
                <div className="flex items-baseline gap-2">
                  <span className="text-4xl font-normal text-[#222222] tracking-[-0.01em] leading-none">
                    {formatoCLP(servicio.precioOferta)}
                  </span>
                  {pctDescuento > 0 && (
                    <span className="bg-green-600 text-white text-xs font-semibold px-1.5 py-0.5 rounded ml-1 align-middle">
                      -{pctDescuento}%
                    </span>
                  )}
                </div>
              </div>
            ) : (
              <span className="text-4xl font-normal text-[#222222] tracking-[-0.01em] leading-none">
                {servicio.precio !== null && servicio.precio > 0 ? formatoCLP(servicio.precio) : "Gratis"}
              </span>
            )}
          </div>

          {/* Descripción — calcado de detalle_servicio.php:547+ */}
          <div>
            <h3 className="font-medium tracking-[-0.01em] text-[#222222] mb-3">Sobre este servicio</h3>
            <p className="text-sm text-gray-600 leading-relaxed whitespace-pre-line break-words">{servicio.descripcion}</p>
          </div>

          {/* Disponibilidad — calcado de detalle_servicio.php:697-748, SIN el selector de
              slot interactivo (eso es flujo de contratación, fuera de alcance de web/) */}
          {disponibilidad.tieneHorarios && (
            <div className="mt-8 pt-8 border-t border-gray-50">
              <h3 className="font-medium tracking-[-0.01em] text-[#222222] mb-5">Disponibilidad</h3>
              <div className="mb-4 flex items-center gap-2">
                <div className="inline-flex items-center gap-1.5 bg-emerald-50 border border-emerald-100 rounded-full px-3 py-1">
                  <span className="w-1.5 h-1.5 bg-emerald-500 rounded-full" />
                  <span className="text-[11px] font-medium text-emerald-700">
                    Disponible {disponibilidad.dias.length} día{disponibilidad.dias.length > 1 ? "s" : ""} a la semana
                  </span>
                </div>
              </div>
              <div className="grid grid-cols-2 md:grid-cols-3 gap-3">
                {disponibilidad.dias.map(({ dia, bloques }) => {
                  const esProximo = dia === disponibilidad.diaProximo;
                  return (
                    <div
                      key={dia}
                      className={`text-left bg-white border rounded-xl p-3 shadow-[0_1px_3px_rgba(0,0,0,0.04)] relative ${
                        esProximo ? "border-[#54A6D8] ring-2 ring-blue-100" : "border-[#f0f0f0]"
                      }`}
                    >
                      {esProximo && (
                        <span className="absolute -top-2 -right-2 bg-[#54A6D8] text-white text-[9px] font-black uppercase tracking-wider px-2 py-0.5 rounded-full shadow-sm">
                          Próximo
                        </span>
                      )}
                      <p className={`text-xs font-medium mb-2 ${esProximo ? "text-[#54A6D8]" : "text-[#222222]"}`}>{dia}</p>
                      <div className="flex flex-col gap-1.5">
                        {bloques.map((bloque) => (
                          <span
                            key={bloque}
                            className="bg-blue-50 text-[#54A6D8] text-[10px] font-medium px-2 py-1 rounded-md text-center border border-blue-100/50 truncate"
                          >
                            {bloque}
                          </span>
                        ))}
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>
          )}

          {/* Opiniones — calcado de detalle_servicio.php:787-825 */}
          <div className="mt-8 pt-8 border-t border-gray-50">
            <h3 className="font-medium tracking-[-0.01em] text-[#222222] mb-6 flex gap-2 items-center">
              Opiniones
              {servicio.rating.votos > 0 && (
                <span className="bg-gray-100 text-gray-600 px-2 py-0.5 rounded-full text-xs">{servicio.rating.votos}</span>
              )}
            </h3>
            {servicio.valoraciones.length > 0 ? (
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                {servicio.valoraciones.map((v) => (
                  <div key={v.id} className="bg-gray-50 border border-[#f0f0f0] shadow-[0_1px_3px_rgba(0,0,0,0.04)] p-4 rounded-xl">
                    <div className="flex items-center gap-3 mb-2">
                      <div className="w-8 h-8 rounded-full bg-white border border-[#f0f0f0] overflow-hidden">
                        {v.evaluador.fotoUrl.startsWith("https://ui-avatars.com") ? (
                          <div className="w-full h-full flex items-center justify-center text-[#54A6D8] bg-blue-50 font-bold text-xs">
                            {inicial(v.evaluador.nombre)}
                          </div>
                        ) : (
                          <img src={v.evaluador.fotoUrl} alt={v.evaluador.nombre ?? "Usuario"} className="w-full h-full object-cover" />
                        )}
                      </div>
                      <div>
                        <p className="font-medium tracking-[-0.01em] text-xs text-[#222222]">{abreviarNombre(v.evaluador.nombre)}</p>
                        <p className="text-[10px] text-gray-400 font-normal">
                          {new Date(v.fecha).toLocaleDateString("es-CL", { day: "2-digit", month: "short", year: "numeric" })}
                        </p>
                      </div>
                    </div>
                    <div className="flex text-yellow-400 text-[10px] mb-2">
                      {Array.from({ length: 5 }, (_, i) => (
                        <span key={i}>{i < v.calificacion ? "★" : "☆"}</span>
                      ))}
                    </div>
                    {v.comentario && <p className="text-gray-600 text-xs font-normal leading-relaxed">{v.comentario}</p>}
                  </div>
                ))}
              </div>
            ) : (
              <div className="text-center py-6 text-gray-400 text-sm italic">Aún no hay opiniones para este servicio.</div>
            )}
          </div>
        </div>
      </main>
    </>
  );
}
