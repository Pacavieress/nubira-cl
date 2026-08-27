"use client";

import { useEffect, useRef, useState } from "react";
import type { DailyCall } from "@daily-co/daily-js";
import type { AulaDetalle } from "@/lib/api";

// Puerto de app/mini_aula.php (view-video: iniciarClase()/colgarLlamada()/checkParticipantsTimer())
// — Grupo Mini Aula, Fase 4. Vanilla @daily-co/daily-js con el iframe prebuilt (misma
// decisión que ya usa el PHP real, confirmada con el usuario antes de construir — NO
// @daily-co/daily-react, que es para armar una UI de video custom con tiles propios, algo
// que nadie pidió acá). daily-js se importa dinámicamente porque el SDK toca window/document
// al cargar — no es seguro en el árbol de render de Next (SSR), solo tras montar en cliente.
//
// A diferencia del PHP (que dispara la creación de sala Daily en CADA carga de página,
// incluso antes de que el usuario haga click), acá GET .../video — que sí crea la sala,
// best-effort — se llama recién al hacer click en "Entrar a la Sala", no en cada poll de
// 30s de AulaShell. Ver aula.repository.ts (ensureSalaVideo) para el porqué completo.

type EstadoLlamada = "inactiva" | "conectando" | "activa" | "error";

function formatoTimer(segundos: number): string {
  const s = String(segundos % 60).padStart(2, "0");
  const m = String(Math.floor(segundos / 60) % 60).padStart(2, "0");
  if (segundos >= 3600) {
    const h = String(Math.floor(segundos / 3600)).padStart(2, "0");
    return `${h}:${m}:${s}`;
  }
  return `${m}:${s}`;
}

export function AulaVideo({ contratoId, detalle }: { contratoId: number; detalle: AulaDetalle }) {
  const [estadoLlamada, setEstadoLlamada] = useState<EstadoLlamada>("inactiva");
  const [errorMsg, setErrorMsg] = useState<string | null>(null);
  const [segundos, setSegundos] = useState(0);

  const contenedorRef = useRef<HTMLDivElement>(null);
  const callFrameRef = useRef<DailyCall | null>(null);
  const pingTimerRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const relojTimerRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const inicioLlamadaRef = useRef<number | null>(null);

  async function colgar() {
    if (pingTimerRef.current) clearInterval(pingTimerRef.current);
    if (relojTimerRef.current) clearInterval(relojTimerRef.current);
    pingTimerRef.current = null;
    relojTimerRef.current = null;
    inicioLlamadaRef.current = null;
    setSegundos(0);
    fetch(`/api/me/aula/${contratoId}/presencia`, { method: "DELETE" }).catch(() => {});
    try {
      await callFrameRef.current?.leave();
    } catch {
      // Ya desconectado o el frame nunca llegó a unirse — mismo criterio best-effort que
      // colgarLlamada() en el PHP real, que tampoco revisa el resultado.
    }
    setEstadoLlamada("inactiva");
  }

  // Puerto de checkParticipantsTimer() — el cronómetro solo arranca cuando hay MÁS de un
  // participante en la sala (no desde que uno mismo entra a esperar), y una vez arrancado
  // no se detiene aunque el otro se desconecte — solo con colgar() propio.
  function checkParticipantsTimer() {
    const frame = callFrameRef.current;
    if (!frame || inicioLlamadaRef.current) return;
    const participantes = frame.participants();
    if (Object.keys(participantes).length > 1) {
      inicioLlamadaRef.current = Date.now();
      relojTimerRef.current = setInterval(() => {
        setSegundos(Math.floor((Date.now() - (inicioLlamadaRef.current ?? Date.now())) / 1000));
      }, 1000);
    }
  }

  useEffect(() => {
    return () => {
      if (pingTimerRef.current) clearInterval(pingTimerRef.current);
      if (relojTimerRef.current) clearInterval(relojTimerRef.current);
      callFrameRef.current?.destroy().catch(() => {});
    };
  }, []);

  async function entrarSala() {
    setErrorMsg(null);
    setEstadoLlamada("conectando");
    try {
      const res = await fetch(`/api/me/aula/${contratoId}/video`);
      const data = (await res.json().catch(() => null)) as { ok?: boolean; roomUrl?: string; userName?: string } | null;
      if (!res.ok || !data?.ok || !data.roomUrl) {
        setErrorMsg("No se pudo iniciar la videollamada. Intenta nuevamente.");
        setEstadoLlamada("error");
        return;
      }

      const { default: Daily } = await import("@daily-co/daily-js");
      if (!callFrameRef.current && contenedorRef.current) {
        const frame = Daily.createFrame(contenedorRef.current, {
          iframeStyle: { width: "100%", height: "100%", border: "0", borderRadius: "1.5rem" },
          showLeaveButton: false,
          showFullscreenButton: true,
          userName: data.userName,
          lang: "es",
        });
        frame.on("left-meeting", () => void colgar());
        frame.on("participant-joined", checkParticipantsTimer);
        frame.on("joined-meeting", checkParticipantsTimer);
        frame.on("track-stopped", (event) => {
          if (event?.participant && "screen" in event.participant && event.participant.screen) {
            window.dispatchEvent(new Event("resize"));
          }
        });
        callFrameRef.current = frame;
      }

      await callFrameRef.current!.join({ url: data.roomUrl });
      setEstadoLlamada("activa");

      fetch(`/api/me/aula/${contratoId}/presencia`, { method: "POST" }).catch(() => {});
      pingTimerRef.current = setInterval(() => {
        fetch(`/api/me/aula/${contratoId}/presencia`, { method: "POST" }).catch(() => {});
      }, 15000);
    } catch {
      setErrorMsg("Hubo un problema al conectar. Contacta a soporte.");
      setEstadoLlamada("error");
      void colgar();
    }
  }

  // Puerto de iniciarClase() — el placeholder desaparece de inmediato al hacer click (no
  // recién cuando join() resuelve), mismo criterio que el PHP real (placeholder.opacity-0 +
  // container visible antes de que Daily termine de conectar). El spinner es un agregado
  // propio: el PHP real solo mostraba un rectángulo negro vacío mientras tanto.
  const mostrarContenedor = estadoLlamada === "activa" || estadoLlamada === "conectando";

  return (
    <div className="w-full h-full bg-white rounded-3xl border border-gray-100 shadow-sm overflow-hidden relative flex items-center justify-center">
      <div ref={contenedorRef} className={`absolute inset-0 bg-black ${mostrarContenedor ? "" : "hidden"}`} />

      {estadoLlamada === "conectando" && (
        <div className="absolute inset-0 flex items-center justify-center z-20">
          <div className="animate-spin h-8 w-8 border-2 border-white border-t-transparent rounded-full" />
        </div>
      )}

      {estadoLlamada === "activa" && (
        <>
          <div className="absolute top-1 left-1/2 -translate-x-1/2 z-30 bg-black/60 backdrop-blur-md text-white px-3.5 py-1.5 rounded-full flex items-center gap-2 font-mono text-sm shadow-lg border border-white/10">
            <span className="w-2 h-2 rounded-full bg-red-500 animate-pulse shadow-[0_0_8px_rgba(239,68,68,0.8)]" />
            <span className="tracking-wider">{formatoTimer(segundos)}</span>
          </div>
          <button
            type="button"
            onClick={() => void colgar()}
            className="absolute bottom-8 left-8 z-50 bg-red-600 hover:bg-red-700 text-white w-14 h-14 rounded-full shadow-lg flex items-center justify-center border-4 border-white transition-transform active:scale-90"
            title="Colgar"
          >
            ✕
          </button>
        </>
      )}

      {!mostrarContenedor && (
        <div className="text-center p-6">
          <h2 className="text-xl font-bold mb-2 text-gray-800">Sala de Reunión</h2>
          {detalle.estado === "cancelado" ? (
            <p className="text-gray-500 text-sm mb-6 max-w-xs mx-auto">Esta clase fue cancelada.</p>
          ) : detalle.esPostClase && !detalle.videoHabilitado ? (
            <p className="text-gray-500 text-sm mb-6 max-w-xs mx-auto">Esta clase ya finalizó.</p>
          ) : detalle.esPostClase && detalle.videoHabilitado ? (
            <p className="text-gray-500 text-sm mb-6 max-w-xs mx-auto">El horario programado terminó, pero la sala sigue disponible por si necesitas reconectarte.</p>
          ) : detalle.tieneReserva ? (
            <p className="text-gray-500 text-sm mb-6 max-w-xs mx-auto">
              Clase agendada: <strong className="text-gray-700">{detalle.fechaAmigable}</strong>
            </p>
          ) : (
            <p className="text-gray-500 text-sm mb-6 max-w-xs mx-auto">Videollamada segura e integrada.</p>
          )}

          {errorMsg && <p className="text-red-600 text-xs mb-4 max-w-xs mx-auto">{errorMsg}</p>}

          {detalle.videoHabilitado ? (
            <button
              type="button"
              onClick={() => void entrarSala()}
              className="bg-[#54A6D8] text-white px-8 py-3 rounded-2xl font-bold shadow-lg shadow-sky-100 hover:scale-105 transition-transform"
            >
              Entrar a la Sala
            </button>
          ) : (
            <>
              <button disabled className="bg-gray-200 text-gray-400 px-8 py-3 rounded-2xl font-bold cursor-not-allowed">
                Sala cerrada
              </button>
              <p className="text-xs text-gray-400 mt-3 max-w-xs mx-auto">El horario de la videollamada finalizó. Puedes seguir usando el chat y el material para coordinar.</p>
            </>
          )}
        </div>
      )}
    </div>
  );
}
