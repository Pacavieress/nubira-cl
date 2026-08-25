import { notFound } from "next/navigation";
import { getServicioDetalle } from "@/lib/api";
import { formatoCLP } from "@/lib/formato";
import { parsearHorariosServicio } from "@/lib/horarios";
import { abreviarNombre, inicial, procesarDescripcionServicio } from "@/lib/texto";
import { Carrusel } from "@/components/Carrusel";
import { CompartirServicioBoton } from "@/components/CompartirServicioBoton";
import { DescripcionExpandible } from "@/components/DescripcionExpandible";
import { FavoritoToggle } from "@/components/FavoritoToggle";
import { Header } from "@/components/Header";
import { ServicioCardCarrusel } from "@/components/ServicioCardCarrusel";
import { TiempoRespuestaPill } from "@/components/TiempoRespuestaPill";
import { VideoTutorPlayer } from "@/components/VideoTutorPlayer";
import { VistaTracker } from "@/components/VistaTracker";

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

  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const disponibilidad = parsearHorariosServicio(servicio.horarios);
  const pctDescuento =
    servicio.ofertaVigente && servicio.precio && servicio.precio > 0 && servicio.precioOferta !== null
      ? Math.round(((servicio.precio - servicio.precioOferta) / servicio.precio) * 100)
      : 0;
  const descripcion = procesarDescripcionServicio(servicio.descripcion);

  const { isOwner, isAuthenticated, contratoId } = servicio.viewer;
  // Puerto exacto de detalle_servicio.php:1126-1130.
  const mostrarBarraMovil = !isOwner && !contratoId && servicio.estado === "aprobado";

  return (
    <>
      <Header titulo={servicio.titulo} />
      {!isOwner && <VistaTracker publicacionId={servicio.id} />}
      <main className={`w-full max-w-[1100px] mx-auto px-4 md:px-8 pt-20 pb-24 lg:pb-16 lg:ml-64 ${mostrarBarraMovil ? "pb-40 lg:pb-16" : ""}`}>
        {/* Banner propietario — puerto de detalle_servicio.php:444-466. Solo puede
            aparecer si isOwner (o admin), único caso en que estado!='aprobado' llega
            hasta acá — ver el fix de visibilidad en servicios.controller.ts. */}
        {isOwner && servicio.estado === "pendiente" && (
          <div className="mb-6 bg-amber-50 border border-yellow-200 rounded-2xl p-4 flex items-start md:items-center gap-4 shadow-sm">
            <div className="w-10 h-10 bg-yellow-100 text-yellow-600 rounded-full flex items-center justify-center shrink-0 mt-1 md:mt-0">
              <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
            </div>
            <div>
              <h4 className="font-bold text-yellow-800 text-sm">Servicio en Revisión</h4>
              <p className="text-xs text-yellow-700 font-medium">
                Editaste este servicio recientemente. Un administrador lo está revisando para asegurar que cumple con nuestras normas. Volverá a la vitrina pronto.
              </p>
            </div>
          </div>
        )}
        {isOwner && servicio.estado === "rechazado" && (
          <div className="mb-6 bg-red-50 border border-red-200 rounded-2xl p-4 flex items-start md:items-center gap-4 shadow-sm">
            <div className="w-10 h-10 bg-red-100 text-red-600 rounded-full flex items-center justify-center shrink-0 mt-1 md:mt-0">
              <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeWidth={1.5}
                  d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"
                />
              </svg>
            </div>
            <div>
              <h4 className="font-bold text-red-800 text-sm">Publicación Pausada</h4>
              <p className="text-xs text-red-700 font-medium">Hubo un problema con la última edición de este servicio. Por favor, revísalo y edítalo nuevamente.</p>
            </div>
          </div>
        )}

        <div className="bg-white border border-[#f0f0f0] rounded-2xl p-6 shadow-[0_1px_3px_rgba(0,0,0,0.04)]">
          <div className="flex items-start justify-between gap-3">
            <span className="px-2.5 py-0.5 rounded text-[10px] font-semibold bg-gray-100 text-gray-700 border border-[#f0f0f0] uppercase tracking-wide">
              {servicio.categoria}
            </span>
            <div className="flex items-center gap-2 ml-auto">
              <CompartirServicioBoton servicioId={servicio.id} titulo={servicio.titulo} />
              {servicio.viewer.isAuthenticated && <FavoritoToggle servicioId={servicio.id} favoritoInicial={servicio.viewer.esFavorito} />}
            </div>
          </div>

          <h1 className="text-3xl md:text-4xl font-medium text-[#222222] leading-tight mb-6 mt-3 tracking-[-0.01em]">{servicio.titulo}</h1>

          <div className="flex items-center gap-4 pb-6 border-b border-[#f0f0f0] w-full">
            <div className="w-24 h-24 rounded-full border border-[#f0f0f0] bg-white overflow-hidden shadow-[0_1px_3px_rgba(0,0,0,0.04)] flex-shrink-0">
              {servicio.tutor.fotoUrl ? (
                <img src={servicio.tutor.fotoUrl} alt={servicio.tutor.nombre ?? "Tutor"} className="w-full h-full object-cover" />
              ) : (
                <div className="w-full h-full flex items-center justify-center bg-blue-50 text-[#54A6D8] font-bold text-2xl">{inicial(servicio.tutor.nombre)}</div>
              )}
            </div>
            <div>
              <div className="flex items-center gap-1.5">
                <p className="text-sm font-medium tracking-[-0.01em] text-[#222222]">Publicado por {abreviarNombre(servicio.tutor.nombre)}</p>
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
                  <svg className="w-3 h-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path
                      strokeLinecap="round"
                      strokeLinejoin="round"
                      strokeWidth={1.5}
                      d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21"
                    />
                  </svg>
                  <p className="text-xs text-gray-500 font-normal tracking-[0.01em]">{servicio.tutor.institucion}</p>
                </div>
              )}
              <div className="mt-1.5">
                <TiempoRespuestaPill tono={servicio.tiempoRespuesta.tono} texto={servicio.tiempoRespuesta.texto} ratingPromedio={servicio.rating.promedio} votos={servicio.rating.votos} />
              </div>
            </div>
          </div>

          <div className="my-6 border-b border-gray-100 pb-6">
            <p className="text-xs text-gray-500 font-bold uppercase mb-1">Inversión total</p>
            {servicio.ofertaVigente && servicio.precioOferta !== null ? (
              <div className="flex flex-col gap-0.5">
                <span className="text-sm text-gray-400 line-through font-medium">Normal {servicio.precio !== null ? formatoCLP(servicio.precio) : ""}</span>
                <div className="flex items-baseline gap-2">
                  <span className="text-4xl font-normal text-[#222222] tracking-[-0.01em] leading-none">{formatoCLP(servicio.precioOferta)}</span>
                  {pctDescuento > 0 && <span className="bg-green-600 text-white text-xs font-semibold px-1.5 py-0.5 rounded ml-1 align-middle">-{pctDescuento}%</span>}
                </div>
              </div>
            ) : (
              <span className="text-4xl font-normal text-[#222222] tracking-[-0.01em] leading-none">
                {servicio.precio !== null && servicio.precio > 0 ? formatoCLP(servicio.precio) : "Gratis"}
              </span>
            )}
          </div>

          {/* CTA — puerto de detalle_servicio.php:882-930. Contratar/Iniciar chat quedan
              excluidos de web/ (pagos y chat, fuera de alcance) y enlazan al sitio real. */}
          <div className="space-y-3">
            {contratoId ? (
              <a
                href={`${phpSiteUrl}/app/mini_aula.php?id=${contratoId}`}
                className="block w-full bg-green-600 text-white font-bold py-3 rounded-xl text-center hover:bg-green-700 transition shadow-md"
              >
                Ir al Aula Virtual
              </a>
            ) : isOwner ? (
              <a href={`${phpSiteUrl}/app/editar_servicio.php?id=${servicio.id}`} className="block w-full bg-gray-100 text-gray-700 font-bold py-3 rounded-xl text-center hover:bg-gray-200 transition">
                Editar Servicio
              </a>
            ) : (
              <>
                <a
                  href={`${phpSiteUrl}/app/contratar_servicio.php?servicio_id=${servicio.id}`}
                  className="w-full text-white bg-[#54A6D8] hover:bg-blue-600 font-bold rounded-xl text-sm px-5 py-3.5 text-center transition-all flex items-center justify-center"
                >
                  {servicio.ofertaVigente && servicio.precioOferta !== null ? `Contratar por ${formatoCLP(servicio.precioOferta)}` : "Contratar Servicio"}
                </a>
                <a
                  href={`${phpSiteUrl}/app/iniciar_chat.php?servicio_id=${servicio.id}`}
                  className="mt-3 w-full bg-white text-[#54A6D8] border-2 border-[#54A6D8] font-bold rounded-xl text-sm px-5 py-3 hover:bg-blue-50 transition-all shadow-sm flex items-center justify-center gap-2"
                >
                  Iniciar chat
                </a>
                {!isAuthenticated && (
                  <div className="p-4 bg-gray-50 rounded-xl text-center border border-gray-200 mt-3">
                    <p className="text-xs text-gray-600 mb-3 font-semibold">¿Ya tienes cuenta?</p>
                    <a href={`${phpSiteUrl}/login`} className="block w-full bg-white text-gray-700 border border-gray-300 font-bold text-sm py-2.5 rounded-xl hover:bg-gray-100 transition mb-2">
                      Ingresar ahora
                    </a>
                    <a href={`${phpSiteUrl}/registro`} className="block w-full text-[#54A6D8] font-bold text-xs hover:underline">
                      ¿No tienes cuenta? Regístrate gratis
                    </a>
                  </div>
                )}
              </>
            )}
          </div>

          <div className="mt-8">
            <h3 className="font-medium tracking-[-0.01em] text-[#222222] mb-3">Sobre este servicio</h3>
            <DescripcionExpandible corta={descripcion.corta} completa={descripcion.completa} esLarga={descripcion.esLarga} />
          </div>

          {servicio.video ? (
            <div className="mt-6 pt-6 border-t border-gray-50">
              <h3 className="text-sm font-bold text-gray-700 mb-3">Video de presentación del tutor</h3>
              <VideoTutorPlayer videoUrl={`${phpSiteUrl}/upload/videos_servicios/${encodeURIComponent(servicio.video.path)}`} posterUrl={servicio.video.thumbUrl} />
            </div>
          ) : (
            isOwner && (
              <div className="mt-6 pt-6 border-t border-gray-50">
                <div className="border border-[#f0f0f0] rounded-2xl p-4 flex items-center justify-between gap-3">
                  <span className="text-sm text-gray-500">Los servicios con video reciben más contactos</span>
                  <a href={`${phpSiteUrl}/app/editar_servicio.php?id=${servicio.id}#seccion-video`} className="text-[11px] font-bold text-[#54A6D8] hover:text-blue-700 bg-blue-50 px-3 py-1.5 rounded-full transition-colors shrink-0 whitespace-nowrap">
                    Agregar video
                  </a>
                </div>
              </div>
            )
          )}

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
                        <span className="absolute -top-2 -right-2 bg-[#54A6D8] text-white text-[9px] font-black uppercase tracking-wider px-2 py-0.5 rounded-full shadow-sm">Próximo</span>
                      )}
                      <p className={`text-xs font-medium mb-2 ${esProximo ? "text-[#54A6D8]" : "text-[#222222]"}`}>{dia}</p>
                      <div className="flex flex-col gap-1.5">
                        {bloques.map((bloque) => (
                          <span key={bloque} className="bg-blue-50 text-[#54A6D8] text-[10px] font-medium px-2 py-1 rounded-md text-center border border-blue-100/50 truncate">
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

          <div className="mt-8 pt-8 border-t border-gray-50">
            <h3 className="font-medium tracking-[-0.01em] text-[#222222] mb-6 flex gap-2 items-center">
              Opiniones
              {servicio.rating.votos > 0 && <span className="bg-gray-100 text-gray-600 px-2 py-0.5 rounded-full text-xs">{servicio.rating.votos}</span>}
            </h3>
            {servicio.valoraciones.length > 0 ? (
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                {servicio.valoraciones.map((v) => (
                  <div key={v.id} className="bg-gray-50 border border-[#f0f0f0] shadow-[0_1px_3px_rgba(0,0,0,0.04)] p-4 rounded-xl">
                    <div className="flex items-center gap-3 mb-2">
                      <div className="w-8 h-8 rounded-full bg-white border border-[#f0f0f0] overflow-hidden">
                        {v.evaluador.fotoUrl.startsWith("https://ui-avatars.com") ? (
                          <div className="w-full h-full flex items-center justify-center text-[#54A6D8] bg-blue-50 font-bold text-xs">{inicial(v.evaluador.nombre)}</div>
                        ) : (
                          <img src={v.evaluador.fotoUrl} alt={v.evaluador.nombre ?? "Usuario"} className="w-full h-full object-cover" />
                        )}
                      </div>
                      <div>
                        <p className="font-medium tracking-[-0.01em] text-xs text-[#222222]">{abreviarNombre(v.evaluador.nombre)}</p>
                        <p className="text-[10px] text-gray-400 font-normal">{new Date(v.fecha).toLocaleDateString("es-CL", { day: "2-digit", month: "short", year: "numeric" })}</p>
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

          <div className="mt-8 bg-slate-50 border border-[#f0f0f0] rounded-2xl p-5 flex gap-4 items-start shadow-[0_1px_3px_rgba(0,0,0,0.04)]">
            <div className="w-10 h-10 rounded-full bg-blue-100 text-[#54A6D8] flex items-center justify-center shrink-0">
              <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeWidth={1.5}
                  d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"
                />
              </svg>
            </div>
            <div>
              <h4 className="font-bold text-slate-800 text-xs uppercase tracking-wider mb-1">Pago Protegido</h4>
              <p className="text-[11px] text-slate-500 leading-relaxed font-medium">
                Tu dinero está seguro. El pago se retiene en nuestra plataforma y solo se libera al estudiante cuando confirmas que la clase o servicio se realizó con éxito.
              </p>
            </div>
          </div>
        </div>

        {servicio.recomendaciones.length > 0 && (
          <div className="mt-8 border-t border-gray-100 pt-6">
            <h2 className="text-xl font-bold text-gray-900 mb-6">Otros tutores disponibles ahora</h2>
            <Carrusel>
              {servicio.recomendaciones.map((s) => (
                <ServicioCardCarrusel key={s.id} servicio={s} />
              ))}
            </Carrusel>
          </div>
        )}
      </main>

      {/* Barra inferior fija (móvil) — puerto de detalle_servicio.php:1121-1197. */}
      {mostrarBarraMovil && (
        <div className="lg:hidden fixed bottom-0 left-0 right-0 bg-white/95 backdrop-blur-md border-t border-gray-100 shadow-[0_-4px_12px_rgba(0,0,0,0.04)] z-40 px-4 py-3 pb-[calc(0.75rem+env(safe-area-inset-bottom))]">
          <div className="flex items-center justify-between gap-3">
            <div className="flex flex-col min-w-0 flex-1">
              {servicio.ofertaVigente && servicio.precioOferta !== null ? (
                <>
                  <span className="text-[10px] text-gray-400 line-through font-medium leading-none">{servicio.precio !== null ? formatoCLP(servicio.precio) : ""}</span>
                  <span className="text-xl font-normal text-[#222222] tracking-[-0.01em] leading-none mt-0.5">{formatoCLP(servicio.precioOferta)}</span>
                </>
              ) : (
                <>
                  <span className="text-[10px] text-gray-400 font-bold uppercase tracking-wide leading-none">Inversión total</span>
                  <span className="text-xl font-normal text-[#222222] tracking-[-0.01em] leading-none mt-0.5">{servicio.precio !== null && servicio.precio > 0 ? formatoCLP(servicio.precio) : "Gratis"}</span>
                </>
              )}
            </div>
            {!isAuthenticated ? (
              <div className="flex gap-2 shrink-0">
                <a href={`${phpSiteUrl}/app/iniciar_chat.php?servicio_id=${servicio.id}`} className="bg-[#54A6D8] hover:bg-blue-600 text-white font-bold rounded-xl px-4 py-3 text-sm shadow-md transition-all whitespace-nowrap">
                  Iniciar chat
                </a>
                <a href={`${phpSiteUrl}/login`} className="bg-white border border-gray-300 text-gray-700 font-bold rounded-xl px-4 py-3 text-sm transition-all whitespace-nowrap">
                  Ingresar
                </a>
              </div>
            ) : (
              <div className="flex gap-2 shrink-0">
                <a
                  href={`${phpSiteUrl}/app/iniciar_chat.php?servicio_id=${servicio.id}`}
                  className="border border-[#54A6D8] bg-white text-[#54A6D8] font-bold rounded-xl px-3 py-3 text-xs whitespace-nowrap transition-all flex items-center justify-center gap-1.5"
                >
                  Iniciar chat
                </a>
                <a
                  href={`${phpSiteUrl}/app/contratar_servicio.php?servicio_id=${servicio.id}`}
                  className="bg-[#54A6D8] hover:bg-blue-600 text-white font-bold rounded-xl px-4 py-3 text-xs shadow-md transition-all whitespace-nowrap"
                >
                  Contratar
                </a>
              </div>
            )}
          </div>
        </div>
      )}
    </>
  );
}
