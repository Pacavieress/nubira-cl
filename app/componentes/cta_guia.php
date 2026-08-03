<?php
// app/componentes/cta_guia.php
// CTA de conversión hacia /clases o /apuntes filtrado por categoría, pensado para
// insertarse dentro del contenido de una guía (Centro de Recursos). No hace queries
// propias — recibe todo ya resuelto por el caller (mismo patrón que render_card.php).
// Devuelve '' si no hay datos reales que respalden el CTA (nunca un link muerto).
if (!function_exists('nb_cta_guia')) {
    function nb_cta_guia(array $args): string {
        $tipo      = $args['tipo'] ?? 'tutores';
        $cantidad  = (int)($args['cantidad'] ?? 0);
        $link      = $args['link'] ?? '';
        $categoria = $args['categoria'] ?? '';

        if ($cantidad <= 0 || empty($link) || empty($categoria)) return '';

        $es_tutores = ($tipo === 'tutores');

        if ($es_tutores) {
            $titulo       = "{$cantidad} " . ($cantidad === 1 ? 'tutor' : 'tutores') . " de {$categoria} disponible" . ($cantidad === 1 ? '' : 's') . " ahora";
            $subtitulo    = 'Agenda una clase particular y avanza más rápido.';
            $boton        = 'Ver tutores';
            $color_border = 'border-[#54A6D8]/15';
            $color_bg     = 'bg-[#54A6D8]/[0.05]';
            $color_franja = 'bg-[#54A6D8]';
            $gradiente    = 'from-sky-400 to-[#54A6D8]';
            $sombra       = 'shadow-blue-200';
        } else {
            $titulo       = "{$cantidad} " . ($cantidad === 1 ? 'apunte' : 'apuntes') . " de {$categoria} disponible" . ($cantidad === 1 ? '' : 's');
            $subtitulo    = 'Resúmenes y guías hechas por otros estudiantes.';
            $boton        = 'Ver apuntes';
            $color_border = 'border-orange-400/15';
            $color_bg     = 'bg-orange-400/[0.05]';
            $color_franja = 'bg-orange-400';
            $gradiente    = 'from-orange-300 to-orange-500';
            $sombra       = 'shadow-orange-200';
        }

        $avatares = $es_tutores ? array_slice(array_filter($args['avatares'] ?? []), 0, 3) : [];

        ob_start();
        ?>
<div class="relative overflow-hidden rounded-2xl border <?= $color_border ?> <?= $color_bg ?> p-5 md:p-6 my-8">
  <span class="absolute left-0 top-0 bottom-0 w-[3px] <?= $color_franja ?>"></span>
  <div class="flex flex-col sm:flex-row sm:items-center gap-4">
    <?php if ($es_tutores): ?>
    <div class="flex -space-x-3 shrink-0">
      <?php if (empty($avatares)): ?>
      <div class="w-10 h-10 rounded-full ring-2 ring-white bg-blue-100 flex items-center justify-center text-[#54A6D8]">
        <i class="fa-solid fa-chalkboard-user text-sm"></i>
      </div>
      <?php else: foreach ($avatares as $foto): ?>
      <div class="w-10 h-10 rounded-full ring-2 ring-white bg-blue-100 overflow-hidden">
        <img src="/app/perfil/fotos/<?= htmlspecialchars($foto) ?>" class="w-full h-full object-cover" alt="" />
      </div>
      <?php endforeach; endif; ?>
    </div>
    <?php else: ?>
    <div class="w-10 h-10 rounded-full bg-orange-100 flex items-center justify-center text-orange-500 shrink-0">
      <i class="fa-solid fa-file-lines"></i>
    </div>
    <?php endif; ?>

    <div class="flex-1 min-w-0">
      <p class="font-bold text-gray-900 text-[15px] leading-snug tracking-[-0.01em]"><?= htmlspecialchars($titulo) ?></p>
      <p class="text-sm text-gray-500 font-normal mt-0.5"><?= htmlspecialchars($subtitulo) ?></p>
    </div>

    <a href="<?= htmlspecialchars($link) ?>"
       class="shrink-0 inline-flex items-center justify-center gap-1.5 bg-gradient-to-r <?= $gradiente ?> text-white text-sm font-bold px-5 py-2.5 rounded-xl shadow-sm <?= $sombra ?> hover:shadow-md transition-all duration-150 hover:scale-[1.02] active:scale-95 whitespace-nowrap">
      <?= htmlspecialchars($boton) ?>
      <i class="fa-solid fa-arrow-right text-xs"></i>
    </a>
  </div>
</div>
        <?php
        return ob_get_clean();
    }
}
