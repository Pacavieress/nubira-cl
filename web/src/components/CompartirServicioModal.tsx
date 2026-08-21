"use client";

import { useState } from "react";

// Puerto de app/img_servicio.php + imagen_compartir.php (nb_generar_imagen_post) — SOLO
// formato POST 4:5 (mismo criterio de slices que Compartir Apuntes/Desafío: HISTORY queda
// para otra pieza). Mismo patrón de UI que CompartirApunteModal.tsx: el objetivo (el
// servicio) ya es fijo desde que se abre el modal, sin paso 1 de selección.

type Formato = "post" | "caption" | "share";

function IconoX() {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-5 h-5">
      <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
    </svg>
  );
}
function IconoCompartir() {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor" className="w-4 h-4">
      <path strokeLinecap="round" strokeLinejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" />
    </svg>
  );
}

export function CompartirServicioModal({
  servicioId,
  titulo,
  abierto,
  onCerrar,
}: {
  servicioId: number;
  titulo: string;
  abierto: boolean;
  onCerrar: () => void;
}) {
  const [copiado, setCopiado] = useState(false);

  if (!abierto) return null;

  const imgUrl = `/api/servicio/compartir/${servicioId}/post`;
  const caption = `${titulo}\n\nEncuéntralo en Nubira: https://nubira.cl/servicios/${servicioId}\n\n#Nubira #ClasesParticulares`;
  const nombreArchivo = `nubira-servicio-${servicioId}.jpg`;

  function track(formato: Formato) {
    fetch("/api/servicio/compartir/track", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ servicioId, formato }),
    }).catch(() => {});
  }

  async function copiarTexto() {
    track("caption");
    try {
      await navigator.clipboard.writeText(caption);
      setCopiado(true);
      setTimeout(() => setCopiado(false), 1500);
    } catch {
      // Clipboard API puede fallar sin permiso/HTTPS — sin bloquear el resto del modal.
    }
  }

  async function compartirNativo() {
    track("share");
    try {
      const resp = await fetch(imgUrl);
      const blob = await resp.blob();
      const file = new File([blob], nombreArchivo, { type: "image/jpeg" });
      const nav = navigator as Navigator & { canShare?: (data: { files: File[] }) => boolean; share?: (data: { files: File[]; text: string }) => Promise<void> };
      if (nav.canShare?.({ files: [file] })) {
        await nav.share?.({ files: [file], text: caption });
        return;
      }
    } catch {
      // Sigue al fallback de descarga directa.
    }
    const a = document.createElement("a");
    a.href = imgUrl;
    a.download = nombreArchivo;
    a.click();
  }

  return (
    <div className="fixed inset-0 z-[120] flex items-center justify-center p-4 bg-gray-900/50 backdrop-blur-sm" onClick={onCerrar}>
      <div
        className="bg-white w-[95%] max-w-[420px] rounded-2xl shadow-xl border border-gray-100 max-h-[92vh] overflow-y-auto"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-center justify-between px-5 pt-4 pb-2">
          <h3 className="text-base font-bold text-gray-900 tracking-tight">Comparte este servicio</h3>
          <button type="button" onClick={onCerrar} aria-label="Cerrar" className="w-9 h-9 rounded-full flex items-center justify-center text-gray-400 hover:bg-gray-100">
            <IconoX />
          </button>
        </div>

        <div className="px-5 pb-5">
          <div className="flex justify-center pb-4 pt-1">
            {/* eslint-disable-next-line @next/next/no-img-element -- imagen generada dinámicamente server-side, no un asset estático */}
            <img
              src={imgUrl}
              alt="Vista previa"
              className="w-[240px] aspect-[4/5] object-cover rounded-xl border border-gray-100 shadow-sm bg-gray-50"
            />
          </div>

          <div className="space-y-2.5">
            <a
              href={imgUrl}
              download={nombreArchivo}
              onClick={() => track("post")}
              className="block text-center bg-[#54A6D8] hover:bg-blue-600 text-white text-sm font-bold py-3 rounded-xl transition-all"
            >
              Descargar imagen
            </a>
            <button type="button" onClick={copiarTexto} className="w-full bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-bold py-3 rounded-xl transition-all">
              {copiado ? "¡Copiado!" : "Copiar texto"}
            </button>
            <button
              type="button"
              onClick={compartirNativo}
              className="w-full border border-[#54A6D8] text-[#54A6D8] hover:bg-blue-50 text-sm font-bold py-3 rounded-xl transition-all flex items-center justify-center gap-2"
            >
              <IconoCompartir /> Compartir
            </button>
            <p className="text-[11px] text-gray-400 text-center pt-1">Descarga la imagen y súbela a tu historia o feed de Instagram.</p>
          </div>
        </div>
      </div>
    </div>
  );
}
