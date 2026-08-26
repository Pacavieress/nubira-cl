import type { Delta } from "@/lib/api";

// Puerto exacto de det_delta_badge() en app/metricas_detalle.php:267-274.
const COLORES: Record<Delta["dir"], string> = {
  up: "text-green-600 bg-green-50",
  down: "text-red-500 bg-red-50",
  flat: "text-gray-400 bg-gray-50",
};
const FLECHAS: Record<Delta["dir"], string> = { up: "↑", down: "↓", flat: "·" };

export function DeltaBadge({ delta }: { delta: Delta | null }) {
  if (!delta) return null;
  return (
    <span className={`inline-flex items-center gap-0.5 text-[10px] font-bold px-1.5 py-0.5 rounded-full ${COLORES[delta.dir]}`}>
      {FLECHAS[delta.dir]} {delta.label}
    </span>
  );
}
