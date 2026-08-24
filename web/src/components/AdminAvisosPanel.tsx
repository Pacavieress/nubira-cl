"use client";

import { useState } from "react";
import type { AvisoCampana, AvisoLector } from "@/lib/api";
import { AvisoMensaje } from "@/components/AvisoMensaje";

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

// Puerto de admin_avisos.php — SOLO lectura: métricas, historial en acordeón, detalle de
// lectores. Crear/enviar/eliminar/duplicar campaña quedan fuera de alcance (enlazan al
// sitio PHP real) — ver nota en server/src/modules/adminAvisos/adminAvisos.types.ts.
export function AdminAvisosPanel({ campanas, phpSiteUrl }: { campanas: AvisoCampana[]; phpSiteUrl: string }) {
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

  if (campanas.length === 0) {
    return <div className="bg-white border border-gray-100 rounded-3xl px-6 py-16 text-center text-gray-400 text-sm">Aún no se han enviado campañas.</div>;
  }

  const modalCampana = campanas.find((c) => c.id === modalLectoresId) ?? null;
  const estadoLectores = modalLectoresId ? lectores[modalLectoresId] : undefined;

  return (
    <>
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

      <div className="flex justify-end mt-3">
        <a href={`${phpSiteUrl}/admin/avisos`} target="_blank" rel="noopener noreferrer" className="text-[12px] font-medium text-gray-500 hover:text-gray-700">
          Crear / eliminar campañas (en el sitio real) →
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
