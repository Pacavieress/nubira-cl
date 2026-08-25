"use client";

import { useEffect, useState } from "react";
import type { ArchivoModeracion, ChatDetalle, ChatListado, ContadoresChats, FiltroChats } from "@/lib/api";

const FILTROS: { key: FiltroChats; label: string; badge?: (c: ContadoresChats) => number }[] = [
  { key: "activos", label: "Activos", badge: (c) => c.activos },
  { key: "alertas_dlp", label: "Alertas DLP", badge: (c) => c.alertasDlp },
  { key: "moderacion", label: "Moderación", badge: (c) => c.moderacion },
  { key: "contrato", label: "Con contrato", badge: (c) => c.contrato },
  { key: "cotizacion", label: "Cotización", badge: (c) => c.cotizacion },
  { key: "inactivos", label: "+7d", badge: (c) => c.inactivos },
  { key: "cerrados", label: "Cerrados", badge: (c) => c.cerrados },
];

function nombreCorto(nombre: string): string {
  const partes = nombre.trim().split(/\s+/);
  return partes[0] + (partes[1] ? ` ${partes[1].charAt(0)}.` : "");
}

function fotoUrl(foto: string | null, phpSiteUrl: string): string | null {
  return foto ? `${phpSiteUrl}/app/perfil/fotos/${encodeURIComponent(foto)}` : null;
}

function perfilUrl(id: number, phpSiteUrl: string): string {
  return `${phpSiteUrl}/perfil/${btoa(`${id}-nubira_secreto`).replace(/=+$/, "")}`;
}

// Puerto de admin_chats.php ("Master Tracker"). Escritura: eliminar/restaurar chat (toggle de
// conversaciones.eliminado), marcar DLP revisado (toggle de dlp_intentos.revisado_admin),
// aprobar archivo de moderación (toggle de mensajes.visible) — las 3 confirmadas con el
// usuario antes de construir, DB-only y reversibles. EXCLUIDAS: "liberar_mensaje_dlp" (nunca
// se porta sin aprobación explícita dedicada del usuario — no es un "excluido por ahora"
// retomable) y "rechazar_archivo" (borra el archivo del disco, mismo bucket que "eliminar" en
// Apuntes) — ambas enlazan al panel PHP real. Se omiten deliberadamente el polling "en vivo",
// los atajos de teclado (↑↓ navegar, / buscar, Esc volver) y el panel redimensionable con
// drag — decorativos, sin pérdida de información ni de ninguna mutación real.
export function AdminChatsPanel({
  chatsIniciales,
  contadoresIniciales,
  phpSiteUrl,
}: {
  chatsIniciales: ChatListado[];
  contadoresIniciales: ContadoresChats;
  phpSiteUrl: string;
}) {
  const [filtro, setFiltro] = useState<FiltroChats>("activos");
  const [orden, setOrden] = useState<"asc" | "desc">("desc");
  const [q, setQ] = useState("");
  const [chats, setChats] = useState(chatsIniciales);
  const [contadores, setContadores] = useState(contadoresIniciales);
  const [cargandoLista, setCargandoLista] = useState(false);
  const [chatSeleccionadoId, setChatSeleccionadoId] = useState<number | null>(null);
  const [detalle, setDetalle] = useState<ChatDetalle | null>(null);
  const [cargandoDetalle, setCargandoDetalle] = useState(false);
  const [moderacion, setModeracion] = useState<ArchivoModeracion[]>([]);
  const [procesando, setProcesando] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function refrescarContadores() {
    const res = await fetch("/api/admin/chats/contadores");
    if (res.ok) setContadores(await res.json());
  }

  async function cargarLista(f: FiltroChats, o: "asc" | "desc", busqueda: string) {
    setCargandoLista(true);
    try {
      const params = new URLSearchParams({ estado: f, orden: o });
      if (busqueda) params.set("q", busqueda);
      const res = await fetch(`/api/admin/chats?${params.toString()}`);
      if (res.ok) setChats((await res.json()).chats);
      if (f === "moderacion") {
        const resMod = await fetch("/api/admin/chats/moderacion");
        if (resMod.ok) setModeracion((await resMod.json()).archivos);
      }
    } finally {
      setCargandoLista(false);
    }
  }

  function cambiarFiltro(f: FiltroChats) {
    setFiltro(f);
    setChatSeleccionadoId(null);
    setDetalle(null);
    cargarLista(f, orden, q);
  }

  useEffect(() => {
    const t = setTimeout(() => cargarLista(filtro, orden, q), 300);
    return () => clearTimeout(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [q]);

  async function abrirChat(id: number) {
    setChatSeleccionadoId(id);
    setCargandoDetalle(true);
    setError(null);
    try {
      const res = await fetch(`/api/admin/chats/${id}`);
      if (res.ok) setDetalle(await res.json());
      else setError("No se pudo cargar la conversación.");
    } finally {
      setCargandoDetalle(false);
    }
  }

  async function eliminarChat(id: number) {
    if (!confirm("¿Archivar esta conversación?")) return;
    setProcesando(`eliminar-${id}`);
    try {
      const res = await fetch(`/api/admin/chats/${id}/eliminar`, { method: "POST" });
      if (res.ok) {
        await abrirChat(id);
        await Promise.all([cargarLista(filtro, orden, q), refrescarContadores()]);
      } else setError("No se pudo archivar la conversación.");
    } finally {
      setProcesando(null);
    }
  }

  async function restaurarChat(id: number) {
    setProcesando(`restaurar-${id}`);
    try {
      const res = await fetch(`/api/admin/chats/${id}/restaurar`, { method: "POST" });
      if (res.ok) {
        await abrirChat(id);
        await Promise.all([cargarLista(filtro, orden, q), refrescarContadores()]);
      } else setError("No se pudo restaurar la conversación.");
    } finally {
      setProcesando(null);
    }
  }

  async function marcarRevisadoDlp(id: number) {
    setProcesando(`dlp-${id}`);
    try {
      const res = await fetch(`/api/admin/chats/${id}/marcar-revisado-dlp`, { method: "POST" });
      if (res.ok) {
        await abrirChat(id);
        await refrescarContadores();
      } else setError("No se pudo marcar como revisado.");
    } finally {
      setProcesando(null);
    }
  }

  async function aprobarArchivo(msgId: number) {
    setProcesando(`aprobar-${msgId}`);
    try {
      const res = await fetch(`/api/admin/chats/moderacion/${msgId}/aprobar`, { method: "POST" });
      if (res.ok) {
        setModeracion((prev) => prev.filter((m) => m.id !== msgId));
        await refrescarContadores();
      } else setError("No se pudo aprobar el archivo.");
    } finally {
      setProcesando(null);
    }
  }

  return (
    <div className="space-y-4">
      {error && <div className="bg-red-50 text-red-600 p-3 rounded-xl text-sm font-medium border border-red-200">{error}</div>}

      <div className="flex flex-col md:flex-row gap-3">
        <input
          type="text"
          value={q}
          onChange={(e) => setQ(e.target.value)}
          placeholder="Buscar ID, usuario o servicio..."
          className="flex-1 bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm font-medium text-gray-900 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-[#54A6D8]"
        />
        <select
          value={orden}
          onChange={(e) => {
            const o = e.target.value === "asc" ? "asc" : "desc";
            setOrden(o);
            cargarLista(filtro, o, q);
          }}
          className="bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm font-medium text-gray-700 focus:outline-none focus:ring-2 focus:ring-[#54A6D8]"
        >
          <option value="desc">Recientes</option>
          <option value="asc">Antiguos</option>
        </select>
      </div>

      <div className="flex gap-2 overflow-x-auto pb-1">
        {FILTROS.map((f) => {
          const n = f.badge ? f.badge(contadores) : 0;
          return (
            <button
              key={f.key}
              type="button"
              onClick={() => cambiarFiltro(f.key)}
              className={`shrink-0 inline-flex items-center gap-1.5 rounded-full px-4 py-2 text-xs font-bold border transition-colors ${
                filtro === f.key ? "bg-[#54A6D8] text-white border-[#54A6D8]" : "bg-white text-gray-600 border-gray-200 hover:bg-gray-50"
              }`}
            >
              {f.label}
              <span className={`px-1.5 rounded-full ${filtro === f.key ? "bg-white/20" : n > 0 && (f.key === "alertas_dlp" || f.key === "moderacion") ? "bg-red-500 text-white" : "bg-black/10"}`}>
                {n}
              </span>
            </button>
          );
        })}
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-[380px_1fr] gap-4 min-h-[500px]">
        <div className="bg-white border border-gray-100 rounded-2xl overflow-hidden flex flex-col max-h-[75vh]">
          <div className="overflow-y-auto flex-1">
            {cargandoLista ? (
              <div className="p-8 text-center text-gray-400 text-sm">Cargando...</div>
            ) : chats.length === 0 ? (
              <div className="p-8 text-center text-gray-400 text-sm">No se encontraron chats.</div>
            ) : (
              chats.map((c) => (
                <button
                  key={c.id}
                  type="button"
                  onClick={() => abrirChat(c.id)}
                  className={`w-full text-left flex items-center gap-3 p-4 border-b border-gray-50 transition-colors ${chatSeleccionadoId === c.id ? "bg-sky-50/50" : "hover:bg-gray-50"} ${c.eliminado ? "opacity-60" : ""}`}
                >
                  <div className="min-w-0 flex-1">
                    <div className="flex justify-between items-center mb-0.5">
                      <p className="text-sm font-extrabold text-gray-900 truncate">
                        {nombreCorto(c.compradorNombre)} <span className="text-gray-300 font-medium">·</span> {nombreCorto(c.vendedorNombre)}
                      </p>
                      <span className="text-[10px] text-[#54A6D8] font-bold shrink-0 ml-2">{c.fechaOrden ? new Date(c.fechaOrden).toLocaleDateString("es-CL", { day: "2-digit", month: "2-digit" }) : "--"}</span>
                    </div>
                    <p className="text-xs font-medium text-gray-500 truncate">{c.servicioTitulo ?? "Chat sin servicio asociado"}</p>
                    <div className="flex gap-1.5 mt-1">
                      {c.eliminado && <span className="bg-gray-100 text-gray-500 text-[9px] px-2 py-0.5 rounded-full font-bold uppercase">Cerrado</span>}
                      {c.contratoId && <span className="bg-indigo-50 text-indigo-500 border border-indigo-100 text-[9px] px-2 py-0.5 rounded-full font-bold uppercase">Aula</span>}
                    </div>
                  </div>
                </button>
              ))
            )}
          </div>
        </div>

        <div className="bg-white border border-gray-100 rounded-2xl overflow-hidden flex flex-col max-h-[75vh]">
          {filtro === "moderacion" ? (
            <ModeracionPane moderacion={moderacion} procesando={procesando} onAprobar={aprobarArchivo} phpSiteUrl={phpSiteUrl} />
          ) : chatSeleccionadoId === null ? (
            <div className="flex-1 flex flex-col items-center justify-center text-center p-8 text-gray-400">
              <p className="text-sm font-bold">Selecciona una conversación para inspeccionar el historial.</p>
            </div>
          ) : cargandoDetalle || !detalle ? (
            <div className="flex-1 flex items-center justify-center text-gray-400 text-sm">Cargando...</div>
          ) : (
            <DetallePane
              detalle={detalle}
              procesando={procesando}
              phpSiteUrl={phpSiteUrl}
              onEliminar={eliminarChat}
              onRestaurar={restaurarChat}
              onMarcarRevisadoDlp={marcarRevisadoDlp}
            />
          )}
        </div>
      </div>
    </div>
  );
}

function ModeracionPane({
  moderacion,
  procesando,
  onAprobar,
  phpSiteUrl,
}: {
  moderacion: ArchivoModeracion[];
  procesando: string | null;
  onAprobar: (msgId: number) => void;
  phpSiteUrl: string;
}) {
  return (
    <div className="flex-1 overflow-y-auto p-6">
      <h2 className="text-lg font-extrabold text-gray-900 mb-1">Moderación de archivos</h2>
      <p className="text-xs text-gray-400 font-medium mb-5">Archivos enviados por usuarios pendientes de revisión.</p>
      {moderacion.length === 0 ? (
        <div className="text-center py-16 text-gray-400 text-sm font-bold">Sin archivos pendientes.</div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          {moderacion.map((m) => {
            const esImagen = (m.archivoTipo ?? "").startsWith("image/");
            const urlArchivo = `${phpSiteUrl}/app/ver_archivo_chat.php?m=${m.id}`;
            return (
              <div key={m.id} className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
                {esImagen ? (
                  <a href={urlArchivo} target="_blank" rel="noopener noreferrer" className="block bg-gray-100" style={{ height: 160 }}>
                    {/* eslint-disable-next-line @next/next/no-img-element */}
                    <img src={urlArchivo} alt={m.archivoNombre ?? ""} className="w-full h-full object-contain" loading="lazy" />
                  </a>
                ) : (
                  <div className="flex items-center justify-center bg-gray-50 border-b border-gray-100" style={{ height: 90 }}>
                    <span className="text-gray-300 text-3xl">📄</span>
                  </div>
                )}
                <div className="p-4">
                  <p className="text-xs font-extrabold text-gray-900 truncate">{m.remitenteNombre}</p>
                  <p className="text-[10px] text-gray-400 font-medium mb-1">
                    Chat #{m.conversacionId} · {m.enviadoEn ? new Date(m.enviadoEn).toLocaleString("es-CL") : "--"} · {Math.round((m.archivoPeso ?? 0) / 1024)} KB
                  </p>
                  <div className="flex gap-2 mt-3">
                    <button
                      type="button"
                      onClick={() => onAprobar(m.id)}
                      disabled={procesando === `aprobar-${m.id}`}
                      className="flex-1 bg-emerald-500 hover:bg-emerald-600 text-white text-xs font-bold py-2 rounded-xl transition-all disabled:opacity-50"
                    >
                      Aprobar
                    </button>
                    <a
                      href={`${phpSiteUrl}/admin/chats?estado=moderacion`}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="flex-1 text-center bg-red-500 hover:bg-red-600 text-white text-xs font-bold py-2 rounded-xl transition-all"
                      title="Rechazar (borra el archivo del disco) en el sitio real"
                    >
                      Rechazar
                    </a>
                  </div>
                </div>
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}

function DetallePane({
  detalle,
  procesando,
  phpSiteUrl,
  onEliminar,
  onRestaurar,
  onMarcarRevisadoDlp,
}: {
  detalle: ChatDetalle;
  procesando: string | null;
  phpSiteUrl: string;
  onEliminar: (id: number) => void;
  onRestaurar: (id: number) => void;
  onMarcarRevisadoDlp: (id: number) => void;
}) {
  const { info, mensajes, dlp, metadata } = detalle;
  const dlpPendientes = dlp.filter((d) => !d.revisadoAdmin);

  return (
    <>
      <div className="px-6 py-4 border-b border-gray-100 sticky top-0 bg-white/95 backdrop-blur-md z-10">
        <div className="flex justify-between items-start gap-4">
          <div className="min-w-0 flex-1">
            <div className="flex items-center gap-2 mb-1 flex-wrap">
              <h3 className="font-extrabold text-gray-900 text-lg">
                {nombreCorto(info.compradorNombre)} <span className="text-gray-300 font-medium">·</span> {nombreCorto(info.vendedorNombre)}
              </h3>
              {info.contratoId ? (
                <span className="bg-indigo-50 text-indigo-600 text-[9px] px-2 py-0.5 rounded-full font-extrabold border border-indigo-100 uppercase">Contrato #{info.contratoId}</span>
              ) : (
                <span className="bg-amber-50 text-amber-700 text-[9px] px-2 py-0.5 rounded-full font-extrabold border border-amber-100 uppercase">Cotización</span>
              )}
            </div>
            <p className="text-xs text-gray-500 font-medium">
              <a href={perfilUrl(info.compradorId, phpSiteUrl)} target="_blank" rel="noopener noreferrer" className="hover:text-[#54A6D8] hover:underline">
                Ver perfil de {nombreCorto(info.compradorNombre)}
              </a>
              <span className="text-gray-300 mx-1">·</span>
              <a href={perfilUrl(info.vendedorId, phpSiteUrl)} target="_blank" rel="noopener noreferrer" className="hover:text-[#54A6D8] hover:underline">
                Ver perfil de {nombreCorto(info.vendedorNombre)}
              </a>
              {info.servicioTitulo && (
                <>
                  <span className="text-gray-300 mx-1">·</span>
                  <span className="italic">{info.servicioTitulo}</span>
                </>
              )}
            </p>
          </div>
          <div className="shrink-0">
            {info.eliminado ? (
              <button
                type="button"
                onClick={() => onRestaurar(info.id)}
                disabled={procesando === `restaurar-${info.id}`}
                className="bg-white border border-gray-200 text-emerald-600 hover:bg-emerald-50 px-4 py-2 rounded-xl text-xs font-bold transition-all disabled:opacity-50"
              >
                Restaurar
              </button>
            ) : (
              <button
                type="button"
                onClick={() => onEliminar(info.id)}
                disabled={procesando === `eliminar-${info.id}`}
                className="bg-white border border-gray-200 text-gray-500 hover:text-red-600 hover:bg-red-50 px-4 py-2 rounded-xl text-xs font-bold transition-all disabled:opacity-50"
              >
                Archivar
              </button>
            )}
          </div>
        </div>
        <div className="flex items-center gap-4 mt-3 pt-3 border-t border-gray-50 text-[10px] font-bold text-gray-500 uppercase tracking-wider">
          <span>{metadata.totalMensajes} mensajes</span>
          <span>{metadata.archivos} archivos</span>
          {metadata.primero && metadata.ultimo && (
            <span className="hidden md:inline text-gray-400 normal-case">
              {new Date(metadata.primero).toLocaleDateString("es-CL")} → {new Date(metadata.ultimo).toLocaleString("es-CL")}
            </span>
          )}
        </div>
      </div>

      <div className="flex-1 overflow-y-auto p-6 space-y-4 bg-gray-50/50">
        {dlp.length > 0 && (
          <div className={`rounded-2xl border overflow-hidden ${dlpPendientes.length > 0 ? "border-red-200" : "border-gray-200"}`}>
            <div className={`flex items-center justify-between px-4 py-3 border-b ${dlpPendientes.length > 0 ? "bg-red-100/60 border-red-200" : "bg-gray-100/60 border-gray-200"}`}>
              <span className={`text-xs font-extrabold uppercase tracking-widest ${dlpPendientes.length > 0 ? "text-red-700" : "text-gray-500"}`}>
                Intentos bloqueados ({dlp.length})
              </span>
              {dlpPendientes.length > 0 ? (
                <span className="text-[10px] font-bold bg-red-500 text-white px-2 py-0.5 rounded-full">{dlpPendientes.length} pendientes</span>
              ) : (
                <span className="text-[10px] font-bold bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded-full">Todos revisados</span>
              )}
            </div>
            {dlp.map((d) => (
              <div key={d.id} className={`px-4 py-3 border-b border-red-100 last:border-b-0 ${d.revisadoAdmin ? "opacity-50" : ""}`}>
                <div className="flex items-center gap-2 mb-2 flex-wrap">
                  <span className="text-xs font-bold text-gray-700">{d.remitenteNombre}</span>
                  <span className="text-[10px] text-gray-400">{d.fecha ? new Date(d.fecha).toLocaleString("es-CL") : "--"}</span>
                  <span className="ml-auto text-[10px] font-extrabold uppercase bg-red-100 text-red-700 px-2 py-0.5 rounded-full border border-red-200">{d.categoria}</span>
                  {d.revisadoAdmin && <span className="text-[10px] font-bold bg-gray-100 text-gray-400 px-2 py-0.5 rounded-full">Revisado</span>}
                </div>
                <div className="bg-white border border-red-100 rounded-xl px-3 py-2 text-xs text-gray-700 font-mono break-all">{d.textoIntentado}</div>
                <div className="mt-2">
                  <a
                    href={`${phpSiteUrl}/admin/chats?id=${info.id}&estado=alertas_dlp`}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="text-[10px] font-bold text-[#54A6D8] hover:underline"
                    title="Liberar y enviar al destinatario (efecto real sobre la conversación) en el sitio real"
                  >
                    Liberar en el sitio real →
                  </a>
                </div>
              </div>
            ))}
            {dlpPendientes.length > 0 && (
              <div className="px-4 py-3">
                <button
                  type="button"
                  onClick={() => onMarcarRevisadoDlp(info.id)}
                  disabled={procesando === `dlp-${info.id}`}
                  className="w-full bg-red-500 hover:bg-red-600 text-white text-xs font-bold py-2.5 rounded-xl transition-all disabled:opacity-50"
                >
                  Marcar todos como revisados
                </button>
              </div>
            )}
          </div>
        )}

        {mensajes.length === 0 ? (
          <div className="flex flex-col items-center justify-center text-center p-8 bg-white rounded-3xl border-2 border-dashed border-gray-200">
            <p className="text-sm font-bold text-gray-700">Aún no hay mensajes.</p>
          </div>
        ) : (
          mensajes.map((m) => {
            if (m.remitenteId === 0) {
              return (
                <div key={m.id} className="flex justify-center mb-4 text-xs text-gray-500 font-medium">
                  {m.mensaje}
                </div>
              );
            }
            const esComprador = m.remitenteId === info.compradorId;
            const foto = fotoUrl(esComprador ? info.compradorFoto : info.vendedorFoto, phpSiteUrl);
            return (
              <div key={m.id} className={`flex items-end gap-2 mb-4 ${esComprador ? "flex-row-reverse" : "flex-row"}`}>
                {foto ? (
                  // eslint-disable-next-line @next/next/no-img-element
                  <img src={foto} alt="" className="w-8 h-8 rounded-full object-cover border-2 border-white shadow-sm hidden md:block shrink-0" />
                ) : (
                  <div className="w-8 h-8 rounded-full bg-gray-100 text-gray-400 text-[10px] font-bold flex items-center justify-center border-2 border-white shadow-sm hidden md:flex shrink-0">
                    {(esComprador ? info.compradorNombre : info.vendedorNombre).charAt(0).toUpperCase()}
                  </div>
                )}
                <div
                  className={`max-w-[85%] md:max-w-[70%] px-4 py-3 rounded-2xl shadow-sm text-sm leading-relaxed ${
                    esComprador ? "bg-[#54A6D8] text-white rounded-tr-sm" : "bg-white text-gray-800 rounded-tl-sm border border-gray-100"
                  }`}
                >
                  {m.archivoRuta && (
                    <a
                      href={`${phpSiteUrl}/app/ver_archivo_chat.php?m=${m.id}`}
                      target="_blank"
                      rel="noopener noreferrer"
                      className={`flex items-center gap-3 p-2 -mx-1 mb-2 rounded-xl transition-all ${esComprador ? "bg-white/10 hover:bg-white/20" : "bg-gray-50 hover:bg-gray-100"}`}
                    >
                      <span className="text-xl">📎</span>
                      <div className="min-w-0 flex-1">
                        <p className="text-xs font-bold truncate">{m.archivoNombre ?? "archivo"}</p>
                        <p className="text-[10px] opacity-70">{Math.round((m.archivoPeso ?? 0) / 1024)} KB</p>
                      </div>
                    </a>
                  )}
                  {m.mensaje.trim() !== "" && <span className="whitespace-pre-line">{m.mensaje}</span>}
                  <div className="flex items-center justify-end mt-1.5 opacity-70 text-[10px] font-bold">
                    <span>{m.enviadoEn ? new Date(m.enviadoEn).toLocaleString("es-CL", { day: "2-digit", month: "2-digit", hour: "2-digit", minute: "2-digit" }) : ""}</span>
                  </div>
                </div>
              </div>
            );
          })
        )}
      </div>
    </>
  );
}
