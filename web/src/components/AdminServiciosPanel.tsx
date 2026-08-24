"use client";

import { useState } from "react";
import type { ServicioAdmin } from "@/lib/api";

const ESTADO_BADGE: Record<string, string> = {
  pendiente: "bg-amber-50 text-amber-600",
  aprobado: "bg-emerald-50 text-emerald-600",
  rechazado: "bg-red-50 text-red-600",
};

// Puerto de admin_servicios.php — listado + zoom de imagen (solo vista) + toggle de
// visibilidad. Aprobar/rechazar/eliminar y el editor de censura de imagen quedan fuera de
// alcance (enlazan al sitio PHP real) — ver nota en server/src/modules/adminServicios/
// adminServicios.types.ts.
export function AdminServiciosPanel({ servicios: serviciosIniciales, phpSiteUrl }: { servicios: ServicioAdmin[]; phpSiteUrl: string }) {
  const [servicios, setServicios] = useState(serviciosIniciales);
  const [zoomUrl, setZoomUrl] = useState<string | null>(null);
  const [cambiando, setCambiando] = useState<number | null>(null);

  async function toggleVisibilidad(s: ServicioAdmin) {
    setCambiando(s.id);
    try {
      const res = await fetch(`/api/admin/servicios/${s.id}/visibilidad`, {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ visible: !s.visible }),
      });
      if (res.ok) {
        setServicios((prev) => prev.map((x) => (x.id === s.id ? { ...x, visible: !x.visible } : x)));
      }
    } finally {
      setCambiando(null);
    }
  }

  return (
    <>
      <div className="bg-white border border-gray-100 rounded-3xl overflow-hidden min-h-[400px]">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[1000px] text-sm text-left">
            <thead className="text-[11px] text-gray-400 uppercase tracking-widest bg-gray-50 border-b border-gray-100">
              <tr>
                <th className="px-6 py-4 font-bold text-center w-16">ID</th>
                <th className="px-6 py-4 font-bold w-16 text-center">Imagen</th>
                <th className="px-6 py-4 font-bold">Título del Servicio</th>
                <th className="px-6 py-4 font-bold">Oferente</th>
                <th className="px-6 py-4 font-bold">Categoría</th>
                <th className="px-6 py-4 font-bold text-center w-28">Estado</th>
                <th className="px-6 py-4 font-bold text-center w-24">Visibilidad</th>
                <th className="px-6 py-4 font-bold text-right w-40">Acciones</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-50">
              {servicios.length === 0 ? (
                <tr>
                  <td colSpan={8} className="text-center py-16 text-gray-400">
                    No se encontraron servicios recientes.
                  </td>
                </tr>
              ) : (
                servicios.map((s) => (
                  <tr key={s.id} className={`hover:bg-gray-50 transition-colors align-middle group ${!s.visible ? "opacity-60 bg-gray-50" : ""}`}>
                    <td className="px-6 py-4 text-center text-gray-400 font-mono text-xs">#{s.id}</td>
                    <td className="px-6 py-4 text-center">
                      <button type="button" onClick={() => setZoomUrl(s.portadaUrl)} className="inline-block bg-gray-100 rounded-xl cursor-zoom-in">
                        {/* eslint-disable-next-line @next/next/no-img-element -- portada dinámica de servicio */}
                        <img src={s.portadaUrl} alt="Portada" loading="lazy" className="w-14 h-10 object-cover rounded-xl border border-gray-200" />
                      </button>
                    </td>
                    <td className="px-6 py-4">
                      <p className="font-bold text-gray-900 text-sm truncate max-w-[250px]" title={s.titulo}>
                        {s.titulo}
                      </p>
                      <p className="text-[10px] text-gray-400 font-medium mt-0.5">{new Date(s.fechaPublicacion).toLocaleDateString("es-CL")}</p>
                    </td>
                    <td className="px-6 py-4 text-xs text-gray-600 font-medium truncate max-w-[150px]">{s.nombreOferente ?? s.nombreAlumno}</td>
                    <td className="px-6 py-4">
                      <span className="bg-gray-100 text-gray-500 px-2 py-1 rounded-md text-[9px] font-black uppercase tracking-widest">{s.categoria}</span>
                    </td>
                    <td className="px-6 py-4 text-center">
                      <span className={`${ESTADO_BADGE[s.estado] ?? "bg-gray-50 text-gray-600"} px-2.5 py-1 rounded-md text-[9px] font-black uppercase tracking-widest`}>
                        {s.estado}
                      </span>
                      {s.estado === "rechazado" && s.motivoRechazo && (
                        <div className="text-[10px] text-red-500 mt-1.5 truncate max-w-[100px] font-medium" title={s.motivoRechazo}>
                          Ver motivo
                        </div>
                      )}
                    </td>
                    <td className="px-6 py-4 text-center">
                      {s.visible ? (
                        <span className="bg-indigo-50 text-indigo-500 px-2.5 py-1 rounded-md text-[9px] font-black uppercase tracking-widest">Visible</span>
                      ) : (
                        <span className="bg-gray-200 text-gray-500 px-2.5 py-1 rounded-md text-[9px] font-black uppercase tracking-widest">Oculto</span>
                      )}
                    </td>
                    <td className="px-6 py-4 text-right">
                      <div className="flex items-center justify-end gap-1.5 opacity-100 md:opacity-60 md:group-hover:opacity-100 transition-opacity">
                        {s.estado === "pendiente" && (
                          <a
                            href={`${phpSiteUrl}/admin/servicios`}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="bg-amber-50 text-amber-600 px-2 py-2 rounded-xl text-[10px] font-bold"
                            title="Aprobar/Rechazar (en el sitio real)"
                          >
                            Revisar
                          </a>
                        )}
                        <a
                          href={`/servicios/${s.id}`}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="bg-blue-50 active:bg-blue-100 text-[#54A6D8] p-2 rounded-xl transition-colors text-xs"
                          title="Ver Detalle"
                        >
                          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-4 h-4">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                          </svg>
                        </a>
                        <button
                          type="button"
                          onClick={() => toggleVisibilidad(s)}
                          disabled={cambiando === s.id}
                          className={`${s.visible ? "bg-gray-100 text-gray-500" : "bg-indigo-50 text-indigo-500"} p-2 rounded-xl transition-colors text-xs disabled:opacity-50`}
                          title={s.visible ? "Ocultar Servicio" : "Mostrar Servicio"}
                        >
                          {s.visible ? (
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-4 h-4">
                              <path strokeLinecap="round" strokeLinejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" />
                            </svg>
                          ) : (
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-4 h-4">
                              <path strokeLinecap="round" strokeLinejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                              <path strokeLinecap="round" strokeLinejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                            </svg>
                          )}
                        </button>
                        <a
                          href={`${phpSiteUrl}/admin/servicios`}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="bg-gray-50 text-gray-400 p-2 rounded-xl transition-colors text-xs"
                          title="Editar / Eliminar (en el sitio real)"
                        >
                          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-4 h-4">
                            <path strokeLinecap="round" strokeLinejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125" />
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

      {zoomUrl && (
        <div className="fixed inset-0 bg-gray-900/95 backdrop-blur-sm z-[120] flex items-center justify-center p-4" onClick={() => setZoomUrl(null)}>
          {/* eslint-disable-next-line @next/next/no-img-element -- portada dinámica de servicio */}
          <img src={zoomUrl} alt="Portada ampliada" className="max-w-full max-h-[85vh] object-contain rounded-2xl" />
          <button type="button" onClick={() => setZoomUrl(null)} className="absolute top-6 right-6 text-white/80 hover:text-white w-10 h-10 rounded-full flex items-center justify-center">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-6 h-6">
              <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
      )}
    </>
  );
}
