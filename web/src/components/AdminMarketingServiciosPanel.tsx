"use client";

import { useMemo, useState } from "react";
import type { ServicioMarketing } from "@/lib/api";

interface ItemCarrusel {
  id: number;
  titulo: string;
}

function imgUrl(id: number): string {
  return `/api/servicio/compartir/${id}/post`;
}

// Puerto de componentes/modal_carrusel_marketing.php — reordenar (▲▼), compartir individual
// (Web Share API con fetch+Blob) o descargar, y "descargar todas" (secuencial con delay en
// desktop; un solo navigator.share() combinado en touch, mismo criterio que el PHP real: iOS
// Safari rompe la descarga múltiple encadenada porque pierde el gesto de usuario confiable).
function CarruselModal({ items: itemsIniciales, onCerrar }: { items: ItemCarrusel[]; onCerrar: () => void }) {
  const [items, setItems] = useState(itemsIniciales);

  const esTactilConShare =
    typeof navigator !== "undefined" &&
    (navigator.maxTouchPoints > 0 || "ontouchstart" in window) &&
    typeof navigator.share === "function" &&
    typeof navigator.canShare === "function";

  function mover(index: number, dir: -1 | 1) {
    setItems((prev) => {
      const next = [...prev];
      const j = index + dir;
      if (j < 0 || j >= next.length) return prev;
      [next[index], next[j]] = [next[j]!, next[index]!];
      return next;
    });
  }

  function descargarDirecto(url: string, filename: string) {
    const a = document.createElement("a");
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
  }

  async function compartirUno(item: ItemCarrusel) {
    const url = imgUrl(item.id);
    const filename = `nubira-${item.id}-post.jpg`;
    if (typeof navigator.share !== "function" || typeof navigator.canShare !== "function") {
      descargarDirecto(url, filename);
      return;
    }
    try {
      const resp = await fetch(url);
      const blob = await resp.blob();
      const file = new File([blob], filename, { type: blob.type || "image/jpeg" });
      if (navigator.canShare({ files: [file] })) {
        await navigator.share({ files: [file], title: item.titulo });
        return;
      }
    } catch (err) {
      if (err instanceof Error && err.name === "AbortError") return;
    }
    descargarDirecto(url, filename);
  }

  async function descargarTodas() {
    if (esTactilConShare) {
      try {
        const archivos = await Promise.all(
          items.map(async (item) => {
            const resp = await fetch(imgUrl(item.id));
            const blob = await resp.blob();
            return new File([blob], `nubira-${item.id}-post.jpg`, { type: blob.type || "image/jpeg" });
          }),
        );
        if (navigator.canShare?.({ files: archivos })) {
          await navigator.share?.({ files: archivos });
          return;
        }
      } catch (err) {
        if (err instanceof Error && err.name === "AbortError") return;
      }
    }
    items.forEach((item, i) => {
      setTimeout(() => descargarDirecto(imgUrl(item.id), `nubira-${item.id}-post.jpg`), i * 400);
    });
  }

  return (
    <div className="fixed inset-0 z-[120] flex items-center justify-center p-4 bg-gray-900/50 backdrop-blur-sm" onClick={onCerrar}>
      <div className="bg-white w-[95%] max-w-[600px] rounded-2xl shadow-xl border border-gray-100 max-h-[88vh] flex flex-col" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-center justify-between px-5 pt-4 pb-3 border-b border-gray-100 shrink-0">
          <h3 className="text-base font-bold text-gray-900 tracking-tight">Carrusel de marketing ({items.length} imágenes)</h3>
          <button type="button" onClick={onCerrar} aria-label="Cerrar" className="w-9 h-9 rounded-full flex items-center justify-center text-gray-400 hover:bg-gray-100">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} className="w-5 h-5">
              <path strokeLinecap="round" strokeLinejoin="round" d="M6 18 18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <ul className="flex-1 overflow-y-auto px-5 py-4 space-y-2">
          {items.map((item, i) => (
            <li key={item.id} className="flex items-center gap-3 bg-gray-50 border border-gray-100 rounded-xl p-2.5">
              {/* eslint-disable-next-line @next/next/no-img-element -- imagen generada dinámicamente server-side */}
              <img src={imgUrl(item.id)} alt="" loading="lazy" className="w-14 h-14 rounded-lg object-cover border border-gray-200 bg-white shrink-0" />
              <p className="flex-1 min-w-0 text-xs font-semibold text-gray-800 truncate">{item.titulo}</p>
              <div className="flex flex-col shrink-0">
                <button type="button" onClick={() => mover(i, -1)} title="Subir" aria-label="Subir" className="w-6 h-5 flex items-center justify-center text-gray-400 hover:text-[#54A6D8]">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={3} className="w-2.5 h-2.5">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5" />
                  </svg>
                </button>
                <button type="button" onClick={() => mover(i, 1)} title="Bajar" aria-label="Bajar" className="w-6 h-5 flex items-center justify-center text-gray-400 hover:text-[#54A6D8]">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={3} className="w-2.5 h-2.5">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                  </svg>
                </button>
              </div>
              <div className="flex items-center gap-1.5 shrink-0">
                <button
                  type="button"
                  onClick={() => compartirUno(item)}
                  title="Compartir"
                  aria-label="Compartir"
                  className="w-9 h-9 rounded-full bg-white border border-gray-200 text-[#54A6D8] hover:bg-blue-50 flex items-center justify-center transition-colors"
                >
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} className="w-4 h-4">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" />
                  </svg>
                </button>
                {!esTactilConShare && (
                  <a
                    href={imgUrl(item.id)}
                    download={`nubira-${item.id}-post.jpg`}
                    title="Descargar"
                    aria-label="Descargar"
                    className="w-9 h-9 rounded-full bg-white border border-gray-200 text-[#54A6D8] hover:bg-blue-50 flex items-center justify-center transition-colors"
                  >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} className="w-4 h-4">
                      <path strokeLinecap="round" strokeLinejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" />
                    </svg>
                  </a>
                )}
              </div>
            </li>
          ))}
        </ul>

        <div className="px-5 py-4 border-t border-gray-100 shrink-0">
          <button
            type="button"
            onClick={descargarTodas}
            className="w-full bg-[#54A6D8] hover:bg-blue-600 text-white text-sm font-bold py-3 rounded-xl transition-all flex items-center justify-center gap-2"
          >
            {esTactilConShare ? "Compartir todas" : "Descargar todas"}
          </button>
          <p className="text-[11px] text-gray-400 text-center mt-2 leading-relaxed">El orden de descarga es el mismo orden en que aparecen arriba. Usa ▲▼ para reordenar antes de descargar.</p>
        </div>
      </div>
    </div>
  );
}

// Puerto de admin_marketing_cards.php, tab Servicios — grid + selección + "Ver como
// carrusel". Puro curador: reutiliza /api/servicio/compartir/{id}/post (ya existente) para
// cada thumbnail, cero generación de imagen nueva acá.
export function AdminMarketingServiciosPanel({ servicios }: { servicios: ServicioMarketing[] }) {
  const [seleccionados, setSeleccionados] = useState<Set<number>>(new Set());
  const [carruselAbierto, setCarruselAbierto] = useState(false);

  const todosSeleccionados = servicios.length > 0 && servicios.every((s) => seleccionados.has(s.id));

  function toggle(id: number) {
    setSeleccionados((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }

  function toggleTodos() {
    setSeleccionados(todosSeleccionados ? new Set() : new Set(servicios.map((s) => s.id)));
  }

  const itemsCarrusel: ItemCarrusel[] = useMemo(() => servicios.filter((s) => seleccionados.has(s.id)).map((s) => ({ id: s.id, titulo: s.titulo })), [servicios, seleccionados]);

  if (servicios.length === 0) {
    return <div className="bg-white border border-dashed border-gray-200 rounded-2xl p-12 text-center text-gray-400">No hay servicios que coincidan con estos filtros.</div>;
  }

  return (
    <>
      <div className="flex items-center gap-3">
        <label className="inline-flex items-center gap-2 text-sm text-gray-600 cursor-pointer select-none">
          <input type="checkbox" checked={todosSeleccionados} onChange={toggleTodos} className="w-4 h-4 rounded accent-[#54A6D8] cursor-pointer" />
          Seleccionar todos los visibles
        </label>
      </div>

      <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-4">
        {servicios.map((s) => (
          <div key={s.id} className="relative bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden group">
            <label className="absolute top-2 left-2 z-10 w-6 h-6 rounded-md bg-white/90 backdrop-blur-sm border border-gray-200 flex items-center justify-center cursor-pointer shadow-sm">
              <input type="checkbox" checked={seleccionados.has(s.id)} onChange={() => toggle(s.id)} className="w-4 h-4 rounded accent-[#54A6D8] cursor-pointer" />
            </label>

            {s.conVideo && (
              <span className="absolute top-2 right-2 z-10 bg-black/60 text-white text-[9px] font-bold uppercase tracking-wide px-2 py-1 rounded-full flex items-center gap-1">Video</span>
            )}

            <div className="w-full aspect-square bg-gray-100">
              {/* eslint-disable-next-line @next/next/no-img-element -- imagen generada dinámicamente server-side */}
              <img src={imgUrl(s.id)} loading="lazy" decoding="async" alt={s.titulo} className="w-full h-full object-cover" />
            </div>

            <div className="p-3">
              <p className="text-xs font-bold text-gray-900 line-clamp-2 leading-snug mb-1">{s.titulo}</p>
              <p className="text-[10px] text-gray-400 truncate">{s.tutorNombre}</p>
              <div className="flex items-center justify-between mt-2">
                <span className="text-[9px] font-bold uppercase tracking-wide text-[#54A6D8] bg-blue-50 border border-blue-100 px-2 py-0.5 rounded-full truncate max-w-[70%]">{s.categoria}</span>
                <span className="text-[9px] text-gray-400 shrink-0">{new Date(s.fechaPublicacion).toLocaleDateString("es-CL")}</span>
              </div>
            </div>
          </div>
        ))}
      </div>

      {seleccionados.size > 0 && (
        <div className="fixed bottom-0 left-0 right-0 lg:left-64 z-40 bg-white border-t border-gray-200 shadow-[0_-4px_12px_rgba(0,0,0,0.06)]">
          <div className="max-w-[1600px] mx-auto px-4 md:px-8 py-4 flex items-center justify-between gap-4">
            <p className="text-sm font-bold text-gray-700">
              {seleccionados.size} {seleccionados.size === 1 ? "servicio seleccionado" : "servicios seleccionados"}
            </p>
            <button
              type="button"
              onClick={() => setCarruselAbierto(true)}
              className="px-5 py-2.5 rounded-xl bg-[#54A6D8] hover:bg-blue-600 text-white text-sm font-bold transition-colors flex items-center gap-2"
            >
              Ver como carrusel
            </button>
          </div>
        </div>
      )}

      {carruselAbierto && <CarruselModal items={itemsCarrusel} onCerrar={() => setCarruselAbierto(false)} />}
    </>
  );
}
