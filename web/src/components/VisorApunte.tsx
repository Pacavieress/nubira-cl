"use client";

import { useEffect, useRef, useState } from "react";

interface VisorApunteProps {
  titulo: string;
  accesoCompleto: boolean;
  fileUrl: string | null;
  esPDF: boolean;
  esImagen: boolean;
  portadaUrl: string;
  previewPaginasUrls: string[];
}

function IconDescargar({ className }: { className: string }) {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" className={className}>
      <path
        fillRule="evenodd"
        clipRule="evenodd"
        d="M5.625 1.5c-1.036 0-1.875.84-1.875 1.875v17.25c0 1.035.84 1.875 1.875 1.875h12.75c1.035 0 1.875-.84 1.875-1.875V12.75A3.75 3.75 0 0016.5 9h-1.875a1.875 1.875 0 01-1.875-1.875V5.25A3.75 3.75 0 009 1.5H5.625zM7.5 15a.75.75 0 01.75-.75h7.5a.75.75 0 010 1.5h-7.5A.75.75 0 017.5 15zm.75 2.25a.75.75 0 000 1.5H12a.75.75 0 000-1.5H8.25z"
      />
      <path d="M12.971 1.816A5.23 5.23 0 0114.25 5.25v1.875c0 .207.168.375.375.375H16.5a5.23 5.23 0 013.434 1.279 9.768 9.768 0 00-6.963-6.963z" />
    </svg>
  );
}

function IconChevron({ direccion, className }: { direccion: "left" | "right"; className: string }) {
  const d = direccion === "left" ? "M15.75 19.5L8.25 12l7.5-7.5" : "M8.25 4.5l7.5 7.5-7.5 7.5";
  return (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} className={className}>
      <path strokeLinecap="round" strokeLinejoin="round" d={d} />
    </svg>
  );
}

// Cabecera oscura del visor — puerto de ver_apunte.php:546-564. `hijoIzquierdo` es la
// navegación de páginas (solo PDF) o la etiqueta "VISUALIZADOR" (imagen).
function CabeceraVisor({ fileUrl, hijoIzquierdo }: { fileUrl: string; hijoIzquierdo: React.ReactNode }) {
  return (
    <div className="bg-gray-800/90 backdrop-blur text-white p-3 flex items-center justify-between z-10 shrink-0 border-b border-white/10 rounded-t-2xl">
      <div className="flex items-center gap-3">{hijoIzquierdo}</div>
      <a href={fileUrl} download className="w-8 h-8 flex items-center justify-center hover:bg-white/20 rounded-lg text-white" title="Descargar">
        <IconDescargar className="w-4 h-4" />
      </a>
    </div>
  );
}

// Puerto de ver_apunte.php:1080-1129 (motor pdf.js) — reusa pdfjs-dist@6.2.108 ya instalado,
// mismo patrón de worker dinámico que web/src/lib/pdfPreview.ts (import + workerSrc con
// new URL). NO carga la v3.11.174 del PHP real por CDN: sería una segunda copia del paquete
// con una API distinta y sin el patrón de worker ya resuelto en este repo.
//
// Fuente: fileUrl (descargar_apunte.php real, vía enlaceDescargaApunte() firmado) — el PHP
// real usa un endpoint aparte para esto (ver_pdf_apunte.php, solo-sesión, sin soporte de
// comprador invitado). fileUrl ya cubre ambos casos (logueado + invitado con link firmado)
// y ya soporta acceso completo, así que no hace falta portar un segundo endpoint solo para
// el visor.
function VisorPdf({ fileUrl }: { fileUrl: string }) {
  const canvasRef = useRef<HTMLCanvasElement>(null);
  const [pageNum, setPageNum] = useState(1);
  const [pageCount, setPageCount] = useState<number | null>(null);
  const [error, setError] = useState(false);

  const pdfDocRef = useRef<import("pdfjs-dist").PDFDocumentProxy | null>(null);
  const renderingRef = useRef(false);
  const pendingPageRef = useRef<number | null>(null);

  useEffect(() => {
    let cancelado = false;

    async function cargar() {
      try {
        const pdfjsLib = await import("pdfjs-dist");
        pdfjsLib.GlobalWorkerOptions.workerSrc = new URL("pdfjs-dist/build/pdf.worker.min.mjs", import.meta.url).toString();

        const pdfDoc = await pdfjsLib.getDocument({ url: fileUrl }).promise;
        if (cancelado) return;
        pdfDocRef.current = pdfDoc;
        setPageCount(pdfDoc.numPages);
        renderPagina(1);
      } catch {
        if (!cancelado) setError(true);
      }
    }

    // Puerto exacto de renderPage()/queueRenderPage() de ver_apunte.php:1088-1111: guard
    // contra renders solapados (pageRendering) — si llega un pedido de página mientras el
    // canvas actual sigue renderizando, se guarda como "pendiente" y se dispara al terminar,
    // en vez de arrancar 2 renders a la vez sobre el mismo canvas.
    function renderPagina(num: number) {
      const pdfDoc = pdfDocRef.current;
      const canvas = canvasRef.current;
      if (!pdfDoc || !canvas) return;

      renderingRef.current = true;
      pdfDoc.getPage(num).then((page) => {
        const escala = window.innerWidth < 768 ? 1.0 : 1.5;
        const viewport = page.getViewport({ scale: escala });
        const outputScale = window.devicePixelRatio || 1;

        canvas.width = Math.floor(viewport.width * outputScale);
        canvas.height = Math.floor(viewport.height * outputScale);
        canvas.style.width = `${Math.floor(viewport.width)}px`;
        canvas.style.height = `${Math.floor(viewport.height)}px`;

        const ctx = canvas.getContext("2d", { willReadFrequently: true });
        if (!ctx) return;

        page
          .render({ canvas, canvasContext: ctx, viewport, transform: [outputScale, 0, 0, outputScale, 0, 0] })
          .promise.then(() => {
            renderingRef.current = false;
            setPageNum(num);
            if (pendingPageRef.current !== null) {
              const siguiente = pendingPageRef.current;
              pendingPageRef.current = null;
              renderPagina(siguiente);
            }
          })
          .catch(() => {
            renderingRef.current = false;
          });
      });
    }

    function pedirPagina(num: number) {
      if (renderingRef.current) pendingPageRef.current = num;
      else renderPagina(num);
    }

    cargar();

    function onKeyDown(e: KeyboardEvent) {
      const pdfDoc = pdfDocRef.current;
      if (!pdfDoc) return;
      if (e.key === "ArrowLeft" && pageNum > 1) pedirPagina(pageNum - 1);
      if (e.key === "ArrowRight" && pageNum < pdfDoc.numPages) pedirPagina(pageNum + 1);
    }
    document.addEventListener("keydown", onKeyDown);

    return () => {
      cancelado = true;
      document.removeEventListener("keydown", onKeyDown);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps -- fileUrl fijo por instancia; pageNum se lee vía closure para el listener de teclado, no debe re-crear el efecto en cada cambio de página.
  }, [fileUrl]);

  function irAnterior() {
    if (pageNum <= 1 || renderingRef.current) return;
    setPageNum((n) => n - 1);
  }
  function irSiguiente() {
    if (!pdfDocRef.current || pageNum >= pdfDocRef.current.numPages || renderingRef.current) return;
    setPageNum((n) => n + 1);
  }

  if (error) {
    return (
      <div className="flex-grow flex items-center justify-center bg-gray-900 rounded-b-2xl">
        <div className="text-red-400 p-6 text-center text-sm font-bold">Error al cargar el documento.</div>
      </div>
    );
  }

  return (
    <>
      <CabeceraVisor
        fileUrl={fileUrl}
        hijoIzquierdo={
          <>
            <button type="button" onClick={irAnterior} className="w-8 h-8 flex items-center justify-center hover:bg-white/20 rounded-lg transition">
              <IconChevron direccion="left" className="w-4 h-4" />
            </button>
            <span className="text-xs md:text-sm font-semibold tabular-nums">
              {pageNum} / {pageCount ?? "--"}
            </span>
            <button type="button" onClick={irSiguiente} className="w-8 h-8 flex items-center justify-center hover:bg-white/20 rounded-lg transition">
              <IconChevron direccion="right" className="w-4 h-4" />
            </button>
          </>
        }
      />
      <div className="flex-grow relative overflow-auto bg-gray-900 flex justify-center items-center rounded-b-2xl">
        {pageCount === null && (
          <div className="flex flex-col items-center justify-center gap-3 py-10">
            <div className="animate-spin h-8 w-8 border-[3px] border-gray-600 border-t-white rounded-full" />
            <span className="text-white/50 text-xs font-medium">Cargando documento...</span>
          </div>
        )}
        <canvas ref={canvasRef} className={`my-4 rounded-sm ${pageCount === null ? "hidden" : "block"}`} style={{ maxWidth: "95%", height: "auto" }} />
      </div>
    </>
  );
}

// Puerto de ver_apunte.php:581-599 (vista previa bloqueada) — imágenes ya pre-generadas,
// sin navegación. onError en cascada porque Node no verifica file_exists() (ver
// resolverPreviewPaginasApunte() en server/src/lib/media.ts): si una página no existe de
// verdad, el navegador la oculta al fallar la carga; si las 3 fallan, cae a portadaUrl.
function VistaBloqueada({ portadaUrl, previewPaginasUrls }: { portadaUrl: string; previewPaginasUrls: string[] }) {
  const [fallidas, setFallidas] = useState<Set<number>>(new Set());
  const todasFallaron = previewPaginasUrls.length === 0 || fallidas.size === previewPaginasUrls.length;

  return (
    <div className="absolute inset-0 w-full h-full bg-gray-200 z-0 rounded-2xl overflow-y-auto">
      <div className="flex flex-col items-center p-4 gap-4 pb-8">
        {!todasFallaron
          ? previewPaginasUrls.map(
              (url, idx) =>
                !fallidas.has(idx) && (
                  <div key={url} className="relative w-full max-w-[800px] bg-white border border-gray-200">
                    {/* eslint-disable-next-line @next/next/no-img-element */}
                    <img
                      src={url}
                      alt=""
                      className="w-full h-auto object-top"
                      onError={() => setFallidas((prev) => new Set(prev).add(idx))}
                    />
                    <div className="absolute bottom-2 right-2 bg-black/40 text-white text-[10px] px-2 py-0.5 rounded backdrop-blur-sm">Pág {idx + 1}</div>
                  </div>
                ),
            )
          : (
              <div className="relative w-full max-w-[800px] bg-white border border-gray-200">
                {/* eslint-disable-next-line @next/next/no-img-element */}
                <img
                  src={portadaUrl}
                  alt=""
                  className="w-full h-auto object-top"
                  onError={(e) => {
                    e.currentTarget.src = "/img/logo2.webp";
                  }}
                />
              </div>
            )}
      </div>
    </div>
  );
}

// Puerto de ver_apunte.php:542-599 — wrapper + despacho según acceso/tipo de archivo.
export function VisorApunte({ titulo, accesoCompleto, fileUrl, esPDF, esImagen, portadaUrl, previewPaginasUrls }: VisorApunteProps) {
  return (
    <div className="bg-gray-200 rounded-2xl w-full relative h-[60vh] md:h-[70vh] border border-gray-200 flex flex-col overflow-hidden" id="visor-wrapper">
      {!accesoCompleto ? (
        <VistaBloqueada portadaUrl={portadaUrl} previewPaginasUrls={previewPaginasUrls} />
      ) : esPDF && fileUrl ? (
        <VisorPdf fileUrl={fileUrl} />
      ) : esImagen && fileUrl ? (
        <>
          <CabeceraVisor fileUrl={fileUrl} hijoIzquierdo={<span className="text-xs font-bold tracking-wide text-white/80">VISUALIZADOR</span>} />
          <div className="flex-grow relative overflow-auto bg-gray-900 flex justify-center items-center rounded-b-2xl">
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src={fileUrl} alt={titulo} className="max-w-full max-h-full object-contain" />
          </div>
        </>
      ) : (
        <div className="flex-grow flex items-center justify-center bg-gray-900 rounded-b-2xl">
          <div className="text-white/70 text-sm p-6 text-center">Este tipo de archivo no se puede previsualizar aquí. Usa &quot;Descargar&quot;.</div>
        </div>
      )}
    </div>
  );
}
