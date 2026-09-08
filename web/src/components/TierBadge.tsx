import type { Tier } from "@/lib/api";

// Puerto exacto de app/componentes/card_servicio_grid.php:143-151 — los 4 niveles
// reciben el mismo badge. [08/09/2026] Antes esta versión restringía el render a solo
// leyenda/élite ("decisión de jerarquía" propia, para no diluir la señal del badge) — bajo
// la regla confirmada de que el PHP manda en todo sin criterio propio, se revirtió: pro/top
// vuelven a mostrarse igual que en el PHP real.
const LABELS: Record<NonNullable<Tier>, string> = {
  leyenda: "Leyenda",
  elite: "Élite",
  pro: "Pro",
  top: "Top",
};

export function TierBadge({ tier }: { tier: Tier }) {
  if (tier === null) return null;

  return (
    <span className="inline-flex items-center px-1.5 py-0 md:px-2 md:py-0.5 rounded-full text-[9px] md:text-[10px] font-medium bg-white/95 backdrop-blur-sm text-[#222222] border border-[#f0f0f0] shadow-[0_1px_2px_rgba(0,0,0,0.08)]">
      {LABELS[tier]}
    </span>
  );
}
