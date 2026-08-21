import { notFound } from "next/navigation";
import { getApunteDetalle } from "@/lib/api";
import { formatoCLP } from "@/lib/formato";
import { abreviarNombre, inicial } from "@/lib/texto";
import { Header } from "@/components/Header";
import { CompartirApunteBoton } from "@/components/CompartirApunteBoton";

interface DetalleProps {
  params: Promise<{ id: string }>;
}

// Alcance: mismo criterio que servicios/[id] — SOLO info pública de lectura. Fuera de
// alcance a propósito (ver apuntes.types.ts en server/): visor de archivo (PDF/imagen),
// flujo de compra/descarga ("acceso_completo", fileUrl firmado), y el carrusel de
// recomendados de ver_apunte.php.
//
// Ruta singular /apunte/[id] (no /apuntes/[id]) — corregido para ser fiel a la ruta real
// (ver_apunte.php vive en /apunte/{hash}). Antes vivía en /apuntes/[id], que colisionaría
// con /apuntes/[cat] (landing SEO por categoría, landing_categoria.php con tipo=apuntes,
// misma ruta plural que el listado) al portar esa landing a web/.
export default async function DetalleApunte({ params }: DetalleProps) {
  const { id } = await params;
  const apunteId = Number(id);
  if (!Number.isInteger(apunteId) || apunteId <= 0) {
    notFound();
  }

  const apunte = await getApunteDetalle(apunteId);
  if (!apunte) {
    notFound();
  }

  return (
    <>
      <Header titulo={apunte.titulo} />
      <main className="w-full max-w-[1100px] mx-auto px-4 md:px-8 pt-20 pb-24 lg:pb-16 lg:ml-64">
        <div className="bg-white border border-[#f0f0f0] rounded-2xl p-6 shadow-[0_1px_3px_rgba(0,0,0,0.04)]">
          {/* Asignatura + Compartir — la asignatura calcada de ver_apunte.php:516-518/704-706;
              "Compartir" no existe en ese archivo (vive en su propio modal en el PHP real,
              modal_compartir_apunte.php) pero se ancla acá por ser el punto de acción natural
              de la página. */}
          <div className="flex items-center justify-between gap-3">
            <span className="px-2.5 py-0.5 rounded text-[10px] font-semibold bg-gray-100 text-gray-700 border border-[#f0f0f0] uppercase tracking-wide">
              {apunte.asignatura ?? apunte.categoria ?? "Apunte"}
            </span>
            <CompartirApunteBoton apunteId={apunte.id} titulo={apunte.titulo} />
          </div>

          <h1 className="text-3xl md:text-4xl font-medium text-[#222222] leading-tight mb-6 mt-3 tracking-[-0.01em]">
            {apunte.titulo}
          </h1>

          {/* Bloque publicador — calcado de ver_apunte.php:764-791 */}
          <div className="flex items-center gap-4 pb-6 border-b border-[#f0f0f0] w-full">
            <div className="w-24 h-24 rounded-full border border-[#f0f0f0] bg-white overflow-hidden shadow-[0_1px_3px_rgba(0,0,0,0.04)] flex-shrink-0">
              {apunte.publicador.fotoUrl.startsWith("https://ui-avatars.com") ? (
                <div className="w-full h-full flex items-center justify-center bg-blue-50 text-[#54A6D8] font-bold text-2xl">
                  {inicial(apunte.publicador.nombre)}
                </div>
              ) : (
                <img
                  src={apunte.publicador.fotoUrl}
                  alt={apunte.publicador.nombre ?? "Publicador"}
                  className="w-full h-full object-cover"
                />
              )}
            </div>
            <div>
              <div className="flex items-center gap-1.5">
                <p className="text-sm font-medium tracking-[-0.01em] text-[#222222]">
                  Publicado por {abreviarNombre(apunte.publicador.nombre)}
                </p>
                {apunte.publicador.verificado && (
                  <svg className="w-3.5 h-3.5 text-[#54A6D8]" fill="currentColor" viewBox="0 0 20 20">
                    <path
                      fillRule="evenodd"
                      d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                      clipRule="evenodd"
                    />
                  </svg>
                )}
              </div>
              {apunte.publicador.institucion && (
                <div className="flex items-center gap-1.5 mt-0.5">
                  <span className="text-gray-400 text-xs">🏛</span>
                  <p className="text-xs text-gray-500 font-normal tracking-[0.01em]">{apunte.publicador.institucion}</p>
                </div>
              )}
            </div>
          </div>

          {/* Precio + descargas — calcado de ver_apunte.php:716-727 */}
          <div className="my-6 border-b border-gray-100 pb-6 flex items-end justify-between">
            <div>
              <p className="text-xs text-gray-500 font-bold uppercase mb-1">Precio</p>
              {apunte.promo?.activa ? (
                <div className="flex items-baseline gap-2">
                  <span className="text-sm text-gray-400 line-through font-medium">{formatoCLP(apunte.precio)}</span>
                  <span className="text-4xl font-normal text-[#54A6D8] tracking-[-0.01em] leading-none">¡Gratis!</span>
                </div>
              ) : (
                <span className="text-4xl font-normal text-[#222222] tracking-[-0.01em] leading-none">
                  {apunte.precio > 0 ? formatoCLP(apunte.precio) : "Gratis"}
                </span>
              )}
            </div>
            <div className="text-right">
              <p className="text-xs text-gray-500 font-bold uppercase mb-1">Descargas</p>
              <p className="text-lg font-medium text-gray-700">{apunte.ventasTotales}</p>
            </div>
          </div>

          {/* Etiquetas IA — calcado de ver_apunte.php:649-658 */}
          {apunte.iaTags.length > 0 && (
            <div className="mb-6">
              <h3 className="font-medium tracking-[-0.01em] text-[#222222] mb-3 text-sm">
                Etiquetas y materias detectadas
              </h3>
              <div className="flex flex-wrap gap-2">
                {apunte.iaTags.map((tag) => (
                  <span
                    key={tag}
                    className="px-3 py-1.5 bg-gray-50 border border-gray-200 rounded-md text-xs font-medium text-gray-700"
                  >
                    {tag.charAt(0).toUpperCase() + tag.slice(1)}
                  </span>
                ))}
              </div>
            </div>
          )}

          {/* Descripción — calcado de ver_apunte.php:660-672 (sin el toggle "Leer más/menos") */}
          {apunte.descripcion && (
            <div>
              <h3 className="font-medium tracking-[-0.01em] text-[#222222] mb-3">Descripción del apunte</h3>
              <p className="text-sm text-gray-600 leading-relaxed whitespace-pre-line break-words">
                {apunte.descripcion}
              </p>
            </div>
          )}
        </div>
      </main>
    </>
  );
}
