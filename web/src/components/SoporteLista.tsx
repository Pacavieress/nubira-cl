"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import type { CategoriaTicket, Ticket } from "@/lib/api";

// Puerto de app/reclamos_sugerencias.php ("Centro de Ayuda") — crear ticket, responder,
// marcar resuelto, marcar leído, eliminar (soft delete), filtros por estado. Todas las
// mutaciones pasan por proxies same-origin (web/src/app/api/soporte/...) que reenvían a
// server/ con la cookie de sesión, mismo patrón que /mis-publicaciones y
// /configurar-cuenta. Tras cada mutación se usa router.refresh() para re-obtener datos
// frescos del Server Component en vez de reconciliar estado optimista a mano.
//
// Simplificación deliberada, documentada: SIN el modo de selección múltiple por
// long-press + barra de eliminación masiva (reclamos_sugerencias.php:618-724, gestos
// touch/mouse nativos). El endpoint bulk (/api/me/soporte/eliminar) sí soporta varios ids
// a la vez (ver server/), pero esta primera pasada solo expone eliminar de a un ticket por
// vez desde su propia fila — cubre el mismo resultado final, con menos superficie de UI
// gestual que portar. Sin la notificación push a admin al crear ticket (ver
// server/src/modules/soporte/soporte.types.ts para el porqué).

// Sin emoji (regla dura del sistema de diseño, CLAUDE.md: "No emoji icons") — SVG inline
// estilo Heroicons outline, mismo criterio que iconos.php/icon() del PHP real.
function IconoCategoria({ path2, path1 }: { path1: string; path2?: string }) {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor" className="w-4 h-4">
      <path strokeLinecap="round" strokeLinejoin="round" d={path1} />
      {path2 && <path strokeLinecap="round" strokeLinejoin="round" d={path2} />}
    </svg>
  );
}

const CATEGORIAS: Record<CategoriaTicket, { label: string; icon: React.ReactNode; badgeClase: string }> = {
  tecnico: {
    label: "Error técnico",
    icon: <IconoCategoria path1="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />,
    badgeClase: "text-red-500",
  },
  chat: {
    label: "Problema con chat",
    icon: <IconoCategoria path1="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" />,
    badgeClase: "text-blue-500",
  },
  pago: {
    label: "Pago o cobro",
    icon: <IconoCategoria path1="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0018.75 4.5H5.25A2.25 2.25 0 003 6.75v10.5A2.25 2.25 0 005.25 19.5z" />,
    badgeClase: "text-emerald-500",
  },
  apunte: {
    label: "Apunte o servicio",
    icon: <IconoCategoria path1="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" />,
    badgeClase: "text-amber-500",
  },
  cuenta: {
    label: "Mi cuenta",
    icon: (
      <IconoCategoria
        path1="M17.982 18.725A7.488 7.488 0 0012 15.75a7.488 7.488 0 00-5.982 2.975m11.963 0a9 9 0 10-11.963 0m11.963 0A8.966 8.966 0 0112 21a8.966 8.966 0 01-5.982-2.275"
        path2="M15 9.75a3 3 0 11-6 0 3 3 0 016 0z"
      />
    ),
    badgeClase: "text-blue-500",
  },
  sugerencia: {
    label: "Sugerencia",
    icon: <IconoCategoria path1="M9 18h6m-5 3h4m.917-2.917a5.5 5.5 0 10-6.834 0m6.834 0a5.482 5.482 0 01-1.929 1.917A5.482 5.482 0 0112 21a5.482 5.482 0 01-3.088-.917 5.482 5.482 0 01-1.929-1.917m6.834 0V15" />,
    badgeClase: "text-purple-500",
  },
  otro: {
    label: "Otra consulta",
    icon: <IconoCategoria path1="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z" />,
    badgeClase: "text-gray-500",
  },
};

const ESTADO_CLASE: Record<string, string> = {
  pendiente: "bg-yellow-50 text-yellow-700",
  en_proceso: "bg-blue-50 text-blue-700",
  resuelto: "bg-green-50 text-green-700",
  cerrado: "bg-green-50 text-green-700",
};

type Filtro = "todos" | "activos" | "resueltos" | "no_leidos";

function IconoChevron({ abierto }: { abierto: boolean }) {
  return (
    <svg
      xmlns="http://www.w3.org/2000/svg"
      fill="none"
      viewBox="0 0 24 24"
      strokeWidth={2}
      stroke="currentColor"
      className={`w-3.5 h-3.5 text-gray-400 transition-transform duration-300 ${abierto ? "rotate-180" : ""}`}
    >
      <path strokeLinecap="round" strokeLinejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
    </svg>
  );
}

function IconoBasura() {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-3.5 h-3.5">
      <path
        strokeLinecap="round"
        strokeLinejoin="round"
        d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"
      />
    </svg>
  );
}

function formatearFechaHora(fechaIso: string): string {
  return new Date(fechaIso).toLocaleString("es-CL", { day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" });
}

export function SoporteLista({ ticketsIniciales }: { ticketsIniciales: Ticket[] }) {
  const router = useRouter();
  const [filtro, setFiltro] = useState<Filtro>("todos");
  const [abiertos, setAbiertos] = useState<Set<number>>(new Set());
  const [modalAbierto, setModalAbierto] = useState(false);
  const [categoriaNueva, setCategoriaNueva] = useState<CategoriaTicket>("otro");
  const [asuntoNuevo, setAsuntoNuevo] = useState("");
  const [mensajeNuevo, setMensajeNuevo] = useState("");
  const [enviando, setEnviando] = useState(false);
  const [respuestas, setRespuestas] = useState<Record<number, string>>({});
  const [error, setError] = useState<string | null>(null);

  const tickets = ticketsIniciales;
  const cntTotal = tickets.length;
  const cntActivos = tickets.filter((t) => t.estado !== "resuelto" && t.estado !== "cerrado").length;
  const cntResueltos = cntTotal - cntActivos;
  const cntNoLeidos = tickets.filter((t) => t.tieneRespuestaNueva).length;

  const ticketsFiltrados = tickets.filter((t) => {
    if (filtro === "todos") return true;
    if (filtro === "no_leidos") return t.tieneRespuestaNueva;
    const esResuelto = t.estado === "resuelto" || t.estado === "cerrado";
    return filtro === "resueltos" ? esResuelto : !esResuelto;
  });

  async function toggleAcordeon(ticket: Ticket) {
    setAbiertos((prev) => {
      const next = new Set(prev);
      if (next.has(ticket.id)) next.delete(ticket.id);
      else next.add(ticket.id);
      return next;
    });
    if (ticket.tieneRespuestaNueva) {
      await fetch(`/api/soporte/${ticket.id}/leido`, { method: "POST" });
      router.refresh();
    }
  }

  async function crearTicket(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    if (!asuntoNuevo.trim() || !mensajeNuevo.trim()) {
      setError("Debes completar el asunto y el mensaje.");
      return;
    }
    setEnviando(true);
    const res = await fetch("/api/soporte", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ asunto: asuntoNuevo, mensaje: mensajeNuevo, categoria: categoriaNueva }),
    });
    setEnviando(false);
    if (res.ok) {
      setModalAbierto(false);
      setAsuntoNuevo("");
      setMensajeNuevo("");
      setCategoriaNueva("otro");
      router.refresh();
    } else {
      const body = await res.json().catch(() => null);
      setError(body?.mensaje ?? "Error al enviar. Intenta más tarde.");
    }
  }

  async function responder(ticketId: number) {
    const mensaje = (respuestas[ticketId] ?? "").trim();
    if (!mensaje) return;
    const res = await fetch(`/api/soporte/${ticketId}/responder`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ mensaje }),
    });
    if (res.ok) {
      setRespuestas((prev) => ({ ...prev, [ticketId]: "" }));
      router.refresh();
    }
  }

  async function marcarResuelto(ticketId: number) {
    if (!confirm("¿Marcar este ticket como resuelto? Se cerrará de forma permanente.")) return;
    const res = await fetch(`/api/soporte/${ticketId}/resolver`, { method: "POST" });
    if (res.ok) router.refresh();
  }

  async function eliminarTicket(ticketId: number) {
    if (!confirm("¿Eliminar este ticket permanentemente?")) return;
    const res = await fetch("/api/soporte/eliminar", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ ids: [ticketId] }),
    });
    if (res.ok) router.refresh();
  }

  return (
    <div>
      <div className="mb-4 flex items-center justify-between gap-3">
        <div>
          <h1 className="text-xl md:text-2xl font-medium tracking-[-0.01em] text-[#222222]">Centro de Ayuda</h1>
          <p className="text-gray-400 text-xs font-medium mt-0.5">Resolvemos tus dudas y problemas.</p>
        </div>
        <button
          type="button"
          onClick={() => setModalAbierto(true)}
          className="bg-[#54A6D8] hover:bg-blue-600 text-white font-bold py-2.5 px-4 md:px-6 rounded-xl transition text-sm flex items-center gap-2"
        >
          + Nuevo ticket
        </button>
      </div>

      {cntTotal > 0 && (
        <div className="flex items-center gap-2 overflow-x-auto pb-1 mb-4">
          {(
            [
              ["todos", `Todos · ${cntTotal}`],
              ["activos", `Activos · ${cntActivos}`],
              ["resueltos", `Resueltos · ${cntResueltos}`],
              ...(cntNoLeidos > 0 ? [["no_leidos", `Sin leer · ${cntNoLeidos}`] as [Filtro, string]] : []),
            ] as [Filtro, string][]
          ).map(([f, label]) => (
            <button
              key={f}
              type="button"
              onClick={() => setFiltro(f)}
              className={`shrink-0 px-4 py-2 rounded-xl text-xs font-bold uppercase tracking-wide border transition-all ${
                filtro === f ? "bg-[#54A6D8] text-white border-[#54A6D8]" : "bg-white text-gray-500 border-gray-200 hover:bg-gray-50"
              }`}
            >
              {label}
            </button>
          ))}
        </div>
      )}

      {ticketsFiltrados.length === 0 ? (
        <div className="bg-white border border-gray-100 rounded-2xl p-10 text-center">
          <h3 className="text-base font-bold text-gray-900">{cntTotal === 0 ? "Aún no tienes tickets" : "No hay tickets aquí"}</h3>
          <p className="text-gray-500 text-sm mt-1">{cntTotal === 0 ? "Si necesitas ayuda, abre un ticket y te respondemos pronto." : "Prueba con otro filtro."}</p>
        </div>
      ) : (
        <div className="bg-white border border-gray-100 rounded-2xl overflow-hidden">
          <ul className="divide-y divide-gray-100">
            {ticketsFiltrados.map((t) => {
              const abierto = abiertos.has(t.id);
              const esResuelto = t.estado === "resuelto" || t.estado === "cerrado";
              const cat = CATEGORIAS[t.categoria];

              return (
                <li key={t.id}>
                  <div className="w-full p-4 md:p-5 flex items-center gap-3 md:gap-4 hover:bg-gray-50 transition-colors cursor-pointer" onClick={() => toggleAcordeon(t)}>
                    <div className={`shrink-0 w-10 h-10 rounded-xl bg-gray-50 border border-gray-100 flex items-center justify-center relative ${cat.badgeClase}`}>
                      {cat.icon}
                      {t.tieneRespuestaNueva && <span className="absolute -top-1 -right-1 w-2.5 h-2.5 rounded-full bg-[#54A6D8] border-2 border-white" />}
                    </div>

                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-2 mb-0.5 flex-wrap">
                        <span className={`${ESTADO_CLASE[t.estado] ?? "bg-gray-100 text-gray-600"} px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wide`}>
                          {t.estado.replace("_", " ")}
                        </span>
                        {t.tieneRespuestaNueva && <span className="bg-[#54A6D8] text-white px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wide">Nueva respuesta</span>}
                        <span className="text-[10px] text-gray-400 font-bold uppercase tracking-wide">{formatearFechaHora(t.fechaCreacion)}</span>
                      </div>
                      <h4 className="font-bold text-gray-900 text-sm truncate">{t.asunto}</h4>
                    </div>

                    <div className="shrink-0 flex items-center gap-1">
                      <button
                        type="button"
                        onClick={(e) => {
                          e.stopPropagation();
                          eliminarTicket(t.id);
                        }}
                        className="w-8 h-8 rounded-xl text-gray-400 hover:text-red-600 hover:bg-red-50 flex items-center justify-center transition-colors"
                        title="Eliminar ticket"
                      >
                        <IconoBasura />
                      </button>
                      <div className="w-8 h-8 rounded-xl bg-gray-50 border border-gray-100 flex items-center justify-center">
                        <IconoChevron abierto={abierto} />
                      </div>
                    </div>
                  </div>

                  {abierto && (
                    <div className="bg-gray-50/50 p-4 md:p-6 border-t border-gray-100">
                      <div className="flex flex-col gap-4 max-h-[500px] overflow-y-auto pb-4">
                        {t.hilo.map((msg, i) => {
                          const esUsuario = msg.remitente === "usuario";
                          return (
                            <div key={i} className={`flex flex-col ${esUsuario ? "items-end" : "items-start"} w-full`}>
                              <span className={`text-[10px] font-bold ${esUsuario ? "text-[#54A6D8]" : "text-gray-400"} uppercase tracking-wide mb-1 px-1`}>
                                {esUsuario ? "Tú" : "Soporte Nubira"} · {formatearFechaHora(msg.fecha)}
                              </span>
                              <div
                                className={`p-4 text-sm leading-relaxed max-w-[90%] md:max-w-[80%] break-words whitespace-pre-wrap ${
                                  esUsuario ? "bg-[#54A6D8] text-white rounded-2xl rounded-tr-sm" : "bg-white text-gray-700 border border-gray-200 rounded-2xl rounded-tl-sm"
                                }`}
                              >
                                {msg.mensaje}
                              </div>
                            </div>
                          );
                        })}
                      </div>

                      {esResuelto ? (
                        <div className="mt-4 text-center p-3 bg-green-50 rounded-xl border border-green-200 border-dashed text-xs font-bold text-green-700 uppercase tracking-wide">
                          Este ticket fue resuelto
                        </div>
                      ) : (
                        <>
                          <form
                            className="mt-2 bg-white border border-gray-200 rounded-xl focus-within:border-[#54A6D8] transition-all overflow-hidden flex items-end"
                            onSubmit={(e) => {
                              e.preventDefault();
                              responder(t.id);
                            }}
                          >
                            <textarea
                              rows={1}
                              placeholder="Escribe tu respuesta a soporte..."
                              required
                              maxLength={2000}
                              value={respuestas[t.id] ?? ""}
                              onChange={(e) => setRespuestas((prev) => ({ ...prev, [t.id]: e.target.value }))}
                              className="flex-1 bg-transparent border-none px-4 py-3 text-sm text-gray-900 outline-none resize-none placeholder-gray-400"
                            />
                            <button type="submit" className="shrink-0 bg-[#54A6D8] hover:bg-blue-600 text-white w-9 h-9 rounded-lg flex items-center justify-center transition-colors mb-1.5 mr-1.5">
                              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-3.5 h-3.5">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" />
                              </svg>
                            </button>
                          </form>
                          <div className="mt-3 text-center">
                            <button type="button" onClick={() => marcarResuelto(t.id)} className="text-xs font-bold text-green-600 hover:text-green-700 uppercase tracking-wide transition-colors">
                              Dar por resuelto
                            </button>
                          </div>
                        </>
                      )}
                    </div>
                  )}
                </li>
              );
            })}
          </ul>
        </div>
      )}

      {modalAbierto && (
        <div className="fixed inset-0 z-[80] bg-slate-900/50 flex items-end md:items-center justify-center p-0 md:p-4" onClick={() => setModalAbierto(false)}>
          <div className="w-full md:max-w-xl bg-white md:rounded-2xl rounded-t-2xl shadow-xl max-h-[92vh] flex flex-col overflow-hidden" onClick={(e) => e.stopPropagation()}>
            <div className="px-6 py-5 border-b border-gray-100 flex items-center justify-between shrink-0">
              <div>
                <h2 className="text-lg font-bold text-gray-900 mb-0.5">Nuevo ticket</h2>
                <p className="text-xs text-gray-500 font-medium">Cuéntanos qué sucede para poder ayudarte.</p>
              </div>
              <button type="button" onClick={() => setModalAbierto(false)} className="w-8 h-8 rounded-xl bg-gray-50 hover:bg-gray-100 border border-gray-200 flex items-center justify-center">
                ✕
              </button>
            </div>

            <form onSubmit={crearTicket} className="flex-1 overflow-y-auto p-6 space-y-6">
              {error && <div className="bg-red-50 border border-red-100 text-red-600 text-sm font-medium rounded-xl px-4 py-3">{error}</div>}

              <div>
                <label className="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-2">¿Qué tipo de problema es?</label>
                <div className="grid grid-cols-2 sm:grid-cols-3 gap-2">
                  {(Object.keys(CATEGORIAS) as CategoriaTicket[]).map((key) => (
                    <button
                      key={key}
                      type="button"
                      onClick={() => setCategoriaNueva(key)}
                      className={`px-3 py-2.5 rounded-xl border transition-all flex items-center gap-2 text-left ${
                        categoriaNueva === key ? "bg-[#54A6D8] border-[#54A6D8] text-white" : `bg-white border-gray-200 text-gray-700 ${CATEGORIAS[key].badgeClase}`
                      }`}
                    >
                      <span className={categoriaNueva === key ? "text-white" : CATEGORIAS[key].badgeClase}>{CATEGORIAS[key].icon}</span>
                      <span className={`text-xs font-bold truncate ${categoriaNueva === key ? "text-white" : "text-gray-700"}`}>{CATEGORIAS[key].label}</span>
                    </button>
                  ))}
                </div>
              </div>

              <div>
                <label className="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1.5">Asunto</label>
                <input
                  type="text"
                  required
                  maxLength={100}
                  value={asuntoNuevo}
                  onChange={(e) => setAsuntoNuevo(e.target.value)}
                  placeholder="Resume tu problema..."
                  className="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-gray-900 text-sm focus:ring-[#54A6D8] focus:border-[#54A6D8] transition outline-none"
                />
              </div>

              <div>
                <label className="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1.5">Descripción</label>
                <textarea
                  required
                  rows={4}
                  maxLength={2000}
                  value={mensajeNuevo}
                  onChange={(e) => setMensajeNuevo(e.target.value)}
                  placeholder="Cuéntanos con detalle..."
                  className="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-gray-900 text-sm focus:ring-[#54A6D8] focus:border-[#54A6D8] transition outline-none resize-none"
                />
              </div>

              <div className="flex items-center justify-end gap-3 pt-2">
                <button type="button" onClick={() => setModalAbierto(false)} className="bg-gray-100 hover:bg-gray-200 text-gray-700 py-2.5 px-6 rounded-xl font-bold transition text-sm">
                  Cancelar
                </button>
                <button type="submit" disabled={enviando} className="bg-[#54A6D8] hover:bg-blue-600 text-white font-bold py-2.5 px-6 rounded-xl transition text-sm disabled:opacity-60">
                  {enviando ? "Enviando..." : "Enviar ticket"}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
