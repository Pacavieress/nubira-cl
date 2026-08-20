"use client";

import { useEffect, useState } from "react";
import type { ApunteListado, DesafioMateria, DesafioPregunta, DesafioResultado, ServicioListado } from "@/lib/api";
import { ApunteCard } from "./ApunteCard";
import { ServicioCard } from "./ServicioCard";

// Puerto de app/desafio.php (pantallas 1-3) + app/cargar_desafio.php + app/responder_desafio.php,
// vía los proxies same-origin en web/src/app/api/desafio/. Simplificación deliberada,
// documentada: SIN la función "Compartir" (genera una imagen tipo historia vía un pipeline
// GD separado, app/img_desafio.php — mismo patrón que las marketing cards ya documentadas
// como pendientes en CLAUDE.md, no es parte del juego en sí).

type Pantalla = "materia" | "preguntas" | "resultado";
type Opcion = "a" | "b" | "c" | "d";

// Mismo hardcode que desafio.php:186 (JS del PHP real) — sin respuesta única, cuentan
// siempre como acierto (diseño ya aprobado "Opción C: auto-acierto neutro").
const TIPOS_OPINION = new Set(["cual_elegirias", "que_harias_primero"]);

function IconoChevronIzquierda() {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-3.5 h-3.5">
      <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
    </svg>
  );
}

export function DesafioJuego({ materias }: { materias: DesafioMateria[] }) {
  const [pantalla, setPantalla] = useState<Pantalla>("materia");
  const [materiaActual, setMateriaActual] = useState<string | null>(null);
  const [preguntas, setPreguntas] = useState<DesafioPregunta[]>([]);
  const [cargandoPreguntas, setCargandoPreguntas] = useState(false);
  const [errorPreguntas, setErrorPreguntas] = useState<string | null>(null);

  const [respuestas, setRespuestas] = useState<Record<number, Opcion>>({});
  // Igual que iniciarTemporizador() en desafio.php: un contador de segundos que decrementa
  // por tick, no un delta contra Date.now(). Sin timestamps de reloj de pared en absoluto —
  // evita el error de purity de React ("Cannot call impure function during render") que
  // Date.now() dispara si se usa para derivar algo durante el render, y de paso replica más
  // fielmente el propio `restante--` del PHP real (tampoco mide wall-clock ahí).
  const [segundosIniciales, setSegundosIniciales] = useState<Record<number, number>>({});
  const [transcurridos, setTranscurridos] = useState(0);

  const [enviando, setEnviando] = useState(false);
  const [resultado, setResultado] = useState<DesafioResultado | null>(null);
  const [errorResultado, setErrorResultado] = useState<string | null>(null);
  const [recomendaciones, setRecomendaciones] = useState<{ servicios: ServicioListado[]; apuntes: ApunteListado[] } | null>(null);

  const nombreMateria = (slug: string) => materias.find((m) => m.slug === slug)?.nombre ?? slug;

  // "Reto rápido": heartbeat de 1s mientras estemos en la pantalla de preguntas — solo
  // incrementa un contador (setState puro, sin derivar ningún otro estado acá) para que
  // `segundosRestantes()` recalcule en el próximo render.
  useEffect(() => {
    if (pantalla !== "preguntas" || preguntas.length === 0) return;
    const id = setInterval(() => setTranscurridos((t) => t + 1), 1000);
    return () => clearInterval(id);
  }, [pantalla, preguntas.length]);

  function primeraOpcion(p: DesafioPregunta): Opcion | undefined {
    return Object.keys(p.opciones)[0] as Opcion | undefined;
  }

  // null = sin límite de tiempo.
  function segundosRestantes(p: DesafioPregunta): number | null {
    if (p.tiempoLimiteSegundos == null) return null;
    const inicial = segundosIniciales[p.id] ?? p.tiempoLimiteSegundos;
    return Math.max(0, inicial - transcurridos);
  }

  function estaBloqueadaPorTiempo(p: DesafioPregunta): boolean {
    const restante = segundosRestantes(p);
    return restante !== null && restante <= 0;
  }

  // Al agotarse el tiempo, si no hay respuesta marcada se considera autoseleccionada la
  // primera opción — mismo criterio que bloquearPreguntaPorTiempo() en desafio.php, así
  // "Ver resultado" (que exige las 3 marcadas) nunca queda permanentemente deshabilitado.
  // Derivado en cada uso (no escrito a estado) — nunca hay que "sincronizar" nada.
  function respuestaEfectiva(p: DesafioPregunta): Opcion | undefined {
    return respuestas[p.id] ?? (estaBloqueadaPorTiempo(p) ? primeraOpcion(p) : undefined);
  }

  async function cargarPreguntas(materia: string) {
    setMateriaActual(materia);
    setPantalla("preguntas");
    setCargandoPreguntas(true);
    setErrorPreguntas(null);
    setPreguntas([]);
    setRespuestas({});
    setSegundosIniciales({});
    setTranscurridos(0);

    try {
      const res = await fetch(`/api/desafio/preguntas?materia=${encodeURIComponent(materia)}`);
      const data = await res.json();
      if (!data.ok) {
        setErrorPreguntas("Todavía no hay suficientes preguntas para este ramo. Prueba con otro.");
        return;
      }
      const nuevasPreguntas: DesafioPregunta[] = data.preguntas;
      setPreguntas(nuevasPreguntas);
      const inicial: Record<number, number> = {};
      for (const p of nuevasPreguntas) {
        if (p.tiempoLimiteSegundos != null) inicial[p.id] = p.tiempoLimiteSegundos;
      }
      setSegundosIniciales(inicial);
    } catch {
      setErrorPreguntas("No pudimos cargar las preguntas. Intenta de nuevo.");
    } finally {
      setCargandoPreguntas(false);
    }
  }

  function elegirOpcion(p: DesafioPregunta, opcion: Opcion) {
    if (estaBloqueadaPorTiempo(p)) return;
    setRespuestas((prev) => ({ ...prev, [p.id]: opcion }));
  }

  const todasRespondidas = preguntas.length === 3 && preguntas.every((p) => respuestaEfectiva(p) !== undefined);

  async function enviarRespuestas() {
    if (!materiaActual || !todasRespondidas) return;
    setEnviando(true);
    setErrorResultado(null);
    setPantalla("resultado");
    setResultado(null);
    setRecomendaciones(null);
    window.scrollTo({ top: 0, behavior: "smooth" });

    const cuerpo = {
      materia: materiaActual,
      respuestas: preguntas.map((p) => ({ preguntaId: p.id, opcion: respuestaEfectiva(p) })),
    };

    try {
      const res = await fetch("/api/desafio/responder", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(cuerpo),
      });
      const data = await res.json();
      if (!data.ok) {
        setErrorResultado("Ocurrió un error. Intenta de nuevo.");
        return;
      }
      const resultadoFinal = data as DesafioResultado;
      setResultado(resultadoFinal);
      if (resultadoFinal.resultado === "mal") {
        cargarRecomendaciones(resultadoFinal.materia, resultadoFinal.categoriaServicio);
      }
    } catch {
      setErrorResultado("Ocurrió un error. Intenta de nuevo.");
    } finally {
      setEnviando(false);
    }
  }

  async function cargarRecomendaciones(materia: string, categoriaServicio: string | null) {
    try {
      const params = new URLSearchParams();
      if (categoriaServicio) params.set("categoria", categoriaServicio);
      params.set("materia", materia);
      const res = await fetch(`/api/desafio/recomendaciones?${params.toString()}`);
      const data = await res.json();
      setRecomendaciones({ servicios: data.servicios ?? [], apuntes: data.apuntes ?? [] });
    } catch {
      setRecomendaciones({ servicios: [], apuntes: [] });
    }
  }

  function jugarDeNuevo() {
    setPantalla("materia");
    setMateriaActual(null);
    setPreguntas([]);
    setRespuestas({});
    setSegundosIniciales({});
    setTranscurridos(0);
    setResultado(null);
    setRecomendaciones(null);
    setErrorResultado(null);
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  return (
    <div>
      {pantalla === "materia" && (
        <div className="max-w-[640px] mx-auto">
          <p className="text-sm text-gray-500 mb-4">Elige un ramo para empezar.</p>
          <div className="grid grid-cols-2 gap-2.5">
            {materias.map((m) => (
              <button
                key={m.slug}
                type="button"
                onClick={() => cargarPreguntas(m.slug)}
                className="text-left px-4 py-3 rounded-xl border border-gray-200 hover:border-[#54A6D8] hover:bg-[#eef6fb] transition-colors text-sm font-medium text-[#222222]"
              >
                {m.nombre}
              </button>
            ))}
          </div>
        </div>
      )}

      {pantalla === "preguntas" && (
        <div className="max-w-[640px] mx-auto">
          <button type="button" onClick={jugarDeNuevo} className="flex items-center gap-1 text-xs text-gray-400 hover:text-[#54A6D8] mb-4">
            <IconoChevronIzquierda /> Cambiar ramo
          </button>

          {cargandoPreguntas && <div className="text-sm text-gray-400 py-6 text-center">Cargando preguntas...</div>}
          {errorPreguntas && <div className="text-sm text-gray-400 py-6 text-center">{errorPreguntas}</div>}

          {!cargandoPreguntas && !errorPreguntas && preguntas.length > 0 && (
            <>
              <div className="space-y-5">
                {preguntas.map((p, i) => {
                  const restante = segundosRestantes(p);
                  const bloqueada = estaBloqueadaPorTiempo(p);
                  const respuestaActual = respuestaEfectiva(p);
                  const esOpinion = TIPOS_OPINION.has(p.tipo);
                  return (
                    <div key={p.id}>
                      {p.nivelPaes && (
                        <span className="inline-block text-[9px] font-bold text-amber-600 bg-amber-50 border border-amber-200 px-1.5 py-0.5 rounded mb-1.5 uppercase tracking-wide">
                          Nivel PAES
                        </span>
                      )}
                      <p className="text-sm font-medium text-[#222222] mb-2 flex items-center gap-2">
                        <span>
                          {i + 1}. {p.enunciado}
                        </span>
                        {p.tiempoLimiteSegundos != null && restante !== null && (
                          <span
                            className={`shrink-0 text-xs font-bold rounded-full px-2 py-0.5 border ${
                              restante <= 5 ? "text-red-500 bg-red-50 border-red-100" : "text-[#54A6D8] bg-[#eef6fb] border-sky-100"
                            }`}
                          >
                            {restante <= 0 ? "¡Tiempo!" : `${restante}s`}
                          </span>
                        )}
                      </p>
                      {p.desarrollo && (
                        <pre className="text-xs bg-gray-50 border border-gray-200 rounded-lg p-3 mb-2 whitespace-pre-wrap font-mono text-gray-700 leading-relaxed">
                          {p.desarrollo}
                        </pre>
                      )}
                      {esOpinion && <p className="text-[11px] text-gray-400 italic mb-2">Sin respuesta única — cuenta tu opinión.</p>}
                      <div className="space-y-1.5">
                        {(Object.keys(p.opciones) as Opcion[]).map((op) => {
                          const seleccionada = respuestaActual === op;
                          return (
                            <label
                              key={op}
                              className={`flex items-center gap-2.5 px-3.5 py-2.5 rounded-xl border cursor-pointer transition-colors ${
                                seleccionada ? "border-[#54A6D8] bg-[#eef6fb]" : "border-gray-200 hover:border-gray-300"
                              } ${bloqueada ? "opacity-60 cursor-not-allowed" : ""}`}
                            >
                              <input
                                type="radio"
                                name={`desafio-p${p.id}`}
                                value={op}
                                checked={seleccionada}
                                disabled={bloqueada}
                                onChange={() => elegirOpcion(p, op)}
                                className="accent-[#54A6D8]"
                              />
                              <span className="text-sm text-gray-700">{p.opciones[op]}</span>
                            </label>
                          );
                        })}
                      </div>
                    </div>
                  );
                })}
              </div>

              <button
                type="button"
                disabled={!todasRespondidas}
                onClick={enviarRespuestas}
                className="mt-6 w-full py-3 rounded-2xl font-medium text-white bg-gradient-to-r from-sky-400 to-[#54A6D8] disabled:opacity-40 disabled:cursor-not-allowed transition-opacity"
              >
                Ver resultado
              </button>
            </>
          )}
        </div>
      )}

      {pantalla === "resultado" && (
        <div>
          {(enviando || !resultado) && !errorResultado && <div className="text-sm text-gray-400 py-10 text-center">Calculando resultado...</div>}

          {errorResultado && <div className="text-sm text-gray-400 py-10 text-center">{errorResultado}</div>}

          {resultado && !errorResultado && (
            <>
              <div className="max-w-[640px] mx-auto pt-1 pb-6 md:py-6 text-left md:text-center">
                {resultado.resultado === "bien" ? (
                  <>
                    <p className="text-base font-medium text-[#222222] mb-1">¡Bien hecho! {resultado.aciertos}/3 correctas.</p>
                    <p className="text-sm text-gray-500 mb-5">Vas por buen camino en {nombreMateria(resultado.materia)}.</p>
                  </>
                ) : (
                  <>
                    <p className="text-base font-medium text-[#222222] mb-1">{resultado.aciertos}/3 correctas.</p>
                    <p className="text-sm text-gray-500 mb-5">Un tutor o un apunte de {nombreMateria(resultado.materia)} te puede ayudar a reforzar esto.</p>
                  </>
                )}
                <button
                  type="button"
                  onClick={jugarDeNuevo}
                  className="inline-flex items-center gap-1 text-sm font-bold px-4 py-2 rounded-full border border-sky-100 text-[#54A6D8] hover:bg-sky-50 transition-all"
                >
                  Jugar de nuevo
                </button>
              </div>

              {resultado.resultado === "mal" && (
                <>
                  <section className="max-w-[1600px] mx-auto mb-8">
                    <h2 className="text-lg md:text-xl font-medium text-[#222222] tracking-[-0.01em] mb-3">Tutores de {nombreMateria(resultado.materia)}</h2>
                    {recomendaciones === null ? (
                      <div className="min-h-[200px]" />
                    ) : recomendaciones.servicios.length === 0 ? (
                      <p className="text-sm text-gray-400">No encontramos tutores para este ramo todavía.</p>
                    ) : (
                      <div className="grid grid-cols-2 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4 md:gap-6 w-full">
                        {recomendaciones.servicios.map((s) => (
                          <ServicioCard key={s.id} servicio={s} />
                        ))}
                      </div>
                    )}
                  </section>

                  <section className="max-w-[1600px] mx-auto mb-8">
                    <h2 className="text-lg md:text-xl font-medium text-[#222222] tracking-[-0.01em] mb-3">Apuntes de {nombreMateria(resultado.materia)}</h2>
                    {recomendaciones === null ? (
                      <div className="min-h-[200px]" />
                    ) : recomendaciones.apuntes.length === 0 ? (
                      <p className="text-sm text-gray-400">No encontramos apuntes para este ramo todavía.</p>
                    ) : (
                      <div className="grid grid-cols-2 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4 md:gap-8 w-full">
                        {recomendaciones.apuntes.map((a) => (
                          <ApunteCard key={a.id} apunte={a} />
                        ))}
                      </div>
                    )}
                  </section>
                </>
              )}
            </>
          )}
        </div>
      )}
    </div>
  );
}
