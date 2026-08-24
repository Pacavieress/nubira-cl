"use client";

import { useState } from "react";
import type { MonitoreoResumen, MonitoreoTab } from "@/lib/api";

const TABS: { key: MonitoreoTab; label: string }[] = [
  { key: "fallos", label: "Intentos" },
  { key: "vips", label: "VIPs" },
  { key: "pendientes", label: "Pendientes" },
];

// Puerto de admin_login_fallos.php ("Log Fail" / "Centro de Monitoreo") — 3 tabs. Se portan
// las mutaciones reversibles: limpiar historial de un correo (tab Intentos) y
// autorizar/revocar VIP (tab VIPs, un simple toggle de `activo`). El tab Pendientes es
// deliberadamente solo-lectura acá: su única acción real en el PHP es 'eliminar_pendiente'
// (hard DELETE FROM alumnos, sin soft-delete) — queda excluida y enlaza al sitio real.
export function AdminLoginFallosPanel({ resumenInicial, phpSiteUrl }: { resumenInicial: MonitoreoResumen; phpSiteUrl: string }) {
  const [resumen, setResumen] = useState(resumenInicial);
  const [cargando, setCargando] = useState(false);
  const [modalVipAbierto, setModalVipAbierto] = useState(false);
  const [correoVip, setCorreoVip] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [accionando, setAccionando] = useState<string | null>(null);

  async function cargar(tab: MonitoreoTab, page: number) {
    setCargando(true);
    try {
      const res = await fetch(`/api/admin/login-fallos?tab=${tab}&page=${page}`);
      if (res.ok) setResumen(await res.json());
    } finally {
      setCargando(false);
    }
  }

  async function limpiarFallos(correo: string) {
    if (!confirm("¿Limpiar historial de intentos fallidos de este correo?")) return;
    setAccionando(correo);
    try {
      const res = await fetch("/api/admin/login-fallos/fallos", {
        method: "DELETE",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ correo }),
      });
      if (res.ok) await cargar(resumen.tab, resumen.page);
    } finally {
      setAccionando(null);
    }
  }

  async function revocarVip(correo: string) {
    if (!confirm(`¿Revocar acceso VIP a ${correo}?`)) return;
    setAccionando(correo);
    try {
      const res = await fetch("/api/admin/login-fallos/vips/revocar", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ correo }),
      });
      if (res.ok) await cargar(resumen.tab, resumen.page);
    } finally {
      setAccionando(null);
    }
  }

  async function autorizarVip() {
    const correo = correoVip.trim().toLowerCase();
    if (!correo) return;
    setError(null);
    setAccionando("__nuevo_vip__");
    try {
      const res = await fetch("/api/admin/login-fallos/vips", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ correo }),
      });
      if (res.ok) {
        setModalVipAbierto(false);
        setCorreoVip("");
        await cargar("vips", 1);
      } else {
        setError("No se pudo autorizar el correo.");
      }
    } finally {
      setAccionando(null);
    }
  }

  const items = resumen.tab === "fallos" ? resumen.itemsFallos ?? [] : resumen.tab === "vips" ? resumen.itemsVips ?? [] : resumen.itemsPendientes ?? [];

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div className="flex gap-1 bg-gray-50 rounded-xl p-1">
          {TABS.map((t) => (
            <button
              key={t.key}
              type="button"
              onClick={() => cargar(t.key, 1)}
              className={`px-4 py-2 rounded-lg text-sm font-bold transition-colors ${
                resumen.tab === t.key ? "bg-white text-[#54A6D8] shadow-sm" : "text-gray-500 hover:text-gray-700"
              }`}
            >
              {t.label} <span className="ml-1 text-[10px] text-gray-400">{resumen.contadores[t.key]}</span>
            </button>
          ))}
        </div>
        <button
          type="button"
          onClick={() => setModalVipAbierto(true)}
          className="bg-[#54A6D8] hover:bg-sky-500 text-white text-sm font-bold py-2.5 px-4 rounded-xl shadow-sm transition-all flex items-center gap-2"
        >
          + Autorizar VIP
        </button>
      </div>

      <div className="bg-white border border-gray-100 rounded-3xl overflow-hidden min-h-[300px]">
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm">
            <thead className="bg-gray-50 text-gray-500 text-[11px] uppercase font-bold tracking-widest border-b border-gray-100">
              {resumen.tab === "vips" && (
                <tr>
                  <th className="p-4">Correo VIP</th>
                  <th className="p-4">Fecha Autorización</th>
                  <th className="p-4 text-right">Acción</th>
                </tr>
              )}
              {resumen.tab === "fallos" && (
                <tr>
                  <th className="p-4">Usuario / Correo</th>
                  <th className="p-4">IP Origen</th>
                  <th className="p-4">Fecha</th>
                  <th className="p-4 text-right">Acción</th>
                </tr>
              )}
              {resumen.tab === "pendientes" && (
                <tr>
                  <th className="p-4">Candidato</th>
                  <th className="p-4">Carrera</th>
                  <th className="p-4 text-right">Acción</th>
                </tr>
              )}
            </thead>
            <tbody className="divide-y divide-gray-50">
              {cargando ? (
                <tr>
                  <td colSpan={4} className="text-center py-16 text-gray-400">
                    Cargando...
                  </td>
                </tr>
              ) : items.length === 0 ? (
                <tr>
                  <td colSpan={4} className="text-center py-16 text-gray-400">
                    Sin registros.
                  </td>
                </tr>
              ) : (
                items.map((item) => {
                  if (resumen.tab === "vips") {
                    const v = item as { id: number; correo: string; fechaCreacion: string };
                    return (
                      <tr key={v.id} className="hover:bg-gray-50 transition-colors">
                        <td className="p-4 font-bold text-gray-800">{v.correo}</td>
                        <td className="p-4 text-xs text-gray-500">{new Date(v.fechaCreacion).toLocaleDateString("es-CL")}</td>
                        <td className="p-4 text-right">
                          <button
                            type="button"
                            onClick={() => revocarVip(v.correo)}
                            disabled={accionando === v.correo}
                            className="text-red-500 hover:text-red-700 bg-red-50 px-2 py-1 rounded text-xs font-bold transition disabled:opacity-50"
                          >
                            Revocar
                          </button>
                        </td>
                      </tr>
                    );
                  }
                  if (resumen.tab === "fallos") {
                    const f = item as { correo: string; ip: string; fecha: string; esAlumno: boolean };
                    return (
                      <tr key={`${f.correo}-${f.fecha}`} className="hover:bg-gray-50 transition-colors">
                        <td className="p-4 font-bold text-gray-700">
                          {f.correo}
                          <div className="text-[10px] text-gray-400 font-normal">{f.esAlumno ? "Registrado" : "Desconocido"}</div>
                        </td>
                        <td className="p-4 font-mono text-xs text-gray-500">{f.ip}</td>
                        <td className="p-4 text-gray-500 text-xs">{new Date(f.fecha).toLocaleString("es-CL", { day: "2-digit", month: "2-digit", hour: "2-digit", minute: "2-digit" })}</td>
                        <td className="p-4 text-right">
                          <button type="button" onClick={() => limpiarFallos(f.correo)} disabled={accionando === f.correo} className="text-red-400 hover:text-red-600 px-2 disabled:opacity-50">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} className="w-4 h-4 inline">
                              <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"
                              />
                            </svg>
                          </button>
                        </td>
                      </tr>
                    );
                  }
                  const p = item as { id: number; nombre: string; correo: string; carrera: string | null; dominio: string | null };
                  return (
                    <tr key={p.id} className="hover:bg-gray-50 transition-colors">
                      <td className="p-4">
                        <div className="font-bold text-gray-800">{p.nombre}</div>
                        <div className="text-xs text-[#54A6D8]">{p.correo}</div>
                      </td>
                      <td className="p-4 text-xs">
                        {p.carrera}
                        <br />
                        <span className="text-gray-400">@{p.dominio}</span>
                      </td>
                      <td className="p-4 text-right">
                        <a
                          href={`${phpSiteUrl}/admin/login-fallos?tab=pendientes`}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="text-red-500 text-xs font-bold border border-red-200 px-2 py-1 rounded hover:bg-red-50"
                          title="Eliminar (en el sitio real)"
                        >
                          Eliminar
                        </a>
                      </td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>
      </div>

      <div className="flex justify-center gap-2">
        {resumen.page > 1 && (
          <button type="button" onClick={() => cargar(resumen.tab, resumen.page - 1)} className="px-4 py-2 bg-white border rounded-xl text-sm font-bold text-gray-600 hover:bg-gray-50 shadow-sm">
            Anterior
          </button>
        )}
        {items.length === resumen.limit && (
          <button type="button" onClick={() => cargar(resumen.tab, resumen.page + 1)} className="px-4 py-2 bg-white border rounded-xl text-sm font-bold text-gray-600 hover:bg-gray-50 shadow-sm">
            Siguiente
          </button>
        )}
      </div>

      {modalVipAbierto && (
        <div className="fixed inset-0 bg-gray-900/40 backdrop-blur-sm flex items-center justify-center z-[100] p-4" onClick={() => setModalVipAbierto(false)}>
          <div className="bg-white p-8 rounded-3xl shadow-2xl w-full max-w-sm border border-gray-100" onClick={(e) => e.stopPropagation()}>
            <div className="flex justify-between items-center mb-6">
              <div className="w-12 h-12 bg-sky-50 text-[#54A6D8] rounded-2xl flex items-center justify-center shadow-sm">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} className="w-6 h-6">
                  <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    d="M11.48 3.5a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.562.562 0 00-.586 0l-4.725 2.885a.562.562 0 01-.84-.61l1.285-5.385a.563.563 0 00-.182-.557L2.94 10.386a.562.562 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z"
                  />
                </svg>
              </div>
              <button type="button" onClick={() => setModalVipAbierto(false)} className="text-gray-400 hover:text-gray-600">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} className="w-6 h-6">
                  <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>
            <h2 className="text-xl font-bold text-gray-900 mb-2 tracking-tight">Autorizar VIP</h2>
            <p className="text-gray-500 text-sm mb-6 leading-relaxed">Otorga acceso manual a una cuenta externa (Gmail, Hotmail, etc) para que pueda registrarse.</p>
            {error && <p className="text-red-500 text-xs mb-3">{error}</p>}
            <input
              type="email"
              value={correoVip}
              onChange={(e) => setCorreoVip(e.target.value)}
              placeholder="ejemplo@gmail.com"
              className="w-full px-4 py-3 rounded-xl bg-gray-50 border border-gray-200 focus:bg-white focus:ring-2 focus:ring-[#54A6D8] outline-none transition text-gray-800 mb-6"
            />
            <button
              type="button"
              onClick={autorizarVip}
              disabled={accionando === "__nuevo_vip__"}
              className="w-full bg-[#54A6D8] hover:bg-sky-500 text-white font-bold py-3.5 rounded-xl shadow-md transition-all disabled:opacity-50"
            >
              Conceder Acceso
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
