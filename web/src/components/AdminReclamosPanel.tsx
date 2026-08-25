"use client";

import { useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import type { EstadoFiltroReclamos, MensajeHiloReclamo, TicketReclamo } from "@/lib/api";

interface Props {
  tickets: TicketReclamo[];
  estadoFiltro: EstadoFiltroReclamos;
}

// Puerto de admin_reclamos.php: format_name_privacy() (líneas 47-54).
function formatearNombrePrivado(nombreCompleto: string): string {
  const partes = nombreCompleto.trim().split(/\s+/).filter(Boolean);
  if (partes.length === 0) return "Usuario";
  const primero = partes[0].charAt(0).toUpperCase() + partes[0].slice(1).toLowerCase();
  const inicialUltimo = partes.length > 1 ? ` ${partes[partes.length - 1].charAt(0).toUpperCase()}.` : "";
  return primero + inicialUltimo;
}

// Puerto de admin_reclamos.php:341-347 (asunto) — mismo separador ":\n" que reclamos_sugerencias.php
// usa para anteponer la categoría al texto libre del ticket.
function extraerAsunto(texto: string): string {
  const idx = texto.indexOf(":\n");
  return idx !== -1 ? texto.slice(0, idx) : "Soporte General";
}

// Puerto de admin_reclamos.php:334-349 para la vista previa de la fila (preview_final).
// NOTA DE FIDELIDAD: el PHP real intenta un segundo strip del prefijo "Categoría:\n" sobre
// $texto_bruto (línea 345), pero eso ocurre DESPUÉS de reemplazar los saltos de línea reales
// por espacios (línea 339) — el separador ":\n" ya no existe en la cadena en ese punto, así que
// ese `explode` nunca matchea y la rama es efectivamente no-op en producción hoy. Se replica
// ese comportamiento tal cual (sin el segundo strip) en vez de "arreglarlo" silenciosamente.
function vistaPrevia(t: TicketReclamo): { asunto: string; preview: string } {
  const ultimo = t.chatThread[t.chatThread.length - 1];
  const prefijo = ultimo.remitente === "admin" ? "Tú: " : "";
  const textoBruto = ultimo.mensaje.replace(/\n/g, " ");
  return { asunto: extraerAsunto(t.texto), preview: (prefijo + textoBruto).trim() };
}

// Puerto de admin_reclamos.php:417-423 (texto de la burbuja del primer mensaje del hilo): a
// diferencia de vistaPrevia(), acá SÍ opera sobre el mensaje original (con \n real todavía
// presente), así que el strip del prefijo "Categoría:\n" sí funciona.
function textoBurbuja(msg: MensajeHiloReclamo, esPrimero: boolean): string {
  if (msg.remitente === "usuario" && esPrimero) {
    const idx = msg.mensaje.indexOf(":\n");
    if (idx !== -1) return msg.mensaje.slice(idx + 2);
  }
  return msg.mensaje;
}

// Puerto de admin_reclamos.php ("Gestión de Reclamos"). Escritura completa: responder, cerrar,
// papelera/restaurar/eliminar_hard (fila y en lote) — mutaciones internas puras sobre
// reclamos_sugerencias/reclamos_mensajes, sin correo ni push. Tras cada mutación se usa
// router.refresh() para re-leer la lista desde el servidor, el mismo efecto neto que el patrón
// PRG (POST -> redirect -> GET) del PHP real, en vez de reconciliar estado local a mano.
//
// Deviaciones documentadas respecto al PHP real:
// - Sin gesto de long-press táctil/mouse para entrar en modo selección (solo el botón
//   explícito "Seleccionar") — el long-press era una segunda entrada al mismo modo, no una
//   función distinta.
// - La foto de tutor SIEMPRE muestra el avatar con inicial: admin_reclamos.php:363 arma el
//   <img src> sin el prefijo "/app/perfil/fotos/" que sí usa header.php:138, así que en
//   producción esa imagen siempre falla a cargar y cae al fallback de inicial — se replica el
//   resultado visual real (inicial) en vez de mostrar una foto que el sitio real nunca muestra.
export function AdminReclamosPanel({ tickets, estadoFiltro }: Props) {
  const router = useRouter();
  const [busqueda, setBusqueda] = useState("");
  const [modoSeleccion, setModoSeleccion] = useState(false);
  const [seleccionados, setSeleccionados] = useState<Set<number>>(new Set());
  const [expandidoId, setExpandidoId] = useState<number | null>(null);
  const [borradores, setBorradores] = useState<Record<number, string>>({});
  const [procesando, setProcesando] = useState<number | "bulk" | null>(null);
  const [error, setError] = useState<string | null>(null);

  const ticketsConVista = useMemo(
    () =>
      tickets.map((t) => {
        const { asunto, preview } = vistaPrevia(t);
        const nombrePrivado = formatearNombrePrivado(t.usuarioNombre);
        const searchData = `${t.usuarioNombre} ${t.texto} ${preview}`.toLowerCase();
        const urgenteVisible = t.urgente;
        return { t, asunto, preview, nombrePrivado, searchData, urgenteVisible };
      }),
    [tickets],
  );

  const visibles = useMemo(() => {
    const term = busqueda.trim().toLowerCase();
    if (term === "") return ticketsConVista;
    return ticketsConVista.filter((x) => x.searchData.includes(term));
  }, [ticketsConVista, busqueda]);

  function salirModoSeleccion() {
    setModoSeleccion(false);
    setSeleccionados(new Set());
  }

  function alternarSeleccionTodos() {
    if (seleccionados.size === visibles.length && visibles.length > 0) {
      setSeleccionados(new Set());
    } else {
      setSeleccionados(new Set(visibles.map((x) => x.t.id)));
    }
  }

  function alternarFila(id: number) {
    setSeleccionados((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }

  async function llamar(url: string, opts?: RequestInit): Promise<boolean> {
    setError(null);
    try {
      const res = await fetch(url, opts);
      if (!res.ok) {
        setError("No se pudo completar la acción.");
        return false;
      }
      return true;
    } catch {
      setError("No se pudo completar la acción.");
      return false;
    }
  }

  async function responder(id: number) {
    const respuesta = (borradores[id] ?? "").trim();
    if (respuesta === "") return;
    setProcesando(id);
    const ok = await llamar(`/api/admin/reclamos/${id}/responder`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ respuesta }),
    });
    setProcesando(null);
    if (ok) {
      setBorradores((prev) => ({ ...prev, [id]: "" }));
      router.refresh();
    }
  }

  async function resolver(id: number) {
    setProcesando(id);
    const ok = await llamar(`/api/admin/reclamos/${id}/resolver`, { method: "POST" });
    setProcesando(null);
    if (ok) router.refresh();
  }

  async function papelera(id: number) {
    if (!confirm("¿Mover este ticket a la papelera?")) return;
    setProcesando(id);
    const ok = await llamar(`/api/admin/reclamos/${id}/papelera`, { method: "POST" });
    setProcesando(null);
    if (ok) router.refresh();
  }

  async function restaurar(id: number) {
    if (!confirm("¿Restaurar este ticket?")) return;
    setProcesando(id);
    const ok = await llamar(`/api/admin/reclamos/${id}/restaurar`, { method: "POST" });
    setProcesando(null);
    if (ok) router.refresh();
  }

  async function eliminarHard(id: number) {
    if (!confirm("¿ELIMINAR DEFINITIVAMENTE DE LA BD? Esto borrará el ticket y sus respuestas sin posibilidad de recuperación.")) return;
    setProcesando(id);
    const ok = await llamar(`/api/admin/reclamos/${id}`, { method: "DELETE" });
    setProcesando(null);
    if (ok) router.refresh();
  }

  async function accionLote(accion: "papelera" | "restaurar" | "eliminar_hard") {
    const ids = Array.from(seleccionados);
    if (ids.length === 0) return;
    const mensajes: Record<string, string> = {
      papelera: "¿Mover tickets seleccionados a la papelera?",
      restaurar: "¿Restaurar los tickets seleccionados?",
      eliminar_hard: "⚠️ ATENCIÓN: ¿Borrar DEFINITIVAMENTE los tickets seleccionados de la base de datos? Esto no se puede deshacer.",
    };
    if (!confirm(mensajes[accion])) return;
    setProcesando("bulk");
    const ok = await llamar(`/api/admin/reclamos/bulk`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ ids, accion }),
    });
    setProcesando(null);
    if (ok) {
      salirModoSeleccion();
      router.refresh();
    }
  }

  return (
    <div className="space-y-4">
      {error && <div className="bg-red-50 text-red-600 p-3 rounded-xl text-sm font-medium border border-red-200">{error}</div>}

      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div className="relative flex-1 max-w-sm">
          <input
            type="search"
            placeholder="Buscar ticket o usuario..."
            value={busqueda}
            onChange={(e) => {
              setBusqueda(e.target.value);
              salirModoSeleccion();
            }}
            className="w-full bg-gray-50 border border-gray-200 rounded-xl pl-4 pr-4 py-2.5 text-sm font-medium text-gray-900 placeholder:text-gray-400 focus:ring-1 focus:ring-[#54A6D8] focus:border-[#54A6D8] outline-none transition-all"
          />
        </div>
        <button
          type="button"
          onClick={() => (modoSeleccion ? salirModoSeleccion() : setModoSeleccion(true))}
          className="bg-white hover:bg-gray-50 text-gray-700 font-semibold text-sm px-4 py-2.5 rounded-xl border border-gray-200 transition-all active:scale-95 shrink-0"
        >
          {modoSeleccion ? "Cancelar" : "Seleccionar"}
        </button>
      </div>

      <div className="bg-white md:rounded-2xl border-y md:border border-gray-100 overflow-hidden">
        {modoSeleccion && (
          <div className="flex items-center px-4 md:px-5 py-3 border-b border-gray-100 bg-blue-50/50">
            <label className="flex items-center gap-3 cursor-pointer select-none">
              <input
                type="checkbox"
                checked={visibles.length > 0 && seleccionados.size === visibles.length}
                onChange={alternarSeleccionTodos}
                className="w-4 h-4 text-[#54A6D8] border-gray-300 rounded focus:ring-[#54A6D8] accent-[#54A6D8]"
              />
              <span className="text-xs font-bold text-gray-800 uppercase tracking-wide">
                {seleccionados.size === visibles.length && visibles.length > 0 ? "Deseleccionar Todos" : "Seleccionar Todos"}
              </span>
            </label>
          </div>
        )}

        {visibles.length === 0 ? (
          <div className="p-12 text-center flex flex-col items-center">
            <h3 className="text-gray-900 font-bold text-sm">Bandeja limpia</h3>
            <p className="text-xs text-gray-500 mt-1">No hay tickets en este estado.</p>
          </div>
        ) : (
          <ul className="divide-y divide-gray-100">
            {visibles.map(({ t, asunto, preview, nombrePrivado, urgenteVisible }) => {
              const abierto = expandidoId === t.id;
              const ultimo = t.chatThread[t.chatThread.length - 1];
              const seleccionado = seleccionados.has(t.id);

              return (
                <li key={t.id} className="bg-white">
                  <div className="flex items-center w-full px-4 md:px-5 py-4">
                    {modoSeleccion && (
                      <div className="shrink-0 mr-3">
                        <input
                          type="checkbox"
                          checked={seleccionado}
                          onChange={() => alternarFila(t.id)}
                          className="w-4 h-4 text-[#54A6D8] border-gray-300 rounded focus:ring-[#54A6D8] accent-[#54A6D8]"
                        />
                      </div>
                    )}

                    <button
                      type="button"
                      onClick={() => (modoSeleccion ? alternarFila(t.id) : setExpandidoId(abierto ? null : t.id))}
                      className="flex-1 flex items-center text-left min-w-0 cursor-pointer"
                    >
                      <div className="relative shrink-0 mr-3">
                        <div className="w-10 h-10 rounded-xl bg-[#54A6D8] flex items-center justify-center text-white font-bold">
                          {t.usuarioNombre.charAt(0)}
                        </div>
                        {urgenteVisible && <span className="absolute -top-1 -right-1 w-3 h-3 rounded-full bg-red-500 border-2 border-white" />}
                      </div>

                      <div className="flex-1 min-w-0 pr-4">
                        <div className="flex justify-between items-end mb-0.5">
                          <h4 className="text-sm font-bold text-gray-900 truncate">{nombrePrivado}</h4>
                          <span className="text-[10px] font-bold text-gray-400 uppercase tracking-wide shrink-0">
                            {new Date(ultimo.fecha).toLocaleString("es-CL", { day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit" })}
                          </span>
                        </div>
                        <p className="text-xs text-gray-900 font-semibold truncate">{asunto}</p>
                        <p className={`text-xs truncate ${ultimo.remitente === "usuario" ? "text-gray-800 font-medium" : "text-gray-500 font-normal"}`}>
                          {preview}
                        </p>
                      </div>
                    </button>

                    {!modoSeleccion && (
                      <div className="shrink-0 flex items-center justify-end gap-1">
                        {estadoFiltro === "eliminado" ? (
                          <>
                            <button
                              type="button"
                              onClick={() => restaurar(t.id)}
                              disabled={procesando === t.id}
                              title="Restaurar ticket"
                              className="w-8 h-8 rounded-xl text-gray-400 hover:text-green-600 hover:bg-green-50 flex items-center justify-center transition-colors disabled:opacity-50"
                            >
                              ↺
                            </button>
                            <button
                              type="button"
                              onClick={() => eliminarHard(t.id)}
                              disabled={procesando === t.id}
                              title="Borrado físico de BD"
                              className="w-8 h-8 rounded-xl text-gray-400 hover:text-red-600 hover:bg-red-50 flex items-center justify-center transition-colors disabled:opacity-50"
                            >
                              ✕
                            </button>
                          </>
                        ) : (
                          <button
                            type="button"
                            onClick={() => papelera(t.id)}
                            disabled={procesando === t.id}
                            title="Eliminar ticket"
                            className="w-8 h-8 rounded-xl text-gray-400 hover:text-red-600 hover:bg-red-50 flex items-center justify-center transition-colors disabled:opacity-50"
                          >
                            ✕
                          </button>
                        )}
                      </div>
                    )}
                  </div>

                  {abierto && !modoSeleccion && (
                    <div className="bg-gray-50/50 border-t border-gray-100">
                      <div className="p-4 md:p-6">
                        <div className="flex flex-col gap-4 max-h-[400px] overflow-y-auto pb-4">
                          {t.chatThread.map((msg, i) => {
                            const esAdmin = msg.remitente === "admin";
                            return (
                              <div key={i} className={`flex flex-col w-full ${esAdmin ? "items-end" : "items-start"}`}>
                                <span className={`text-[10px] font-bold mb-1 uppercase tracking-wide px-1 ${esAdmin ? "text-[#54A6D8]" : "text-gray-500"}`}>
                                  {esAdmin ? "Tú" : nombrePrivado} • {new Date(msg.fecha).toLocaleTimeString("es-CL", { hour: "2-digit", minute: "2-digit" })}
                                </span>
                                <div
                                  className={`p-4 rounded-2xl text-[13px] font-medium max-w-[90%] md:max-w-[80%] break-words whitespace-pre-line ${
                                    esAdmin ? "bg-[#54A6D8] text-white rounded-tr-sm" : "bg-white border border-gray-200 text-gray-700 rounded-tl-sm"
                                  }`}
                                >
                                  {textoBurbuja(msg, i === 0).trim()}
                                </div>
                              </div>
                            );
                          })}
                        </div>

                        <div className="mt-2 bg-white rounded-xl border border-gray-200 focus-within:border-[#54A6D8] focus-within:ring-1 focus-within:ring-[#54A6D8] transition-all flex flex-col">
                          <textarea
                            rows={1}
                            placeholder="Respuesta oficial..."
                            value={borradores[t.id] ?? ""}
                            onChange={(e) => setBorradores((prev) => ({ ...prev, [t.id]: e.target.value }))}
                            className="w-full bg-transparent border-none px-4 py-3 text-sm text-gray-900 outline-none resize-none placeholder-gray-400"
                          />
                          <div className="flex items-center justify-between px-3 pb-2 pt-1 mt-1">
                            <button
                              type="button"
                              onClick={() => resolver(t.id)}
                              disabled={procesando === t.id}
                              className="text-xs font-bold text-gray-500 hover:text-green-600 uppercase tracking-wide px-3 py-2 transition-colors rounded-lg disabled:opacity-50"
                            >
                              Cerrar ticket
                            </button>
                            <button
                              type="button"
                              onClick={() => responder(t.id)}
                              disabled={procesando === t.id || (borradores[t.id] ?? "").trim() === ""}
                              className="bg-gray-900 hover:bg-black text-white font-bold py-2 px-5 rounded-lg text-xs uppercase tracking-wide transition-all disabled:opacity-50"
                            >
                              Enviar
                            </button>
                          </div>
                        </div>
                      </div>
                    </div>
                  )}
                </li>
              );
            })}
          </ul>
        )}
      </div>

      {modoSeleccion && seleccionados.size > 0 && (
        <div className="fixed bottom-20 md:bottom-6 left-4 right-4 md:left-auto md:right-6 md:w-auto z-40">
          <div className="bg-gray-900 text-white rounded-2xl shadow-lg pl-4 pr-4 py-3 flex items-center justify-between gap-4 md:gap-6 md:max-w-md md:ml-auto border border-gray-800">
            <div className="flex items-center gap-3">
              <button type="button" onClick={salirModoSeleccion} className="w-10 h-10 flex items-center justify-center rounded-xl bg-white/10 hover:bg-white/20 transition-colors">
                ✕
              </button>
              <div className="hidden sm:block">
                <div className="text-sm font-bold">{seleccionados.size} items</div>
                <div className="text-[10px] text-gray-400 font-bold uppercase tracking-wide">Seleccionados</div>
              </div>
            </div>

            <div className="flex items-center gap-2">
              {estadoFiltro === "eliminado" ? (
                <>
                  <button
                    type="button"
                    onClick={() => accionLote("restaurar")}
                    disabled={procesando === "bulk"}
                    className="text-green-400 hover:text-green-300 text-xs font-bold uppercase tracking-wide px-3 py-2 transition-colors bg-white/10 hover:bg-white/20 rounded-xl disabled:opacity-50"
                  >
                    Restaurar
                  </button>
                  <button
                    type="button"
                    onClick={() => accionLote("eliminar_hard")}
                    disabled={procesando === "bulk"}
                    className="text-red-400 hover:text-red-300 text-xs font-bold uppercase tracking-wide px-3 py-2 transition-colors bg-white/10 hover:bg-white/20 rounded-xl disabled:opacity-50"
                  >
                    Borrar DB
                  </button>
                </>
              ) : (
                <button
                  type="button"
                  onClick={() => accionLote("papelera")}
                  disabled={procesando === "bulk"}
                  className="text-red-400 hover:text-red-300 text-xs font-bold uppercase tracking-wide px-3 py-2 transition-colors bg-white/10 hover:bg-white/20 rounded-xl disabled:opacity-50"
                >
                  A Papelera
                </button>
              )}
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
