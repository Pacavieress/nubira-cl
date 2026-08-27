"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import Link from "next/link";
import type { ChatDetalle, MensajeChatPrevio } from "@/lib/api";
import { formatoCLP } from "@/lib/formato";

// Puerto de app/chat_previo_contrato.php — Grupo Mensajes/Chat, Pieza 1 (26/08/2026).
// Simplificación deliberada del manejo de teclado móvil: el PHP real recalcula --vh a mano
// con visualViewport (iOS Safari no tenía otra forma en su momento). Acá se usa `h-dvh`
// (unidad CSS moderna, soportada en todo navegador móvil evergreen en 2026) que resuelve el
// mismo problema sin JS — no es una omisión, es una simplificación real disponible hoy que
// no lo estaba cuando se escribió el PHP original.

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

function MensajeBurbuja({ msg, soyYo }: { msg: MensajeChatPrevio; soyYo: boolean }) {
  if (msg.esSistema) {
    // Mismo mecanismo que render_mensajes.php:20-21 (echo $msg['mensaje']) — el HTML es
    // 100% generado por el server (generarSlotExcepcion, ver contratos.repository.ts), nunca
    // texto de usuario, así que no hay riesgo XSS real acá.
    return <div className="flex justify-center mb-2" dangerouslySetInnerHTML={{ __html: msg.mensaje }} />;
  }

  const esImagen = msg.archivo && msg.archivo.tipo.startsWith("image/");
  const esPdf = msg.archivo && msg.archivo.tipo === "application/pdf";

  return (
    <div className={`flex w-full ${soyYo ? "justify-end" : "justify-start"} mb-2`}>
      <div
        className={`relative max-w-[85%] md:max-w-[70%] px-4 py-2 shadow-sm text-[14px] leading-snug break-words ${
          soyYo ? "bg-[#54A6D8] text-white rounded-[18px_18px_4px_18px]" : "bg-white text-gray-900 border border-gray-100 rounded-[18px_18px_18px_4px]"
        }`}
      >
        {esImagen && msg.archivo && (
          <div className="relative -mx-4 -mt-2 mb-1 overflow-hidden rounded-t-[18px]">
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src={msg.archivo.url} alt={msg.archivo.nombre} className="w-full max-w-[280px] object-cover" loading="lazy" />
          </div>
        )}
        {esPdf && msg.archivo && (
          <a
            href={msg.archivo.url}
            target="_blank"
            rel="noopener"
            className={`flex items-center gap-3 px-3 py-2.5 rounded-xl -mx-2 my-1 min-w-[220px] ${soyYo ? "bg-white/15 hover:bg-white/25" : "bg-gray-50 hover:bg-gray-100"}`}
          >
            <div className={`w-10 h-10 rounded-lg flex items-center justify-center shrink-0 ${soyYo ? "bg-white/20" : "bg-red-50 text-red-500"}`}>PDF</div>
            <p className={`text-[13px] font-medium truncate ${soyYo ? "text-white" : "text-gray-900"}`}>{msg.archivo.nombre}</p>
          </a>
        )}
        {msg.mensaje && <span className="whitespace-pre-wrap">{msg.mensaje}</span>}
        <div className={`text-[10px] flex items-center justify-end gap-1 mt-1 opacity-80 ${soyYo ? "text-blue-50" : "text-gray-400"}`}>{horaCorta(msg.enviadoEn)}</div>
      </div>
    </div>
  );
}

export function ChatWindow({
  detalleInicial,
  mensajesIniciales,
  usuarioId,
  phpSiteUrl,
}: {
  detalleInicial: ChatDetalle;
  mensajesIniciales: MensajeChatPrevio[];
  usuarioId: number;
  phpSiteUrl: string;
}) {
  const [detalle, setDetalle] = useState(detalleInicial);
  const [mensajes, setMensajes] = useState(mensajesIniciales);
  const [optimistas, setOptimistas] = useState<MensajeOptimista[]>([]);
  const [otroEscribiendo, setOtroEscribiendo] = useState(false);
  const [input, setInput] = useState("");
  const [enviando, setEnviando] = useState(false);
  const [toast, setToast] = useState<string | null>(null);
  const [mostrarBannerExpress, setMostrarBannerExpress] = useState(false);
  const [mostrarModalExpress, setMostrarModalExpress] = useState(false);
  const [modalReservaAbierto, setModalReservaAbierto] = useState(false);

  const contenedorRef = useRef<HTMLDivElement>(null);
  const pollTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const pollIntervaloRef = useRef(3000);
  const typingLastRef = useRef(0);
  // Contador monotónico en vez de Date.now()/Math.random() — evita llamar funciones impuras
  // "alcanzables durante el render" (react-hooks/purity), y de paso da IDs temporales más
  // simples de razonar (solo necesitan ser únicos dentro de esta sesión del componente).
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
    const res = await fetch(`/api/me/chat/${detalleInicial.id}/mensajes`, { cache: "no-store" });
    if (!res.ok) return false;
    const data = (await res.json()) as { mensajes: MensajeChatPrevio[]; otroEscribiendo: boolean };
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
  }, [detalleInicial.id, estaAbajo, scrollAbajo]);

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
    fetch(`/api/me/chat/${detalleInicial.id}/typing`, { method: "POST" }).catch(() => {});
  }

  async function enviar(texto: string) {
    tempIdContadorRef.current += 1;
    const tempId = `tmp-${tempIdContadorRef.current}`;
    setOptimistas((prev) => [...prev, { tempId, texto, estado: "pendiente" }]);
    requestAnimationFrame(scrollAbajo);

    try {
      const res = await fetch(`/api/me/chat/${detalleInicial.id}/mensajes`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ mensaje: texto }),
      });
      const data = (await res.json()) as { ok: boolean; mostrarBannerExpress?: boolean; requiereCompletar?: boolean; limiteAlcanzado?: boolean; error?: string };

      if (data.ok) {
        if (data.mostrarBannerExpress) setMostrarBannerExpress(true);
        pollIntervaloRef.current = 3000;
        const hubo = await actualizarChat();
        if (!hubo) {
          setOptimistas((prev) => prev.map((o) => (o.tempId === tempId ? { ...o, estado: "pendiente" } : o)));
        }
      } else {
        if (data.requiereCompletar) {
          setMostrarModalExpress(true);
        } else if (data.limiteAlcanzado) {
          setDetalle((prev) => ({ ...prev, limiteMensajesAlcanzado: true }));
        }
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
    if (!texto || enviando) return;
    setInput("");
    setEnviando(true);
    try {
      await enviar(texto);
    } finally {
      setEnviando(false);
    }
  }

  const chatBloqueado = detalle.tutorInactivo || detalle.destinatarioSuspendido || detalle.limiteMensajesAlcanzado;
  const placeholderBloqueo = detalle.destinatarioSuspendido
    ? "Esta persona no está disponible..."
    : detalle.limiteMensajesAlcanzado
      ? "Límite de mensajes alcanzado..."
      : "Chat pausado por inactividad...";

  return (
    <div className="w-full flex flex-col h-dvh bg-gray-50 text-gray-900">
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

      {mostrarBannerExpress && (
        <div className="bg-amber-100 border-b border-amber-200 text-amber-900 text-xs md:text-sm">
          <div className="max-w-[1600px] mx-auto px-4 py-2 flex flex-wrap items-center justify-between gap-2">
            <span className="font-medium">💬 Crea tu contraseña para guardar esta conversación.</span>
            <a href={`${phpSiteUrl}/completar-registro?redir=${encodeURIComponent(`/chat/${detalle.id}`)}`} className="font-bold underline whitespace-nowrap">
              Crear contraseña →
            </a>
          </div>
        </div>
      )}

      {mostrarModalExpress && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4">
          <div className="bg-white rounded-2xl shadow-xl max-w-sm w-full p-6 text-center">
            <h2 className="text-xl font-bold text-gray-900 mb-2">Completa tu registro</h2>
            <p className="text-gray-500 text-sm mb-6">Para enviar más mensajes, crea una contraseña y protege tu cuenta.</p>
            <a
              href={`${phpSiteUrl}/completar-registro?redir=${encodeURIComponent(`/chat/${detalle.id}`)}`}
              className="block w-full bg-[#54A6D8] text-white font-bold py-3 rounded-2xl mb-3 hover:bg-[#4895c3] transition-all"
            >
              Crear contraseña
            </a>
            <button type="button" onClick={() => setMostrarModalExpress(false)} className="block w-full text-gray-400 text-sm hover:text-gray-600 py-1">
              Más tarde
            </button>
          </div>
        </div>
      )}

      <header className="h-16 bg-white/95 backdrop-blur-md shrink-0 flex items-center justify-between px-3 border-b border-gray-100 shadow-sm z-30">
        <div className="flex items-center gap-2 overflow-hidden">
          <Link href="/bandeja-entrada" className="text-gray-500 hover:text-[#54A6D8] w-10 h-10 flex items-center justify-center rounded-full hover:bg-gray-50 -ml-1">
            ←
          </Link>
          <div className="relative shrink-0">
            {detalle.otroFotoUrl ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img src={detalle.otroFotoUrl} alt="" className="w-10 h-10 rounded-full object-cover border border-gray-200 bg-gray-100" />
            ) : (
              <div className="w-10 h-10 rounded-full bg-blue-50 flex items-center justify-center font-bold text-[#54A6D8] border border-blue-100 text-lg">
                {detalle.otroNombre.charAt(0).toUpperCase()}
              </div>
            )}
            {detalle.otroOnline && <span className="absolute bottom-0 right-0 w-2.5 h-2.5 bg-green-500 border-2 border-white rounded-full" />}
          </div>
          <div className="leading-tight overflow-hidden pl-1">
            <h1 className="font-bold text-gray-900 text-[15px] truncate">{detalle.otroNombre}</h1>
            <p className="text-[11px] text-gray-500 truncate max-w-[160px]">{detalle.servicioTitulo}</p>
          </div>
        </div>
        <div className="shrink-0 pl-2">
          {!detalle.esVendedor ? (
            <Link
              href={`/contratar/${detalle.servicioId}`}
              className="flex items-center gap-1 bg-gradient-to-r from-[#54A6D8] to-blue-600 hover:to-blue-700 text-white px-3 py-2.5 rounded-full text-xs font-bold shadow-md shadow-blue-200"
            >
              Contratar
            </Link>
          ) : (
            <button
              type="button"
              onClick={() => setModalReservaAbierto(true)}
              className="flex items-center gap-1 bg-gray-50 hover:bg-gray-100 text-gray-700 border border-gray-200 px-3 py-2.5 rounded-full text-xs font-bold"
            >
              Generar Reserva
            </button>
          )}
        </div>
      </header>

      <main ref={contenedorRef} className="flex-1 overflow-y-auto p-4 pb-4 space-y-1 w-full relative">
        <div className="flex justify-center mb-4 mt-2">
          {!detalle.esVendedor ? (
            <div className="bg-amber-50 text-amber-900 text-[11px] px-4 py-3 rounded-xl max-w-[85%] border border-amber-200 leading-snug">
              <p className="font-bold mb-1">¿Cómo contratar?</p>
              <p>1. Acuerda día, hora y precio aquí</p>
              <p>2. Pulsa Contratar arriba ↑</p>
              <p>3. Pago en custodia — solo se libera al tutor cuando confirmes la clase</p>
            </div>
          ) : (
            <div className="bg-amber-50 text-amber-900 text-[11px] px-4 py-2.5 rounded-xl max-w-[85%] border border-amber-200 leading-snug">
              <b>Cobra de forma segura:</b> Acepta el pago solo por Nubira.
            </div>
          )}
        </div>

        {mensajes.map((msg) => (
          <MensajeBurbuja key={msg.id} msg={msg} soyYo={msg.remitenteId === usuarioId} />
        ))}

        {optimistas.map((op) => (
          <div key={op.tempId} className="flex w-full justify-end mb-2">
            <div
              className={`relative max-w-[85%] md:max-w-[70%] px-4 py-2 shadow-sm text-[14px] leading-snug break-words rounded-[18px_4px_18px_18px] ${
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
          <div className="flex justify-start mb-2">
            <div className="bg-white border border-gray-100 rounded-[4px_18px_18px_18px] px-4 py-3 shadow-sm flex items-center gap-1.5">
              <span className="w-1.5 h-1.5 rounded-full bg-gray-400 animate-bounce" style={{ animationDelay: "0ms" }} />
              <span className="w-1.5 h-1.5 rounded-full bg-gray-400 animate-bounce" style={{ animationDelay: "180ms" }} />
              <span className="w-1.5 h-1.5 rounded-full bg-gray-400 animate-bounce" style={{ animationDelay: "360ms" }} />
            </div>
          </div>
        )}
      </main>

      <footer className="bg-white px-3 py-2 border-t border-gray-100 shrink-0 w-full z-20" style={{ paddingBottom: "calc(0.5rem + env(safe-area-inset-bottom))" }}>
        {detalle.tutorInactivo && (
          <div className="max-w-4xl mx-auto w-full bg-orange-50 border border-orange-200 rounded-2xl p-3 flex items-center justify-between gap-3 mb-3">
            <div>
              <h4 className="text-[12px] font-bold text-orange-800">Tutor inactivo (más de 48 hrs)</h4>
              <p className="text-[11px] text-orange-600 mt-0.5">El chat ha sido pausado para evitar esperas.</p>
            </div>
            <Link href="/servicios" className="bg-white hover:bg-orange-100 text-orange-600 border border-orange-200 px-3 py-1.5 rounded-xl text-xs font-bold shrink-0">
              Buscar otro
            </Link>
          </div>
        )}
        {detalle.limiteMensajesAlcanzado && (
          <div className="max-w-4xl mx-auto w-full bg-sky-50 border border-sky-200 rounded-2xl p-3 mb-3">
            <h4 className="text-[12px] font-bold text-sky-800">Llegaste al límite de mensajes</h4>
            <p className="text-[11px] text-sky-600 mt-0.5">Si quieres seguir conversando, avanza con la contratación del servicio.</p>
          </div>
        )}

        <form onSubmit={onSubmit} className={`flex items-end gap-2 max-w-4xl mx-auto w-full relative ${chatBloqueado ? "opacity-50 pointer-events-none" : ""}`}>
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
              disabled={chatBloqueado}
              rows={1}
              placeholder={chatBloqueado ? placeholderBloqueo : "Escribe un mensaje..."}
              className="w-full bg-transparent text-gray-900 text-sm focus:outline-none resize-none max-h-32 py-1 leading-relaxed placeholder-gray-400"
            />
          </div>
          <button
            type="submit"
            disabled={!input.trim() || enviando || chatBloqueado}
            className="bg-[#54A6D8] text-white w-11 h-11 rounded-full flex items-center justify-center hover:bg-blue-600 transition-all shadow-sm shrink-0 disabled:opacity-50"
          >
            →
          </button>
        </form>
      </footer>

      {modalReservaAbierto && (
        <ModalGenerarReserva
          conversacionId={detalle.id}
          servicioTitulo={detalle.servicioTitulo}
          servicio={detalle.servicio}
          onCerrar={() => setModalReservaAbierto(false)}
          onExito={() => {
            setModalReservaAbierto(false);
            pollIntervaloRef.current = 1000;
            void actualizarChat();
          }}
        />
      )}
    </div>
  );
}

function ModalGenerarReserva({
  conversacionId,
  servicioTitulo,
  servicio,
  onCerrar,
  onExito,
}: {
  conversacionId: number;
  servicioTitulo: string;
  servicio: ChatDetalle["servicio"];
  onCerrar: () => void;
  onExito: () => void;
}) {
  const [fecha, setFecha] = useState("");
  const [hora, setHora] = useState("");
  const [enviando, setEnviando] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function enviar() {
    setError(null);
    if (!fecha) return setError("Elige una fecha para la reserva.");
    if (!hora) return setError("Elige una hora para la reserva.");
    const hh = Number(hora.split(":")[0]);
    if (hh < 7) return setError("Elige una hora válida (desde las 07:00).");

    setEnviando(true);
    try {
      const res = await fetch("/api/me/contratos/slots-excepcion", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ conversacionId, fecha, hora }),
      });
      const data = (await res.json()) as { ok?: boolean; mensaje?: string };
      if (res.ok && data.ok) {
        onExito();
      } else {
        setError(data.mensaje ?? "No se pudo generar la reserva.");
      }
    } catch {
      setError("Error de conexión. Intenta nuevamente.");
    } finally {
      setEnviando(false);
    }
  }

  const hoy = new Date().toISOString().slice(0, 10);

  return (
    <div className="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4" onClick={onCerrar}>
      <div className="bg-white rounded-2xl shadow-2xl max-w-md w-full overflow-hidden" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-center justify-between px-5 py-4 border-b border-gray-100">
          <h3 className="font-bold text-gray-900 text-[15px]">Generar Reserva</h3>
          <button type="button" onClick={onCerrar} className="text-gray-400 hover:text-gray-600 w-8 h-8 flex items-center justify-center rounded-full hover:bg-gray-50">
            ✕
          </button>
        </div>
        <div className="px-5 pt-4 pb-3 bg-gray-50 border-b border-gray-100">
          <p className="text-[10px] font-bold text-gray-400 uppercase tracking-wide mb-1">Servicio</p>
          <p className="text-sm font-bold text-gray-900 truncate">{servicioTitulo}</p>
          <div className="flex items-center gap-6 mt-2">
            <div>
              <p className="text-[10px] text-gray-400 uppercase tracking-wide">Duración</p>
              <p className="text-sm font-bold text-gray-700">{servicio.duracionMinutos} min</p>
            </div>
            <div>
              <p className="text-[10px] text-gray-400 uppercase tracking-wide">Precio</p>
              <p className="text-sm font-bold text-gray-700">{formatoCLP(servicio.esOferta && servicio.precioOferta !== null ? servicio.precioOferta : servicio.precio)}</p>
            </div>
          </div>
        </div>
        <div className="p-5">
          <div className="grid grid-cols-2 gap-3 mb-4">
            <div>
              <label className="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5">Fecha</label>
              <input
                type="date"
                min={hoy}
                value={fecha}
                onChange={(e) => setFecha(e.target.value)}
                className="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2.5 text-sm font-medium text-gray-900 focus:outline-none focus:border-[#54A6D8]"
              />
            </div>
            <div>
              <label className="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5">Hora</label>
              <input
                type="time"
                min="07:00"
                value={hora}
                onChange={(e) => setHora(e.target.value)}
                className="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2.5 text-sm font-medium text-gray-900 focus:outline-none focus:border-[#54A6D8]"
              />
            </div>
          </div>
          <p className="text-[11px] text-gray-400 mb-4 leading-snug">El estudiante recibirá un enlace de pago válido por 24 horas. El precio no se puede modificar.</p>
          {error && <p className="text-[12px] text-red-600 mb-3">{error}</p>}
          <div className="flex gap-2">
            <button type="button" onClick={onCerrar} className="flex-1 py-2.5 text-sm font-medium text-gray-600 hover:bg-gray-50 rounded-xl">
              Cancelar
            </button>
            <button
              type="button"
              onClick={enviar}
              disabled={enviando}
              className="flex-1 py-2.5 text-sm font-bold text-white bg-[#54A6D8] hover:bg-blue-600 rounded-xl shadow-sm disabled:opacity-50"
            >
              {enviando ? "Generando..." : "Generar enlace"}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
