"use client";

import { useState } from "react";
import type { ContratoAdmin } from "@/lib/api";
import { formatoCLP } from "@/lib/formato";

const BADGE: Record<string, string> = {
  pendiente_pago: "bg-yellow-50 text-yellow-700 border border-yellow-200",
  en_progreso: "bg-sky-50 text-[#54A6D8] border border-sky-200",
  liberado: "bg-green-50 text-green-700 border border-green-200",
  cancelado: "bg-red-50 text-red-700 border border-red-200",
};

// Puerto de admin_contratos.php — tabla + modal de detalle + liberar/cancelar/revertir
// [26/08/2026, Grupo de Contratación]. "Eliminar" (borrado permanente) sigue fuera de
// alcance — ver nota en server/src/modules/adminContratos/adminContratos.types.ts. El link
// "Ver Chat" navega al sitio PHP real, sin mutar nada.
export function AdminContratosPanel({ contratos: contratosIniciales, phpSiteUrl }: { contratos: ContratoAdmin[]; phpSiteUrl: string }) {
  const [contratos, setContratos] = useState(contratosIniciales);
  const [seleccionado, setSeleccionado] = useState<ContratoAdmin | null>(null);
  const [procesando, setProcesando] = useState<number | null>(null);

  async function ejecutarAccion(c: ContratoAdmin, accion: "liberar" | "cancelar" | "revertir", confirmacion: string) {
    if (!window.confirm(confirmacion)) return;
    setProcesando(c.id);
    try {
      const res = await fetch(`/api/admin/contratos/${c.id}/${accion}`, { method: "POST" });
      const data = (await res.json().catch(() => null)) as { ok?: boolean } | null;
      if (res.ok && data?.ok) {
        const nuevoEstado = accion === "revertir" ? "en_progreso" : accion === "liberar" ? "liberado" : "cancelado";
        setContratos((prev) => prev.map((x) => (x.id === c.id ? { ...x, estado: nuevoEstado } : x)));
        setSeleccionado((prev) => (prev && prev.id === c.id ? { ...prev, estado: nuevoEstado } : prev));
      } else {
        window.alert("No se pudo completar la acción. El contrato puede haber cambiado de estado — recarga la página.");
      }
    } finally {
      setProcesando(null);
    }
  }

  return (
    <>
      <div className="bg-white border border-gray-100 rounded-2xl overflow-hidden">
        {contratos.length === 0 ? (
          <div className="p-16 text-center text-gray-400">No se encontraron contratos.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse">
              <thead>
                <tr className="bg-gray-50 border-b border-gray-100 text-xs font-bold text-gray-500 uppercase tracking-wider">
                  <th className="px-6 py-4">ID / Fecha</th>
                  <th className="px-6 py-4">Servicio</th>
                  <th className="px-6 py-4">Involucrados</th>
                  <th className="px-6 py-4">Monto</th>
                  <th className="px-6 py-4">Estado</th>
                  <th className="px-6 py-4 text-right">Detalle</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100 text-sm">
                {contratos.map((c) => (
                  <tr key={c.id} className="hover:bg-gray-50 transition-colors">
                    <td className="px-6 py-4">
                      <div className="font-mono text-gray-400 text-xs">#{c.id}</div>
                      <div className="text-xs font-medium text-gray-500 mt-0.5">
                        {new Date(c.fechaCreacion).toLocaleDateString("es-CL", { day: "2-digit", month: "short" })}
                      </div>
                    </td>
                    <td className="px-6 py-4">
                      <div className="font-bold text-gray-900 line-clamp-1 max-w-[200px]">{c.servicioTitulo}</div>
                      {c.conversacionId ? (
                        <a href={`${phpSiteUrl}/admin/chats?id=${c.conversacionId}`} target="_blank" rel="noopener noreferrer" className="text-[10px] text-purple-500 font-bold hover:underline mt-1 inline-block">
                          Ver Chat
                        </a>
                      ) : null}
                    </td>
                    <td className="px-6 py-4">
                      <div className="flex flex-col gap-1 text-xs">
                        <div className="flex items-center gap-2">
                          <span className="w-1.5 h-1.5 rounded-full bg-blue-400" />
                          <span className="text-gray-600">
                            C: <b>{c.compradorNombre}</b>
                          </span>
                        </div>
                        <div className="flex items-center gap-2">
                          <span className="w-1.5 h-1.5 rounded-full bg-orange-400" />
                          <span className="text-gray-600">V: {c.vendedorNombre}</span>
                        </div>
                      </div>
                    </td>
                    <td className="px-6 py-4 font-bold text-gray-900">{formatoCLP(c.monto)}</td>
                    <td className="px-6 py-4">
                      <span className={`px-2.5 py-1 rounded-full text-xs font-bold ${BADGE[c.estado] ?? "bg-gray-50 text-gray-600"}`}>
                        {c.estado.replace("_", " ")}
                      </span>
                    </td>
                    <td className="px-6 py-4 text-right">
                      <div className="flex justify-end items-center gap-1">
                        <button type="button" onClick={() => setSeleccionado(c)} className="p-2 text-gray-400 hover:text-[#54A6D8] hover:bg-sky-50 rounded-lg transition-all" title="Ver detalle">
                          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-5 h-5">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path strokeLinecap="round" strokeLinejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                          </svg>
                        </button>

                        {c.estado === "en_progreso" && (
                          <>
                            <button
                              type="button"
                              disabled={procesando === c.id}
                              onClick={() => ejecutarAccion(c, "liberar", "¿CONFIRMAR? Se liberará el dinero al vendedor.")}
                              className="p-2 text-green-500 hover:bg-green-50 rounded-lg transition-all disabled:opacity-40"
                              title="Liberar fondos"
                            >
                              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-5 h-5">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                              </svg>
                            </button>
                            <button
                              type="button"
                              disabled={procesando === c.id}
                              onClick={() => ejecutarAccion(c, "cancelar", "¿CONFIRMAR? Se cancelará y reembolsará al comprador.")}
                              className="p-2 text-red-400 hover:bg-red-50 rounded-lg transition-all disabled:opacity-40"
                              title="Cancelar contrato"
                            >
                              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-5 h-5">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                              </svg>
                            </button>
                          </>
                        )}

                        {(c.estado === "liberado" || c.estado === "cancelado") && (
                          <button
                            type="button"
                            disabled={procesando === c.id}
                            onClick={() => ejecutarAccion(c, "revertir", "¿Revertir estado a EN PROGRESO?")}
                            className="p-2 text-orange-400 hover:bg-orange-50 rounded-lg transition-all disabled:opacity-40"
                            title="Revertir estado"
                          >
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-5 h-5">
                              <path strokeLinecap="round" strokeLinejoin="round" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6" />
                            </svg>
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {seleccionado && (
        <div className="fixed inset-0 z-[120] flex items-center justify-center p-4 bg-gray-900/40 backdrop-blur-sm" onClick={() => setSeleccionado(null)}>
          <div className="bg-white w-full max-w-md rounded-3xl shadow-2xl overflow-hidden" onClick={(e) => e.stopPropagation()}>
            <div className="bg-gradient-to-r from-sky-50 to-white px-6 py-4 border-b border-gray-100 flex justify-between items-center">
              <h3 className="font-bold text-gray-800 text-lg">Detalle Contrato</h3>
              <button type="button" onClick={() => setSeleccionado(null)} className="w-8 h-8 flex items-center justify-center bg-white rounded-full shadow-sm text-gray-400 hover:text-red-500">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-4 h-4">
                  <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>
            <div className="p-6 space-y-5">
              <div className="text-center">
                <div className={`inline-block px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider mb-2 ${BADGE[seleccionado.estado] ?? "bg-gray-100 text-gray-600"}`}>
                  {seleccionado.estado.replace("_", " ")}
                </div>
                <h2 className="text-3xl font-extrabold text-[#54A6D8] tracking-tight">{formatoCLP(seleccionado.monto)}</h2>
                <p className="text-gray-500 text-sm mt-1">{seleccionado.servicioTitulo}</p>
              </div>
              <div className="bg-gray-50 rounded-xl p-4 border border-gray-100 space-y-3 text-sm">
                <div className="flex justify-between border-b border-gray-200/50 pb-2">
                  <span className="text-gray-500 font-medium">ID Referencia</span>
                  <span className="font-mono font-bold text-gray-700">#{seleccionado.id}</span>
                </div>
                <div className="flex justify-between border-b border-gray-200/50 pb-2">
                  <span className="text-gray-500 font-medium">Comprador</span>
                  <span className="font-bold text-gray-900">{seleccionado.compradorNombre}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-gray-500 font-medium">Vendedor</span>
                  <span className="font-bold text-gray-900">{seleccionado.vendedorNombre}</span>
                </div>
              </div>
              <a
                href={`/aula/${seleccionado.id}`}
                target="_blank"
                rel="noopener noreferrer"
                className="block w-full py-3.5 bg-[#54A6D8] hover:bg-[#4a95c5] text-white text-center rounded-xl font-bold shadow-md shadow-blue-200 transition-transform active:scale-95"
              >
                Ir al Aula Virtual
              </a>
            </div>
          </div>
        </div>
      )}
    </>
  );
}
