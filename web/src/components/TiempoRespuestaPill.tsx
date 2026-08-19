import type { TonoRespuesta } from "@/lib/api";

// Puerto exacto de detalle_servicio.php:517-540 ($clases_tono + el bloque de texto).
const CLASES_TONO: Record<TonoRespuesta, { icono: string; texto: string }> = {
  verde: { icono: "text-emerald-500", texto: "text-emerald-700" },
  azul: { icono: "text-[#54A6D8]", texto: "text-gray-900" },
  naranjo: { icono: "text-orange-500", texto: "text-orange-700" },
  gris: { icono: "text-gray-400", texto: "text-gray-500" },
};

export function TiempoRespuestaPill({
  tono,
  texto,
  ratingPromedio,
  votos,
}: {
  tono: TonoRespuesta;
  texto: string;
  ratingPromedio: number | null;
  votos: number;
}) {
  const c = CLASES_TONO[tono];

  return (
    <p className="text-[11px] text-gray-500 font-medium flex items-center gap-1.5 flex-wrap">
      <svg className={`w-3.5 h-3.5 ${c.icono}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M12 6v6l4 2m5-2a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
      {tono === "gris" ? (
        <span className={`${c.texto} font-bold`}>Sin historial de respuesta</span>
      ) : (
        <>
          Responde <span className={`${c.texto} font-bold`}>{texto}</span>
        </>
      )}
      <span className="text-gray-300">·</span>
      <svg className="w-2.5 h-2.5 text-gray-700" fill="currentColor" viewBox="0 0 20 20">
        <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
      </svg>
      <span className="font-bold text-gray-900">{ratingPromedio && ratingPromedio > 0 ? ratingPromedio.toFixed(1) : "Nuevo"}</span>
      {votos > 0 && (
        <>
          <span className="text-gray-300">·</span>
          <span className="text-gray-500">
            {votos} reseña{votos !== 1 ? "s" : ""}
          </span>
        </>
      )}
    </p>
  );
}
