"use client";

import { useEffect, useRef, useState } from "react";
import type { AulaDetalle, AulaListado } from "@/lib/api";

const POLL_MS = 5000; // admin_chats_aula.php:465

function iniciales(nombre: string): string {
  const partes = nombre.trim().split(/\s+/);
  return ((partes[0]?.[0] ?? "U") + (partes[1]?.[0] ?? "")).toUpperCase();
}

function AvatarAula({ nombre, fotoUrl }: { nombre: string; fotoUrl: string }) {
  const esExterna = fotoUrl.includes("ui-avatars.com");
  if (!esExterna) {
    // eslint-disable-next-line @next/next/no-img-element -- avatar dinámico de usuario
    return <img src={fotoUrl} alt="" className="w-9 h-9 rounded-full object-cover border-2 border-white shadow-sm shrink-0" />;
  }
  return (
    <div className="w-9 h-9 rounded-full border-2 border-white shadow-sm shrink-0 bg-sky-100 text-sky-600 flex items-center justify-center text-xs font-bold">
      {iniciales(nombre)}
    </div>
  );
}

// Puerto de admin_chats_aula.php ("Monitor Aulas") — 100% lectura: lista de aulas con
// búsqueda + orden, detalle de mensajes (historial pre-venta + aula virtual) con polling.
// Sin ninguna acción de escritura, sin CSRF necesario.
export function AdminAulasPanel({ aulasIniciales, ordenInicial }: { aulasIniciales: AulaListado[]; ordenInicial: "asc" | "desc" }) {
  const [aulas, setAulas] = useState(aulasIniciales);
  const [q, setQ] = useState("");
  const [orden, setOrden] = useState<"asc" | "desc">(ordenInicial);
  const [seleccionadaId, setSeleccionadaId] = useState<number | null>(null);
  const [detalle, setDetalle] = useState<AulaDetalle | null>(null);
  const [cargandoDetalle, setCargandoDetalle] = useState(false);
  const scrollRef = useRef<HTMLDivElement>(null);
  const mensajeCountRef = useRef(0);

  useEffect(() => {
    const t = setTimeout(async () => {
      const params = new URLSearchParams({ orden });
      if (q) params.set("q", q);
      const res = await fetch(`/api/admin/aulas?${params.toString()}`);
      if (res.ok) setAulas(await res.json());
    }, 400);
    return () => clearTimeout(t);
  }, [q, orden]);

  useEffect(() => {
    if (seleccionadaId === null) return;
    let activo = true;
    mensajeCountRef.current = 0;

    async function cargar(mostrarLoader: boolean) {
      if (document.hidden) return;
      if (mostrarLoader) setCargandoDetalle(true);
      try {
        const res = await fetch(`/api/admin/aulas/${seleccionadaId}/mensajes`);
        if (!activo) return;
        if (res.ok) {
          const data: AulaDetalle = await res.json();
          if (data.mensajes.length !== mensajeCountRef.current) {
            mensajeCountRef.current = data.mensajes.length;
            setDetalle(data);
            requestAnimationFrame(() => scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight, behavior: mostrarLoader ? "auto" : "smooth" }));
          }
        }
      } finally {
        if (activo) setCargandoDetalle(false);
      }
    }

    cargar(true);
    const interval = setInterval(() => cargar(false), POLL_MS);
    return () => {
      activo = false;
      clearInterval(interval);
    };
  }, [seleccionadaId]);

  const separadorIdx = detalle?.mensajes.findIndex((m) => m.origen === "aula") ?? -1;

  return (
    <div className="bg-white border border-gray-100 rounded-3xl overflow-hidden flex h-[calc(100vh-11rem)] min-h-[500px]">
      <div className={`w-full md:w-[380px] border-r border-gray-100 flex flex-col shrink-0 ${seleccionadaId !== null ? "hidden md:flex" : "flex"}`}>
        <div className="p-4 space-y-2 border-b border-gray-50">
          <input
            type="text"
            value={q}
            onChange={(e) => setQ(e.target.value)}
            placeholder="Buscar ID o usuario..."
            className="w-full bg-gray-50 border border-gray-100 rounded-xl px-4 py-2.5 text-sm focus:border-[#54A6D8] focus:bg-white outline-none"
          />
          <div className="flex justify-between items-center">
            <span className="text-[10px] font-bold text-gray-400 uppercase tracking-wider">{aulas.length} aula(s)</span>
            <select
              value={orden}
              onChange={(e) => setOrden(e.target.value as "asc" | "desc")}
              className="bg-gray-50 text-xs font-bold text-gray-600 border border-gray-100 rounded-xl py-1.5 px-3 cursor-pointer outline-none"
            >
              <option value="desc">Recientes</option>
              <option value="asc">Antiguos</option>
            </select>
          </div>
        </div>

        <div className="flex-1 overflow-y-auto py-2">
          {aulas.length === 0 && <div className="text-center text-gray-400 text-sm py-12">No hay aulas activas.</div>}
          {aulas.map((a) => (
            <button
              key={a.id}
              type="button"
              onClick={() => {
                setDetalle(null);
                setSeleccionadaId(a.id);
              }}
              className={`w-full text-left flex items-center gap-3 mx-3 mb-2 p-3 rounded-2xl border border-gray-100 transition-all hover:shadow-md relative ${
                seleccionadaId === a.id ? "bg-blue-50/50" : "bg-white hover:bg-gray-50"
              } ${a.cerrado ? "opacity-60" : ""}`}
            >
              {seleccionadaId === a.id && <div className="absolute left-0 top-0 bottom-0 w-1 bg-[#54A6D8] rounded-l-2xl" />}
              <div className="relative flex -space-x-3 shrink-0">
                {a.enVivo && (
                  <span className="absolute -top-1 -right-1 flex h-3 w-3 z-10">
                    <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75" />
                    <span className="relative inline-flex rounded-full h-3 w-3 bg-emerald-500 border border-white" />
                  </span>
                )}
                <AvatarAula nombre={a.compradorNombre} fotoUrl={a.compradorFotoUrl} />
                <AvatarAula nombre={a.vendedorNombre} fotoUrl={a.vendedorFotoUrl} />
              </div>
              <div className="min-w-0 flex-1">
                <div className="flex justify-between items-center gap-1">
                  <p className="text-sm font-bold text-gray-900 truncate">
                    C-{a.id} {a.compradorNombre}
                    {a.estado === "finalizado" && <span className="ml-1 text-[9px] bg-gray-100 text-gray-500 px-1.5 py-0.5 rounded-full uppercase font-bold">Fin</span>}
                    {a.estado === "disputa" && <span className="ml-1 text-[9px] bg-red-100 text-red-600 px-1.5 py-0.5 rounded-full uppercase font-bold">Disputa</span>}
                  </p>
                  <span className="text-[10px] text-gray-400 shrink-0">{a.fechaReferencia ? new Date(a.fechaReferencia).toLocaleDateString("es-CL", { day: "2-digit", month: "2-digit" }) : "--"}</span>
                </div>
                <p className="text-xs text-gray-500 truncate">{a.ultimoMensaje ?? "Sin mensajes..."}</p>
              </div>
            </button>
          ))}
        </div>
      </div>

      <div className={`flex-1 flex flex-col min-w-0 ${seleccionadaId !== null ? "flex" : "hidden md:flex"}`}>
        {seleccionadaId === null ? (
          <div className="flex-1 flex flex-col items-center justify-center text-center p-8">
            <div className="w-16 h-16 bg-gray-50 rounded-2xl flex items-center justify-center mb-4 border border-gray-100">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} className="w-7 h-7 text-[#54A6D8]/60">
                <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
              </svg>
            </div>
            <h2 className="text-lg font-semibold text-gray-900 mb-1">Centro de Auditoría</h2>
            <p className="text-gray-500 text-sm max-w-sm">Selecciona un aula en el panel lateral para inspeccionar el historial.</p>
          </div>
        ) : (
          <>
            <div className="px-5 py-4 border-b border-gray-100 flex items-center gap-3">
              <button
                type="button"
                onClick={() => {
                  setDetalle(null);
                  setSeleccionadaId(null);
                }}
                className="md:hidden text-[#54A6D8] p-1"
              >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} className="w-5 h-5">
                  <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
                </svg>
              </button>
              <div className="min-w-0">
                <h3 className="font-bold text-gray-900 text-sm truncate">Contrato #{seleccionadaId} — Auditoría Aula</h3>
                {detalle && (
                  <p className="text-xs text-gray-500 truncate">
                    {detalle.compradorNombre} y {detalle.vendedorNombre}
                  </p>
                )}
              </div>
            </div>

            <div ref={scrollRef} className="flex-1 overflow-y-auto p-5">
              {cargandoDetalle && !detalle && <div className="text-center text-gray-400 text-sm py-8">Cargando...</div>}
              {detalle && detalle.mensajes.length === 0 && <div className="text-center text-gray-400 text-sm py-8">El aula no tiene mensajes.</div>}
              {detalle?.mensajes.map((m, i) => {
                const esComprador = m.remitenteId === detalle.compradorId;
                return (
                  <div key={i}>
                    {i === separadorIdx && separadorIdx > 0 && (
                      <div className="flex items-center justify-center my-6">
                        <div className="bg-gray-200 h-px flex-1" />
                        <span className="px-4 text-[10px] font-bold text-gray-400 uppercase tracking-widest">Servicio Contratado</span>
                        <div className="bg-gray-200 h-px flex-1" />
                      </div>
                    )}
                    <div className={`flex items-end gap-2 mb-3 ${esComprador ? "flex-row-reverse" : "flex-row"} ${m.origen === "previo" ? "opacity-75" : ""}`}>
                      <div
                        className={`max-w-[85%] md:max-w-[70%] px-4 py-2.5 rounded-2xl shadow-sm text-sm leading-relaxed whitespace-pre-line ${
                          esComprador ? "bg-[#54A6D8] text-white rounded-tr-sm" : "bg-gray-50 text-gray-800 rounded-tl-sm border border-gray-100"
                        }`}
                      >
                        {m.mensaje}
                        <div className="flex items-center justify-end mt-1 opacity-80 text-[10px] font-medium">
                          {new Date(m.enviadoEn).toLocaleString("es-CL", { day: "2-digit", month: "2-digit", hour: "2-digit", minute: "2-digit" })}
                        </div>
                      </div>
                    </div>
                  </div>
                );
              })}
            </div>
          </>
        )}
      </div>
    </div>
  );
}
