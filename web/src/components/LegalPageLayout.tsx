import type { ReactNode } from "react";
import { Footer } from "./Footer";

// Shell compartido entre privacidad.php y terminos.php — badge + h1 + fecha + card blanca +
// botón "Volver al inicio" + footer, idéntico en ambos PHP salvo el contenido de las
// secciones numeradas (children). Extraído porque es harto markup repetido entre
// exactamente 2 páginas construidas en la misma pasada, no una abstracción especulativa
// para un tercer caso futuro.
//
// El patrón de puntos de fondo (bg-pattern) es CSS puro replicado del <style> inline del
// PHP real — mismo radial-gradient, mismo tamaño/posición.
//
// lg:pl-64 en vez de md:ml-64 (privacidad.php real) / lg:ml-64 (terminos.php real): el PHP
// real tiene esa inconsistencia entre los 2 archivos, y además md:ml-64/lg:ml-64 son el
// bug de overflow ya documentado en otras páginas de este port (Sidebar solo es visible
// desde lg, así que reservar espacio con margin-left en vez de padding-left empuja el
// contenido fuera del viewport bajo <body flex flex-col>). Unifiqué a lg:pl-64, el patrón
// correcto ya establecido en el resto de web/ — no repliqué la inconsistencia del PHP.
export function LegalPageLayout({
  badge,
  titulo,
  ultimaActualizacion,
  children,
}: {
  badge: string;
  titulo: string;
  ultimaActualizacion: string;
  children: ReactNode;
}) {
  return (
    <main className="flex-grow pt-24 pb-28 md:pb-16 w-full relative lg:pl-64 transition-all duration-300 max-w-[1600px] mx-auto">
      <div
        className="absolute inset-0 pointer-events-none -z-10 opacity-10"
        style={{
          backgroundImage: "radial-gradient(#54A6D8 0.5px, transparent 0.5px), radial-gradient(#54A6D8 0.5px, #ffffff 0.5px)",
          backgroundSize: "20px 20px",
          backgroundPosition: "0 0, 10px 10px",
        }}
      />

      <div className="w-full max-w-4xl mx-auto px-4 md:px-8 mt-6">
        <div className="text-center mb-10 border-b border-gray-100 pb-8">
          <span className="inline-block py-1.5 px-4 rounded-full bg-blue-50 text-[#54A6D8] text-xs font-bold uppercase tracking-wider mb-4 border border-blue-100 shadow-sm">
            {badge}
          </span>
          <h1 className="text-3xl md:text-5xl font-extrabold text-gray-900 mb-4 tracking-tight leading-tight">{titulo}</h1>
          <p className="text-gray-500 font-medium">Última actualización: {ultimaActualizacion}</p>
        </div>

        <div className="bg-white p-8 md:p-12 rounded-[2.5rem] shadow-sm border border-gray-100 mb-12">
          <div className="space-y-10 text-gray-700 leading-relaxed">{children}</div>

          <div className="mt-12 pt-8 border-t border-gray-100 text-center">
            <a
              href="/explorar"
              className="inline-flex items-center gap-2 bg-gray-900 text-white font-bold px-8 py-3 rounded-xl hover:bg-gray-800 transition shadow-md hover:-translate-y-0.5"
            >
              Volver al inicio
            </a>
          </div>
        </div>

        <Footer />
      </div>
    </main>
  );
}

// Sección numerada compartida (span circular con número + título + cuerpo) — mismo patrón
// repetido 5-6 veces por página en el PHP real.
export function LegalSection({ n, titulo, children }: { n: number; titulo: string; children: ReactNode }) {
  return (
    <section>
      <h2 className="text-xl font-bold text-gray-900 tracking-[-0.01em] mb-4 flex items-center gap-3">
        <span className="bg-blue-50 w-10 h-10 rounded-xl flex items-center justify-center text-lg text-[#54A6D8] shrink-0">{n}</span>
        {titulo}
      </h2>
      <div className="ml-1 md:ml-[3.25rem] text-gray-600">{children}</div>
    </section>
  );
}
