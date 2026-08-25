"use client";

import { useState } from "react";
import type { ServicioConOferta } from "@/lib/api";

interface FilaEstado {
  tipo: "porcentaje" | "precio";
  pct: string;
  precio: string;
  cupos: string;
  fecha: string;
}

function estadoInicial(): FilaEstado {
  return { tipo: "porcentaje", pct: "", precio: "", cupos: "", fecha: "" };
}

// Puerto de admin_ofertas.php ("Centro de Subsidios") — listado + las 2 mutaciones completas
// (aplicar oferta con toggle %/$, quitar oferta). Son UPDATE puros sobre `servicios`, sin
// efecto externo, mismo nivel de riesgo que adminOfertasApuntes ya portado. Toggle %/$ nativo
// de React en vez del toggle vía IDs por fila del PHP real (toggleTipoOferta(id, tipo)).
export function AdminOfertasPanel({ servicios: serviciosIniciales }: { servicios: ServicioConOferta[] }) {
  const [servicios, setServicios] = useState(serviciosIniciales);
  const [filas, setFilas] = useState<Record<number, FilaEstado>>({});
  const [guardando, setGuardando] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);

  function filaDe(id: number): FilaEstado {
    return filas[id] ?? estadoInicial();
  }

  function actualizarFila(id: number, cambios: Partial<FilaEstado>) {
    setFilas((prev) => ({ ...prev, [id]: { ...filaDe(id), ...cambios } }));
  }

  function actualizarLocal(id: number, cambios: Partial<ServicioConOferta>) {
    setServicios((prev) => prev.map((s) => (s.id === id ? { ...s, ...cambios } : s)));
  }

  async function aplicarOferta(s: ServicioConOferta) {
    const fila = filaDe(s.id);
    const cupos = Number(fila.cupos);
    if (!Number.isInteger(cupos) || cupos <= 0) {
      setError("Ingresa la cantidad de cupos.");
      return;
    }
    if (fila.tipo === "porcentaje") {
      const pct = Number(fila.pct);
      if (!Number.isInteger(pct) || pct <= 0 || pct >= 100) {
        setError("Ingresa un porcentaje válido (1-99).");
        return;
      }
    } else {
      const precio = Number(fila.precio);
      if (!Number.isInteger(precio) || precio < 0) {
        setError("Ingresa un precio subsidiado válido.");
        return;
      }
    }

    setError(null);
    setGuardando(s.id);
    try {
      const res = await fetch(`/api/admin/ofertas/${s.id}/aplicar-oferta`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          tipo: fila.tipo,
          pctOferta: fila.tipo === "porcentaje" ? Number(fila.pct) : null,
          precioOferta: fila.tipo === "precio" ? Number(fila.precio) : null,
          cupos,
          ofertaTermino: fila.fecha || null,
        }),
      });
      const data = await res.json();
      if (!res.ok) {
        setError(data.mensaje ?? "Datos inválidos. Verifica el porcentaje o precio y los cupos.");
        return;
      }
      actualizarLocal(s.id, { precioOferta: data.precioOferta, cuposOferta: data.cupos, isSubvencionado: true, ofertaTermino: data.ofertaTermino });
      setFilas((prev) => ({ ...prev, [s.id]: estadoInicial() }));
    } catch {
      setError("No se pudo aplicar la oferta.");
    } finally {
      setGuardando(null);
    }
  }

  async function quitarOferta(s: ServicioConOferta) {
    if (!confirm("¿Seguro que deseas retirar el subsidio y devolver el servicio a su precio normal?")) return;
    setError(null);
    setGuardando(s.id);
    try {
      const res = await fetch(`/api/admin/ofertas/${s.id}/quitar-oferta`, { method: "POST" });
      if (res.ok) actualizarLocal(s.id, { precioOferta: null, cuposOferta: 0, isSubvencionado: false, ofertaTermino: null });
      else setError("No se pudo desactivar la oferta.");
    } finally {
      setGuardando(null);
    }
  }

  return (
    <div className="space-y-3">
      {error && <div className="bg-red-50 text-red-600 p-3 rounded-xl text-sm font-medium border border-red-200">{error}</div>}

      <div className="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm whitespace-nowrap">
            <thead className="bg-gray-50/80 text-gray-500 text-xs uppercase font-bold tracking-wider border-b border-gray-100">
              <tr>
                <th className="px-6 py-4">Servicio y Tutor</th>
                <th className="px-6 py-4">Tarifa Normal</th>
                <th className="px-6 py-4">Descuento</th>
                <th className="px-6 py-4">Termina</th>
                <th className="px-6 py-4">Estado Actual</th>
                <th className="px-6 py-4 text-right">Panel de Control</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-50">
              {servicios.length === 0 ? (
                <tr>
                  <td colSpan={6} className="px-6 py-12 text-center text-gray-400 font-medium">
                    No hay servicios aprobados en la plataforma aún.
                  </td>
                </tr>
              ) : (
                servicios.map((s) => {
                  const hoy = new Date().toISOString().slice(0, 10);
                  const vencida = s.isSubvencionado && !!s.ofertaTermino && s.ofertaTermino < hoy;
                  const pct = s.isSubvencionado && s.precioOferta && s.precio > 0 ? Math.round(((s.precio - s.precioOferta) / s.precio) * 100) : null;
                  const fila = filaDe(s.id);

                  return (
                    <tr key={s.id} className={`hover:bg-gray-50/50 transition-colors ${s.isSubvencionado && !vencida ? "bg-orange-50/10" : ""}`}>
                      <td className="px-6 py-4">
                        <p className="font-bold text-gray-900 line-clamp-1 max-w-[300px] whitespace-normal leading-tight">{s.titulo}</p>
                        <p className="text-xs text-gray-500 mt-0.5">Por {s.tutorNombre}</p>
                        {s.categoria && <p className="text-[11px] text-gray-400 font-medium mt-0.5">{s.categoria}</p>}
                      </td>

                      <td className="px-6 py-4 font-bold text-gray-600">${s.precio.toLocaleString("es-CL")}</td>

                      <td className="px-6 py-4 font-bold text-gray-700">{pct !== null ? `-${pct}%` : <span className="text-gray-300 font-normal">—</span>}</td>

                      <td className="px-6 py-4 text-sm text-gray-600">
                        {s.ofertaTermino ? new Date(s.ofertaTermino).toLocaleDateString("es-CL") : <span className="text-gray-300">—</span>}
                      </td>

                      <td className="px-6 py-4">
                        {vencida ? (
                          <span className="inline-flex items-center px-2.5 py-1 rounded-md text-[10px] font-bold uppercase tracking-wide bg-gray-100 text-gray-400">Expirada</span>
                        ) : s.isSubvencionado && s.cuposOferta > 0 ? (
                          <div className="inline-flex flex-col gap-1">
                            <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-[10px] font-black uppercase tracking-wide bg-gradient-to-r from-orange-400 to-orange-500 text-white shadow-sm shadow-orange-200">
                              Subvencionado
                            </span>
                            <span className="text-xs font-bold text-[#54A6D8]">
                              ${(s.precioOferta ?? 0).toLocaleString("es-CL")} <span className="text-gray-400 font-medium">| Quedan {s.cuposOferta} cupos</span>
                            </span>
                          </div>
                        ) : s.isSubvencionado && s.cuposOferta <= 0 ? (
                          <span className="inline-flex items-center px-2.5 py-1 rounded-md text-[10px] font-bold uppercase tracking-wide bg-red-100 text-red-600">Agotado</span>
                        ) : (
                          <span className="inline-flex items-center px-2.5 py-1 rounded-md text-[10px] font-bold uppercase tracking-wide bg-gray-100 text-gray-500">Normal</span>
                        )}
                      </td>

                      <td className="px-6 py-4 text-right">
                        {s.isSubvencionado ? (
                          <button
                            type="button"
                            onClick={() => quitarOferta(s)}
                            disabled={guardando === s.id}
                            className="text-red-500 hover:text-white hover:bg-red-500 border border-red-200 hover:border-red-500 font-bold text-xs px-4 py-2 rounded-xl transition-all shadow-sm disabled:opacity-50"
                          >
                            Apagar Oferta
                          </button>
                        ) : (
                          <div className="flex items-center justify-end gap-2 flex-wrap">
                            <div className="flex rounded-lg border border-gray-200 overflow-hidden text-xs font-bold shadow-sm">
                              <button
                                type="button"
                                onClick={() => actualizarFila(s.id, { tipo: "porcentaje" })}
                                className={`px-3 py-2 transition-colors ${fila.tipo === "porcentaje" ? "bg-[#54A6D8] text-white" : "bg-white text-gray-500"}`}
                              >
                                %
                              </button>
                              <button
                                type="button"
                                onClick={() => actualizarFila(s.id, { tipo: "precio" })}
                                className={`px-3 py-2 transition-colors ${fila.tipo === "precio" ? "bg-[#54A6D8] text-white" : "bg-white text-gray-500"}`}
                              >
                                $
                              </button>
                            </div>

                            {fila.tipo === "porcentaje" ? (
                              <div className="relative">
                                <span className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 font-bold text-xs">%</span>
                                <input
                                  type="number"
                                  placeholder="20"
                                  min={1}
                                  max={99}
                                  value={fila.pct}
                                  onChange={(e) => actualizarFila(s.id, { pct: e.target.value })}
                                  title="Porcentaje de descuento"
                                  className="w-20 pl-7 pr-3 py-2 text-sm font-bold text-gray-900 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-[#54A6D8] focus:border-transparent transition-all shadow-sm"
                                />
                              </div>
                            ) : (
                              <div className="relative">
                                <span className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 font-bold text-xs">$</span>
                                <input
                                  type="number"
                                  placeholder="0"
                                  min={0}
                                  max={s.precio - 1}
                                  value={fila.precio}
                                  onChange={(e) => actualizarFila(s.id, { precio: e.target.value })}
                                  title="Precio subsidiado"
                                  className="w-24 pl-6 pr-3 py-2 text-sm font-bold text-gray-900 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-[#54A6D8] focus:border-transparent transition-all shadow-sm"
                                />
                              </div>
                            )}

                            <div className="relative">
                              <span className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs">#</span>
                              <input
                                type="number"
                                placeholder="Cupos"
                                min={1}
                                value={fila.cupos}
                                onChange={(e) => actualizarFila(s.id, { cupos: e.target.value })}
                                title="Cantidad de usos"
                                className="w-24 pl-6 pr-3 py-2 text-sm font-bold text-gray-900 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-[#54A6D8] focus:border-transparent transition-all shadow-sm"
                              />
                            </div>

                            <input
                              type="date"
                              min={hoy}
                              value={fila.fecha}
                              onChange={(e) => actualizarFila(s.id, { fecha: e.target.value })}
                              title="Fecha de término (opcional)"
                              className="py-2 px-3 text-sm text-gray-700 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-[#54A6D8] focus:border-transparent transition-all shadow-sm"
                            />

                            <button
                              type="button"
                              onClick={() => aplicarOferta(s)}
                              disabled={guardando === s.id}
                              className="bg-gradient-to-r from-sky-400 to-[#54A6D8] text-white px-4 py-2 rounded-xl text-xs font-bold shadow-md hover:shadow-lg hover:shadow-blue-200 transform hover:scale-[1.02] active:scale-95 transition-all disabled:opacity-50"
                            >
                              Inyectar
                            </button>
                          </div>
                        )}
                      </td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
