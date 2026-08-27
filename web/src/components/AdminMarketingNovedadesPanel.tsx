"use client";

import { useState } from "react";
import type { NovedadMarketing } from "@/lib/api";

function urlPost(id: number): string {
  return `/api/novedad/compartir/${id}/post`;
}
function urlHistory(id: number): string {
  return `/api/novedad/compartir/${id}/history`;
}

async function compartirImagen(url: string, filename: string, titulo: string) {
  if (typeof navigator.share !== "function" || typeof navigator.canShare !== "function") {
    descargarDirecto(url, filename);
    return;
  }
  try {
    const resp = await fetch(url);
    const blob = await resp.blob();
    const file = new File([blob], filename, { type: blob.type || "image/jpeg" });
    if (navigator.canShare({ files: [file] })) {
      await navigator.share({ files: [file], title: titulo });
      return;
    }
  } catch (err) {
    if (err instanceof Error && err.name === "AbortError") return;
  }
  descargarDirecto(url, filename);
}

function descargarDirecto(url: string, filename: string) {
  const a = document.createElement("a");
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
}

function BotonesFormato({ id, titulo, formato }: { id: number; titulo: string; formato: "post" | "history" }) {
  const url = formato === "post" ? urlPost(id) : urlHistory(id);
  const filename = `nubira-novedad-${id}-${formato}.jpg`;
  return (
    <div className="flex items-center justify-center gap-2">
      <button
        type="button"
        onClick={() => compartirImagen(url, filename, titulo)}
        className="px-3 py-2 rounded-xl border border-gray-200 text-[#54A6D8] hover:bg-blue-50 text-xs font-bold flex items-center gap-1.5"
      >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} className="w-4 h-4">
          <path strokeLinecap="round" strokeLinejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" />
        </svg>
        Compartir
      </button>
      <a href={url} download={filename} className="px-3 py-2 rounded-xl border border-gray-200 text-[#54A6D8] hover:bg-blue-50 text-xs font-bold flex items-center gap-1.5">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} className="w-4 h-4">
          <path strokeLinecap="round" strokeLinejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" />
        </svg>
        Descargar
      </a>
    </div>
  );
}

// Puerto de admin_marketing_cards.php, tab Novedades — única mutación real de todo el panel
// Marketing/Cards. Sin editar ni eliminar (mismo criterio que el PHP real, confirmado no es
// un vacío a llenar). Las imágenes se generan bajo demanda en la primera carga de
// /api/novedad/compartir/{id}/post|history (cache miss del lado de server/).
export function AdminMarketingNovedadesPanel({ novedadesIniciales }: { novedadesIniciales: NovedadMarketing[] }) {
  const [novedades, setNovedades] = useState(novedadesIniciales);
  const [titulo, setTitulo] = useState("");
  const [cuerpo, setCuerpo] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [enviando, setEnviando] = useState(false);
  const [recienCreada, setRecienCreada] = useState<NovedadMarketing | null>(null);

  async function guardar() {
    setError(null);
    if (titulo.trim() === "" || titulo.length > 120) {
      setError("El título es obligatorio y debe tener máximo 120 caracteres.");
      return;
    }
    if (cuerpo.trim() === "" || cuerpo.length > 280) {
      setError("El cuerpo es obligatorio y debe tener máximo 280 caracteres.");
      return;
    }

    setEnviando(true);
    try {
      const res = await fetch("/api/admin/marketing-cards/novedades", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ titulo: titulo.trim(), cuerpo: cuerpo.trim() }),
      });
      const data = await res.json();
      if (!res.ok) {
        setError(data?.mensaje ?? data?.error ?? "No se pudo guardar la novedad.");
        return;
      }
      const nueva: NovedadMarketing = { id: data.id, titulo: titulo.trim(), cuerpo: cuerpo.trim(), creadoEn: new Date().toISOString() };
      setRecienCreada(nueva);
      setNovedades((prev) => [nueva, ...prev]);
      setTitulo("");
      setCuerpo("");
    } catch {
      setError("Error de conexión. Intenta de nuevo.");
    } finally {
      setEnviando(false);
    }
  }

  return (
    <div className="space-y-8">
      <section className="bg-white border border-gray-100 rounded-2xl shadow-sm p-6">
        <h2 className="text-base font-bold text-gray-900 mb-4">Nueva novedad</h2>
        <div className="space-y-4">
          <div>
            <label className="block text-[10px] font-bold text-gray-400 uppercase tracking-wide mb-1">Título</label>
            <input
              type="text"
              maxLength={120}
              value={titulo}
              onChange={(e) => setTitulo(e.target.value)}
              placeholder="Ej: Nuevo: Métricas para tus publicaciones"
              className="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-[#54A6D8] outline-none"
            />
            <p className="text-[11px] text-gray-400 text-right mt-1">{titulo.length} / 120</p>
          </div>

          <div>
            <label className="block text-[10px] font-bold text-gray-400 uppercase tracking-wide mb-1">Cuerpo</label>
            <textarea
              maxLength={280}
              rows={4}
              value={cuerpo}
              onChange={(e) => setCuerpo(e.target.value)}
              placeholder="Describe la novedad en un par de frases..."
              className="w-full px-4 py-3 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-[#54A6D8] outline-none resize-none"
            />
            <p className="text-[11px] text-gray-400 text-right mt-1">{cuerpo.length} / 280</p>
          </div>

          {error && <p className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-xl px-4 py-2.5">{error}</p>}

          <button
            type="button"
            disabled={enviando}
            onClick={guardar}
            className="px-5 py-2.5 rounded-xl bg-[#54A6D8] hover:bg-blue-600 disabled:bg-gray-200 disabled:text-gray-400 text-white text-sm font-bold transition-colors flex items-center gap-2"
          >
            {enviando ? "Guardando..." : "Guardar y generar imágenes"}
          </button>
        </div>
      </section>

      {recienCreada && (
        <section className="bg-white border border-gray-100 rounded-2xl shadow-sm p-6">
          <h2 className="text-base font-bold text-gray-900 mb-4">Imágenes generadas</h2>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-6">
            <div className="text-center">
              <p className="text-[10px] font-bold text-gray-400 uppercase tracking-wide mb-2">Post (1:1)</p>
              {/* eslint-disable-next-line @next/next/no-img-element -- imagen generada dinámicamente server-side */}
              <img src={urlPost(recienCreada.id)} alt="Preview POST" className="w-full max-w-[320px] mx-auto rounded-xl border border-gray-100 aspect-square object-cover bg-gray-50" />
              <div className="mt-3">
                <BotonesFormato id={recienCreada.id} titulo={recienCreada.titulo} formato="post" />
              </div>
            </div>
            <div className="text-center">
              <p className="text-[10px] font-bold text-gray-400 uppercase tracking-wide mb-2">History (9:16)</p>
              {/* eslint-disable-next-line @next/next/no-img-element -- imagen generada dinámicamente server-side */}
              <img src={urlHistory(recienCreada.id)} alt="Preview HISTORY" className="w-full max-w-[220px] mx-auto rounded-xl border border-gray-100 aspect-[9/16] object-cover bg-gray-50" />
              <div className="mt-3">
                <BotonesFormato id={recienCreada.id} titulo={recienCreada.titulo} formato="history" />
              </div>
            </div>
          </div>
        </section>
      )}

      <section>
        <h2 className="text-base font-bold text-gray-900 mb-4">Historial</h2>
        {novedades.length === 0 ? (
          <div className="bg-white border border-dashed border-gray-200 rounded-2xl p-12 text-center text-gray-400 text-sm">Todavía no hay novedades creadas.</div>
        ) : (
          <ul className="space-y-2">
            {novedades.map((n) => (
              <li key={n.id} className="flex items-center gap-3 bg-white border border-gray-100 rounded-xl p-3">
                {/* eslint-disable-next-line @next/next/no-img-element -- imagen generada dinámicamente server-side */}
                <img src={urlPost(n.id)} loading="lazy" alt="" className="w-14 h-14 rounded-lg object-cover border border-gray-200 bg-gray-50 shrink-0" />
                <div className="flex-1 min-w-0">
                  <p className="text-xs font-bold text-gray-800 truncate">{n.titulo}</p>
                  <p className="text-[10px] text-gray-400">{new Date(n.creadoEn).toLocaleString("es-CL", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit" })}</p>
                </div>
                <div className="flex items-center gap-1.5 shrink-0">
                  <button
                    type="button"
                    onClick={() => compartirImagen(urlPost(n.id), `nubira-novedad-${n.id}-post.jpg`, n.titulo)}
                    title="Compartir POST"
                    aria-label="Compartir POST"
                    className="w-9 h-9 rounded-full bg-white border border-gray-200 text-[#54A6D8] hover:bg-blue-50 flex items-center justify-center transition-colors"
                  >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} className="w-3.5 h-3.5">
                      <path strokeLinecap="round" strokeLinejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" />
                    </svg>
                  </button>
                  <a
                    href={urlPost(n.id)}
                    download={`nubira-novedad-${n.id}-post.jpg`}
                    title="Descargar POST"
                    aria-label="Descargar POST"
                    className="w-9 h-9 rounded-full bg-white border border-gray-200 text-[#54A6D8] hover:bg-blue-50 flex items-center justify-center transition-colors"
                  >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} className="w-3.5 h-3.5">
                      <path strokeLinecap="round" strokeLinejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" />
                    </svg>
                  </a>
                  <a
                    href={urlHistory(n.id)}
                    download={`nubira-novedad-${n.id}-history.jpg`}
                    title="Descargar HISTORY"
                    aria-label="Descargar HISTORY"
                    className="w-9 h-9 rounded-full bg-white border border-gray-200 text-[#54A6D8] hover:bg-blue-50 flex items-center justify-center transition-colors"
                  >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} className="w-3.5 h-3.5">
                      <path strokeLinecap="round" strokeLinejoin="round" d="M12 9.75v6.75m0 0-3-3m3 3 3-3m-8.25 6h10.5a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 18 4.5H6a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 6 19.5Z" />
                    </svg>
                  </a>
                </div>
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  );
}
