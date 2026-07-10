<?php
// app/componentes/overlay_card_servicio.php
// Partial compartido: overlay del tutor sobre la portada de una card de SERVICIO.
// "Clase de [categoría]" + categoría + avatar del tutor + nombre.
//
// Variables esperadas (setear ANTES del include):
//   $ov_prefijo   string  prefijo ("Clase de" o '')
//   $ov_categoria string  nombre de categoría ("Matemáticas", "Clase", ...)
//   $ov_foto      string  URL de la foto del tutor (fallback ui-avatars ya resuelto)
//   $ov_nombre    string  nombre del tutor (abreviado "Ángel R.")
//   $ov_size      string  'lg' (default) | 'sm'  → tamaño de texto según el tipo de card
//
// Reemplaza el bloque inline duplicado en: cargar_servicios.php, componentes/card_servicio_grid.php,
// componentes/render_card.php, cargar_vistos.php y vitrina.php (x4).
$ov_size        = (($ov_size ?? 'lg') === 'sm') ? 'sm' : 'lg';
$ov_cls_prefijo = ($ov_size === 'sm') ? 'text-[10px] md:text-xs' : 'text-xs md:text-sm';
$ov_cls_titulo  = ($ov_size === 'sm') ? 'text-sm md:text-base'   : 'text-base md:text-lg';
?>
<!-- [OVERLAY NUBIRA] gradient + avatar tutor + categoría (partial) -->
<div class="absolute inset-0 z-[5] pointer-events-none" style="background:linear-gradient(to bottom, rgba(0,0,0,0.42) 0%, rgba(0,0,0,0.08) 32%, rgba(0,0,0,0) 52%, rgba(0,0,0,0.10) 70%, rgba(0,0,0,0.48) 100%);"></div>

<!-- TOP IZQUIERDA: prefijo + categoría -->
<div class="absolute top-3 left-3 z-10 pr-2 leading-tight" style="max-width:70%;">
    <?php if (!empty($ov_prefijo)): ?>
    <div class="text-white <?= $ov_cls_prefijo ?> font-medium opacity-90" style="text-shadow:0 1px 2px rgba(0,0,0,0.5);">
        <?= htmlspecialchars($ov_prefijo) ?>
    </div>
    <?php endif; ?>
    <div class="text-white <?= $ov_cls_titulo ?> font-bold" style="text-shadow:0 1px 3px rgba(0,0,0,0.6);">
        <?= htmlspecialchars($ov_categoria) ?>
    </div>
</div>

<!-- BOTTOM IZQUIERDA: avatar + nombre del tutor -->
<div class="absolute bottom-3 left-3 z-10 pr-2 flex items-center gap-2 text-white <?= $ov_cls_titulo ?> font-bold" style="max-width:80%; text-shadow:0 1px 3px rgba(0,0,0,0.6);">
        <img src="<?= htmlspecialchars($ov_foto) ?>"
             alt="<?= htmlspecialchars($ov_nombre) ?>"
             class="w-10 h-10 md:w-12 md:h-12 rounded-full object-cover ring-1 ring-white/30 avatar-tutor"
             loading="lazy"
             onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($ov_nombre) ?>&background=54A6D8&color=fff&size=128'">
        <span class="truncate min-w-0"><?= htmlspecialchars($ov_nombre) ?></span>
</div>
