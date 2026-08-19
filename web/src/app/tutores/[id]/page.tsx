import { notFound } from "next/navigation";
import { getTutorPerfil } from "@/lib/api";
import { inicial } from "@/lib/texto";
import { Header } from "@/components/Header";
import { ServicioCard } from "@/components/ServicioCard";
import { ApunteCard } from "@/components/ApunteCard";

interface PerfilProps {
  params: Promise<{ id: string }>;
}

// Alcance: mismo criterio que servicios/[id] y apuntes/[id] — SOLO info pública de
// lectura de perfil.php. Fuera de alcance a propósito, no por olvido:
//   - Todo lo que depende de $es_propio (edición de foto/bio, panel de gestión, banner
//     de "completa tu perfil", gamificación "Tu Nivel de Tutor") — sección de dueño, no
//     tiene sentido en una vista pública sin sesión.
//   - Contador de "Visitas" (perfil.php:634-639, solo dueño) y el badge "Demanda" que se
//     le muestra a un visitante cuando vistas_perfil >= 100 (perfil.php:640-647) — el
//     primero es de dueño, el segundo es un flourish menor que además requeriría un
//     efecto de escritura (incrementar el contador) para tener sentido, fuera de alcance
//     de una página de solo lectura.
//   - El rating combina SOLO valoraciones (id_evaluado + rol_evaluado), nunca
//     alumnos.calificacion_promedio/cantidad_votos — perfil.php:304 sí mezcla ambas
//     fuentes; decisión de producto ya tomada de no replicar esa mezcla acá (ver
//     tutores.repository.ts).
//   - Servicios y apuntes se muestran en 2 grillas separadas, no en un único feed
//     cronológico intercalado (perfil.php:376-378 los intercala por fecha) — la API
//     pública (ServicioListado/ApunteListado) no expone fecha de publicación en ninguna
//     de las 2 formas, así que no hay con qué ordenarlos juntos sin agregar ese campo.
//   - Las 2 pestañas de reseñas (Tutor/Alumno) se muestran apiladas en vez de con el
//     switcher de tabs con JS de perfil.php — mismo criterio de simplificación ya usado
//     en apuntes/[id] (se omitió el toggle "Leer más/menos" de la descripción).
export default async function PerfilTutor({ params }: PerfilProps) {
  const { id } = await params;
  const tutorId = Number(id);
  if (!Number.isInteger(tutorId) || tutorId <= 0) {
    notFound();
  }

  const tutor = await getTutorPerfil(tutorId);
  if (!tutor) {
    notFound();
  }

  const { statsAcademicas } = tutor;
  const statsVisibles = [
    statsAcademicas.universidad ? { label: "Institución", value: statsAcademicas.universidad } : null,
    statsAcademicas.anioEgreso && statsAcademicas.anioEgreso > 1970
      ? { label: "Año de egreso", value: String(statsAcademicas.anioEgreso) }
      : null,
    statsAcademicas.aniosExperiencia && statsAcademicas.aniosExperiencia > 0
      ? { label: "Experiencia", value: `${statsAcademicas.aniosExperiencia} años` }
      : null,
  ].filter((s): s is { label: string; value: string } => s !== null);

  return (
    <>
      <Header titulo={tutor.nombre ?? "Tutor"} />
      <main className="w-full max-w-[1100px] mx-auto px-4 md:px-8 pt-20 pb-24 lg:pb-16 lg:ml-64 space-y-6">
        {/* Bloque principal — calcado de perfil.php:528-682 (sección de foto/nombre/bio) */}
        <section className="bg-white border border-gray-100 rounded-[2rem] p-6 md:p-10">
          <div className="flex flex-col gap-6 md:gap-8">
            <div className="flex flex-row gap-3 md:gap-8 items-start w-full">
              <div className="shrink-0 w-[104px] h-[104px] md:w-36 md:h-36 rounded-full border border-gray-200 bg-white overflow-hidden">
                {tutor.fotoUrl.startsWith("https://ui-avatars.com") ? (
                  <div className="w-full h-full flex items-center justify-center bg-blue-50 text-[#54A6D8] font-bold text-3xl">
                    {inicial(tutor.nombre)}
                  </div>
                ) : (
                  <img src={tutor.fotoUrl} alt={tutor.nombre ?? "Tutor"} className="w-full h-full object-cover" />
                )}
              </div>

              <div className="flex-1 min-w-0 w-full pt-1">
                <div className="flex items-center gap-2">
                  <h1 className="text-2xl md:text-3xl font-medium tracking-[-0.01em] text-[#222222] break-words">
                    {tutor.nombre ?? "Usuario"}
                  </h1>
                  {tutor.verificado && (
                    <svg className="w-5 h-5 text-[#54A6D8] shrink-0" fill="currentColor" viewBox="0 0 20 20">
                      <path
                        fillRule="evenodd"
                        d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                        clipRule="evenodd"
                      />
                    </svg>
                  )}
                </div>
                <p className="text-xs text-gray-500 flex items-center gap-1.5 mt-1.5 uppercase tracking-wider font-medium">
                  {tutor.subtitulo}
                </p>

                {tutor.tiempoRespuesta && (
                  <div className="mt-4">
                    <p className="text-[11px] md:text-xs text-gray-600 font-medium inline-flex items-center gap-2 bg-gray-50 border border-gray-100 rounded-xl px-4 py-2 w-fit">
                      <svg className="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path
                          strokeLinecap="round"
                          strokeLinejoin="round"
                          strokeWidth={1.5}
                          d="M12 6v6l4 2m5-2a9 9 0 11-18 0 9 9 0 0118 0z"
                        />
                      </svg>
                      {tutor.tiempoRespuesta.tono === "gris" ? (
                        <span className="font-medium text-gray-900">{tutor.tiempoRespuesta.texto}</span>
                      ) : (
                        <span>
                          Responde <span className="font-medium text-gray-900">{tutor.tiempoRespuesta.texto}</span>
                        </span>
                      )}
                    </p>
                  </div>
                )}
              </div>
            </div>

            <div className="w-full h-px bg-gray-100" />

            {/* Stats académicas — calcado de perfil.php:588-611 */}
            {statsVisibles.length > 0 && (
              <div className="flex flex-wrap gap-x-8 gap-y-3 border-b border-gray-100 pb-4">
                {statsVisibles.map((stat) => (
                  <div key={stat.label}>
                    <div className="text-[10px] uppercase font-semibold text-gray-400 tracking-wider mb-0.5">
                      {stat.label}
                    </div>
                    <div className="text-base font-bold tracking-tight text-gray-900">{stat.value}</div>
                  </div>
                ))}
              </div>
            )}

            {/* Reseñas/rating — calcado de perfil.php:614-624, solo con las cifras "limpias"
                de valoraciones (ver nota de alcance arriba) */}
            <div className="flex items-center gap-6">
              <div>
                <p className="text-[10px] uppercase font-semibold text-gray-400 mb-0.5 tracking-wider">Reseñas</p>
                <p className="text-lg font-bold tracking-tight text-gray-900">{tutor.rating.votos}</p>
              </div>
              <div className="border-l border-gray-100 pl-6">
                <p className="text-[10px] uppercase font-semibold text-gray-400 mb-0.5 tracking-wider">Rating</p>
                <p className="text-lg font-bold tracking-tight text-gray-900 flex items-center gap-1">
                  {tutor.rating.promedio !== null ? tutor.rating.promedio.toFixed(1) : "—"}
                  <svg className="w-4 h-4 text-gray-900" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                  </svg>
                </p>
              </div>
            </div>

            {/* Bio — calcado de perfil.php:650-664 */}
            <div>
              <h2 className="text-[11px] font-medium uppercase tracking-widest text-gray-400 mb-3">Biografía</h2>
              <p className="text-gray-700 text-sm leading-relaxed whitespace-pre-line break-words">
                {tutor.bio ? tutor.bio : "Aún preparando mi biografía..."}
              </p>
            </div>
          </div>
        </section>

        {tutor.servicios.length > 0 && (
          <section>
            <h2 className="text-lg font-medium tracking-[-0.01em] text-[#222222] mb-4">Servicios</h2>
            <div className="grid grid-cols-2 sm:grid-cols-2 md:grid-cols-3 gap-4 md:gap-6">
              {tutor.servicios.map((servicio) => (
                <ServicioCard key={servicio.id} servicio={servicio} />
              ))}
            </div>
          </section>
        )}

        {tutor.apuntes.length > 0 && (
          <section>
            <h2 className="text-lg font-medium tracking-[-0.01em] text-[#222222] mb-4">Apuntes</h2>
            <div className="grid grid-cols-2 sm:grid-cols-2 md:grid-cols-3 gap-4 md:gap-6">
              {tutor.apuntes.map((apunte) => (
                <ApunteCard key={apunte.id} apunte={apunte} />
              ))}
            </div>
          </section>
        )}

        {/* Reseñas — calcado de perfil.php:758-... (apiladas, sin el switcher de tabs) */}
        {tutor.resenasComoTutor.length > 0 && (
          <section>
            <h2 className="text-lg font-medium tracking-[-0.01em] text-[#222222] mb-4">
              Reseñas como tutor ({tutor.rating.votos})
            </h2>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              {tutor.resenasComoTutor.map((r) => (
                <ResenaCard key={r.id} resena={r} />
              ))}
            </div>
          </section>
        )}

        {tutor.resenasComoAlumno.length > 0 && (
          <section>
            <h2 className="text-lg font-medium tracking-[-0.01em] text-[#222222] mb-4">
              Reseñas como alumno ({tutor.resenasComoAlumno.length})
            </h2>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              {tutor.resenasComoAlumno.map((r) => (
                <ResenaCard key={r.id} resena={r} />
              ))}
            </div>
          </section>
        )}
      </main>
    </>
  );
}

function ResenaCard({
  resena,
}: {
  resena: { id: number; calificacion: number; comentario: string | null; fecha: string; evaluador: { nombre: string | null; fotoUrl: string } };
}) {
  return (
    <div className="bg-gray-50 border border-[#f0f0f0] p-4 rounded-xl">
      <div className="flex items-center gap-3 mb-2">
        <div className="w-8 h-8 rounded-full bg-white border border-[#f0f0f0] overflow-hidden">
          {resena.evaluador.fotoUrl.startsWith("https://ui-avatars.com") ? (
            <div className="w-full h-full flex items-center justify-center text-[#54A6D8] bg-blue-50 font-bold text-xs">
              {inicial(resena.evaluador.nombre)}
            </div>
          ) : (
            <img src={resena.evaluador.fotoUrl} alt={resena.evaluador.nombre ?? "Usuario"} className="w-full h-full object-cover" />
          )}
        </div>
        <div>
          <p className="font-medium tracking-[-0.01em] text-xs text-[#222222]">{resena.evaluador.nombre ?? "Usuario"}</p>
          <p className="text-[10px] text-gray-400 font-normal">
            {new Date(resena.fecha).toLocaleDateString("es-CL", { day: "2-digit", month: "short", year: "numeric" })}
          </p>
        </div>
      </div>
      <div className="flex text-yellow-400 text-[10px] mb-2">
        {Array.from({ length: 5 }, (_, i) => (
          <span key={i}>{i < resena.calificacion ? "★" : "☆"}</span>
        ))}
      </div>
      {resena.comentario && <p className="text-gray-600 text-xs font-normal leading-relaxed">{resena.comentario}</p>}
    </div>
  );
}
