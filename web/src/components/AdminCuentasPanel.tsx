"use client";

import { Fragment, useMemo, useState } from "react";
import type { CuentaBancariaAdmin } from "@/lib/api";

type Columna = "nombre" | "banco" | "fecha";

// Puerto de admin_cuentas.php — panel 100% lectura, sin ningún endpoint que mutar (el PHP
// real tampoco tiene POST alguno: solo búsqueda/filtro/orden client-side + copiar al
// portapapeles). Todo el estado de este componente es de presentación, no hay fetch propio.
export function AdminCuentasPanel({ cuentas }: { cuentas: CuentaBancariaAdmin[] }) {
  const [busqueda, setBusqueda] = useState("");
  const [filtroBanco, setFiltroBanco] = useState("");
  const [filtroTipo, setFiltroTipo] = useState("");
  const [expandidoId, setExpandidoId] = useState<number | null>(null);
  const [copiado, setCopiado] = useState<string | null>(null);
  const [orden, setOrden] = useState<{ columna: Columna; dir: "asc" | "desc" } | null>(null);

  const bancos = useMemo(() => Array.from(new Set(cuentas.map((c) => c.banco))).sort(), [cuentas]);

  const filtradas = useMemo(() => {
    const q = busqueda.trim().toLowerCase();
    let resultado = cuentas.filter((c) => {
      const matchQ =
        !q ||
        c.nombre.toLowerCase().includes(q) ||
        c.correo.toLowerCase().includes(q) ||
        c.rut.toLowerCase().includes(q) ||
        c.banco.toLowerCase().includes(q);
      return matchQ && (!filtroBanco || c.banco === filtroBanco) && (!filtroTipo || c.tipoCuenta === filtroTipo);
    });

    if (orden) {
      resultado = [...resultado].sort((a, b) => {
        let va: string | number;
        let vb: string | number;
        if (orden.columna === "fecha") {
          va = new Date(a.fechaConfiguracion).getTime();
          vb = new Date(b.fechaConfiguracion).getTime();
        } else {
          va = a[orden.columna].toLowerCase();
          vb = b[orden.columna].toLowerCase();
        }
        const cmp = va < vb ? -1 : va > vb ? 1 : 0;
        return orden.dir === "asc" ? cmp : -cmp;
      });
    }
    return resultado;
  }, [cuentas, busqueda, filtroBanco, filtroTipo, orden]);

  function toggleOrden(columna: Columna) {
    setOrden((prev) => (prev?.columna === columna ? { columna, dir: prev.dir === "asc" ? "desc" : "asc" } : { columna, dir: "asc" }));
  }

  async function copiar(texto: string) {
    try {
      await navigator.clipboard.writeText(texto);
      setCopiado(texto);
      setTimeout(() => setCopiado(null), 1200);
    } catch {
      // Clipboard API puede fallar sin permiso/HTTPS — sin bloquear el resto del panel.
    }
  }

  const indicador = (columna: Columna) => (orden?.columna === columna ? (orden.dir === "asc" ? "▲" : "▼") : "");

  return (
    <div className="space-y-4">
      {cuentas.length > 0 && (
        <div className="flex flex-col md:flex-row gap-3">
          <input
            type="search"
            value={busqueda}
            onChange={(e) => setBusqueda(e.target.value)}
            placeholder="Buscar por nombre, correo, RUT o banco…"
            className="flex-1 px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-[#54A6D8] outline-none"
          />
          <select value={filtroBanco} onChange={(e) => setFiltroBanco(e.target.value)} className="px-3 py-2.5 rounded-xl border border-gray-200 text-sm bg-white">
            <option value="">Todos los bancos</option>
            {bancos.map((b) => (
              <option key={b} value={b}>
                {b}
              </option>
            ))}
          </select>
          <select value={filtroTipo} onChange={(e) => setFiltroTipo(e.target.value)} className="px-3 py-2.5 rounded-xl border border-gray-200 text-sm bg-white">
            <option value="">Todos los tipos</option>
            <option value="Cuenta Corriente">Cuenta Corriente</option>
            <option value="Cuenta Vista">Cuenta Vista</option>
            <option value="Cuenta Rut">Cuenta Rut</option>
          </select>
        </div>
      )}

      <div className="bg-white border border-gray-100 rounded-2xl overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse">
            <thead>
              <tr className="bg-gray-50 border-b border-gray-200 text-xs uppercase text-gray-500 tracking-wide">
                <th className="p-4 w-10"></th>
                <th className="p-4 font-semibold cursor-pointer select-none" onClick={() => toggleOrden("nombre")}>
                  Estudiante <span className="text-gray-300">{indicador("nombre")}</span>
                </th>
                <th className="p-4 font-semibold">Correo</th>
                <th className="p-4 font-semibold cursor-pointer select-none" onClick={() => toggleOrden("banco")}>
                  Banco <span className="text-gray-300">{indicador("banco")}</span>
                </th>
                <th className="p-4 font-semibold text-right cursor-pointer select-none" onClick={() => toggleOrden("fecha")}>
                  Fecha <span className="text-gray-300">{indicador("fecha")}</span>
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {cuentas.length === 0 ? (
                <tr>
                  <td colSpan={5} className="p-8 text-center text-gray-500 text-sm">
                    Aún no hay usuarios con datos bancarios registrados.
                  </td>
                </tr>
              ) : filtradas.length === 0 ? (
                <tr>
                  <td colSpan={5} className="p-8 text-center text-gray-400 text-sm">
                    No hay coincidencias con tu búsqueda.
                  </td>
                </tr>
              ) : (
                filtradas.map((c) => {
                  const expandido = expandidoId === c.idUsuario;
                  const fecha = new Date(c.fechaConfiguracion);
                  return (
                    <Fragment key={c.idUsuario}>
                      <tr className={`hover:bg-blue-50/50 transition-colors ${!c.visible || c.bloqueado ? "opacity-60" : ""}`}>
                        <td className="p-4 align-top">
                          <button
                            type="button"
                            onClick={() => setExpandidoId(expandido ? null : c.idUsuario)}
                            className="w-7 h-7 rounded-full bg-gray-50 border border-gray-200 flex items-center justify-center text-gray-500 hover:bg-gray-100"
                            aria-label="Ver detalle"
                          >
                            <svg
                              xmlns="http://www.w3.org/2000/svg"
                              fill="none"
                              viewBox="0 0 24 24"
                              strokeWidth={1.5}
                              stroke="currentColor"
                              className={`w-4 h-4 transition-transform ${expandido ? "rotate-180" : ""}`}
                            >
                              <path strokeLinecap="round" strokeLinejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                            </svg>
                          </button>
                        </td>
                        <td className="p-4">
                          <div className="flex items-center gap-2 flex-wrap">
                            <span className="text-sm font-medium text-gray-900">{c.nombre}</span>
                            {!c.visible ? (
                              <span className="inline-flex items-center bg-red-50 text-red-600 px-2 py-0.5 rounded text-[9px] font-bold uppercase tracking-widest border border-red-100">
                                Eliminado
                              </span>
                            ) : c.bloqueado ? (
                              <span className="inline-flex items-center bg-red-50 text-red-500 px-2 py-0.5 rounded text-[9px] font-bold uppercase tracking-widest border border-red-100">
                                Suspendido
                              </span>
                            ) : null}
                          </div>
                        </td>
                        <td className="p-4">
                          <div className="text-sm text-gray-500">{c.correo}</div>
                        </td>
                        <td className="p-4">
                          <div className="text-sm text-gray-700">{c.banco}</div>
                        </td>
                        <td className="p-4 text-right">
                          <div className="text-sm text-gray-500">
                            {fecha.toLocaleDateString("es-CL", { day: "2-digit", month: "2-digit", year: "numeric" })}
                            <span className="text-xs text-gray-400 block">{fecha.toLocaleTimeString("es-CL", { hour: "2-digit", minute: "2-digit" })} hrs</span>
                          </div>
                        </td>
                      </tr>
                      {expandido && (
                        <tr className="bg-gray-50/70">
                          <td colSpan={5} className="px-6 py-4">
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-2 text-sm max-w-2xl">
                              <div>
                                <span className="text-gray-400">Banco:</span> <span className="font-medium text-gray-800">{c.banco}</span>
                              </div>
                              <div>
                                <span className="text-gray-400">Tipo de cuenta:</span> <span className="font-medium text-gray-800">{c.tipoCuenta}</span>
                              </div>
                              <div className="flex items-center gap-2">
                                <span className="text-gray-400">N° cuenta:</span>
                                <span className="font-mono font-semibold text-gray-900">{c.numeroCuenta}</span>
                                <button
                                  type="button"
                                  onClick={() => copiar(c.numeroCuenta)}
                                  className={`transition ${copiado === c.numeroCuenta ? "text-green-600" : "text-gray-400 hover:text-[#54A6D8]"}`}
                                  title="Copiar número"
                                >
                                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor" className="w-4 h-4">
                                    <path
                                      strokeLinecap="round"
                                      strokeLinejoin="round"
                                      d="M15.666 3.888A2.25 2.25 0 0 0 13.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 0 1-.75.75H9a.75.75 0 0 1-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 0 1-2.25 2.25H6.75A2.25 2.25 0 0 1 4.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 0 1 1.927-.184"
                                    />
                                  </svg>
                                </button>
                              </div>
                              <div className="flex items-center gap-2">
                                <span className="text-gray-400">RUT:</span>
                                <span className="font-mono font-semibold text-gray-900">{c.rut}</span>
                                <button
                                  type="button"
                                  onClick={() => copiar(c.rut)}
                                  className={`transition ${copiado === c.rut ? "text-green-600" : "text-gray-400 hover:text-[#54A6D8]"}`}
                                  title="Copiar RUT"
                                >
                                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor" className="w-4 h-4">
                                    <path
                                      strokeLinecap="round"
                                      strokeLinejoin="round"
                                      d="M15.666 3.888A2.25 2.25 0 0 0 13.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 0 1-.75.75H9a.75.75 0 0 1-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 0 1-2.25 2.25H6.75A2.25 2.25 0 0 1 4.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 0 1 1.927-.184"
                                    />
                                  </svg>
                                </button>
                              </div>
                              <div>
                                <span className="text-gray-400">Titular:</span> <span className="font-medium text-gray-800">{c.titularNombre}</span>
                              </div>
                              <div>
                                <span className="text-gray-400">Registrado:</span>{" "}
                                <span className="font-medium text-gray-800">
                                  {fecha.toLocaleDateString("es-CL", { day: "2-digit", month: "2-digit", year: "numeric" })}{" "}
                                  {fecha.toLocaleTimeString("es-CL", { hour: "2-digit", minute: "2-digit" })} hrs
                                </span>
                              </div>
                            </div>
                          </td>
                        </tr>
                      )}
                    </Fragment>
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
