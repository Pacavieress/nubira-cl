"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import type { MensajeAula } from "@/lib/api";

// Puerto de app/chat_mini_aula.php + cargar/enviar/typing _mini_aula.php — Grupo Mini Aula,
// Pieza 2. Mismo patrón de envío optimista que ChatWindow.tsx (chat pre-contrato), sin la
// lógica de express/límite-de-6-mensajes/archivos (no aplica al chat de aula), con
// separadores de fecha (Hoy/Ayer/dd-mm) que el chat pre-contrato no tiene.

type EstadoOptimista = "pendiente" | "fallido";
interface MensajeOptimista {
  tempId: string;
  texto: string;
  estado: EstadoOptimista;
}

function horaCorta(iso: string): string {
  const d = new Date(iso);
  return `${String(d.getHours()).padStart(2, "0")}:${String(d.getMinutes()).padStart(2, "0")}`;
}

function claveFecha(iso: string): string {
  return iso.slice(0, 10);
}

function etiquetaFecha(iso: string): string {
  const hoy = new Date().toISOString().slice(0, 10);
  const ayer = new Date(Date.now() - 86_400_000).toISOString().slice(0, 10);
  const clave = claveFecha(iso);
  if (clave === hoy) return "Hoy";
  if (clave === ayer) return "Ayer";
  return new Date(iso).toLocaleDateString("es-CL", { day: "2-digit", month: "2-digit", year: "numeric" });
}

export function AulaChat({ contratoId, mensajesIniciales, usuarioId, bloqueado }: { contratoId: number; mensajesIniciales: MensajeAula[]; usuarioId: number; bloqueado: boolean }) {
  const [mensajes, setMensajes] = useState(mensajesIniciales);
  const [optimistas, setOptimistas] = useState<MensajeOptimista[]>([]);
  const [otroEscribiendo, setOtroEscribiendo] = useState(false);
  const [input, setInput] = useState("");
  const [toast, setToast] = useState<string | null>(null);

  const contenedorRef = useRef<HTMLDivElement>(null);
  const pollTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const pollIntervaloRef = useRef(3000);
  const typingLastRef = useRef(0);
  const tempIdContadorRef = useRef(0);

  const estaAbajo = useCallback(() => {
    const el = contenedorRef.current;
    if (!el) return true;
    return el.scrollHeight - el.scrollTop - el.clientHeight < 300;
  }, []);
  const scrollAbajo = useCallback(() => {
    const el = contenedorRef.current;
    if (el) el.scrollTop = el.scrollHeight;
  }, []);

  useEffect(() => {
    scrollAbajo();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const actualizarChat = useCallback(async () => {
    const abajoAntes = estaAbajo();
    const res = await fetch(`/api/me/aula/${contratoId}/mensajes`, { cache: "no-store" });
    if (!res.ok) return false;
    const data = (await res.json()) as { mensajes: MensajeAula[]; otroEscribiendo: boolean };
    setOtroEscribiendo(data.otroEscribiendo);

    let huboCambio = false;
    setMensajes((prev) => {
      if (prev.length !== data.mensajes.length || prev.at(-1)?.id !== data.mensajes.at(-1)?.id) huboCambio = true;
      return data.mensajes;
    });
    if (huboCambio) {
      setOptimistas([]);
      if (abajoAntes) requestAnimationFrame(scrollAbajo);
    }
    return huboCambio;
  }, [contratoId, estaAbajo, scrollAbajo]);

  useEffect(() => {
    function agendar() {
      if (pollTimerRef.current) clearTimeout(pollTimerRef.current);
      if (document.hidden) return;
      pollTimerRef.current = setTimeout(async () => {
        const hubo = await actualizarChat();
        pollIntervaloRef.current = hubo ? 3000 : Math.min(pollIntervaloRef.current + 2000, 20000);
        agendar();
      }, pollIntervaloRef.current);
    }
    function onVisibility() {
      if (document.hidden) {
        if (pollTimerRef.current) clearTimeout(pollTimerRef.current);
      } else {
        pollIntervaloRef.current = 3000;
        actualizarChat().then(agendar);
      }
    }
    document.addEventListener("visibilitychange", onVisibility);
    agendar();
    return () => {
      if (pollTimerRef.current) clearTimeout(pollTimerRef.current);
      document.removeEventListener("visibilitychange", onVisibility);
    };
  }, [actualizarChat]);

  function pingTyping() {
    const ahora = Date.now();
    if (ahora - typingLastRef.current < 2000) return;
    typingLastRef.current = ahora;
    fetch(`/api/me/aula/${contratoId}/typing`, { method: "POST" }).catch(() => {});
  }

  async function enviar(texto: string) {
    tempIdContadorRef.current += 1;
    const tempId = `tmp-${tempIdContadorRef.current}`;
    setOptimistas((prev) => [...prev, { tempId, texto, estado: "pendiente" }]);
    requestAnimationFrame(scrollAbajo);

    try {
      const res = await fetch(`/api/me/aula/${contratoId}/mensajes`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ mensaje: texto }),
      });
      const data = (await res.json()) as { ok: boolean; error?: string };
      if (data.ok) {
        pollIntervaloRef.current = 3000;
        await actualizarChat();
      } else {
        setOptimistas((prev) => prev.map((o) => (o.tempId === tempId ? { ...o, estado: "fallido" } : o)));
        setToast(data.error ?? "Error al enviar. Toca el ícono de reintentar.");
      }
    } catch {
      setOptimistas((prev) => prev.map((o) => (o.tempId === tempId ? { ...o, estado: "fallido" } : o)));
      setToast("Error de conexión. Toca el ícono de reintentar.");
    }
  }

  function reintentar(tempId: string) {
    const item = optimistas.find((o) => o.tempId === tempId);
    if (!item) return;
    setOptimistas((prev) => prev.filter((o) => o.tempId !== tempId));
    void enviar(item.texto);
  }

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    const texto = input.trim();
    if (!texto) return;
    setInput("");
    await enviar(texto);
  }

  // Sin variable mutable recorriendo el arreglo (regla de pureza de React) — cada mensaje
  // compara su propia fecha contra la del anterior por índice, sin acumular estado.
  const mensajesConSeparador = mensajes.map((msg, i) => ({
    msg,
    mostrarSeparador: i === 0 || claveFecha(msg.fecha) !== claveFecha(mensajes[i - 1]!.fecha),
  }));

  return (
    <div className="flex flex-col h-full bg-[#EFEAE2] bg-opacity-30">
      {toast && (
        <div className="fixed top-20 left-1/2 z-50 w-[90%] max-w-sm -translate-x-1/2">
          <div className="bg-red-500 text-white px-4 py-3 rounded-2xl shadow-xl flex items-start justify-between gap-3 border border-red-600">
            <p className="text-[13px] font-medium leading-snug">{toast}</p>
            <button type="button" onClick={() => setToast(null)} className="text-white hover:text-red-200 shrink-0">
              ✕
            </button>
          </div>
        </div>
      )}

      <div ref={contenedorRef} className="flex-1 overflow-y-auto p-4 space-y-1">
        {!bloqueado ? (
          <div className="flex justify-center mb-4">
            <div className="bg-sky-50 text-sky-800 text-[11px] px-4 py-2.5 rounded-xl max-w-[85%] text-center border border-sky-100">
              <b>Aula Virtual Activa.</b> Coordina la reunión, comparte recursos y resuelve dudas.
            </div>
          </div>
        ) : (
          <div className="flex justify-center mb-4">
            <div className="bg-gray-100 text-gray-600 text-[11px] px-4 py-2.5 rounded-xl max-w-[85%] text-center border border-gray-200 font-bold">Este chat de aula está cerrado.</div>
          </div>
        )}

        {mensajesConSeparador.map(({ msg, mostrarSeparador }) => {
          const soyYo = msg.remitenteId === usuarioId;
          return (
            <div key={msg.id}>
              {mostrarSeparador && (
                <div className="flex justify-center my-4">
                  <span className="bg-gray-100 text-gray-500 text-[10px] font-bold px-3 py-1 rounded-full uppercase tracking-wider border border-gray-200/50">
                    {etiquetaFecha(msg.fecha)}
                  </span>
                </div>
              )}
              <div className={`flex w-full ${soyYo ? "justify-end" : "justify-start"} mt-1 mb-1`}>
                <div
                  className={`relative max-w-[85%] md:max-w-[75%] px-3 py-2 text-[14px] leading-relaxed break-words ${
                    soyYo ? "bg-[#54A6D8] text-white rounded-2xl rounded-tr-sm shadow-sm" : "bg-white text-gray-800 border border-gray-100 rounded-2xl rounded-tl-sm shadow-sm"
                  }`}
                >
                  <span className="whitespace-pre-wrap">{msg.mensaje}</span>
                  <div className={`text-[9px] text-right mt-1 font-medium flex justify-end gap-1 items-center ${soyYo ? "text-blue-100" : "text-gray-400"}`}>
                    {horaCorta(msg.fecha)}
                    {soyYo && <span>{msg.visto ? "✓✓" : "✓"}</span>}
                  </div>
                </div>
              </div>
            </div>
          );
        })}

        {optimistas.map((op) => (
          <div key={op.tempId} className="flex w-full justify-end mt-1 mb-1">
            <div
              className={`relative max-w-[85%] md:max-w-[75%] px-3 py-2 text-[14px] leading-relaxed break-words rounded-2xl rounded-tr-sm ${
                op.estado === "fallido" ? "bg-red-50 text-red-800 border border-red-200" : "bg-[#54A6D8] text-white opacity-75"
              }`}
            >
              <span className="whitespace-pre-wrap">{op.texto}</span>
              {op.estado === "fallido" && (
                <button type="button" onClick={() => reintentar(op.tempId)} className="ml-2 text-red-600 underline text-[11px]">
                  Reintentar
                </button>
              )}
            </div>
          </div>
        ))}

        {otroEscribiendo && (
          <div className="flex justify-start mt-1 mb-1">
            <div className="bg-white border border-gray-100 rounded-2xl rounded-tl-sm px-4 py-3 shadow-sm flex items-center gap-1.5">
              <span className="w-1.5 h-1.5 rounded-full bg-gray-400 animate-bounce" style={{ animationDelay: "0ms" }} />
              <span className="w-1.5 h-1.5 rounded-full bg-gray-400 animate-bounce" style={{ animationDelay: "180ms" }} />
              <span className="w-1.5 h-1.5 rounded-full bg-gray-400 animate-bounce" style={{ animationDelay: "360ms" }} />
            </div>
          </div>
        )}
      </div>

      <form onSubmit={onSubmit} className={`bg-white px-3 py-2 border-t border-gray-100 shrink-0 flex items-end gap-2 ${bloqueado ? "opacity-50 pointer-events-none" : ""}`}>
        <div className="relative flex-1 bg-gray-100 rounded-[24px] flex items-center px-4 py-1 border border-transparent focus-within:border-blue-200 focus-within:bg-white transition-all">
          <textarea
            value={input}
            onChange={(e) => {
              setInput(e.target.value);
              if (e.target.value.trim()) pingTyping();
            }}
            onKeyDown={(e) => {
              if (e.key === "Enter" && !e.shiftKey) {
                e.preventDefault();
                if (input.trim()) void onSubmit(e as unknown as React.FormEvent);
              }
            }}
            disabled={bloqueado}
            rows={1}
            placeholder={bloqueado ? "Chat cerrado..." : "Escribe en el aula..."}
            className="w-full bg-transparent text-gray-900 text-sm focus:outline-none resize-none max-h-32 py-1 leading-relaxed placeholder-gray-400"
          />
        </div>
        <button
          type="submit"
          disabled={!input.trim() || bloqueado}
          className="bg-[#54A6D8] text-white w-11 h-11 rounded-full flex items-center justify-center hover:bg-blue-600 transition-all shadow-sm shrink-0 disabled:opacity-50"
        >
          →
        </button>
      </form>
    </div>
  );
}
