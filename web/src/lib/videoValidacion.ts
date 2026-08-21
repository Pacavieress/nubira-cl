// Puerto de la validación client-side + captura de miniatura de desafio... digo, de
// publicar_servicio.php (el bloque "VIDEO DE PRESENTACIÓN", líneas ~881-963): duración
// máxima 45s, debe ser vertical (9:16), miniatura capturada vía <canvas> en el segundo
// min(15, duracion-0.5). Mejor esfuerzo — si la captura de miniatura falla, el video sigue
// siendo válido, solo sin thumbBlob (mismo criterio que el PHP real).
export interface ValidacionVideo {
  ok: boolean;
  error?: string;
  duracionSeg?: number;
  thumbBlob?: Blob | null;
}

export function validarYCapturarVideo(file: File): Promise<ValidacionVideo> {
  return new Promise((resolve) => {
    const objUrl = URL.createObjectURL(file);
    const video = document.createElement("video");
    video.preload = "metadata";
    video.muted = true;
    video.src = objUrl;

    video.addEventListener("loadedmetadata", () => {
      if (video.duration > 45) {
        URL.revokeObjectURL(objUrl);
        resolve({ ok: false, error: `El video dura ${Math.ceil(video.duration)}s. Máximo 45 segundos.` });
        return;
      }
      if (video.videoWidth > 0 && video.videoWidth >= video.videoHeight) {
        URL.revokeObjectURL(objUrl);
        resolve({ ok: false, error: "El video debe ser vertical (9:16). Grábalo con el celular en modo retrato." });
        return;
      }

      const duracionSeg = video.duration;
      let resuelto = false;
      const finalizar = (thumbBlob: Blob | null) => {
        if (resuelto) return;
        resuelto = true;
        URL.revokeObjectURL(objUrl);
        resolve({ ok: true, duracionSeg, thumbBlob });
      };
      const timeoutCaptura = setTimeout(() => finalizar(null), 3000);

      video.addEventListener(
        "seeked",
        () => {
          try {
            const w = video.videoWidth;
            const h = video.videoHeight;
            const escala = Math.min(1, 480 / Math.max(w, h));
            const canvas = document.createElement("canvas");
            canvas.width = Math.round(w * escala);
            canvas.height = Math.round(h * escala);
            const ctx = canvas.getContext("2d");
            if (!ctx) {
              clearTimeout(timeoutCaptura);
              finalizar(null);
              return;
            }
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
            canvas.toBlob(
              (blob) => {
                clearTimeout(timeoutCaptura);
                finalizar(blob);
              },
              "image/jpeg",
              0.82,
            );
          } catch {
            clearTimeout(timeoutCaptura);
            finalizar(null);
          }
        },
        { once: true },
      );
      video.currentTime = Math.max(0, Math.min(15, duracionSeg - 0.5));
    });

    video.addEventListener("error", () => {
      URL.revokeObjectURL(objUrl);
      resolve({ ok: false, error: "No se pudo leer el video. Asegúrate de que el archivo no esté dañado." });
    });
  });
}
