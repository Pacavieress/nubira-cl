"use client";

import { useState } from "react";
import type { DesafioMateria } from "@/lib/api";

// Puerto de app/componentes/modal_compartir_desafio.php — los 3 pasos: "paso 1: elegir
// materia" + "paso 2: preview y acciones" (invitación genérica, formato POST 4:5) y
// "paso 3: compartir las 3 preguntas de esta sesión" (formato HISTORY 9:16, ver
// preguntasSesion más abajo). Ver server/src/modules/compartir/ para el motor de
// generación (SVG + resvg, no GD).

type Formato = "post" | "caption" | "share" | "preguntas";

export interface PreguntasSesionCompartir {
  ids: number[];
  materiaSlug: string;
  materiaNombre: string;
}

function IconoX() {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-5 h-5">
      <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
    </svg>
  );
}
function IconoChevronIzquierda() {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-3.5 h-3.5">
      <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
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

export function CompartirDesafioModal({
  materias,
  abierto,
  onCerrar,
  preguntasSesion,
}: {
  materias: DesafioMateria[];
  abierto: boolean;
  onCerrar: () => void;
  // Cuando viene informado, el modal abre DIRECTO en el paso 3 (compartir las 3 preguntas
  // ya jugadas en esta sesión) — sin pasar por "elegir materia", igual que el trigger
  // `nb-compartir-desafio-preguntas` del PHP real (desafio.php), que dispara desde la
  // pantalla de preguntas con las 3 ids ya conocidas.
  preguntasSesion?: PreguntasSesionCompartir | null;
}) {
  const [slugElegido, setSlugElegido] = useState<string | null>(null);
  const [copiado, setCopiado] = useState(false);

  if (!abierto) return null;

  const modoPreguntas = !!preguntasSesion;
  const materiaActual = materias.find((m) => m.slug === slugElegido) ?? null;

  const imgUrl = modoPreguntas
    ? `/api/desafio/compartir-preguntas/${preguntasSesion!.ids.join("-")}/history`
    : slugElegido
      ? `/api/desafio/compartir/${slugElegido}/post`
      : "";

  const caption = modoPreguntas
    ? `¿Te animas con estas 3 preguntas de ${preguntasSesion!.materiaNombre}?\n\nSin spoilers — respóndelas tú también en https://nubira.cl/desafio\n\n#Nubira #DesafioDeHoy`
    : materiaActual
      ? `¿Te atreves con el Desafío de ${materiaActual.nombre}?\n\n3 preguntas rápidas para poner a prueba lo que sabes.\n\nJuega en https://nubira.cl/desafio\n\n#Nubira #DesafioDeHoy`
      : "";

  const nombreArchivo = modoPreguntas ? "nubira-desafio-preguntas.jpg" : `nubira-desafio-${slugElegido}.jpg`;
  const materiaSlugParaTrack = modoPreguntas ? preguntasSesion!.materiaSlug : slugElegido;

  function cerrar() {
    setSlugElegido(null);
    setCopiado(false);
    onCerrar();
  }

  function track(formato: Formato) {
    if (!materiaSlugParaTrack) return;
    fetch("/api/desafio/compartir/track", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ materiaSlug: materiaSlugParaTrack, formato }),
    }).catch(() => {});
  }

  async function copiarTexto() {
    track(modoPreguntas ? "preguntas" : "caption");
    try {
      await navigator.clipboard.writeText(caption);
      setCopiado(true);
      setTimeout(() => setCopiado(false), 1500);
    } catch {
      // Clipboard API puede fallar sin permiso/HTTPS — sin bloquear el resto del modal.
    }
  }

  async function compartirNativo() {
    track(modoPreguntas ? "preguntas" : "share");
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

  const mostrarPreview = modoPreguntas || !!materiaActual;

  return (
    <div className="fixed inset-0 z-[120] flex items-center justify-center p-4 bg-gray-900/50 backdrop-blur-sm" onClick={cerrar}>
      <div
        className="bg-white w-[95%] max-w-[420px] rounded-2xl shadow-xl border border-gray-100 max-h-[92vh] overflow-y-auto"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-center justify-between px-5 pt-4 pb-2">
          <h3 className="text-base font-bold text-gray-900 tracking-tight">Comparte el Desafío</h3>
          <button type="button" onClick={cerrar} aria-label="Cerrar" className="w-9 h-9 rounded-full flex items-center justify-center text-gray-400 hover:bg-gray-100">
            <IconoX />
          </button>
        </div>

        {!mostrarPreview ? (
          <div className="px-5 py-4">
            <p className="text-sm text-gray-500 mb-3">¿Qué materia quieres invitar a jugar?</p>
            <div className="grid grid-cols-2 gap-2">
              {materias.map((m) => (
                <button
                  key={m.slug}
                  type="button"
                  onClick={() => setSlugElegido(m.slug)}
                  className="text-left px-3 py-2.5 rounded-xl border border-gray-200 hover:border-[#54A6D8] hover:bg-[#eef6fb] transition-colors text-sm font-medium text-[#222222]"
                >
                  {m.nombre}
                </button>
              ))}
            </div>
          </div>
        ) : (
          <div className="px-5 pb-5">
            {!modoPreguntas && (
              <button type="button" onClick={() => setSlugElegido(null)} className="flex items-center gap-1 text-xs text-gray-400 hover:text-[#54A6D8] mb-3 mt-1">
                <IconoChevronIzquierda /> Cambiar materia
              </button>
            )}

            <div className={`flex justify-center pb-4 ${modoPreguntas ? "pt-1" : ""}`}>
              {/* eslint-disable-next-line @next/next/no-img-element -- imagen generada dinámicamente server-side, no un asset estático */}
              <img
                src={imgUrl}
                alt="Vista previa"
                className={modoPreguntas ? "w-[220px] aspect-[9/16] object-cover rounded-xl border border-gray-100 shadow-sm bg-gray-50" : "w-[240px] aspect-[4/5] object-cover rounded-xl border border-gray-100 shadow-sm bg-gray-50"}
              />
            </div>

            <div className="space-y-2.5">
              <a
                href={imgUrl}
                download={nombreArchivo}
                onClick={() => track(modoPreguntas ? "preguntas" : "post")}
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
              <p className="text-[11px] text-gray-400 text-center pt-1">
                {modoPreguntas ? "Sin spoilers: la imagen no muestra cuál opción es la correcta." : "Descarga la imagen y súbela a tu historia o feed de Instagram."}
              </p>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
