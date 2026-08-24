"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import type { ReporteServicio } from "@/lib/api";

// Puerto de admin_reportes_servicios.php ("Reportes") — listado + bloquear/desbloquear el
// usuario reportado (UPDATE puro, reversible). Deliberadamente SIN 'marcar_revisado' (envía
// 2 correos reales al reportado y al reportante, admin_reportes_servicios.php:62-128) — esa
// acción específica queda excluida y enlaza al sitio PHP real; el resto (lectura + bloqueo)
// sí se porta. Tras cada mutación se usa router.refresh() para re-obtener datos frescos del
// Server Component, mismo patrón que SoporteLista.tsx.
export function AdminReportesServiciosPanel({ reportes, phpSiteUrl }: { reportes: ReporteServicio[]; phpSiteUrl: string }) {
  const router = useRouter();
  const [accionando, setAccionando] = useState<number | null>(null);

  async function toggleBloqueo(r: ReporteServicio) {
    const accion = r.usuarioReportado.bloqueado ? "desbloquear" : "bloquear";
    if (!confirm(`¿${accion === "bloquear" ? "Bloquear" : "Desbloquear"} a este usuario?`)) return;

    setAccionando(r.usuarioReportado.id);
    try {
      const res = await fetch(`/api/admin/reportes-servicios/usuarios/${r.usuarioReportado.id}/bloqueo`, {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ bloqueado: !r.usuarioReportado.bloqueado }),
      });
      if (res.ok) router.refresh();
    } finally {
      setAccionando(null);
    }
  }

  if (reportes.length === 0) {
    return (
      <div className="bg-white border border-gray-100 rounded-3xl px-6 py-16 text-center">
        <p className="text-gray-400 font-medium">No hay reportes.</p>
      </div>
    );
  }

  return (
    <div className="bg-white border border-gray-100 rounded-3xl overflow-hidden">
      <div className="overflow-x-auto">
        <table className="w-full text-left text-sm">
          <thead className="bg-gray-50 text-gray-500 text-[11px] uppercase font-bold tracking-widest border-b border-gray-100">
            <tr>
              <th className="px-6 py-4">Servicio Reportado</th>
              <th className="px-6 py-4">Motivo / Mensaje</th>
              <th className="px-6 py-4">Reportado Por</th>
              <th className="px-6 py-4">Usuario Reportado</th>
              <th className="px-6 py-4">Fecha</th>
              <th className="px-6 py-4 text-right">Acciones</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-50">
            {reportes.map((r) => (
              <tr key={r.id} className="hover:bg-gray-50 transition-colors">
                <td className="px-6 py-4">
                  <a href={`/servicios/${r.servicioId}`} target="_blank" rel="noopener noreferrer" className="font-bold text-[#54A6D8] hover:underline line-clamp-2 max-w-[200px] inline-block">
                    {r.tituloServicio}
                  </a>
                  <div className="text-xs text-gray-400 mt-1">ID: #{r.servicioId}</div>
                </td>
                <td className="px-6 py-4">
                  <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800 mb-1">{r.motivo}</span>
                  {r.mensaje && <div className="text-xs text-gray-600 bg-gray-50 p-2 rounded border border-gray-100 mt-1 max-w-[250px] italic">&quot;{r.mensaje.slice(0, 100)}&quot;</div>}
                </td>
                <td className="px-6 py-4">
                  <div className="font-medium text-gray-900">{r.usuarioReporta.nombre}</div>
                  <div className="text-xs text-gray-500">{r.usuarioReporta.correo}</div>
                </td>
                <td className="px-6 py-4">
                  <div className="flex items-center gap-2">
                    <div>
                      <div className="font-medium text-gray-900">{r.usuarioReportado.nombre}</div>
                      <div className="text-xs text-gray-500">{r.usuarioReportado.correo}</div>
                    </div>
                    {r.usuarioReportado.bloqueado && <span className="text-[10px] bg-red-600 text-white px-2 py-0.5 rounded-full font-bold">BLOQUEADO</span>}
                  </div>
                  <button
                    type="button"
                    onClick={() => toggleBloqueo(r)}
                    disabled={accionando === r.usuarioReportado.id}
                    className={`mt-2 text-xs font-semibold disabled:opacity-50 ${r.usuarioReportado.bloqueado ? "text-green-600 hover:text-green-800" : "text-red-500 hover:text-red-700"}`}
                  >
                    {r.usuarioReportado.bloqueado ? "Desbloquear" : "Bloquear Usuario"}
                  </button>
                </td>
                <td className="px-6 py-4 whitespace-nowrap">
                  <div className="text-sm text-gray-900">{new Date(r.fecha).toLocaleDateString("es-CL", { day: "2-digit", month: "short", year: "numeric" })}</div>
                  <div className="text-xs text-gray-400">{new Date(r.fecha).toLocaleTimeString("es-CL", { hour: "2-digit", minute: "2-digit" })}</div>
                </td>
                <td className="px-6 py-4 text-right">
                  {r.revisado ? (
                    <span className="inline-flex items-center gap-1 px-3 py-1 bg-gray-100 text-gray-500 rounded-lg text-xs font-bold border border-gray-200">Revisado</span>
                  ) : (
                    <a
                      href={`${phpSiteUrl}/admin/reporte-servicios?estado=pendientes`}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="bg-green-50 text-green-700 hover:bg-green-100 border border-green-200 font-bold py-2 px-4 rounded-xl text-xs transition-all inline-block"
                      title="Marcar revisado y notificar por correo (en el sitio real)"
                    >
                      Marcar Revisado
                    </a>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
