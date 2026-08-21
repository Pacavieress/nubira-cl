"use client";

import { useMemo, useState } from "react";
import type { DominioPermitido } from "@/lib/api";

// Puerto de admin_dominios.php — gestor de instituciones/dominios de correo permitidos.
// Mejora deliberada sobre el PHP real (documentada, no un descuido): el PHP hace
// POST -> redirect -> reload completo de la página para cada acción; acá se actualiza la
// lista en memoria tras cada respuesta 2xx, sin recargar. Misma lógica de negocio
// (normalización de dominio, mayúsculas de institución, confirm() con aviso reforzado si
// tiene usuarios activos).
export function AdminDominiosPanel({ dominiosIniciales }: { dominiosIniciales: DominioPermitido[] }) {
  const [dominios, setDominios] = useState(dominiosIniciales);
  const [busqueda, setBusqueda] = useState("");
  const [nuevoNombre, setNuevoNombre] = useState("");
  const [nuevoDominio, setNuevoDominio] = useState("");
  const [enviando, setEnviando] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [editandoId, setEditandoId] = useState<number | null>(null);
  const [nombreEdicion, setNombreEdicion] = useState("");

  const filtrados = useMemo(() => {
    const q = busqueda.trim().toLowerCase();
    if (!q) return dominios;
    return dominios.filter((d) => `${d.institucion} ${d.dominio}`.toLowerCase().includes(q));
  }, [busqueda, dominios]);

  async function agregar(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setEnviando(true);
    try {
      const res = await fetch("/api/admin/dominios", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ dominio: nuevoDominio, institucion: nuevoNombre }),
      });
      const data = await res.json();
      if (!res.ok) {
        setError(data.mensaje ?? "No se pudo agregar la universidad.");
        return;
      }
      setDominios((prev) => [...prev, data].sort((a, b) => a.institucion.localeCompare(b.institucion)));
      setNuevoNombre("");
      setNuevoDominio("");
    } catch {
      setError("No se pudo agregar la universidad.");
    } finally {
      setEnviando(false);
    }
  }

  async function guardarEdicion(id: number) {
    const res = await fetch(`/api/admin/dominios/${id}`, {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ institucion: nombreEdicion }),
    });
    if (res.ok) {
      setDominios((prev) => prev.map((d) => (d.id === id ? { ...d, institucion: nombreEdicion.trim().toUpperCase() } : d)));
      setEditandoId(null);
    }
  }

  async function eliminar(dominio: DominioPermitido) {
    const confirmado = dominio.totalUsuarios > 0
      ? confirm(
          `¡ADVERTENCIA CRÍTICA!\n\nHay ${dominio.totalUsuarios} usuarios registrados bajo este dominio.\nSi lo eliminas, estos usuarios podrían perder acceso a sus cuentas o tener problemas.\n\n¿Estás 100% seguro de eliminarlo?`,
        )
      : confirm("¿Estás seguro de eliminar esta institución?");
    if (!confirmado) return;

    const res = await fetch(`/api/admin/dominios/${dominio.id}`, { method: "DELETE" });
    if (res.ok) {
      setDominios((prev) => prev.filter((d) => d.id !== dominio.id));
    }
  }

  return (
    <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <div className="lg:col-span-1">
        <div className="bg-white p-6 rounded-2xl border border-gray-100 lg:sticky lg:top-24">
          <h2 className="font-bold text-gray-800 mb-4">Nueva Institución</h2>
          <form onSubmit={agregar} className="space-y-4">
            <div>
              <label className="block text-xs font-bold text-gray-500 uppercase mb-1">Nombre Oficial</label>
              <input
                type="text"
                value={nuevoNombre}
                onChange={(e) => setNuevoNombre(e.target.value)}
                placeholder="Ej: U. DE SANTIAGO"
                required
                className="w-full px-4 py-2 border border-gray-200 rounded-lg uppercase focus:ring-2 focus:ring-blue-100 outline-none text-sm font-semibold text-gray-700"
              />
            </div>
            <div>
              <label className="block text-xs font-bold text-gray-500 uppercase mb-1">Dominio de Correo</label>
              <div className="relative">
                <span className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400 font-bold text-sm">@</span>
                <input
                  type="text"
                  value={nuevoDominio}
                  onChange={(e) => setNuevoDominio(e.target.value)}
                  placeholder="usach.cl"
                  required
                  className="w-full pl-7 pr-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-100 outline-none text-sm font-mono text-gray-600"
                />
              </div>
              <p className="text-[10px] text-gray-400 mt-1">Sin &quot;www&quot; ni &quot;http&quot; (se limpia automáticamente).</p>
            </div>
            {error && <p className="text-xs font-medium text-red-600">{error}</p>}
            <button
              type="submit"
              disabled={enviando}
              className="w-full bg-[#54A6D8] hover:bg-blue-500 text-white font-bold py-2.5 rounded-lg transition-all text-sm disabled:opacity-50"
            >
              {enviando ? "Agregando..." : "Habilitar Acceso"}
            </button>
          </form>
        </div>
      </div>

      <div className="lg:col-span-2">
        <div className="bg-white rounded-2xl border border-gray-100 overflow-hidden">
          <div className="bg-gray-50 px-6 py-3 border-b border-gray-100 flex items-center justify-between gap-3">
            <span className="font-bold text-xs text-gray-500 uppercase tracking-wider shrink-0">
              Listado Maestro <span className="bg-gray-200 text-gray-600 px-2 py-0.5 rounded text-[10px] font-bold ml-1">{dominios.length}</span>
            </span>
            <input
              type="text"
              value={busqueda}
              onChange={(e) => setBusqueda(e.target.value)}
              placeholder="Buscar universidad..."
              className="w-48 px-3 py-1.5 bg-white border border-gray-200 rounded-lg text-xs focus:ring-2 focus:ring-[#54A6D8] outline-none"
            />
          </div>

          <div className="overflow-x-auto max-h-[75vh]">
            <table className="w-full text-left text-sm">
              <tbody className="divide-y divide-gray-100">
                {filtrados.length === 0 ? (
                  <tr>
                    <td className="px-6 py-10 text-center text-gray-400 text-sm">
                      {dominios.length === 0 ? "No hay universidades registradas." : "No se encontraron coincidencias."}
                    </td>
                  </tr>
                ) : (
                  filtrados.map((d) => (
                    <tr key={d.id} className="hover:bg-gray-50 group transition-colors">
                      <td className="px-6 py-4">
                        {editandoId === d.id ? (
                          <div className="flex items-center gap-2">
                            <input
                              type="text"
                              value={nombreEdicion}
                              onChange={(e) => setNombreEdicion(e.target.value)}
                              className="flex-1 px-3 py-1.5 border border-[#54A6D8] rounded-lg uppercase text-sm font-semibold outline-none"
                              autoFocus
                            />
                            <button onClick={() => guardarEdicion(d.id)} className="text-xs font-bold text-[#54A6D8] px-2 py-1 hover:bg-blue-50 rounded">
                              Guardar
                            </button>
                            <button onClick={() => setEditandoId(null)} className="text-xs font-bold text-gray-400 px-2 py-1 hover:bg-gray-100 rounded">
                              Cancelar
                            </button>
                          </div>
                        ) : (
                          <div className="flex items-center gap-4">
                            <div
                              className={`w-10 h-10 rounded-full ${d.totalUsuarios > 0 ? "bg-blue-100 text-blue-600 border-blue-200" : "bg-gray-100 text-gray-400 border-gray-200"} flex items-center justify-center font-bold text-sm border shrink-0`}
                            >
                              {d.institucion.slice(0, 2)}
                            </div>
                            <div>
                              <div className="font-bold text-gray-800">{d.institucion}</div>
                              <div className="flex items-center gap-2 mt-0.5">
                                <span className="text-xs text-gray-500 font-mono bg-gray-100 px-1.5 rounded border border-gray-200">@{d.dominio}</span>
                                {d.totalUsuarios > 0 ? (
                                  <span className="text-[10px] text-green-600 bg-green-50 px-1.5 rounded font-bold border border-green-100">
                                    {d.totalUsuarios} usuario{d.totalUsuarios === 1 ? "" : "s"}
                                  </span>
                                ) : (
                                  <span className="text-[10px] text-gray-400 bg-gray-50 px-1.5 rounded border border-gray-100">Sin usuarios</span>
                                )}
                              </div>
                            </div>
                          </div>
                        )}
                      </td>
                      <td className="px-6 py-4 text-right">
                        {editandoId !== d.id && (
                          <div className="flex justify-end gap-2 opacity-100 md:opacity-0 md:group-hover:opacity-100 transition-opacity">
                            <button
                              onClick={() => {
                                setEditandoId(d.id);
                                setNombreEdicion(d.institucion);
                              }}
                              className="w-8 h-8 flex items-center justify-center text-gray-400 hover:text-blue-500 rounded-lg hover:bg-blue-50"
                              title="Editar"
                            >
                              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor" className="w-4 h-4">
                                <path strokeLinecap="round" strokeLinejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125" />
                              </svg>
                            </button>
                            <button
                              onClick={() => eliminar(d)}
                              className="w-8 h-8 flex items-center justify-center text-gray-300 hover:text-red-500 rounded-lg hover:bg-red-50"
                              title="Eliminar"
                            >
                              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor" className="w-4 h-4">
                                <path strokeLinecap="round" strokeLinejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                              </svg>
                            </button>
                          </div>
                        )}
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  );
}
