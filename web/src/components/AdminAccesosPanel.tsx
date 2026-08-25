"use client";

import { useEffect, useMemo, useState } from "react";
import type { AccesosResumen, DetalleResumen, TabAccesos, UsuarioTrafico } from "@/lib/api";

const TABS: { key: TabAccesos; label: string }[] = [
  { key: "trafico", label: "Tráfico Real" },
  { key: "bots", label: "Bots / Crawlers" },
  { key: "paginas", label: "Top Páginas" },
  { key: "fallidas", label: "Búsquedas Fallidas" },
];

type FiltroTarjeta = "todos" | "alumnos" | "invitados" | "online";

interface UbicacionInfo {
  ciudad: string | null;
  pais: string | null;
}

function esOnline(iso: string): boolean {
  return Date.now() - new Date(iso).getTime() < 300_000;
}

// Puerto de admin_accesos_vitrina.php ("Analíticas"). Escritura completa (eliminar selección
// de eventos, purgar bots antiguos) — confirmado con el usuario antes de construir: DELETE
// puros sobre historial_actividad (log), mismo nivel de riesgo ya aceptado en los toggles
// anteriores. Simplificación deliberada y documentada: la geolocalización se reduce a texto
// "Ciudad, País" (vía /api/admin/accesos/geolocalizar) sin los mapas embebidos ni el tooltip
// hover interactivo del PHP real — mismo dato, sin la capa decorativa. El toggle "En Vivo"
// (auto-refresh cada 30s) tampoco se replica — nice-to-have de bajo valor frente al costo de
// mantenerlo sincronizado con el estado de selección de la vista detalle.
export function AdminAccesosPanel({ resumenInicial, phpSiteUrl }: { resumenInicial: AccesosResumen; phpSiteUrl: string }) {
  const [resumen, setResumen] = useState(resumenInicial);
  const [cargando, setCargando] = useState(false);
  const [filtroTarjeta, setFiltroTarjeta] = useState<FiltroTarjeta>("todos");
  const [detalle, setDetalle] = useState<DetalleResumen | null>(null);
  const [seleccionados, setSeleccionados] = useState<Set<number>>(new Set());
  const [ubicaciones, setUbicaciones] = useState<Record<string, UbicacionInfo>>({});
  const [error, setError] = useState<string | null>(null);
  const [procesando, setProcesando] = useState(false);

  async function cargarTab(tab: TabAccesos) {
    setCargando(true);
    setDetalle(null);
    setError(null);
    try {
      const res = await fetch(`/api/admin/accesos?tab=${tab}`);
      if (res.ok) setResumen(await res.json());
    } finally {
      setCargando(false);
    }
  }

  async function abrirDetalle(usuarioId: number, ip: string | null) {
    setCargando(true);
    setError(null);
    setSeleccionados(new Set());
    try {
      const qs = new URLSearchParams({ uid: String(usuarioId) });
      if (ip) qs.set("ip", ip);
      const res = await fetch(`/api/admin/accesos/detalle?${qs.toString()}`);
      if (res.ok) setDetalle(await res.json());
    } finally {
      setCargando(false);
    }
  }

  function volver() {
    setDetalle(null);
    setSeleccionados(new Set());
  }

  async function eliminarSeleccionados() {
    if (seleccionados.size === 0) return;
    if (!confirm(`¿Eliminar ${seleccionados.size} evento(s) del registro? Esta acción no se puede deshacer.`)) return;
    setProcesando(true);
    try {
      const res = await fetch("/api/admin/accesos/eliminar", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ ids: Array.from(seleccionados) }),
      });
      if (res.ok && detalle) {
        setDetalle({ ...detalle, eventos: detalle.eventos.filter((e) => !seleccionados.has(e.id)) });
        setSeleccionados(new Set());
      } else {
        setError("No se pudo eliminar la selección.");
      }
    } finally {
      setProcesando(false);
    }
  }

  async function purgarBots() {
    if (!confirm("¿Eliminar registros de bots de más de 30 días? Esta acción es irreversible.")) return;
    setProcesando(true);
    try {
      const res = await fetch("/api/admin/accesos/purgar-bots", { method: "POST" });
      if (res.ok) await cargarTab("bots");
      else setError("No se pudo purgar los bots antiguos.");
    } finally {
      setProcesando(false);
    }
  }

  // Geolocalización simplificada: junta las IPs visibles (tarjetas de tráfico o timeline de
  // detalle) y pide "Ciudad, País" en un solo POST batch, sin mapas ni tooltip.
  useEffect(() => {
    const ips = new Set<string>();
    if (!detalle && resumen.trafico) {
      for (const u of resumen.trafico.usuarios) if (u.ipUsuario) ips.add(u.ipUsuario);
    }
    if (detalle) {
      for (const e of detalle.eventos) if (e.ipUsuario) ips.add(e.ipUsuario);
    }
    const faltantes = Array.from(ips).filter((ip) => !(ip in ubicaciones) && !["0.0.0.0", "::1", "127.0.0.1"].includes(ip));
    if (faltantes.length === 0) return;

    let cancelado = false;
    const marcarSinDatos = () => {
      if (cancelado) return;
      setUbicaciones((prev) => {
        const next = { ...prev };
        for (const ip of faltantes) next[ip] = { ciudad: null, pais: null };
        return next;
      });
    };

    fetch("/api/admin/accesos/geolocalizar", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ ips: faltantes }),
    })
      .then((r) => r.json())
      .then((json) => {
        if (cancelado) return;
        // json.ok=false llega como respuesta HTTP resuelta (ej. 403 si la sesión PHP no
        // está activa — ver nota en el proxy), no como fetch-reject: sin este chequeo acá
        // (además del catch de abajo, que solo cubre fallos de red) esas IPs quedaban
        // atascadas en "Cargando…" para siempre en vez de caer a "Sin datos".
        if (!json?.ok || !json.data) {
          marcarSinDatos();
          return;
        }
        setUbicaciones((prev) => {
          const next = { ...prev };
          for (const ip of faltantes) {
            const info = json.data[ip];
            next[ip] = info ? { ciudad: info.ciudad, pais: info.pais } : { ciudad: null, pais: null };
          }
          return next;
        });
      })
      .catch(marcarSinDatos);
    return () => {
      cancelado = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [resumen, detalle]);

  function ubicacionTexto(ip: string | null): string {
    if (!ip) return "—";
    if (["0.0.0.0", "::1", "127.0.0.1"].includes(ip)) return "Localhost / Red Interna";
    const info = ubicaciones[ip];
    if (!info) return "Cargando…";
    if (!info.ciudad && !info.pais) return "Sin datos";
    return [info.ciudad, info.pais].filter(Boolean).join(", ");
  }

  const tarjetasFiltradas = useMemo(() => {
    const usuarios = resumen.trafico?.usuarios ?? [];
    if (filtroTarjeta === "todos") return usuarios;
    if (filtroTarjeta === "alumnos") return usuarios.filter((u) => u.usuarioId !== 0);
    if (filtroTarjeta === "invitados") return usuarios.filter((u) => u.usuarioId === 0);
    return usuarios.filter((u) => esOnline(u.ultimaActividad));
  }, [resumen.trafico, filtroTarjeta]);

  if (detalle) {
    return (
      <VistaDetalle
        detalle={detalle}
        cargando={cargando}
        procesando={procesando}
        error={error}
        seleccionados={seleccionados}
        setSeleccionados={setSeleccionados}
        ubicacionTexto={ubicacionTexto}
        onVolver={volver}
        onEliminar={eliminarSeleccionados}
        phpSiteUrl={phpSiteUrl}
      />
    );
  }

  return (
    <div className="space-y-4">
      {error && <div className="bg-red-50 text-red-600 p-3 rounded-xl text-sm font-medium border border-red-200">{error}</div>}

      <div className="flex items-center justify-between gap-3 flex-wrap">
        <div className="flex gap-1 bg-gray-50 rounded-xl p-1 overflow-x-auto">
          {TABS.map((t) => (
            <button
              key={t.key}
              type="button"
              onClick={() => cargarTab(t.key)}
              className={`px-4 py-2 rounded-lg text-xs md:text-sm font-bold whitespace-nowrap transition-colors ${
                resumen.tab === t.key ? "bg-white text-[#54A6D8] shadow-sm" : "text-gray-500 hover:text-gray-700"
              }`}
            >
              {t.label}
            </button>
          ))}
        </div>
        <a
          href={`/api/admin/accesos/exportar`}
          className="text-gray-500 hover:text-emerald-600 hover:bg-emerald-50 transition-colors text-xs font-bold uppercase tracking-widest px-4 py-2 rounded-xl border border-gray-200"
        >
          Exportar CSV
        </a>
      </div>

      {cargando ? (
        <div className="text-center py-16 text-gray-400 font-medium">Cargando...</div>
      ) : resumen.tab === "trafico" ? (
        <div className="space-y-4">
          <div className="flex items-center gap-3 overflow-x-auto pb-1">
            {(["todos", "alumnos", "invitados", "online"] as FiltroTarjeta[]).map((f) => (
              <button
                key={f}
                type="button"
                onClick={() => setFiltroTarjeta(f)}
                className={`shrink-0 rounded-full px-4 py-1.5 text-xs font-bold border transition-colors ${
                  filtroTarjeta === f ? "bg-gray-900 text-white border-gray-900" : "bg-white text-gray-600 border-gray-200 hover:border-gray-300"
                }`}
              >
                {f === "todos" ? "Todos" : f === "alumnos" ? "Alumnos" : f === "invitados" ? "Invitados" : "Online Ahora"}
              </button>
            ))}
            {resumen.trafico && (
              <div className="ml-auto text-[10px] font-bold text-gray-400 uppercase tracking-widest whitespace-nowrap pr-2">
                {resumen.trafico.contadores.alumnos} alumnos · {resumen.trafico.contadores.invitados} invitados ·{" "}
                <span className="text-purple-500">{resumen.trafico.contadores.bots} bots</span>
              </div>
            )}
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
            {tarjetasFiltradas.length === 0 ? (
              <div className="col-span-full text-center py-16 text-gray-400 font-medium">Sin tráfico real en los últimos 14 días.</div>
            ) : (
              tarjetasFiltradas.map((u) => <TarjetaUsuario key={`${u.usuarioId}-${u.ipUsuario}`} u={u} ubicacionTexto={ubicacionTexto} onClick={abrirDetalle} />)
            )}
          </div>
        </div>
      ) : resumen.tab === "bots" ? (
        <div className="space-y-4">
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <StatCard label="Eventos de Bots (30d)" value={resumen.bots?.stats.totalEventos ?? 0} />
            <StatCard label="IPs Únicas" value={resumen.bots?.stats.ipsUnicas ?? 0} />
            <StatCard label="Bots Distintos" value={resumen.bots?.stats.botsUnicos ?? 0} />
          </div>
          <div className="flex justify-end">
            <button
              type="button"
              onClick={purgarBots}
              disabled={procesando}
              className="text-purple-600 hover:text-purple-800 text-[10px] font-black uppercase tracking-widest px-3 py-2 rounded-xl bg-purple-50 border border-purple-100 hover:bg-purple-100 disabled:opacity-50"
            >
              Purgar Bots Antiguos
            </button>
          </div>
          <div className="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
            <table className="w-full text-left text-sm">
              <thead className="bg-gray-50 text-[10px] uppercase text-gray-400 font-bold tracking-widest border-b border-gray-100">
                <tr>
                  <th className="px-6 py-4">Bot / Crawler</th>
                  <th className="px-6 py-4">IP</th>
                  <th className="px-6 py-4 text-center">Hits</th>
                  <th className="px-6 py-4 text-right">Última visita</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-50">
                {(resumen.bots?.bots ?? []).length === 0 ? (
                  <tr>
                    <td colSpan={4} className="px-6 py-16 text-center text-gray-400">
                      Sin actividad de bots en los últimos 30 días.
                    </td>
                  </tr>
                ) : (
                  resumen.bots!.bots.map((b, i) => (
                    <tr key={i} className="hover:bg-purple-50/30 transition-colors">
                      <td className="px-6 py-4">
                        <p className="text-[10px] font-mono text-gray-400 truncate max-w-md" title={b.userAgent ?? ""}>
                          {(b.userAgent ?? "Sin User-Agent").slice(0, 80)}
                        </p>
                      </td>
                      <td className="px-6 py-4 font-mono text-xs text-[#54A6D8]">{b.ipUsuario}</td>
                      <td className="px-6 py-4 text-center">
                        <span className="inline-flex items-center justify-center bg-purple-50 text-purple-700 font-black text-xs px-2.5 py-1 rounded-full border border-purple-100">
                          {b.totalHits.toLocaleString("es-CL")}
                        </span>
                      </td>
                      <td className="px-6 py-4 text-right text-[11px] font-mono text-gray-500">{new Date(b.ultimaVisita).toLocaleString("es-CL")}</td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>
      ) : resumen.tab === "paginas" ? (
        <div className="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
          <table className="w-full text-left text-sm">
            <thead className="bg-gray-50 text-[10px] uppercase text-gray-400 font-bold tracking-widest border-b border-gray-100">
              <tr>
                <th className="px-6 py-4">URL</th>
                <th className="px-6 py-4 text-center">Hits</th>
                <th className="px-6 py-4 text-center">Visitantes únicos</th>
                <th className="px-6 py-4 text-right">% del total</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-50">
              {(resumen.paginas?.paginas ?? []).length === 0 ? (
                <tr>
                  <td colSpan={4} className="px-6 py-16 text-center text-gray-400">
                    Sin datos de páginas en los últimos 14 días.
                  </td>
                </tr>
              ) : (
                resumen.paginas!.paginas.map((p, i) => {
                  const pct = resumen.paginas!.totalHits > 0 ? Math.round((p.hits / resumen.paginas!.totalHits) * 1000) / 10 : 0;
                  return (
                    <tr key={i} className="hover:bg-sky-50/30 transition-colors">
                      <td className="px-6 py-4 max-w-xs xl:max-w-lg">
                        <a href={p.url} target="_blank" rel="noopener noreferrer" className="font-mono text-xs text-[#54A6D8] hover:underline truncate block">
                          {p.url.length > 60 ? p.url.slice(0, 60) + "…" : p.url}
                        </a>
                      </td>
                      <td className="px-6 py-4 text-center">
                        <span className="inline-flex items-center justify-center bg-sky-50 text-[#54A6D8] font-black text-xs px-2.5 py-1 rounded-full border border-sky-100">
                          {p.hits.toLocaleString("es-CL")}
                        </span>
                      </td>
                      <td className="px-6 py-4 text-center text-xs font-bold text-gray-600">{p.uniques.toLocaleString("es-CL")}</td>
                      <td className="px-6 py-4 text-right text-xs font-bold text-gray-700">{pct}%</td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>
      ) : (
        <div className="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
          <table className="w-full text-left text-sm">
            <thead className="bg-gray-50 text-[10px] uppercase text-gray-400 font-bold tracking-widest border-b border-gray-100">
              <tr>
                <th className="px-6 py-4">Término Buscado</th>
                <th className="px-6 py-4 text-center">Intentos</th>
                <th className="px-6 py-4 text-right">Última Búsqueda</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-50">
              {(resumen.fallidas?.busquedas ?? []).length === 0 ? (
                <tr>
                  <td colSpan={3} className="px-6 py-16 text-center text-gray-400">
                    No hay búsquedas fallidas registradas.
                  </td>
                </tr>
              ) : (
                resumen.fallidas!.busquedas.map((b, i) => (
                  <tr key={i} className="hover:bg-orange-50/30 transition-colors">
                    <td className="px-6 py-4 font-bold text-gray-800 text-sm">{b.termino.charAt(0).toUpperCase() + b.termino.slice(1).toLowerCase()}</td>
                    <td className="px-6 py-4 text-center">
                      <span className="inline-flex items-center justify-center bg-gray-50 text-gray-700 font-black text-xs w-8 h-8 rounded-full border border-gray-200">
                        {b.totalIntentos}
                      </span>
                    </td>
                    <td className="px-6 py-4 text-right text-[11px] font-mono text-gray-500">{new Date(b.ultimaBusqueda).toLocaleString("es-CL")}</td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}

function StatCard({ label, value }: { label: string; value: number }) {
  return (
    <div className="bg-white rounded-2xl p-5 border border-gray-100 shadow-sm">
      <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1.5">{label}</p>
      <p className="text-2xl font-black text-gray-900 tracking-tight">{value.toLocaleString("es-CL")}</p>
    </div>
  );
}

function TarjetaUsuario({
  u,
  ubicacionTexto,
  onClick,
}: {
  u: UsuarioTrafico;
  ubicacionTexto: (ip: string | null) => string;
  onClick: (usuarioId: number, ip: string | null) => void;
}) {
  const esInvitado = u.usuarioId === 0;
  const online = esOnline(u.ultimaActividad);
  const nombreCorto = esInvitado ? `Invitado ${(u.ipUsuario ?? "").slice(-8)}` : (u.nombre ?? "Usuario");

  return (
    <button
      type="button"
      onClick={() => onClick(u.usuarioId, esInvitado ? u.ipUsuario : null)}
      className={`text-left rounded-2xl p-5 border transition-all hover:shadow-md hover:scale-[1.01] shadow-sm flex flex-col h-full ${esInvitado ? "border-gray-200 bg-gray-50/50" : "border-gray-100 bg-white hover:border-[#54A6D8]"}`}
    >
      <div className="flex items-start gap-3 mb-3">
        <div className={`w-12 h-12 rounded-xl relative flex items-center justify-center shrink-0 ${esInvitado ? "bg-gray-200 text-gray-500" : "bg-gray-100 text-gray-400"}`}>
          <span className="font-bold text-lg">{esInvitado ? "?" : (u.nombre ?? "U").charAt(0).toUpperCase()}</span>
          {online && <span className="absolute -bottom-1 -right-1 w-3.5 h-3.5 bg-emerald-500 border-2 border-white rounded-full" />}
        </div>
        <div className="min-w-0 flex-1">
          <h3 className={`font-extrabold truncate text-sm ${esInvitado ? "text-gray-600" : "text-gray-900"}`}>{nombreCorto}</h3>
          <p className="text-[10px] text-gray-500 font-medium truncate mt-0.5">{ubicacionTexto(u.ipUsuario)}</p>
          <div className="mt-2 flex items-center gap-1.5 text-[10px] border-t border-gray-50 pt-2">
            <span className="font-bold text-gray-500 uppercase tracking-widest truncate max-w-[45%]">{(u.ultimaAccionTxt ?? "N/A").replace(/_/g, " ")}</span>
            <span className="text-gray-300">•</span>
            <span className="font-mono text-[#54A6D8] truncate flex-1">{u.ultimaUrl ?? "/"}</span>
          </div>
        </div>
      </div>
      <div className="mt-auto flex justify-between items-end pt-3 border-t border-gray-50">
        <div>
          <span className="text-[9px] text-gray-400 uppercase font-bold tracking-widest block mb-1">Últ. Conexión</span>
          <span className="text-[11px] text-gray-600 font-mono font-medium">{new Date(u.ultimaActividad).toLocaleString("es-CL", { hour: "2-digit", minute: "2-digit", day: "2-digit", month: "short" })}</span>
        </div>
        <div className="text-right">
          <span className="text-[10px] text-gray-400 uppercase font-bold tracking-widest block mb-0.5">Eventos</span>
          <span className="text-lg font-black leading-none inline-block text-gray-800">{u.totalAcciones}</span>
        </div>
      </div>
    </button>
  );
}

function VistaDetalle({
  detalle,
  cargando,
  procesando,
  error,
  seleccionados,
  setSeleccionados,
  ubicacionTexto,
  onVolver,
  onEliminar,
  phpSiteUrl,
}: {
  detalle: DetalleResumen;
  cargando: boolean;
  procesando: boolean;
  error: string | null;
  seleccionados: Set<number>;
  setSeleccionados: (s: Set<number>) => void;
  ubicacionTexto: (ip: string | null) => string;
  onVolver: () => void;
  onEliminar: () => void;
  phpSiteUrl: string;
}) {
  const u = detalle.usuario;
  // Mismo esquema que web/src/app/admin/videos/page.tsx usa con Buffer (ahí corre en
  // servidor); acá el componente es cliente, así que se usa btoa() (disponible en el
  // navegador, a diferencia de Buffer) para el mismo cálculo base64.
  const urlPerfil = !u.esGuest ? `${phpSiteUrl}/perfil/${btoa(`${u.usuarioId}-nubira_secreto`).replace(/=+$/, "")}` : null;

  function alternarFila(id: number) {
    const next = new Set(seleccionados);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    setSeleccionados(next);
  }
  function alternarTodos(checked: boolean) {
    setSeleccionados(checked ? new Set(detalle.eventos.map((e) => e.id)) : new Set());
  }

  const qsExport = new URLSearchParams({ uid: String(u.usuarioId) });
  if (u.ip) qsExport.set("ip", u.ip);

  return (
    <div className="space-y-4">
      {error && <div className="bg-red-50 text-red-600 p-3 rounded-xl text-sm font-medium border border-red-200">{error}</div>}

      <div className="flex items-center justify-between">
        <button type="button" onClick={onVolver} className="inline-flex items-center gap-2 text-xs font-bold uppercase tracking-wide text-gray-400 hover:text-[#54A6D8] transition-colors">
          ← Volver
        </button>
        <a
          href={`/api/admin/accesos/exportar?${qsExport.toString()}`}
          className="text-white bg-[#54A6D8] hover:bg-sky-500 transition-colors text-[10px] font-bold uppercase tracking-widest px-4 py-2 rounded-xl shadow-sm"
        >
          Exportar Registro
        </a>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div className="bg-white rounded-2xl p-6 border border-gray-100 shadow-sm lg:col-span-2">
          <div className="flex items-center gap-5">
            <div className={`w-16 h-16 rounded-2xl relative border border-gray-100 flex items-center justify-center shrink-0 ${u.fueBot ? "bg-purple-100 text-purple-600" : "bg-gray-50 text-gray-300"}`}>
              <span className="text-2xl font-bold">{u.esGuest ? "?" : u.nombre.charAt(0).toUpperCase()}</span>
              {u.online && !u.fueBot && <span className="absolute -bottom-1 -right-1 w-4 h-4 bg-emerald-500 border-[3px] border-white rounded-full" />}
            </div>
            <div>
              <h1 className="text-xl font-extrabold text-gray-900 flex items-center gap-2 tracking-tight">
                {urlPerfil ? (
                  <a href={urlPerfil} target="_blank" rel="noopener noreferrer" className="hover:text-[#54A6D8] hover:underline transition-colors">
                    {u.nombre}
                  </a>
                ) : (
                  u.nombre
                )}
                {u.fueBot && <span className="text-[9px] bg-purple-50 text-purple-600 border border-purple-100 px-2 py-0.5 rounded-md font-bold uppercase">Bot</span>}
                {u.online && !u.fueBot && <span className="text-[9px] bg-emerald-50 text-emerald-600 border border-emerald-100 px-2 py-0.5 rounded-md font-bold uppercase">Online</span>}
              </h1>
              <p className="text-sm text-gray-500 font-medium">{u.correo ?? "Sin correo"}</p>
            </div>
          </div>
          <div className="grid grid-cols-2 gap-4 mt-4 pt-4 border-t border-gray-50">
            <div>
              <span className="text-[10px] font-bold text-gray-400 uppercase tracking-widest block mb-1">Total Eventos</span>
              <p className="text-xl font-black text-gray-900">{u.totalEventos.toLocaleString("es-CL")}</p>
            </div>
            <div>
              <span className="text-[10px] font-bold text-gray-400 uppercase tracking-widest block mb-1">Top Acción</span>
              <p className="text-sm font-bold text-gray-900 truncate mt-1">{u.accionFav}</p>
            </div>
          </div>
        </div>

        <div className="bg-white rounded-2xl p-4 border border-gray-100 shadow-sm flex flex-col justify-between">
          <div>
            <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Ubicación (IP)</p>
            <p className="text-sm font-bold text-gray-800">{ubicacionTexto(u.ip)}</p>
            <p className="text-[10px] font-mono text-gray-400 mt-1">{u.ip ?? "—"}</p>
          </div>
          <div className="mt-3 pt-2 border-t border-gray-50 flex justify-between items-center">
            <span className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Última Conex.</span>
            <span className="text-xs font-mono text-gray-600 font-medium">{u.ultimaVisita ? new Date(u.ultimaVisita).toLocaleString("es-CL") : "N/A"}</span>
          </div>
        </div>
      </div>

      <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
        <h3 className="font-bold text-gray-900 text-sm uppercase tracking-widest mb-4">Resumen de Trayectoria</h3>
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          <ResumenCard titulo="Origen" valor={u.primerReferrer ? hostnameSeguro(u.primerReferrer) : u.primerUtm ? `UTM: ${u.primerUtm}` : "Directo / Desconocido"} />
          <div className="bg-gray-50 rounded-xl p-4">
            <span className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Comportamiento</span>
            <div className="space-y-1.5 mt-2">
              <FilaMetrica label="Págs. únicas" valor={u.urlsUnicas} />
              <FilaMetrica label="Días activo" valor={u.diasDesdePrimera === 0 ? "Hoy" : `${u.diasDesdePrimera}d`} />
            </div>
          </div>
          <div className="bg-gray-50 rounded-xl p-4">
            <span className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Conversión</span>
            {u.esGuest ? (
              <p className="text-[10px] text-gray-400 mt-2">No aplica para visitantes</p>
            ) : (
              <div className="space-y-2 mt-2">
                <FilaBooleana label="Contacto" activo={!!u.primerContacto} fecha={u.primerContacto} />
                <FilaBooleana label="Publicó Apunte" activo={!!u.primerApunte} fecha={u.primerApunte} />
              </div>
            )}
          </div>
          <ResumenCard titulo="Primera visita" valor={u.primeraVisita ? new Date(u.primeraVisita).toLocaleDateString("es-CL") : "—"} />
        </div>
      </div>

      <div className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
        <div className="px-6 py-4 border-b border-gray-50 flex justify-between items-center">
          <h3 className="font-bold text-gray-900 text-sm">Línea de Tiempo Detallada</h3>
          <button
            type="button"
            onClick={onEliminar}
            disabled={seleccionados.size === 0 || procesando}
            className="text-red-500 hover:text-red-700 text-[10px] font-black uppercase tracking-widest disabled:opacity-30 transition-opacity"
          >
            Eliminar Selección
          </button>
        </div>
        <div className="max-h-[600px] overflow-y-auto">
          <table className="w-full text-left border-collapse">
            <thead className="bg-gray-50 sticky top-0 text-[10px] font-bold text-gray-400 uppercase tracking-widest border-b border-gray-100">
              <tr>
                <th className="px-5 py-3.5 w-12">
                  <input type="checkbox" onChange={(e) => alternarTodos(e.target.checked)} checked={seleccionados.size > 0 && seleccionados.size === detalle.eventos.length} />
                </th>
                <th className="px-4 py-3.5">Evento</th>
                <th className="px-4 py-3.5">Descripción</th>
                <th className="px-4 py-3.5">Ubicación de Red</th>
                <th className="px-5 py-3.5 text-right">Timestamp</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-50 text-sm">
              {cargando ? (
                <tr>
                  <td colSpan={5} className="text-center py-12 text-gray-400">
                    Cargando...
                  </td>
                </tr>
              ) : detalle.eventos.length === 0 ? (
                <tr>
                  <td colSpan={5} className="text-center py-12 text-gray-400">
                    Sin eventos registrados.
                  </td>
                </tr>
              ) : (
                detalle.eventos.map((e) => (
                  <tr key={e.id} className={`hover:bg-gray-50/50 transition-colors ${e.esBot ? "bg-purple-50/30" : ""}`}>
                    <td className="px-5 py-3">
                      <input type="checkbox" checked={seleccionados.has(e.id)} onChange={() => alternarFila(e.id)} />
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap">
                      <span className="inline-flex items-center px-2 py-1 rounded-md text-[9px] font-black bg-gray-50 text-gray-500 uppercase tracking-widest">{e.accion}</span>
                      {e.esBot && <span className="ml-1 px-1.5 py-0.5 rounded text-[8px] font-black bg-purple-50 text-purple-600 uppercase">Bot</span>}
                    </td>
                    <td className="px-4 py-3">
                      <p className="text-gray-700 text-xs font-medium max-w-xs truncate" title={e.detalle ?? "-"}>
                        {e.detalle ?? "-"}
                      </p>
                      {e.url && (
                        <a href={e.url} target="_blank" rel="noopener noreferrer" className="text-[10px] font-mono text-[#54A6D8] hover:underline truncate block mt-0.5">
                          {e.url}
                        </a>
                      )}
                    </td>
                    <td className="px-4 py-3">
                      <div className="text-[11px] text-gray-500 font-medium truncate">{ubicacionTexto(e.ipUsuario)}</div>
                      <div className="text-[9px] font-mono text-gray-400 mt-0.5">{e.ipUsuario}</div>
                    </td>
                    <td className="px-5 py-3 text-right whitespace-nowrap">
                      <span className="text-[11px] font-mono font-medium text-gray-600 block">{new Date(e.fecha).toLocaleTimeString("es-CL")}</span>
                      <span className="text-[9px] font-bold text-gray-400 uppercase tracking-widest">{new Date(e.fecha).toLocaleDateString("es-CL")}</span>
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

function hostnameSeguro(url: string): string {
  try {
    return new URL(url).hostname;
  } catch {
    return url;
  }
}

function ResumenCard({ titulo, valor }: { titulo: string; valor: string }) {
  return (
    <div className="bg-gray-50 rounded-xl p-4">
      <span className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">{titulo}</span>
      <p className="text-sm font-bold text-gray-800 mt-2 truncate">{valor}</p>
    </div>
  );
}

function FilaMetrica({ label, valor }: { label: string; valor: string | number }) {
  return (
    <div className="flex justify-between items-center">
      <span className="text-[10px] text-gray-500">{label}</span>
      <span className="text-xs font-black text-gray-900">{valor}</span>
    </div>
  );
}

function FilaBooleana({ label, activo, fecha }: { label: string; activo: boolean; fecha: string | null }) {
  return (
    <div className="flex items-center gap-2">
      <span className={`w-4 h-4 rounded-full flex items-center justify-center shrink-0 text-[7px] ${activo ? "bg-emerald-500 text-white" : "bg-gray-200 text-gray-400"}`}>{activo ? "✓" : "–"}</span>
      {activo ? (
        <div className="min-w-0">
          <span className="text-[10px] font-bold text-gray-800 block">{label}</span>
          <span className="text-[9px] font-mono text-gray-400">{fecha ? new Date(fecha).toLocaleString("es-CL") : ""}</span>
        </div>
      ) : (
        <span className="text-[10px] text-gray-400">Sin {label.toLowerCase()} aún</span>
      )}
    </div>
  );
}
