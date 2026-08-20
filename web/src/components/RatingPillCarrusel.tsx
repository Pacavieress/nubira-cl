// Puerto exacto de render_rating_html() en app/vitrina.php:666-676 — estilo DISTINTO al
// de RatingPill.tsx (que porta la versión de card_servicio_grid.php): fondo/borde propios,
// texto más chico y liviano. Son 2 partials reales distintos del PHP, no una duplicación
// accidental — cada uno se usa donde el original lo usa (grid vs carrusel de home).
export function RatingPillCarrusel({ promedio, votos }: { promedio: number | null; votos: number }) {
  if (votos <= 0) return null;

  return (
    <div className="flex items-center gap-1 bg-gray-50 px-1.5 py-0.5 rounded border border-gray-100">
      <svg className="w-3 h-3 text-gray-900" style={{ paddingBottom: 1 }} fill="currentColor" viewBox="0 0 20 20">
        <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
      </svg>
      <span className="text-[10px] font-light tracking-[0.01em] text-gray-800 leading-none">{(promedio ?? 0).toFixed(1)}</span>
    </div>
  );
}
