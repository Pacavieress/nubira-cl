import type { Tier } from "@/lib/api";

// Etiquetas calcadas de app/componentes/card_servicio_grid.php:143-151 — pero acá solo
// se listan leyenda/élite. Decisión de jerarquía (aprobada): los 4 niveles reciben el
// mismo badge brillante en PHP, lo que diluye la señal ("Top", el piso más bajo que
// igual califica, se ve tan especial como "Leyenda"). Restringir a los 2 niveles
// superiores hace que cuando el badge SÍ aparece, signifique algo genuinamente
// destacado — no un cambio a la API (tier sigue calculándose igual en server/), solo a
// qué se renderiza acá.
const LABELS: Partial<Record<NonNullable<Tier>, string>> = {
  leyenda: "Leyenda",
  elite: "Élite",
};

export function TierBadge({ tier }: { tier: Tier }) {
  const label = tier === null ? undefined : LABELS[tier];
  if (!label) return null;

  return (
    <span className="inline-flex items-center px-1.5 py-0 md:px-2 md:py-0.5 rounded-full text-[9px] md:text-[10px] font-medium bg-white/95 backdrop-blur-sm text-[#222222] border border-[#f0f0f0] shadow-[0_1px_2px_rgba(0,0,0,0.08)]">
      {label}
    </span>
  );
}
