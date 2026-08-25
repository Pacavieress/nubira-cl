"use client";

import { useRef, useState } from "react";

// Puerto de detalle_servicio.php:589-625 (reproductor del video de presentación del tutor,
// con botón de play superpuesto sobre el poster que se oculta al reproducir y reaparece al
// pausar/terminar — mismo comportamiento del script inline real).
export function VideoTutorPlayer({ videoUrl, posterUrl }: { videoUrl: string; posterUrl: string | null }) {
  const videoRef = useRef<HTMLVideoElement>(null);
  const [reproduciendo, setReproduciendo] = useState(false);

  return (
    <div className="w-[140px] md:w-[180px]">
      <div className="relative aspect-[9/16] bg-black rounded-xl overflow-hidden shadow-sm">
        <video
          ref={videoRef}
          src={videoUrl}
          poster={posterUrl ?? undefined}
          className="w-full h-full object-cover"
          controls
          preload="none"
          controlsList="nodownload"
          disablePictureInPicture
          playsInline
          onPause={() => setReproduciendo(false)}
          onEnded={() => setReproduciendo(false)}
        />
        {!reproduciendo && (
          <button
            type="button"
            onClick={() => {
              videoRef.current?.play();
              setReproduciendo(true);
            }}
            className="absolute inset-0 flex items-center justify-center bg-black/10 hover:bg-black/20 transition-colors"
            aria-label="Reproducir video"
          >
            <span className="w-11 h-11 rounded-full bg-white/90 flex items-center justify-center shadow-md">
              <svg className="w-4 h-4 text-gray-900 ml-0.5" fill="currentColor" viewBox="0 0 24 24">
                <path d="M8 5v14l11-7z" />
              </svg>
            </span>
          </button>
        )}
        <div className="absolute top-1.5 right-1.5 pointer-events-none z-10">
          <span className="text-white/75 text-[9px] font-bold tracking-wide px-1.5 py-0.5 rounded bg-black/30" style={{ textShadow: "0 1px 2px rgba(0,0,0,0.8)" }}>
            Nubira.cl
          </span>
        </div>
      </div>
    </div>
  );
}
