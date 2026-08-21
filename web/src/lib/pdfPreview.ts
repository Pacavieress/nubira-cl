// Renderiza la página 1 de un PDF a un Blob de imagen en el navegador — cierra el gap
// documentado en publicar.controller.ts (server/): Node no tiene equivalente directo a
// Imagick para generar el preview/portada de un PDF subido, así que se resuelve del mismo
// modo que ya confirmó el usuario: render client-side + subir el blob resultante junto al
// archivo real. Ancho objetivo ~900px, igual al thumbnailImage(900,0) del pipeline Imagick
// real (app/formulario_subir_apunte.php:266). Solo la página 1 — SIN el selector de
// portada multi-página del PHP real (selector-portada-container), que es en sí mismo una
// porción de UI aparte, no parte de este cierre puntual del gap de preview.
export async function renderizarPrimeraPaginaPdf(archivo: File): Promise<Blob | null> {
  try {
    const pdfjsLib = await import("pdfjs-dist");
    pdfjsLib.GlobalWorkerOptions.workerSrc = new URL("pdfjs-dist/build/pdf.worker.min.mjs", import.meta.url).toString();

    const buffer = await archivo.arrayBuffer();
    const pdf = await pdfjsLib.getDocument({ data: buffer }).promise;
    const pagina = await pdf.getPage(1);

    const viewportBase = pagina.getViewport({ scale: 1 });
    const escala = 900 / viewportBase.width;
    const viewport = pagina.getViewport({ scale: Math.max(escala, 0.1) });

    const canvas = document.createElement("canvas");
    canvas.width = Math.round(viewport.width);
    canvas.height = Math.round(viewport.height);

    await pagina.render({ canvas, viewport }).promise;

    return await new Promise<Blob | null>((resolve) => {
      canvas.toBlob((blob) => resolve(blob), "image/jpeg", 0.85);
    });
  } catch {
    // Mismo criterio defensivo que el resto del pipeline: un PDF que pdf.js no puede
    // renderizar (corrupto, escaneado de forma rara, etc.) no debe bloquear la
    // publicación — el apunte se crea igual, solo sin preview.
    return null;
  }
}
