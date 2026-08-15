/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    "./*.php",
    "./app/**/*.php",
  ],
  theme: {
    extend: {
      colors: {
        // Antes solo vivía en el <script>tailwind.config={...}</script> de vitrina.php —
        // por eso bg-nubira/text-nubira no funcionaban en las otras 9 páginas que las usan.
        nubira: '#54A6D8',
      },
    },
  },
  // Clases armadas dinámicamente en PHP (reclamos_sugerencias.php + admin_config_precios.php) —
  // el scanner de contenido nunca las ve completas en el código fuente, así que no las detecta
  // solo. Mapeadas 1:1 contra la investigación de la Fase 1 (ver CLAUDE.md / sesión de auditoría).
  safelist: [
    // reclamos_sugerencias.php — text-<?= $color ?>-500, $color viene de $CATEGORIAS (7 fijas)
    'text-red-500',
    'text-blue-500',
    'text-green-500',
    'text-yellow-500',
    'text-purple-500',
    'text-gray-500',
    // admin_config_precios.php — $bg_color completo, descompuesto en tokens individuales
    'bg-emerald-50', 'border-emerald-100', 'text-emerald-700',
    'bg-red-50', 'border-red-100', 'text-red-700',
  ],
  // aspect-ratio: quitado (10/08/2026) — su theme.aspectRatio pisaba las claves
  // nativas (auto/square/video) que el core de Tailwind v3 ya trae sin plugin,
  // dejando aspect-square/aspect-video/aspect-[N/M] sin generar CSS. El sitio
  // usa 100% sintaxis nativa, cero uso de la sintaxis vieja del plugin
  // (aspect-w-N/aspect-h-N), así que no hace falta.
  plugins: [
    require('@tailwindcss/forms'),
    require('@tailwindcss/typography'),
  ],
}
