"use client";

import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import type { ChatBandejaItem } from "@/lib/api";

// Puerto de app/bandeja_entrada.php:96-483 — helpers de tiempo/iniciales, modo edición
// (selección múltiple + eliminar), y el "smart polling" cada 12s con pausa en
// document.hidden (mismo patrón ya usado en ContratarForm/MisContratosTabs de esta
// migración, no una decisión nueva).

function tiempoTranscurrido(fechaIso: string): string {
  if (!fechaIso) return "";
  const ts = new Date(fechaIso).getTime();
  if (Number.isNaN(ts)) return "";
  const diff = (Date.now() - ts) / 1000;
  if (diff < 60) return "Ahora";
  if (diff < 3600) return `${Math.floor(diff / 60)} min`;
  if (diff < 86400) return `${Math.floor(diff / 3600)} h`;
  if (diff < 604800) return `${Math.floor(diff / 86400)} d`;
  return new Date(fechaIso).toLocaleDateString("es-CL", { day: "2-digit", month: "2-digit" });
}

function iniciales(nombreCorto: string): string {
  const partes = nombreCorto.replace(".", "").trim().split(/\s+/);
  const primero = partes[0]?.charAt(0) ?? "?";
  const segundo = partes[1]?.charAt(0) ?? "";
  return (primero + segundo).toUpperCase();
}

export function BandejaChats({ itemsIniciales, phpSiteUrl }: { itemsIniciales: ChatBandejaItem[]; phpSiteUrl: string }) {
  const [items, setItems] = useState(itemsIniciales);
  const [editando, setEditando] = useState(false);
  const [seleccionados, setSeleccionados] = useState<Set<string>>(new Set());
  const [eliminando, setEliminando] = useState(false);
  const enVueloRef = useRef(false);

  useEffect(() => {
    async function sync() {
      if (document.hidden || editando || enVueloRef.current) return;
      enVueloRef.current = true;
      try {
        const res = await fetch("/api/me/chat/bandeja", { cache: "no-store" });
        if (res.ok) {
          const data = (await res.json()) as { items: ChatBandejaItem[] };
          setItems(data.items);
        }
      } finally {
        enVueloRef.current = false;
      }
    }
    const timer = setInterval(sync, 12000);
    document.addEventListener("visibilitychange", () => {
      if (!document.hidden) sync();
    });
    return () => clearInterval(timer);
  }, [editando]);

  function toggleSeleccion(uniqueId: string) {
    setSeleccionados((prev) => {
      const next = new Set(prev);
      if (next.has(uniqueId)) next.delete(uniqueId);
      else next.add(uniqueId);
      return next;
    });
  }

  function toggleSeleccionarTodos(marcar: boolean) {
    setSeleccionados(marcar ? new Set(items.map((i) => `${i.tipo}_${i.id}`)) : new Set());
  }

  async function borrarSeleccionados() {
    if (seleccionados.size === 0) return;
    if (!window.confirm(`¿Eliminar ${seleccionados.size} chats?`)) return;

    setEliminando(true);
    const ids = Array.from(seleccionados);
    try {
      await fetch("/api/me/chat/bandeja/eliminar", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ ids }),
      });
      setItems((prev) => prev.filter((i) => !seleccionados.has(`${i.tipo}_${i.id}`)));
      setSeleccionados(new Set());
      setEditando(false);
    } finally {
      setEliminando(false);
    }
  }

  return (
    <>
      <div className="sticky top-16 md:top-0 z-30 bg-white/95 backdrop-blur py-2 mb-4">
        {!editando ? (
          <div className="flex items-center justify-between">
            <h1 className="text-2xl font-extrabold text-gray-900 tracking-tight">Mensajes</h1>
            {items.length > 0 && (
              <button
                type="button"
                onClick={() => setEditando(true)}
                className="text-[15px] font-semibold text-[#54A6D8] bg-blue-50 hover:bg-blue-100 px-4 py-1.5 rounded-lg transition-colors"
              >
                Editar
              </button>
            )}
          </div>
        ) : (
          <>
            <div className="flex items-center justify-between bg-gray-50 rounded-xl p-1.5 border border-gray-100">
              <button
                type="button"
                onClick={() => {
                  setEditando(false);
                  setSeleccionados(new Set());
                }}
                className="text-[15px] font-medium text-gray-500 hover:text-gray-800 px-3 py-1"
              >
                Cancelar
              </button>
              <span className="text-sm font-bold text-gray-800">{seleccionados.size} seleccionados</span>
              <button
                type="button"
                onClick={borrarSeleccionados}
                disabled={seleccionados.size === 0 || eliminando}
                className="text-[14px] font-bold text-red-500 hover:text-red-600 bg-white border border-gray-200 px-4 py-1.5 rounded-lg shadow-sm disabled:opacity-50 disabled:cursor-not-allowed transition-all"
              >
                Eliminar
              </button>
            </div>
            <div className="mt-2 border-b border-gray-100 pb-2">
              <label className="flex items-center gap-3 cursor-pointer pl-2">
                <input
                  type="checkbox"
                  checked={seleccionados.size === items.length && items.length > 0}
                  onChange={(e) => toggleSeleccionarTodos(e.target.checked)}
                  className="peer sr-only"
                />
                <span className="text-sm font-medium text-[#54A6D8]">Seleccionar todos</span>
              </label>
            </div>
          </>
        )}
      </div>

      <div className="space-y-1">
        {items.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-20 text-center">
            <div className="w-20 h-20 bg-gray-50 rounded-full flex items-center justify-center text-gray-300 mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor" className="w-9 h-9">
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z"
                />
              </svg>
            </div>
            <h3 className="text-lg font-bold text-gray-900">Sin mensajes</h3>
            <p className="text-sm text-gray-500 max-w-xs mx-auto mt-2">No hay conversaciones activas.</p>
          </div>
        ) : (
          items.map((chat) => {
            const uniqueId = `${chat.tipo}_${chat.id}`;
            const esAula = chat.tipo === "aula";
            const href = esAula ? `${phpSiteUrl}/app/mini_aula.php?id=${chat.id}` : `/chat/${chat.id}`;
            const marcado = seleccionados.has(uniqueId);
            const tiempo = tiempoTranscurrido(chat.fechaSort);

            const Contenido = (
              <div className="flex items-center p-3 gap-3 w-full">
                <div className="relative shrink-0 w-12 h-12">
                  {chat.otroFotoUrl ? (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img src={chat.otroFotoUrl} alt="" className="w-12 h-12 rounded-full object-cover bg-gray-100 border border-gray-100 shadow-sm" />
                  ) : (
                    <div className="w-12 h-12 rounded-full bg-blue-50 flex items-center justify-center text-[#54A6D8] font-bold text-lg tracking-wide border border-blue-100">
                      {iniciales(chat.otroNombre)}
                    </div>
                  )}
                  <div className="absolute -bottom-0.5 -right-0.5 w-5 h-5 rounded-full border-[1.5px] border-white flex items-center justify-center bg-white shadow-sm">
                    {esAula ? (
                      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-3 h-3 text-[#54A6D8]">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.57 50.57 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0112 13.489a50.702 50.702 0 017.74-3.342M6.75 15a.75.75 0 100-1.5.75.75 0 000 1.5zm0 0v-3.675A55.378 55.378 0 0112 8.443" />
                      </svg>
                    ) : (
                      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-3 h-3 text-[#54A6D8]">
                        <path
                          strokeLinecap="round"
                          strokeLinejoin="round"
                          d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 01.865-.501 48.172 48.172 0 003.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z"
                        />
                      </svg>
                    )}
                  </div>
                </div>

                <div className="flex-1 min-w-0">
                  <div className="flex justify-between items-baseline mb-0.5">
                    <h3 className={`text-[15px] truncate pr-2 ${chat.sinLeer > 0 ? "font-extrabold text-gray-900" : "font-medium text-gray-700"}`}>{chat.otroNombre}</h3>
                    <span className={`text-[11px] shrink-0 ${chat.sinLeer > 0 ? "font-bold text-[#54A6D8]" : "font-medium text-gray-400"}`}>{tiempo}</span>
                  </div>
                  <p className={`text-[11px] truncate mb-0.5 ${chat.sinLeer > 0 ? "font-semibold text-[#54A6D8]" : "font-medium text-gray-400 opacity-80"}`}>
                    {chat.servicioTitulo}
                  </p>
                  <p className={`text-[13px] truncate leading-snug ${chat.sinLeer > 0 ? "font-bold text-gray-900" : "font-normal text-gray-500"}`}>
                    {chat.ultimoMensaje || "Inicia la conversación..."}
                  </p>
                </div>

                {chat.sinLeer > 0 ? (
                  <div className="shrink-0 pl-2">
                    <span className="bg-red-500 text-white text-[10px] font-bold h-5 min-w-[20px] px-1.5 rounded-full flex items-center justify-center shadow-sm">
                      {chat.sinLeer > 99 ? "99+" : chat.sinLeer}
                    </span>
                  </div>
                ) : (
                  <div className="shrink-0 pl-2 text-gray-300">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-3.5 h-3.5">
                      <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                    </svg>
                  </div>
                )}
              </div>
            );

            return (
              <div
                key={uniqueId}
                className={`group relative rounded-xl overflow-hidden transition-all duration-200 border-b border-gray-100 last:border-0 ${
                  chat.sinLeer > 0 ? "bg-blue-50/40" : "bg-white"
                } ${!editando ? "hover:bg-gray-50" : ""}`}
              >
                {editando && (
                  <button
                    type="button"
                    onClick={() => toggleSeleccion(uniqueId)}
                    className="absolute left-0 top-0 bottom-0 w-10 flex items-center justify-center z-20"
                    aria-label="Seleccionar"
                  >
                    <div className={`w-[22px] h-[22px] rounded-full border-2 grid place-items-center transition-all ${marcado ? "bg-[#54A6D8] border-[#54A6D8]" : "border-gray-300"}`}>
                      {marcado && (
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={3} stroke="white" className="w-2.5 h-2.5">
                          <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                        </svg>
                      )}
                    </div>
                  </button>
                )}
                <div
                  className="transition-transform duration-300"
                  style={editando ? { transform: "translateX(30px)", pointerEvents: "none" } : undefined}
                >
                  {esAula ? (
                    <a href={href} className="block w-full">
                      {Contenido}
                    </a>
                  ) : (
                    <Link href={href} className="block w-full">
                      {Contenido}
                    </Link>
                  )}
                </div>
              </div>
            );
          })
        )}
      </div>
    </>
  );
}
