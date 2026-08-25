"use client";

import { useState } from "react";
import type { ApunteAdminListado } from "@/lib/api";

const BADGES_ESTADO: Record<string, string> = {
  aprobado: "bg-green-100 text-green-700 border-green-200",
  rechazado: "bg-red-100 text-red-700 border-red-200",
  pendiente: "bg-yellow-100 text-yellow-700 border-yellow-200",
};

// Puerto de admin_apuntes.php ("Gestión de Apuntes") — listado + UNA sola mutación: alternar
// visibilidad (toggle de `publico`, UPDATE de 1 columna, reversible). Alcance confirmado con
// el usuario antes de construir: aprobar/rechazar/eliminar/censura de miniatura quedan
// excluidos y enlazan al sitio real (ver adminApuntes.types.ts para el detalle completo de
// por qué cada uno).
export function AdminApuntesPanel({ apuntesIniciales, phpSiteUrl }: { apuntesIniciales: ApunteAdminListado[]; phpSiteUrl: string }) {
  const [apuntes, setApuntes] = useState(apuntesIniciales);
  const [cargando, setCargando] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function alternar(id: number) {
    setError(null);
    setCargando(id);
    try {
      const res = await fetch(`/api/admin/apuntes/${id}/alternar`, { method: "POST" });
      if (res.ok) {
        setApuntes((prev) => prev.map((a) => (a.id === id ? { ...a, publico: !a.publico } : a)));
      } else {
        setError("No se pudo actualizar la visibilidad.");
      }
    } catch {
      setError("No se pudo actualizar la visibilidad.");
    } finally {
      setCargando(null);
    }
  }

  return (
    <div className="space-y-3">
      {error && <div className="bg-red-50 text-red-600 p-3 rounded-xl text-sm font-medium border border-red-200">{error}</div>}

      <div className="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-sm text-left">
            <thead className="text-xs text-gray-500 uppercase bg-gray-50 border-b border-gray-100">
              <tr>
                <th className="px-6 py-4 font-bold">Título / Archivo</th>
                <th className="px-6 py-4 font-bold">Info</th>
                <th className="px-6 py-4 font-bold text-center">Estado</th>
                <th className="px-6 py-4 font-bold text-right">Acciones</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-50">
              {apuntes.length === 0 ? (
                <tr>
                  <td colSpan={4} className="text-center py-16 text-gray-400">
                    No se encontraron apuntes.
                  </td>
                </tr>
              ) : (
                apuntes.map((a) => (
                  <tr key={a.id} className="hover:bg-gray-50 transition align-middle">
                    <td className="px-6 py-4">
                      <div className="flex items-center gap-4">
                        {/* eslint-disable-next-line @next/next/no-img-element */}
                        <img
                          src={a.miniaturaUrl.startsWith("http") ? a.miniaturaUrl : `${phpSiteUrl}${a.miniaturaUrl}`}
                          alt=""
                          loading="lazy"
                          className="w-12 h-16 object-cover rounded border border-gray-200 shadow-sm shrink-0 bg-gray-100"
                        />
                        <div className="min-w-0">
                          <div className="font-bold text-gray-900 truncate max-w-xs" title={a.titulo}>
                            {a.titulo}
                          </div>
                          <a
                            href={`${phpSiteUrl}/app/ver_pdf_apunte.php?id=${a.id}`}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="text-xs text-[#54A6D8] hover:underline font-medium mt-0.5 inline-block"
                          >
                            Ver documento
                          </a>
                        </div>
                      </div>
                    </td>

                    <td className="px-6 py-4">
                      <div className="flex flex-col">
                        <span className="text-gray-900 text-xs font-bold uppercase tracking-tight bg-gray-100 inline-block px-2 py-0.5 rounded w-fit mb-1">
                          {a.asignatura}
                        </span>
                        <span className="text-gray-500 text-xs">Por: {a.autor}</span>
                        <span className="text-gray-400 text-[10px]">{new Date(a.fechaSubida).toLocaleDateString("es-CL")}</span>
                      </div>
                    </td>

                    <td className="px-6 py-4 text-center">
                      <div className="mb-1.5">
                        <span className={`px-2 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider border ${BADGES_ESTADO[a.estado] ?? BADGES_ESTADO.pendiente}`}>
                          {a.estado}
                        </span>
                      </div>
                      <div>
                        {a.publico ? (
                          <span className="text-[10px] font-bold text-[#54A6D8] bg-blue-50 px-2 py-0.5 rounded border border-blue-100 uppercase tracking-wide">
                            Público
                          </span>
                        ) : (
                          <span className="text-[10px] font-bold text-gray-500 bg-gray-100 px-2 py-0.5 rounded border border-gray-200 uppercase tracking-wide">
                            Oculto
                          </span>
                        )}
                      </div>
                      {a.totalVentas > 0 && (
                        <div className="mt-1.5">
                          <span className="bg-emerald-50 text-emerald-700 border border-emerald-100 px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wide">
                            {a.totalVentas} {a.totalVentas === 1 ? "venta" : "ventas"}
                          </span>
                        </div>
                      )}
                    </td>

                    <td className="px-6 py-4 text-right">
                      <div className="flex items-center justify-end gap-2">
                        {a.estado === "pendiente" && (
                          <a
                            href={`${phpSiteUrl}/admin/apuntes`}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="bg-gray-100 text-gray-500 px-3 py-2 rounded-lg text-xs font-bold border border-gray-200 whitespace-nowrap"
                            title="Aprobar/rechazar (efectos en filesystem) en el sitio real"
                          >
                            Aprobar / Rechazar en el sitio real
                          </a>
                        )}
                        <button
                          type="button"
                          onClick={() => alternar(a.id)}
                          disabled={cargando === a.id}
                          title={a.publico ? "Ocultar apunte" : "Hacer visible"}
                          className={`p-2 rounded-lg transition disabled:opacity-50 ${a.publico ? "bg-blue-50 text-[#54A6D8] hover:bg-blue-100" : "bg-gray-50 text-gray-400 hover:bg-gray-100"}`}
                        >
                          {a.publico ? (
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor">
                              <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"
                              />
                              <path strokeLinecap="round" strokeLinejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                          ) : (
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor">
                              <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"
                              />
                            </svg>
                          )}
                        </button>
                        <a
                          href={`${phpSiteUrl}/admin/apuntes`}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="bg-gray-50 text-gray-400 hover:bg-red-50 hover:text-red-600 p-2 rounded-lg transition"
                          title="Eliminar permanentemente (borra compras reales) en el sitio real"
                        >
                          <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor">
                            <path
                              strokeLinecap="round"
                              strokeLinejoin="round"
                              d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"
                            />
                          </svg>
                        </a>
                      </div>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
