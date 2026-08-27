"use client";

import { useMemo, useState } from "react";
import type { UsuarioDormido } from "@/lib/api";

const TOPE_DESTINATARIOS_POR_ENVIO = 150;

type Proveedor = "gmail" | "outlook" | "yahoo" | "institucional" | "otro";

const PROVEEDOR_LABEL: Record<Proveedor, string> = {
  gmail: "Gmail",
  outlook: "Outlook/Hotmail",
  yahoo: "Yahoo",
  institucional: "Institucional",
  otro: "Otro",
};

// Puerto exacto de clasificar_proveedor() (enviar_despertar_dormidos.php:342-358) — se
// resuelve en el cliente sobre el listado ya cargado en vez de ida y vuelta al server por
// cada filtro, mismo criterio documentado en adminDespertarDormidos.types.ts (server/).
function clasificarProveedor(correo: string): Proveedor {
  const c = correo.toLowerCase().trim();
  if (c.endsWith("@gmail.com")) return "gmail";
  if (/@(outlook|hotmail|live)\./i.test(c)) return "outlook";
  if (/@yahoo\./i.test(c)) return "yahoo";
  const institucionales = [
    "uc.cl", "usach.cl", "uandes.cl", "unab.cl", "uandresbello.edu", "duoc.cl",
    "aiep.cl", "santotomas.cl", "fen.uchile.cl", "sansano.usm.cl", "ing.puc.cl",
    "utem.cl", "umayor.cl", "uai.cl", "uchile.cl", "uv.cl", "udec.cl",
    "pucv.cl", "ulagos.cl", "ucn.cl", "uach.cl", "umag.cl", "utalca.cl",
  ];
  const dominio = c.slice(c.indexOf("@") + 1);
  if (institucionales.includes(dominio)) return "institucional";
  if (/^(correo|alumnos|estudiantes|mail)\./.test(dominio)) return "institucional";
  if (dominio.endsWith(".edu") || dominio.endsWith(".edu.cl")) return "institucional";
  return "otro";
}

const ESTADO_BADGE: Record<UsuarioDormido["estado"], string> = {
  pendiente: "bg-gray-100 text-gray-500",
  enviado: "bg-emerald-50 text-emerald-700",
  fallo: "bg-amber-50 text-amber-700",
};

// Puerto de app/enviar_despertar_dormidos.php (modo WEB) — panel de campaña "Despertar
// Dormidos", autorizado explícitamente por el usuario con envío real de correo. Filtros de
// estado/proveedor y checkboxes se resuelven en el cliente sobre el listado completo (server/
// ya excluye a quien está en `unsubscribed` — ver adminDespertarDormidos.repository.ts).
export function AdminDespertarDormidosPanel({ resumenInicial }: { resumenInicial: { usuarios: UsuarioDormido[]; stats: { total: number; enviados: number; pendientes: number; fallidos: number } } }) {
  const [usuarios, setUsuarios] = useState(resumenInicial.usuarios);
  const [filtroEstado, setFiltroEstado] = useState<"pendiente" | "enviado" | "todos">("pendiente");
  const [proveedoresActivos, setProveedoresActivos] = useState<Set<Proveedor>>(new Set(["gmail", "outlook", "yahoo", "institucional", "otro"]));
  const [seleccionados, setSeleccionados] = useState<Set<number>>(new Set());

  const [incluirCupon, setIncluirCupon] = useState(false);
  const [codigoCupon, setCodigoCupon] = useState("");
  const [infoCupon, setInfoCupon] = useState<{ porcentaje: number; fechaExpiracion: string | null } | null>(null);
  const [errorCupon, setErrorCupon] = useState<string | null>(null);

  const [enviando, setEnviando] = useState(false);
  const [resultado, setResultado] = useState<{ enviados: number; fallidos: number; omitidos: number } | null>(null);
  const [error, setError] = useState<string | null>(null);

  const filas = useMemo(() => {
    return usuarios
      .filter((u) => (filtroEstado === "todos" ? true : filtroEstado === "pendiente" ? u.estado !== "enviado" : u.estado === "enviado"))
      .filter((u) => proveedoresActivos.has(clasificarProveedor(u.correo)));
  }, [usuarios, filtroEstado, proveedoresActivos]);

  const stats = useMemo(() => {
    const enviados = usuarios.filter((u) => u.estado === "enviado").length;
    const fallidos = usuarios.filter((u) => u.estado === "fallo").length;
    return { total: usuarios.length, enviados, pendientes: usuarios.length - enviados - fallidos, fallidos };
  }, [usuarios]);

  const conteoProveedores = useMemo(() => {
    const base = usuarios.filter((u) => (filtroEstado === "todos" ? true : filtroEstado === "pendiente" ? u.estado !== "enviado" : u.estado === "enviado"));
    const conteo: Record<Proveedor, number> = { gmail: 0, outlook: 0, yahoo: 0, institucional: 0, otro: 0 };
    for (const u of base) conteo[clasificarProveedor(u.correo)]++;
    return conteo;
  }, [usuarios, filtroEstado]);

  function toggleProveedor(p: Proveedor) {
    setProveedoresActivos((prev) => {
      const next = new Set(prev);
      if (next.has(p)) next.delete(p);
      else next.add(p);
      return next;
    });
  }

  function toggleSeleccion(id: number) {
    setSeleccionados((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }

  function toggleSeleccionarTodo() {
    const idsFilas = filas.map((f) => f.alumnoId);
    const todosSeleccionados = idsFilas.every((id) => seleccionados.has(id));
    setSeleccionados((prev) => {
      const next = new Set(prev);
      idsFilas.forEach((id) => (todosSeleccionados ? next.delete(id) : next.add(id)));
      return next;
    });
  }

  async function consultarCupon() {
    setErrorCupon(null);
    setInfoCupon(null);
    if (!codigoCupon.trim()) return;
    try {
      const res = await fetch(`/api/admin/despertar-dormidos/cupon?codigo=${encodeURIComponent(codigoCupon.trim())}`);
      const data = await res.json();
      if (!data.ok) {
        setErrorCupon(data.error ?? "Código inválido.");
        return;
      }
      setInfoCupon({ porcentaje: data.porcentaje, fechaExpiracion: data.fechaExpiracion });
    } catch {
      setErrorCupon("Error de conexión.");
    }
  }

  async function enviarSeleccionados() {
    setError(null);
    setResultado(null);
    const ids = [...seleccionados];
    if (ids.length === 0) return;
    if (ids.length > TOPE_DESTINATARIOS_POR_ENVIO) {
      setError(`Máximo ${TOPE_DESTINATARIOS_POR_ENVIO} destinatarios por envío — selecciona menos o envía en varias tandas.`);
      return;
    }
    if (!window.confirm(`¿Confirmas el envío del correo a ${ids.length} usuario${ids.length !== 1 ? "s" : ""}?`)) return;

    setEnviando(true);
    try {
      const res = await fetch("/api/admin/despertar-dormidos/enviar", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ alumnoIds: ids, codigo: incluirCupon ? codigoCupon.trim() : undefined }),
      });
      const data = await res.json();
      if (!res.ok) {
        setError(data?.mensaje ?? data?.error ?? "Error al enviar.");
        return;
      }
      setResultado(data);
      const idsEnviados = new Set(ids);
      setUsuarios((prev) => prev.map((u) => (idsEnviados.has(u.alumnoId) ? { ...u, estado: "enviado", fechaEnviado: new Date().toISOString() } : u)));
      setSeleccionados(new Set());
    } catch {
      setError("Error de conexión.");
    } finally {
      setEnviando(false);
    }
  }

  return (
    <div className="space-y-6">
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div className="bg-white rounded-2xl border border-gray-100 p-5">
          <p className="text-[11px] font-semibold uppercase tracking-wider text-gray-500 mb-1">Total usuarios</p>
          <p className="text-2xl font-bold text-[#222222]">{stats.total}</p>
        </div>
        <div className="bg-white rounded-2xl border border-emerald-100 p-5">
          <p className="text-[11px] font-semibold uppercase tracking-wider text-emerald-600 mb-1">Ya enviados</p>
          <p className="text-2xl font-bold text-emerald-700">{stats.enviados}</p>
        </div>
        <div className="bg-white rounded-2xl border border-gray-100 p-5">
          <p className="text-[11px] font-semibold uppercase tracking-wider text-gray-500 mb-1">Pendientes</p>
          <p className="text-2xl font-bold text-[#222222]">{stats.pendientes}</p>
        </div>
        <div className="bg-white rounded-2xl border border-amber-100 p-5">
          <p className="text-[11px] font-semibold uppercase tracking-wider text-amber-600 mb-1">Fallidos</p>
          <p className="text-2xl font-bold text-amber-700">{stats.fallidos}</p>
        </div>
      </div>

      <div className="bg-white rounded-2xl border border-gray-100 p-5">
        <label className="flex items-center gap-2 cursor-pointer mb-3">
          <input type="checkbox" checked={incluirCupon} onChange={(e) => setIncluirCupon(e.target.checked)} className="w-4 h-4 rounded accent-[#54A6D8] cursor-pointer" />
          <span className="text-xs font-semibold text-gray-500 uppercase tracking-widest">Incluir cupón de descuento</span>
        </label>
        {incluirCupon && (
          <div>
            <label className="block text-xs font-semibold text-gray-500 uppercase tracking-widest mb-2">Código de cupón (creado en /admin/cupones, sin servicio asociado)</label>
            <div className="flex gap-2">
              <input
                type="text"
                value={codigoCupon}
                onChange={(e) => setCodigoCupon(e.target.value.toUpperCase())}
                placeholder="REACTIVACION-DORMIDOS"
                className="flex-1 px-4 py-2.5 border border-gray-200 rounded-xl font-mono uppercase text-sm focus:border-[#54A6D8] focus:ring-1 focus:ring-[#54A6D8]/30 outline-none"
              />
              <button type="button" onClick={consultarCupon} className="px-4 py-2.5 bg-gray-100 hover:bg-gray-200 rounded-xl text-sm font-semibold text-gray-700">
                Validar
              </button>
            </div>
            {errorCupon && <p className="text-xs text-rose-500 mt-2">{errorCupon}</p>}
            {infoCupon && (
              <p className="text-xs text-gray-500 mt-2">
                {infoCupon.porcentaje}% de descuento · {infoCupon.fechaExpiracion ? `Vence ${new Date(infoCupon.fechaExpiracion).toLocaleDateString("es-CL")}` : "Sin fecha límite"}
              </p>
            )}
          </div>
        )}
      </div>

      <div className="flex flex-wrap gap-2">
        {(
          [
            ["pendiente", "Pendientes", stats.pendientes + stats.fallidos],
            ["enviado", "Ya enviados", stats.enviados],
            ["todos", "Todos", stats.total],
          ] as const
        ).map(([key, label, cnt]) => (
          <button
            key={key}
            type="button"
            onClick={() => setFiltroEstado(key)}
            className={`px-4 py-2 rounded-xl text-sm font-semibold border transition flex items-center gap-1.5 ${
              filtroEstado === key ? "bg-[#54A6D8] text-white border-[#54A6D8]" : "bg-white text-gray-600 border-gray-200 hover:border-[#54A6D8] hover:text-[#54A6D8]"
            }`}
          >
            {label}
            <span className={`${filtroEstado === key ? "bg-white/25 text-white" : "bg-gray-100 text-gray-500"} text-[10px] font-bold px-1.5 py-0.5 rounded-full`}>{cnt}</span>
          </button>
        ))}
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <span className="text-xs font-semibold text-gray-500 uppercase tracking-wider mr-1 shrink-0">Proveedor:</span>
        {(Object.keys(PROVEEDOR_LABEL) as Proveedor[]).map((p) => {
          const activo = proveedoresActivos.has(p);
          return (
            <button
              key={p}
              type="button"
              onClick={() => toggleProveedor(p)}
              className={`px-3 py-1.5 rounded-xl text-xs font-semibold border transition flex items-center gap-1.5 ${
                activo ? "bg-white text-gray-700 border-gray-300 hover:border-[#54A6D8]" : "bg-gray-50 text-gray-300 border-gray-100 opacity-50 hover:opacity-75"
              }`}
            >
              {PROVEEDOR_LABEL[p]}
              <span className={`${activo ? "bg-gray-100 text-gray-500" : "bg-gray-100 text-gray-300"} text-[10px] font-bold px-1.5 py-0.5 rounded-full`}>{conteoProveedores[p]}</span>
            </button>
          );
        })}
      </div>

      {filas.length === 0 ? (
        <div className="bg-white border border-dashed border-gray-200 rounded-2xl p-16 text-center text-gray-400 text-sm">No hay usuarios en este estado.</div>
      ) : (
        <div className="bg-white rounded-2xl border border-gray-100 overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 border-b border-gray-100 text-gray-500 text-xs font-semibold uppercase tracking-wider">
                <tr>
                  <th className="px-4 py-3.5 w-10 text-center">
                    <input type="checkbox" checked={filas.every((f) => seleccionados.has(f.alumnoId))} onChange={toggleSeleccionarTodo} className="w-4 h-4 rounded accent-[#54A6D8] cursor-pointer" />
                  </th>
                  <th className="px-4 py-3.5 text-left">ID</th>
                  <th className="px-4 py-3.5 text-left">Nombre</th>
                  <th className="px-4 py-3.5 text-left hidden md:table-cell">Correo</th>
                  <th className="px-4 py-3.5 text-left">Estado</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-50">
                {filas.map((f) => (
                  <tr key={f.alumnoId} className="hover:bg-gray-50/70 transition-colors">
                    <td className="px-4 py-3 text-center">
                      <input type="checkbox" checked={seleccionados.has(f.alumnoId)} onChange={() => toggleSeleccion(f.alumnoId)} className="w-4 h-4 rounded accent-[#54A6D8] cursor-pointer" />
                    </td>
                    <td className="px-4 py-3 text-xs text-gray-400 font-mono">{f.alumnoId}</td>
                    <td className="px-4 py-3 font-semibold text-gray-800">{f.nombre}</td>
                    <td className="px-4 py-3 text-xs text-gray-500 font-mono hidden md:table-cell">{f.correo}</td>
                    <td className="px-4 py-3">
                      <span className={`inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-bold ${ESTADO_BADGE[f.estado]}`}>
                        {f.estado === "enviado" ? "Enviado" : f.estado === "fallo" ? "Falló" : "Pendiente"}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="px-5 py-3 bg-gray-50 border-t border-gray-100 text-xs text-gray-400 text-right">
            {filas.length} usuario{filas.length !== 1 ? "s" : ""}
          </div>
        </div>
      )}

      {error && <p className="text-rose-500 text-sm">{error}</p>}
      {resultado && (
        <p className="text-emerald-600 text-sm font-medium">
          {resultado.enviados} enviado{resultado.enviados !== 1 ? "s" : ""}
          {resultado.fallidos > 0 ? `, ${resultado.fallidos} fallido${resultado.fallidos !== 1 ? "s" : ""}` : ""}
          {resultado.omitidos > 0 ? `, ${resultado.omitidos} omitido${resultado.omitidos !== 1 ? "s" : ""} (ya no calificaba)` : ""}
        </p>
      )}

      {seleccionados.size > 0 && (
        <div className="fixed bottom-0 left-0 right-0 lg:left-64 z-50 bg-white border-t border-gray-200 shadow-xl px-6 py-4 flex items-center justify-between gap-4">
          <p className="text-sm font-semibold text-gray-700">
            {seleccionados.size} seleccionado{seleccionados.size !== 1 ? "s" : ""}
            {seleccionados.size > TOPE_DESTINATARIOS_POR_ENVIO && <span className="text-rose-500"> — supera el máximo de {TOPE_DESTINATARIOS_POR_ENVIO}</span>}
          </p>
          <button
            type="button"
            disabled={enviando || seleccionados.size > TOPE_DESTINATARIOS_POR_ENVIO}
            onClick={enviarSeleccionados}
            className="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold shadow-sm transition bg-[#54A6D8] hover:bg-sky-500 text-white disabled:bg-gray-200 disabled:text-gray-400 disabled:cursor-not-allowed"
          >
            {enviando ? "Enviando…" : "Enviar a seleccionados"}
          </button>
        </div>
      )}
    </div>
  );
}
