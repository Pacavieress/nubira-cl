"use client";

import { useState } from "react";
import type { GamificacionPerfil, PerfilPropio } from "@/lib/api";
import { abreviarNombre, inicial } from "@/lib/texto";

function IconoSparkles() {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor" className="w-4 h-4 text-[#54A6D8] shrink-0">
      <path
        strokeLinecap="round"
        strokeLinejoin="round"
        d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z"
      />
    </svg>
  );
}
// Mismo SVG que Footer.tsx (única fuente reusable hoy — no hay un componente
// IconoInstagram compartido en web/, este mismo path/rect/circle ya está duplicado en
// varios archivos de "compartir"; se copia acá tal cual en vez de inventar un path nuevo).
function IconoInstagram() {
  return (
    <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={1.5}>
      <rect x="2" y="2" width="20" height="20" rx="5" ry="5" />
      <path d="M16 11.37A4 4 0 1112.63 8 4 4 0 0116 11.37z" />
      <circle cx="17.5" cy="6.5" r="0.5" fill="currentColor" />
    </svg>
  );
}
function IconoPencil() {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor" className="w-4 h-4">
      <path strokeLinecap="round" strokeLinejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125" />
    </svg>
  );
}

// Puerto de perfil.php con $es_propio=true: banner "Completa tu perfil" (:502-526) + bloque
// de foto/nombre/institución/tiempo-respuesta (:528-583, mismo diseño que TutorPerfil en
// tutores/[id]/page.tsx) + bio editable inline (:650-679). Client Component porque el botón
// "Bio" del banner necesita abrir el editor de bio más abajo en la misma tarjeta — un solo
// componente comparte ese estado sin tener que levantarlo hasta la página server-component.
// Fuera de alcance (ver AskUserQuestion respondida por el usuario): subida de foto inline
// (el botón "Foto" del banner enlaza al sitio PHP real, que si tiene ese widget).
export function PerfilPropioCard({ perfil, phpSiteUrl }: { perfil: PerfilPropio; phpSiteUrl: string }) {
  const [bio, setBio] = useState(perfil.bio ?? "");
  const [faltaBio, setFaltaBio] = useState(perfil.completitud.faltaBio);
  const [gamificacion, setGamificacion] = useState<GamificacionPerfil>(perfil.gamificacion);
  const [editando, setEditando] = useState(false);
  const [borrador, setBorrador] = useState(bio);
  const [guardando, setGuardando] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const { completitud } = perfil;
  const mostrarBanner = completitud.faltaBanco || completitud.faltaFoto || faltaBio || completitud.faltaHorarios || completitud.faltaVideo;

  async function guardarBio() {
    setGuardando(true);
    setError(null);
    try {
      const res = await fetch("/api/perfil/bio", {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ bio: borrador }),
      });
      const data = await res.json();
      if (!res.ok || !data.ok) {
        setError(data.mensaje ?? "No se pudo guardar. Intenta de nuevo.");
        return;
      }
      setBio(data.bio);
      setFaltaBio(false);
      setGamificacion(data.gamificacion);
      setEditando(false);
    } catch {
      setError("No se pudo guardar. Intenta de nuevo.");
    } finally {
      setGuardando(false);
    }
  }

  return (
    <>
      {mostrarBanner && (
        <div className="bg-blue-50 border border-blue-100 rounded-xl px-4 py-2.5 flex flex-wrap items-center justify-between gap-3 mb-5">
          <div className="flex items-center gap-2 min-w-0">
            <IconoSparkles />
            <span className="text-sm font-medium text-gray-700">Completa tu perfil para generar más confianza</span>
          </div>
          <div className="flex flex-wrap items-center gap-2 shrink-0">
            {completitud.faltaBanco && (
              <a href={`${phpSiteUrl}/datos_bancarios`} className="bg-white border border-blue-100 text-[#54A6D8] text-xs font-semibold px-3 py-1.5 rounded-lg hover:bg-blue-100 transition-colors">
                Datos bancarios
              </a>
            )}
            {completitud.faltaFoto && (
              <a href={`${phpSiteUrl}/perfil`} className="bg-white border border-blue-100 text-[#54A6D8] text-xs font-semibold px-3 py-1.5 rounded-lg hover:bg-blue-100 transition-colors">
                Foto
              </a>
            )}
            {faltaBio && (
              <button
                type="button"
                onClick={() => setEditando(true)}
                className="bg-white border border-blue-100 text-[#54A6D8] text-xs font-semibold px-3 py-1.5 rounded-lg hover:bg-blue-100 transition-colors"
              >
                Bio
              </button>
            )}
            {completitud.faltaHorarios && (
              <a
                href={`${phpSiteUrl}/app/editar_horarios.php?id=${completitud.servicioFaltaHorariosId}`}
                className="bg-white border border-blue-100 text-[#54A6D8] text-xs font-semibold px-3 py-1.5 rounded-lg hover:bg-blue-100 transition-colors"
              >
                Horarios
              </a>
            )}
            {completitud.faltaVideo && (
              <a
                href={`${phpSiteUrl}/app/editar_servicio.php?id=${completitud.servicioFaltaVideoId}`}
                className="bg-white border border-blue-100 text-[#54A6D8] text-xs font-semibold px-3 py-1.5 rounded-lg hover:bg-blue-100 transition-colors"
              >
                Video
              </a>
            )}
          </div>
        </div>
      )}

      <section className="bg-white border border-gray-100 rounded-[2rem] p-6 md:p-10 relative w-full">
        <div className="flex flex-col gap-6 md:gap-8">
          <div className="flex flex-row gap-3 md:gap-8 items-start w-full">
            <div className="shrink-0 relative w-[104px] h-[104px] md:w-36 md:h-36">
              <div className="w-full h-full rounded-full border border-gray-200 bg-white overflow-hidden">
                {perfil.fotoUrl.startsWith("https://ui-avatars.com") ? (
                  <div className="w-full h-full flex items-center justify-center bg-blue-50 text-[#54A6D8] font-bold text-3xl">
                    {inicial(perfil.nombre)}
                  </div>
                ) : (
                  /* eslint-disable-next-line @next/next/no-img-element -- foto de usuario dinámica */
                  <img src={perfil.fotoUrl} alt={perfil.nombre ?? "Tú"} className="w-full h-full object-cover" />
                )}
              </div>
              {/* Puerto de perfil.php:545-547 — badge en la esquina del wrapper CUADRADO (no
                  del círculo inscrito), mismo patrón que un badge de estado de Discord/WhatsApp:
                  no tapa la foto (queda en la esquina, fuera del área facial) aunque
                  geométricamente roce el borde exterior del círculo. */}
              <a
                href="https://instagram.com/nubira.cl"
                target="_blank"
                rel="noopener noreferrer"
                title="Síguenos en Instagram"
                className="absolute bottom-0 right-0 bg-white rounded-full p-1.5 shadow-md hover:scale-110 transition-transform text-pink-600 z-10"
              >
                <IconoInstagram />
              </a>
            </div>

            <div className="flex-1 min-w-0 w-full pt-1">
              <div className="flex items-center gap-2">
                <h1 className="text-2xl md:text-3xl font-medium tracking-[-0.01em] text-[#222222] break-words">
                  {perfil.nombre ? abreviarNombre(perfil.nombre) : "Usuario"}
                </h1>
                {perfil.verificado && (
                  <svg className="w-5 h-5 text-[#54A6D8] shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <title>Alumno Verificado</title>
                    <path
                      fillRule="evenodd"
                      d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                      clipRule="evenodd"
                    />
                  </svg>
                )}
              </div>
              {perfil.subtitulo && (
                <p className="text-xs text-gray-500 flex items-center gap-1.5 mt-1.5 uppercase tracking-wider font-medium">{perfil.subtitulo}</p>
              )}

              {perfil.tiempoRespuesta && (
                <div className="mt-4">
                  <p
                    className="text-[11px] md:text-xs text-gray-600 font-medium inline-flex items-center gap-2 bg-gray-50 border border-gray-100 rounded-xl px-4 py-2 w-fit"
                    title={perfil.tiempoRespuesta.tono === "gris" ? "Tutor nuevo, aún sin métricas de respuesta" : "Tiempo promedio de respuesta"}
                  >
                    <svg className="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M12 6v6l4 2m5-2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    {perfil.tiempoRespuesta.tono === "gris" ? (
                      <span className="font-medium text-gray-900">{perfil.tiempoRespuesta.texto}</span>
                    ) : (
                      <span>
                        Responde <span className="font-medium text-gray-900">{perfil.tiempoRespuesta.texto}</span>
                      </span>
                    )}
                  </p>
                </div>
              )}
            </div>
          </div>

          <div className="w-full h-px bg-gray-100" />

          {(perfil.statsAcademicas.universidad || (perfil.statsAcademicas.anioEgreso && perfil.statsAcademicas.anioEgreso > 1970) || (perfil.statsAcademicas.aniosExperiencia && perfil.statsAcademicas.aniosExperiencia > 0)) && (
            <div className="grid grid-cols-3 gap-2 md:flex md:flex-wrap md:gap-x-8 md:gap-y-3 border-b border-gray-100 pb-4">
              {perfil.statsAcademicas.universidad && (
                <div className="text-center md:text-left">
                  <div className="text-[10px] uppercase font-semibold text-gray-400 tracking-wider mb-0.5">Institución</div>
                  <div className="text-base font-bold tracking-tight text-gray-900">{perfil.statsAcademicas.universidad}</div>
                </div>
              )}
              {perfil.statsAcademicas.anioEgreso && perfil.statsAcademicas.anioEgreso > 1970 && (
                <div className="text-center md:text-left">
                  <div className="text-[10px] uppercase font-semibold text-gray-400 tracking-wider mb-0.5">Año de egreso</div>
                  <div className="text-base font-bold tracking-tight text-gray-900">{perfil.statsAcademicas.anioEgreso}</div>
                </div>
              )}
              {perfil.statsAcademicas.aniosExperiencia && perfil.statsAcademicas.aniosExperiencia > 0 && (
                <div className="text-center md:text-left">
                  <div className="text-[10px] uppercase font-semibold text-gray-400 tracking-wider mb-0.5">Experiencia</div>
                  <div className="text-base font-bold tracking-tight text-gray-900">{perfil.statsAcademicas.aniosExperiencia} años</div>
                </div>
              )}
            </div>
          )}

          {/* Reseñas/rating/visitas — visitas es dato propio, no aparece en /tutores/[id].
              Puerto exacto de perfil.php:614 — posición/tamaño: flex normal en mobile,
              absolute top-10 right-10 (alineado con el nombre) desde lg+. */}
          <div className="flex flex-row justify-start lg:justify-end items-center divide-x divide-gray-200 md:divide-none mt-1 lg:mt-0 lg:absolute lg:top-10 lg:right-10">
            <div>
              <p className="text-[10px] uppercase font-semibold text-gray-400 mb-0.5 tracking-wider">Reseñas</p>
              <p className="text-xl md:text-lg font-bold tracking-tight text-gray-900">{perfil.resenasRecibidasTotal}</p>
            </div>
            <div className="pl-6">
              <p className="text-[10px] uppercase font-semibold text-gray-400 mb-0.5 tracking-wider">Rating</p>
              <p className="text-xl md:text-lg font-bold tracking-tight text-gray-900 flex items-center gap-1">
                {perfil.rating.promedio !== null ? perfil.rating.promedio.toFixed(1) : "0.0"}
                <svg className="w-4 h-4 text-gray-900" fill="currentColor" viewBox="0 0 20 20">
                  <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                </svg>
              </p>
            </div>
            <div className="pl-6">
              <p className="text-[10px] uppercase font-semibold text-gray-400 mb-0.5 tracking-wider">Visitas</p>
              <p className="text-xl md:text-lg font-bold tracking-tight text-gray-900 flex items-center gap-1.5">
                {perfil.vistasPerfil}
                <svg className="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    strokeWidth={1.5}
                    d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"
                  />
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
              </p>
            </div>
          </div>

          <div className="w-full relative">
            <div className="flex items-center justify-between mb-3">
              <h2 className="text-[11px] font-medium uppercase tracking-widest text-gray-400">Biografía</h2>
              {!editando && (
                <button
                  type="button"
                  onClick={() => {
                    setBorrador(bio);
                    setError(null);
                    setEditando(true);
                  }}
                  className="bg-gray-50 text-gray-400 hover:text-[#54A6D8] transition-colors p-2 rounded-full border border-gray-200"
                  aria-label="Editar biografía"
                >
                  <IconoPencil />
                </button>
              )}
            </div>

            {!editando ? (
              <p className="text-gray-700 text-sm leading-relaxed whitespace-pre-line break-words">
                {bio || "Añade una breve biografía para que estudiantes y tutores confíen en ti."}
              </p>
            ) : (
              <div className="space-y-3">
                <textarea
                  value={borrador}
                  onChange={(e) => setBorrador(e.target.value)}
                  maxLength={520}
                  rows={5}
                  className="w-full p-4 border border-gray-200 rounded-2xl focus:border-[#54A6D8] focus:ring-4 focus:ring-[#54A6D8]/10 outline-none text-gray-800 text-sm leading-relaxed bg-gray-50 resize-none"
                />
                <div className="flex flex-col sm:flex-row justify-between items-center gap-3">
                  <span className="text-[10px] font-medium text-gray-400 uppercase tracking-widest">{borrador.length}/500</span>
                  <div className="flex justify-end gap-3 w-full sm:w-auto">
                    <button
                      type="button"
                      onClick={() => {
                        setEditando(false);
                        setError(null);
                      }}
                      className="flex-1 sm:flex-none px-4 py-2 text-[10px] font-medium uppercase text-gray-500 hover:text-red-500 rounded-xl transition-colors"
                    >
                      Cancelar
                    </button>
                    <button
                      type="button"
                      onClick={guardarBio}
                      disabled={guardando}
                      className="flex-1 sm:flex-none px-6 py-2 bg-[#54A6D8] text-white text-[10px] font-medium uppercase rounded-xl hover:bg-[#3d91c7] transition-all disabled:opacity-50"
                    >
                      {guardando ? "Guardando..." : "Guardar"}
                    </button>
                  </div>
                </div>
                {error && <p className="text-red-500 text-[11px] font-medium uppercase text-center sm:text-left">{error}</p>}
              </div>
            )}
          </div>
        </div>
      </section>

      <GamificacionWidget gamificacion={gamificacion} />
    </>
  );
}

const TIER_LABEL: Record<GamificacionPerfil["tier"], string> = { basico: "Básico", top: "Top", pro: "Pro", leyenda: "Leyenda" };
const TIER_COLOR: Record<GamificacionPerfil["tier"], string> = {
  basico: "bg-gray-100 text-gray-500 border-gray-200",
  top: "bg-gradient-to-tr from-slate-200 to-gray-300 text-slate-800 border-white/60",
  pro: "bg-gradient-to-tr from-yellow-400 to-amber-500 text-white border-yellow-300",
  leyenda: "bg-gradient-to-r from-slate-950 to-slate-900 text-amber-400 border-amber-500/30",
};

// Puerto de perfil.php:709 (icon($tier_icon)) — 'user' outline para básico, 'star-solid'
// para el resto. Mismos paths que icon('user')/icon('star-solid') en app/iconos.php.
function IconoTier({ tier }: { tier: GamificacionPerfil["tier"] }) {
  if (tier === "basico") {
    return (
      <svg className="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={1.5}>
        <path
          strokeLinecap="round"
          strokeLinejoin="round"
          d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z"
        />
      </svg>
    );
  }
  return (
    <svg className="w-3 h-3" fill="currentColor" viewBox="0 0 24 24">
      <path
        fillRule="evenodd"
        clipRule="evenodd"
        d="M10.788 3.21c.448-1.077 1.976-1.077 2.424 0l2.082 5.007 5.404.433c1.164.093 1.636 1.545.749 2.305l-4.117 3.527 1.257 5.273c.271 1.136-.964 2.033-1.96 1.425L12 18.354 7.373 21.18c-.996.608-2.231-.29-1.96-1.425l1.257-5.273-4.117-3.527c-.887-.76-.415-2.212.749-2.305l5.404-.433 2.082-5.006z"
      />
    </svg>
  );
}

// Puerto de perfil.php:684-746 ("Tu Nivel de Tutor") — solo se muestra si maxScore > 0
// (mismo gate que el PHP real). Simplificado: siempre expandido, sin el toggle
// mostrar/ocultar misiones del original (interacción menor, no aporta al alcance de esta
// pieza).
function GamificacionWidget({ gamificacion }: { gamificacion: GamificacionPerfil }) {
  if (gamificacion.maxScore <= 0) return null;
  const { misiones } = gamificacion;
  const items: { label: string; ok: boolean }[] = [
    { label: "Foto de perfil (+20)", ok: misiones.foto },
    { label: "Biografía (+20)", ok: misiones.bioLarga },
    { label: "Descripción Larga (+20)", ok: misiones.descripcionLarga },
    { label: "Subir Apunte Público (+20)", ok: misiones.apuntePublico },
    { label: "Obtener 3 Reseñas (+20)", ok: misiones.tresResenas },
    { label: "Video de Presentación (+20)", ok: misiones.video },
  ];

  return (
    <section className="bg-white rounded-3xl border border-gray-200 p-6 md:p-8">
      <div className="flex items-center justify-between mb-4">
        <div>
          <h2 className="text-sm md:text-base font-bold text-gray-900">Tu Nivel de Tutor</h2>
          <p className="text-[10px] md:text-xs text-gray-500 mt-0.5">Sube de nivel completando misiones para destacar en búsquedas.</p>
        </div>
        <span
          className={`${TIER_COLOR[gamificacion.tier]} text-[10px] md:text-xs font-extrabold uppercase tracking-wider px-3 md:px-4 py-1.5 md:py-2 rounded-full border shadow-sm shrink-0 flex items-center gap-1.5`}
        >
          <IconoTier tier={gamificacion.tier} />
          {TIER_LABEL[gamificacion.tier]}
        </span>
      </div>

      <div className="w-full bg-gray-100 rounded-full h-3 mb-2 overflow-hidden border border-gray-200/50">
        <div className="bg-gradient-to-r from-sky-400 to-[#54A6D8] h-full rounded-full transition-all duration-1000" style={{ width: `${gamificacion.progresoPorcentaje}%` }} />
      </div>
      <div className="flex justify-between text-[9px] md:text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-5">
        <span>0 Pts</span>
        <span className="text-[#54A6D8]">{gamificacion.maxScore} Pts</span>
        <span>100 Pts</span>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
        {items.map((item) => (
          <div
            key={item.label}
            className={`flex items-center gap-2 text-[11px] md:text-xs ${item.ok ? "text-emerald-600 font-semibold" : "text-gray-500"} bg-gray-50 p-2.5 rounded-xl border border-gray-200/60`}
          >
            {item.ok ? (
              <svg className="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                <path
                  fillRule="evenodd"
                  d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                  clipRule="evenodd"
                />
              </svg>
            ) : (
              <svg className="w-3 h-3 text-gray-300" fill="currentColor" viewBox="0 0 20 20">
                <circle cx="10" cy="10" r="7" fill="none" stroke="currentColor" strokeWidth="2" />
              </svg>
            )}
            {item.label}
          </div>
        ))}
      </div>
    </section>
  );
}
