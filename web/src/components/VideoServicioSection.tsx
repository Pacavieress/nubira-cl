"use client";

import { useRef, useState } from "react";
import { validarYCapturarVideo } from "@/lib/videoValidacion";

// Puerto de la sección "Video de presentación" de publicar_servicio.php — opcional, se
// sube DESPUÉS de crear el servicio y guardar el horario (ver PublicarServicioForm.tsx).
// Simplificación deliberada respecto al PHP real: sin el acordeón colapsable con animación
// de chevron (siempre visible acá, es puramente cosmético) y con los 2 cuadros informativos
// (reglas + guion sugerido) fusionados en uno solo más compacto.
export interface VideoSeleccionado {
  archivo: File;
  thumbBlob: Blob | null;
}

const TIPOS_AUDIO_VIDEO_VALIDOS = ["video/mp4", "video/webm", "video/quicktime"];
const MAX_BYTES = 30 * 1024 * 1024;

export function VideoServicioSection({
  video,
  onVideoChange,
  consentimiento,
  onConsentimientoChange,
}: {
  video: VideoSeleccionado | null;
  onVideoChange: (v: VideoSeleccionado | null) => void;
  consentimiento: boolean;
  onConsentimientoChange: (v: boolean) => void;
}) {
  const inputRef = useRef<HTMLInputElement>(null);
  const [previewUrl, setPreviewUrl] = useState<string | null>(null);
  const [validando, setValidando] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function elegirArchivo(file: File) {
    setError(null);
    if (!TIPOS_AUDIO_VIDEO_VALIDOS.includes(file.type)) {
      setError("Formato no válido. Usa MP4, WebM o MOV.");
      return;
    }
    if (file.size > MAX_BYTES) {
      setError(`Tu video pesa ${(file.size / 1024 / 1024).toFixed(1)} MB, el máximo permitido es 30 MB. Comprime el video e intenta de nuevo.`);
      return;
    }

    setValidando(true);
    const resultado = await validarYCapturarVideo(file);
    setValidando(false);

    if (!resultado.ok) {
      setError(resultado.error ?? "No se pudo validar el video.");
      return;
    }

    setPreviewUrl((anterior) => {
      if (anterior) URL.revokeObjectURL(anterior);
      return URL.createObjectURL(file);
    });
    onVideoChange({ archivo: file, thumbBlob: resultado.thumbBlob ?? null });
  }

  function quitar() {
    onVideoChange(null);
    setPreviewUrl((anterior) => {
      if (anterior) URL.revokeObjectURL(anterior);
      return null;
    });
    setError(null);
    if (inputRef.current) inputRef.current.value = "";
  }

  return (
    <div>
      <div className="bg-sky-50 border border-sky-100 rounded-xl p-4 mb-4">
        <p className="text-xs font-bold text-sky-800 mb-1">Reglas del video</p>
        <p className="text-xs text-sky-700 leading-relaxed">
          Solo tu primer nombre (sin apellido), sin teléfonos ni redes sociales visibles o mencionadas, vertical (9:16), máx. 45 segundos. Ej: &quot;Hola, soy Juan. Enseño Cálculo hace 3 años.
          Escríbeme por Nubira si tienes dudas.&quot;
        </p>
      </div>

      <label
        onClick={() => inputRef.current?.click()}
        className="border-2 border-dashed border-gray-200 rounded-2xl p-6 text-center cursor-pointer hover:border-[#54A6D8] hover:bg-blue-50/20 transition-all flex flex-col items-center gap-2"
      >
        {video ? (
          <div className="flex flex-col items-center gap-2">
            <video src={previewUrl ?? undefined} className="rounded-xl bg-black aspect-[9/16] w-[120px] object-contain" muted playsInline />
            <p className="text-xs text-gray-500 truncate max-w-[220px]">{video.archivo.name}</p>
            <button
              type="button"
              onClick={(e) => {
                e.stopPropagation();
                quitar();
              }}
              className="text-xs font-bold text-red-400 hover:text-red-600"
            >
              Quitar
            </button>
          </div>
        ) : validando ? (
          <p className="text-sm text-gray-400">Validando video...</p>
        ) : (
          <>
            <p className="text-sm font-bold text-gray-700">Toca para seleccionar tu video</p>
            <p className="text-xs text-gray-400">MP4 · WebM · MOV · Máx. 30 MB · Vertical 9:16</p>
          </>
        )}
      </label>
      <input
        ref={inputRef}
        type="file"
        className="hidden"
        accept=".mp4,.webm,.mov,video/mp4,video/webm,video/quicktime"
        onChange={(e) => {
          const file = e.target.files?.[0];
          if (file) elegirArchivo(file);
        }}
      />

      {error && (
        <div className="mt-3 bg-red-50 border border-red-100 rounded-xl px-4 py-3">
          <span className="text-xs font-bold text-red-700">{error}</span>
        </div>
      )}

      <label className="mt-4 flex items-start gap-3 p-4 bg-gray-50 rounded-xl border border-gray-100 cursor-pointer hover:bg-blue-50/30 hover:border-blue-100 transition-all select-none">
        <input
          type="checkbox"
          checked={consentimiento}
          onChange={(e) => onConsentimientoChange(e.target.checked)}
          className="mt-0.5 h-4 w-4 rounded border-gray-300 text-[#54A6D8] focus:ring-[#54A6D8] cursor-pointer shrink-0"
        />
        <span className="text-xs text-gray-600 leading-relaxed">
          <span className="font-bold text-gray-800">Autorizo a Nubira</span> a publicar este video en redes sociales (Instagram, TikTok, Facebook) para promocionar mi servicio. El video no será
          editado y siempre se asociará a mi perfil de tutor.
        </span>
      </label>
    </div>
  );
}
