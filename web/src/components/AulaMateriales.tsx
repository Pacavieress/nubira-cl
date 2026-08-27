"use client";

import { useRef, useState } from "react";
import type { ArchivoContrato } from "@/lib/api";

// Puerto de app/entregas_servicio.php — Grupo Mini Aula, Pieza 2. El PHP real permite
// arrastrar-y-soltar sobre toda la zona; acá se simplifica a click (mismo resultado, menos
// superficie de JS que mantener por ahora — arrastrar se puede sumar después sin tocar el
// backend).
const ICONOS_EXT: Record<string, string> = {
  pdf: "📄",
  doc: "📝",
  docx: "📝",
  xls: "📊",
  xlsx: "📊",
  ppt: "📽️",
  pptx: "📽️",
  zip: "🗜️",
  rar: "🗜️",
  "7z": "🗜️",
  jpg: "🖼️",
  jpeg: "🖼️",
  png: "🖼️",
  mp4: "🎬",
  mov: "🎬",
  txt: "📃",
};

export function AulaMateriales({ contratoId, archivosIniciales, puedeSubir }: { contratoId: number; archivosIniciales: ArchivoContrato[]; puedeSubir: boolean }) {
  const [archivos, setArchivos] = useState(archivosIniciales);
  const [subiendo, setSubiendo] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const inputRef = useRef<HTMLInputElement>(null);

  async function refrescar() {
    const res = await fetch(`/api/me/aula/${contratoId}/archivos`, { cache: "no-store" });
    if (res.ok) {
      const data = (await res.json()) as { archivos: ArchivoContrato[] };
      setArchivos(data.archivos);
    }
  }

  async function subir(file: File) {
    setError(null);
    setSubiendo(true);
    try {
      const fd = new FormData();
      fd.append("archivo", file);
      const res = await fetch(`/api/me/aula/${contratoId}/archivos`, { method: "POST", body: fd });
      const data = (await res.json().catch(() => null)) as { ok?: boolean; error?: string } | null;
      if (res.ok && data?.ok) {
        await refrescar();
      } else {
        setError(data?.error ?? "No se pudo subir el archivo.");
      }
    } catch {
      setError("Error de conexión. Intenta nuevamente.");
    } finally {
      setSubiendo(false);
      if (inputRef.current) inputRef.current.value = "";
    }
  }

  return (
    <div className="w-full h-full bg-white rounded-3xl border border-gray-100 shadow-sm overflow-hidden relative flex flex-col">
      {puedeSubir && (
        <div className="p-4 bg-white shrink-0 border-b border-gray-50">
          <label className="block border-2 border-dashed border-gray-300 rounded-xl bg-gray-50/50 p-6 flex flex-col items-center justify-center cursor-pointer transition-all hover:border-blue-400 hover:bg-blue-50/20 relative">
            <div className="w-12 h-12 bg-white rounded-full shadow-sm border border-gray-100 flex items-center justify-center mb-2 text-blue-400 text-xl">↑</div>
            <span className="text-sm font-bold text-gray-700">Subir archivo</span>
            <span className="text-xs text-gray-400">PDF, Word, Excel, imágenes, video (máx. 50 MB)</span>
            <input
              ref={inputRef}
              type="file"
              className="hidden"
              onChange={(e) => {
                const file = e.target.files?.[0];
                if (file) void subir(file);
              }}
            />
            {subiendo && (
              <div className="absolute inset-0 bg-white/90 flex flex-col items-center justify-center backdrop-blur-sm z-20 rounded-xl">
                <div className="animate-spin h-6 w-6 border-2 border-blue-500 border-t-transparent rounded-full mb-1" />
                <span className="text-xs font-bold text-blue-600">Subiendo...</span>
              </div>
            )}
          </label>
          {error && <p className="text-xs text-red-600 mt-2">{error}</p>}
        </div>
      )}

      <div className="flex-1 overflow-y-auto p-4 space-y-2">
        {archivos.length === 0 ? (
          <div className="flex flex-col items-center justify-center h-full text-gray-300 py-10">
            <p className="text-xs">Sin archivos</p>
          </div>
        ) : (
          archivos.map((f) => {
            const ext = f.nombreOriginal.split(".").pop()?.toLowerCase() ?? "";
            return (
              <div key={f.id} className={`flex items-center p-3 border rounded-lg hover:shadow-sm transition bg-white ${f.esMio ? "border-blue-100" : "border-gray-100"}`}>
                <div className="w-10 h-10 flex items-center justify-center bg-gray-50 rounded border border-gray-100 shrink-0 text-xl">{ICONOS_EXT[ext] ?? "📎"}</div>
                <div className="ml-3 flex-1 min-w-0">
                  <p className="text-sm font-bold text-gray-800 truncate">{f.nombreOriginal}</p>
                  <p className="text-[10px] text-gray-500">
                    <span className={f.esMio ? "text-blue-500 font-bold" : ""}>{f.esMio ? "Tú" : f.subidoPor.split(" ")[0]}</span> · {f.pesoKb} KB ·{" "}
                    {new Date(f.fecha).toLocaleDateString("es-CL", { day: "2-digit", month: "2-digit", hour: "2-digit", minute: "2-digit" })}
                  </p>
                </div>
                <a href={f.url} download className="w-8 h-8 flex items-center justify-center text-gray-400 hover:text-blue-500 hover:bg-blue-50 rounded-full transition">
                  ↓
                </a>
              </div>
            );
          })
        )}
      </div>
    </div>
  );
}
