// Puerto exacto de det_sparkline() en app/metricas_detalle.php:288-307 — mismo cálculo de
// puntos (W=300, H=48, pad=3), mismo gradiente y mismo trazo. Sin interactividad (el PHP
// real tampoco la tiene, es un SVG estático), así que no necesita ser Client Component.
export function MetricaSparkline({ valores }: { valores: number[] }) {
  const max = Math.max(1, ...valores);
  const n = valores.length;
  const W = 300;
  const H = 48;
  const pad = 3;

  const puntos = valores.map((v, i) => {
    const x = Math.round((pad + (i / (n - 1)) * (W - 2 * pad)) * 10) / 10;
    const y = Math.round((H - pad - (v / max) * (H - 2 * pad)) * 10) / 10;
    return `${x},${y}`;
  });
  const linea = puntos.join(" ");
  const area = `0,${H} ${linea} ${W},${H}`;

  return (
    <svg viewBox={`0 0 ${W} ${H}`} className="w-full h-12" preserveAspectRatio="none">
      <defs>
        <linearGradient id="metrica_spk_grad" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stopColor="#54A6D8" stopOpacity="0.15" />
          <stop offset="100%" stopColor="#54A6D8" stopOpacity="0" />
        </linearGradient>
      </defs>
      <polygon points={area} fill="url(#metrica_spk_grad)" />
      <polyline points={linea} fill="none" stroke="#54A6D8" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  );
}
