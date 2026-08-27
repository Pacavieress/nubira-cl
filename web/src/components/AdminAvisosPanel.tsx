"use client";

import { useState } from "react";
import type { AvisoCampana, AvisoLector } from "@/lib/api";
import { AvisoMensaje, AVISO_ICONO_KEYS } from "@/components/AvisoMensaje";

const TIPO_BADGE: Record<string, string> = {
  info: "bg-gray-100 text-gray-700",
  novedad: "bg-sky-50 text-sky-700",
  importante: "bg-rose-50 text-rose-700",
};

const SEGMENTO_LABEL: Record<string, string> = {
  todos: "Todos los usuarios",
  tutores: "Solo tutores",
  no_tutores: "Solo no-tutores",
  usuario: "Usuario específico",
};

interface UsuarioBusqueda {
  id: number;
  nombre: string;
  correo: string;
  institucion: string;
}

// Formulario de nueva campaña — puerto de admin_avisos.php (crear + enviar), autorizado
// explícitamente por el usuario. Ver adminAvisos.types.ts (server/) para lo que sigue
// deliberadamente fuera de alcance (imágenes, duplicar, eliminar).
function NuevaCampanaForm({ onCreada }: { onCreada: (campana: AvisoCampana) => void }) {
  const [abierto, setAbierto] = useState(false);
  const [titulo, setTitulo] = useState("");
  const [mensaje, setMensaje] = useState("");
  const [tipo, setTipo] = useState<"info" | "novedad" | "importante">("info");
  const [segmento, setSegmento] = useState<"todos" | "tutores" | "no_tutores" | "usuario">("todos");
  const [busqueda, setBusqueda] = useState("");
  const [resultadosBusqueda, setResultadosBusqueda] = useState<UsuarioBusqueda[]>([]);
  const [usuarioSel, setUsuarioSel] = useState<UsuarioBusqueda | null>(null);
  const [enviando, setEnviando] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [exito, setExito] = useState<string | null>(null);

  async function buscarUsuarios(q: string) {
    setBusqueda(q);
    setUsuarioSel(null);
    if (q.trim().length < 2) {
      setResultadosBusqueda([]);
      return;
    }
    try {
      const res = await fetch(`/api/admin/avisos/buscar-usuarios?q=${encodeURIComponent(q)}`);
      if (!res.ok) return;
      setResultadosBusqueda(await res.json());
    } catch {
      setResultadosBusqueda([]);
    }
  }

  function insertarEnMensaje(antes: string, despues: string) {
    setMensaje((m) => `${m}${antes}texto${despues}`);
  }

  async function enviar() {
    setError(null);
    setExito(null);
    if (segmento === "usuario" && !usuarioSel) {
      setError("Selecciona un usuario primero.");
      return;
    }
    const confirmado = window.confirm(`¿Enviar campaña a "${SEGMENTO_LABEL[segmento]}"? Esta acción manda un aviso real y no se puede deshacer.`);
    if (!confirmado) return;

    setEnviando(true);
    try {
      const res = await fetch("/api/admin/avisos", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ titulo, mensaje, tipo, segmento, usuarioId: usuarioSel?.id ?? null }),
      });
      const data = await res.json();
      if (!res.ok) {
        setError(data?.mensaje ?? data?.error ?? "Error al enviar la campaña.");
        return;
      }
      setExito(`Campaña enviada a ${data.enviados} usuario${data.enviados === 1 ? "" : "s"}.`);
      onCreada({
        id: data.campanaId,
        titulo,
        mensaje,
        tipo,
        segmento,
        totalDestinatarios: data.enviados,
        leidos: 0,
        fechaCreacion: new Date().toISOString(),
        imagenes: [],
      });
      setTitulo("");
      setMensaje("");
      setTipo("info");
      setSegmento("todos");
      setUsuarioSel(null);
      setBusqueda("");
    } catch {
      setError("Error de conexión.");
    } finally {
      setEnviando(false);
    }
  }

  if (!abierto) {
    return (
      <button
        type="button"
        onClick={() => setAbierto(true)}
        className="w-full bg-white border border-gray-100 rounded-3xl px-6 py-4 text-left text-sm font-semibold text-gray-700 hover:border-gray-200 hover:bg-gray-50 transition-colors flex items-center gap-2"
      >
        <span className="w-6 h-6 rounded-full bg-gray-900 text-white flex items-center justify-center text-base leading-none">+</span>
        Nueva campaña
      </button>
    );
  }

  return (
    <div className="bg-white border border-gray-100 rounded-3xl p-6 space-y-4">
      <div className="flex items-center justify-between">
        <h2 className="font-semibold text-sm">Nueva campaña</h2>
        <button type="button" onClick={() => setAbierto(false)} className="text-gray-400 hover:text-gray-700 text-sm">
          Cerrar
        </button>
      </div>

      <div>
        <label className="text-[11px] font-semibold uppercase tracking-wider text-gray-500">Título interno</label>
        <input
          type="text"
          maxLength={150}
          value={titulo}
          onChange={(e) => setTitulo(e.target.value)}
          placeholder="Ej: Configura tus horarios"
          className="w-full mt-1 px-4 py-2.5 border border-gray-200 rounded-lg focus:border-[#54A6D8] focus:ring-2 focus:ring-[#54A6D8]/10 outline-none text-sm"
        />
      </div>

      <div>
        <label className="text-[11px] font-semibold uppercase tracking-wider text-gray-500">Mensaje al usuario</label>
        <div className="flex flex-wrap items-center gap-1.5 mt-1.5 mb-1.5">
          <button
            type="button"
            onClick={() => insertarEnMensaje("[b]", "[/b]")}
            title="Negrita"
            className="w-8 h-8 rounded-lg border border-gray-200 hover:border-[#54A6D8] hover:bg-sky-50 flex items-center justify-center text-xs font-bold text-gray-600 transition-colors"
          >
            B
          </button>
          <span className="w-px h-5 bg-gray-200 mx-1" />
          {AVISO_ICONO_KEYS.map((key) => (
            <button
              key={key}
              type="button"
              onClick={() => insertarEnMensaje(`[icon:${key}]`, "")}
              title={key}
              className="px-2 h-8 rounded-lg border border-gray-200 hover:border-[#54A6D8] hover:bg-sky-50 text-[10px] text-gray-600 transition-colors"
            >
              {key}
            </button>
          ))}
        </div>
        <textarea
          maxLength={1000}
          rows={4}
          value={mensaje}
          onChange={(e) => setMensaje(e.target.value)}
          placeholder="Escribe el mensaje que verán los usuarios..."
          className="w-full p-4 border border-gray-200 rounded-lg focus:border-[#54A6D8] focus:ring-2 focus:ring-[#54A6D8]/10 outline-none text-sm resize-none"
        />
        <p className="text-[11px] text-gray-400 text-right mt-1">{mensaje.length} / 1000</p>

        <div className="mt-2 border border-gray-200 rounded-xl p-4 bg-gray-50">
          <p className="text-[11px] font-semibold uppercase tracking-wider text-gray-400 mb-2">Vista previa</p>
          <AvisoMensaje mensaje={mensaje} className="text-[15px] text-gray-700 leading-snug break-words whitespace-pre-line" />
        </div>
      </div>

      <div className="grid grid-cols-2 gap-3">
        <div>
          <label className="text-[11px] font-semibold uppercase tracking-wider text-gray-500">Tipo</label>
          <select
            value={tipo}
            onChange={(e) => setTipo(e.target.value as typeof tipo)}
            className="w-full mt-1 px-4 py-2.5 border border-gray-200 rounded-lg outline-none text-sm bg-white"
          >
            <option value="info">Info</option>
            <option value="novedad">Novedad</option>
            <option value="importante">Importante</option>
          </select>
        </div>
        <div>
          <label className="text-[11px] font-semibold uppercase tracking-wider text-gray-500">Enviar a</label>
          <select
            value={segmento}
            onChange={(e) => setSegmento(e.target.value as typeof segmento)}
            className="w-full mt-1 px-4 py-2.5 border border-gray-200 rounded-lg outline-none text-sm bg-white"
          >
            <option value="todos">Todos los usuarios</option>
            <option value="tutores">Solo tutores (con publicaciones)</option>
            <option value="no_tutores">Solo no-tutores</option>
            <option value="usuario">Usuario específico</option>
          </select>
        </div>
      </div>

      {segmento === "usuario" && (
        <div className="relative">
          <label className="text-[11px] font-semibold uppercase tracking-wider text-gray-500">Buscar usuario</label>
          <input
            type="text"
            value={busqueda}
            onChange={(e) => buscarUsuarios(e.target.value)}
            placeholder="Nombre o correo..."
            autoComplete="off"
            className="w-full mt-1 px-4 py-2.5 border border-gray-200 rounded-lg focus:border-[#54A6D8] focus:ring-2 focus:ring-[#54A6D8]/10 outline-none text-sm"
          />
          {resultadosBusqueda.length > 0 && !usuarioSel && (
            <div className="absolute top-full left-0 right-0 mt-1 bg-white border border-gray-200 rounded-lg shadow-lg max-h-64 overflow-y-auto z-10">
              {resultadosBusqueda.map((u) => (
                <button
                  type="button"
                  key={u.id}
                  onClick={() => {
                    setUsuarioSel(u);
                    setResultadosBusqueda([]);
                    setBusqueda(u.nombre);
                  }}
                  className="w-full text-left px-4 py-3 hover:bg-gray-50 border-b border-gray-100 last:border-0"
                >
                  <p className="text-sm font-medium">{u.nombre}</p>
                  <p className="text-[11px] text-gray-500">
                    {u.correo} · {u.institucion || "Sin institución"}
                  </p>
                </button>
              ))}
            </div>
          )}
          {usuarioSel && (
            <div className="mt-2 flex items-center justify-between gap-3 px-3 py-2 bg-sky-50 border border-sky-200 rounded-lg">
              <div className="min-w-0 flex-1">
                <p className="text-sm font-medium truncate">{usuarioSel.nombre}</p>
                <p className="text-[11px] text-gray-500 truncate">{usuarioSel.correo}</p>
              </div>
              <button type="button" onClick={() => (setUsuarioSel(null), setBusqueda(""))} className="text-gray-400 hover:text-rose-500 shrink-0">
                ✕
              </button>
            </div>
          )}
        </div>
      )}

      <div className="flex items-center justify-between pt-2 border-t border-gray-100">
        <p className="text-rose-500 text-[12px]">{error}</p>
        {exito && <p className="text-emerald-600 text-[12px] font-medium">{exito}</p>}
        <button
          type="button"
          disabled={enviando || titulo.trim().length < 3 || mensaje.trim().length < 5}
          onClick={enviar}
          className="ml-auto px-6 py-2.5 bg-gray-900 hover:bg-black disabled:bg-gray-200 disabled:text-gray-400 text-white text-[13px] font-medium rounded-lg transition-all active:scale-[0.98]"
        >
          {enviando ? "Enviando..." : "Enviar campaña"}
        </button>
      </div>
    </div>
  );
}

// Puerto de admin_avisos.php — historial en acordeón, detalle de lectores, y crear+enviar
// campaña (NuevaCampanaForm arriba). Sigue SIN eliminar/duplicar campaña ni subir imágenes —
// ver nota en server/src/modules/adminAvisos/adminAvisos.types.ts.
export function AdminAvisosPanel({ campanas: campanasIniciales, phpSiteUrl }: { campanas: AvisoCampana[]; phpSiteUrl: string }) {
  const [campanas, setCampanas] = useState(campanasIniciales);
  const [abierta, setAbierta] = useState<number | null>(null);
  const [lectores, setLectores] = useState<Record<number, AvisoLector[] | "cargando" | "error">>({});
  const [modalLectoresId, setModalLectoresId] = useState<number | null>(null);

  async function verLectores(campanaId: number) {
    setModalLectoresId(campanaId);
    if (lectores[campanaId]) return;
    setLectores((prev) => ({ ...prev, [campanaId]: "cargando" }));
    try {
      const res = await fetch(`/api/admin/avisos/${campanaId}/lectores`);
      if (!res.ok) throw new Error();
      const data: AvisoLector[] = await res.json();
      setLectores((prev) => ({ ...prev, [campanaId]: data }));
    } catch {
      setLectores((prev) => ({ ...prev, [campanaId]: "error" }));
    }
  }

  const modalCampana = campanas.find((c) => c.id === modalLectoresId) ?? null;
  const estadoLectores = modalLectoresId ? lectores[modalLectoresId] : undefined;

  return (
    <>
      <div className="mb-3">
        <NuevaCampanaForm onCreada={(c) => setCampanas((prev) => [c, ...prev])} />
      </div>

      {campanas.length === 0 ? (
        <div className="bg-white border border-gray-100 rounded-3xl px-6 py-16 text-center text-gray-400 text-sm">Aún no se han enviado campañas.</div>
      ) : (
        <div className="bg-white border border-gray-100 rounded-3xl overflow-hidden divide-y divide-gray-50">
          {campanas.map((c) => {
          const pct = c.totalDestinatarios > 0 ? Math.round((c.leidos / c.totalDestinatarios) * 100) : 0;
          const abiertaAhora = abierta === c.id;

          return (
            <div key={c.id}>
              <button
                type="button"
                onClick={() => setAbierta(abiertaAhora ? null : c.id)}
                className="w-full px-6 py-4 hover:bg-gray-50 transition-colors text-left flex items-center gap-3"
              >
                <span className={`w-6 h-6 rounded-full bg-gray-100 flex items-center justify-center shrink-0 transition-transform ${abiertaAhora ? "rotate-45" : ""}`}>
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} className="w-2.5 h-2.5 text-gray-500">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                  </svg>
                </span>
                <div className="min-w-0 flex-1">
                  <div className="flex items-center gap-2">
                    <h3 className="font-semibold text-sm truncate">{c.titulo}</h3>
                    <span className={`text-[10px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded ${TIPO_BADGE[c.tipo] ?? "bg-gray-100 text-gray-700"}`}>{c.tipo}</span>
                  </div>
                  <p className="text-[11px] text-gray-400 mt-0.5">
                    {new Date(c.fechaCreacion).toLocaleString("es-CL", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit" })} · {c.leidos}/
                    {c.totalDestinatarios} leídos ({pct}%)
                  </p>
                </div>
              </button>

              {abiertaAhora && (
                <div className="px-6 pb-4">
                  <AvisoMensaje mensaje={c.mensaje} className="text-xs text-gray-600 mb-3 whitespace-pre-line" />

                  {c.imagenes.length > 0 && (
                    <div className="flex gap-2 mb-3">
                      {c.imagenes.map((img) => (
                        // eslint-disable-next-line @next/next/no-img-element -- imagen adjunta de aviso, dinámica
                        <img key={img.archivo} src={img.url} alt="" className="w-16 h-16 object-cover rounded-lg border border-gray-100" />
                      ))}
                    </div>
                  )}

                  <div className="flex items-center gap-4 text-[11px] text-gray-500 mb-2">
                    <span>
                      Segmento: <strong>{SEGMENTO_LABEL[c.segmento] ?? c.segmento}</strong>
                    </span>
                  </div>

                  <div className="w-full bg-gray-100 rounded-full h-1 overflow-hidden mb-3">
                    <div className="bg-[#54A6D8] h-full rounded-full" style={{ width: `${pct}%` }} />
                  </div>

                  <button type="button" onClick={() => verLectores(c.id)} className="text-[12px] font-medium text-[#54A6D8] hover:underline">
                    Ver lectores
                  </button>
                </div>
              )}
            </div>
          );
          })}
        </div>
      )}

      <div className="flex justify-end mt-3">
        <a href={`${phpSiteUrl}/admin/avisos`} target="_blank" rel="noopener noreferrer" className="text-[12px] font-medium text-gray-500 hover:text-gray-700">
          Duplicar / eliminar campañas (en el sitio real) →
        </a>
      </div>

      {modalCampana && (
        <div className="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-gray-900/40 backdrop-blur-md" onClick={() => setModalLectoresId(null)}>
          <div className="bg-white w-full max-w-lg rounded-2xl shadow-xl border border-gray-200 max-h-[80vh] flex flex-col" onClick={(e) => e.stopPropagation()}>
            <div className="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
              <h3 className="font-semibold">Lectores de &ldquo;{modalCampana.titulo}&rdquo;</h3>
              <button type="button" onClick={() => setModalLectoresId(null)} className="text-gray-400 hover:text-gray-700">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} className="w-5 h-5">
                  <path strokeLinecap="round" strokeLinejoin="round" d="M6 18 18 6M6 6l12 12" />
                </svg>
              </button>
            </div>
            <div className="overflow-y-auto p-2">
              {estadoLectores === "cargando" && <div className="text-center text-gray-400 py-8 text-sm">Cargando...</div>}
              {estadoLectores === "error" && <div className="text-center text-rose-500 py-8 text-sm">Error al cargar.</div>}
              {Array.isArray(estadoLectores) &&
                (estadoLectores.length === 0 ? (
                  <div className="text-center text-gray-400 py-8 text-sm">Aún nadie ha leído esta campaña.</div>
                ) : (
                  estadoLectores.map((l, i) => (
                    <div key={i} className="px-4 py-3 border-b border-gray-100 last:border-0 flex items-center justify-between">
                      <div className="min-w-0">
                        <p className="font-medium text-sm truncate">{l.nombre}</p>
                        <p className="text-[11px] text-gray-500">{l.institucion || "Sin institución"}</p>
                      </div>
                      <p className="text-[11px] text-gray-400 shrink-0">
                        {new Date(l.fechaLeido).toLocaleString("es-CL", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit" })}
                      </p>
                    </div>
                  ))
                ))}
            </div>
          </div>
        </div>
      )}
    </>
  );
}
