<?php
// app/componentes/card_servicio_grid.php
// Componente compartido: card de servicio para GRID (w-full), idéntica a la de /servicios.
//
// TODO (deuda técnica conocida): refactorizar app/cargar_servicios.php (bloque ~183-375)
//   para que use esta misma función y eliminar la duplicación. NO se hizo en la Fase 1 pSEO
//   por ser un endpoint crítico de producción; requiere QA dedicado.
//
// NOTA: el data-prep y el markup se mantienen VERBATIM respecto a cargar_servicios.php
//   (incluye variables computadas pero NO renderizadas: $foto_tutor, $tutor_nombre,
//   $icon_mod, $mod, $nombre_completo, $partes_nombre — código muerto preservado a propósito
//   para lograr paridad byte-a-byte cuando algún día se refactorice cargar_servicios.php).

require_once __DIR__ . '/../helpers/imagen_servicio.php';  // url_portada()
require_once __DIR__ . '/../helpers/ofertas.php';          // oferta_vigente()
require_once __DIR__ . '/../helpers/institucion.php';      // institucion_tutor()
require_once __DIR__ . '/../seguridad_url.php';            // nubira_encriptar_id()
require_once __DIR__ . '/../helpers/seo.php';              // url_servicio()

if (!function_exists('render_card_servicio_grid')) {
    /**
     * Renderiza una card de servicio para grid responsive y devuelve el HTML.
     * @param array $row  Fila del servicio (mismas columnas que el SELECT maestro de cargar_servicios.php).
     * @param array $opts ['hide_inst' => bool, 'compacto' => bool]
     */
    function render_card_servicio_grid(array $row, array $opts = []): string {
        $hide_inst = !empty($opts['hide_inst']);
        $compacto  = !empty($opts['compacto']);
        $hoy = new DateTime();

        // ===== DATA-PREP (verbatim de cargar_servicios.php 183-289) =====
        $link_hash = url_servicio((int)$row['id'], $row['slug'] ?? null);

        // 1. DATA PREP PORTADA (banco → legacy → placeholder, vía helper unificado; ignora imagen_estado)
        $portada_url = url_portada($row);

        // [OVERLAY NUBIRA] categoría sobre la portada
        $categoria_overlay = $row['categoria'] ?? 'Otros';
        $prefijo_overlay = in_array($categoria_overlay, ['Otros','Asesoría']) ? '' : 'Clase de';
        $nombre_categoria_overlay = ($categoria_overlay === 'Otros') ? 'Clase' : $categoria_overlay;

        $fecha_pub = !empty($row['fecha_publicacion']) ? new DateTime($row['fecha_publicacion']) : $hoy;
        $es_nuevo  = ($hoy->diff($fecha_pub)->days <= 14);

        // Rating
        $rating_val = isset($row['rating_promedio']) ? (float)$row['rating_promedio'] : 0;
        $total_v    = isset($row['total_votos']) ? (int)$row['total_votos'] : 0;

       // --- LÓGICA DE PRECIOS Y OFERTAS (NUBIRA 2.0) ---
        $precio_val = $row['precio'] ?? 0;
        $es_oferta = oferta_vigente($row);
        $pct_descuento = ($es_oferta && (int)$precio_val > 0) ? round(((int)$precio_val - (int)$row['precio_oferta']) / (int)$precio_val * 100) : 0;
        $precio_html = "";

       if ($es_oferta) {
            $precio_oferta = (int)$row['precio_oferta'];
            $precio_html = '<div class="flex items-baseline gap-1.5 mb-0.5"><span class="text-[11px] text-gray-400 line-through font-medium leading-none">$' . number_format($precio_val, 0, ',', '.') . '</span><span class="text-[14px] text-[#222222] font-normal tracking-[-0.01em] leading-none">$' . number_format($precio_oferta, 0, ',', '.') . '</span>' . ($pct_descuento > 0 ? '<span class="bg-green-600 text-white text-[9px] font-semibold px-1 py-px rounded ml-1.5 leading-none relative -top-0.5">-' . $pct_descuento . '%</span>' : '') . '</div>';

        } else {
            if (is_numeric($precio_val) && $precio_val > 0) {
                $precio = "$" . number_format($precio_val, 0, ',', '.');
                $precio_class = "text-[#222222] font-normal tracking-[-0.01em]";
                $precio_html = '<div class="text-[13px] ' . $precio_class . ' leading-none mb-0.5">' . $precio . '</div>';
            } else {
                $precio = "Gratis";
                $precio_class = "text-[#222222] font-normal tracking-[-0.01em]";
                $precio_html = '<div class="text-[13px] ' . $precio_class . ' leading-none mb-0.5">' . $precio . '</div>';
            }
        }

        // --- LÓGICA DE ESCALAFONES DE STATUS (TIERS NUBIRA 2.0) ---
        $score = (int)($row['score_nubira'] ?? 0);
        $nivel_tutor = '';
        $es_basico = ($score < 60);

        if ($score >= 100 && $total_v >= 10 && $rating_val >= 4.7) {
            $nivel_tutor = 'leyenda';
        } elseif ($score >= 80 && $total_v >= 3 && $rating_val >= 4.0) {
            $nivel_tutor = 'elite';
        } elseif ($score >= 80) {
            $nivel_tutor = 'pro';
        } elseif ($score >= 60) {
            $nivel_tutor = 'top';
        }

        // --- LÓGICA DE AVATAR Y TUTOR --- (código muerto: no se renderiza, ver NOTA arriba)
        $nombre_completo = !empty($row['nombre_tutor']) ? $row['nombre_tutor'] : 'Profesor';
        $partes_nombre = array_values(array_filter(explode(' ', trim((string)$nombre_completo))));
        $tutor_nombre = "Profesor";
        if (!empty($partes_nombre[0])) {
            $tutor_nombre = ucwords(strtolower($partes_nombre[0]));
            if (count($partes_nombre) >= 2) {
                $tutor_nombre .= ' ' . strtoupper(substr($partes_nombre[count($partes_nombre)-1], 0, 1)) . '.';
            }
        }
        $foto_tutor = !empty($row['foto_perfil']) ? '/app/perfil/fotos/' . $row['foto_perfil'] : "https://ui-avatars.com/api/?name=".urlencode($tutor_nombre)."&background=f1f5f9&color=64748b&size=128";

        // Modalidad Icono (código muerto: no se renderiza)
        $mod = ucfirst($row['modalidad'] ?? '');
        if (stripos($mod, 'online') !== false) $icon_mod = '<i class="fa-solid fa-wifi text-[10px]"></i>';
        elseif (stripos($mod, 'presencial') !== false) $icon_mod = '<i class="fa-solid fa-user-group text-[10px]"></i>';
        else $icon_mod = '<i class="fa-solid fa-laptop text-[10px]"></i>';

        // --- HTML RATING (Derecha) ---
        $html_stars = '';
        if ($total_v > 0) {
            $html_stars = '<div class="flex items-center gap-1">
            <svg class="w-3 h-3 text-gray-700" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path></svg>
            <span class="text-[11px] text-gray-700 font-semibold leading-none">'.number_format($rating_val, 1).'</span>
        </div>';
        }

        // --- HTML INSTITUCIÓN (Izquierda) ---
        $inst_text = $hide_inst ? '' : institucion_tutor($row['institucion_maestra'] ?? ($row['institucion'] ?? ''));

        // ===== MARKUP (verbatim de cargar_servicios.php 292-375) =====
        ob_start();
        ?>
<a href="<?= $link_hash ?>"
   class="block rounded-xl flex flex-col transition-transform duration-300 hover:-translate-y-1 cursor-pointer w-[100%] sm:w-full sm:max-w-[380px] mx-auto md:max-w-none bg-transparent group h-full <?php echo $es_basico ? 'opacity-90 grayscale-[15%]' : ''; ?>">

  <div class="card-apunte relative overflow-hidden w-full <?= $compacto ? 'aspect-square rounded-xl' : 'aspect-[3/2] rounded-xl' ?> bg-gray-100 border border-[#f0f0f0] shadow-[0_1px_3px_rgba(0,0,0,0.04)]">
    <img src="<?= htmlspecialchars($portada_url) ?>"
         alt="<?= htmlspecialchars($row['titulo']) ?>"
       class="w-full h-full object-cover transition-transform duration-500 ease-out group-hover:scale-105"
         loading="lazy"
         width="<?= $compacto ? 400 : 600 ?>" height="400"
         onerror="this.src='/upload/preview/default_file.webp'">

  <?php
  $ov_prefijo   = $prefijo_overlay;
  $ov_categoria = $nombre_categoria_overlay;
  $ov_foto      = $foto_tutor;
  $ov_nombre    = $tutor_nombre;
  $ov_size      = 'lg';
  $ov_liviano   = true;
  include __DIR__ . '/overlay_card_servicio.php';
  ?>

  <!-- Badge derecha: tier (oculto en ofertas; ahí manda cupos) -->
  <?php if (!$es_oferta): ?>
  <div class="absolute top-1 right-1 z-10">
    <?php if ($nivel_tutor === 'leyenda'): ?>
        <span class="inline-flex items-center px-1.5 py-0 md:px-2 md:py-0.5 rounded-full text-[9px] md:text-[10px] font-medium bg-white/95 backdrop-blur-sm text-[#222222] border border-[#f0f0f0] shadow-[0_1px_2px_rgba(0,0,0,0.08)]">Leyenda</span>
    <?php elseif ($nivel_tutor === 'elite'): ?>
        <span class="inline-flex items-center px-1.5 py-0 md:px-2 md:py-0.5 rounded-full text-[9px] md:text-[10px] font-medium bg-white/95 backdrop-blur-sm text-[#222222] border border-[#f0f0f0] shadow-[0_1px_2px_rgba(0,0,0,0.08)]">Élite</span>
    <?php elseif ($nivel_tutor === 'pro'): ?>
        <span class="inline-flex items-center px-1.5 py-0 md:px-2 md:py-0.5 rounded-full text-[9px] md:text-[10px] font-medium bg-white/95 backdrop-blur-sm text-[#222222] border border-[#f0f0f0] shadow-[0_1px_2px_rgba(0,0,0,0.08)]">Pro</span>
    <?php elseif ($nivel_tutor === 'top'): ?>
        <span class="inline-flex items-center px-1.5 py-0 md:px-2 md:py-0.5 rounded-full text-[9px] md:text-[10px] font-medium bg-white/95 backdrop-blur-sm text-[#222222] border border-[#f0f0f0] shadow-[0_1px_2px_rgba(0,0,0,0.08)]">Top</span>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Badge cupos (derecha) -->
  <?php if ($es_oferta): ?>
  <div class="absolute top-1 right-1 z-10">
    <span class="inline-flex items-center px-1.5 py-0 md:px-2 md:py-0.5 rounded-full text-[9px] md:text-[10px] font-semibold bg-amber-100 text-amber-900 border border-amber-200">
        <?= (int)$row['cupos_oferta'] ?> <?= (int)$row['cupos_oferta'] === 1 ? 'cupo' : 'cupos' ?>
    </span>
  </div>
  <?php endif; ?>
  </div>

 <div class="pl-1 pr-1 pt-3 pb-1 flex flex-col flex-1 text-left min-h-[90px]">

      <?php if ($compacto): ?>
      <h6 class="font-medium text-[14px] leading-[1.3] tracking-[-0.01em] text-[#222222] line-clamp-2 h-[36px] overflow-hidden">
              <?= htmlspecialchars($row['titulo']) ?>
          </h6>

          <?= $precio_html ?>

<div class="flex items-center justify-between">
              <div class="flex items-center gap-1 text-[9px] text-gray-500 font-normal uppercase tracking-[0.01em] truncate max-w-[65%]">
                <?php if(!empty($inst_text)): ?>
                    <span class="truncate"><?= $inst_text ?></span>
                <?php endif; ?>
              </div>
              <?php if ($html_stars): ?><div class="shrink-0"><?= $html_stars ?></div><?php endif; ?>
          </div>

      <?php else: ?>
          <h6 class="font-medium text-[14px] leading-[1.3] tracking-[-0.01em] text-[#222222] line-clamp-2 h-[36px] overflow-hidden mb-1">
              <?= htmlspecialchars($row['titulo']) ?>
          </h6>

          <?= $precio_html ?>

<div class="flex items-center justify-between pt-1">
              <div class="flex items-center gap-1.5 text-[10px] text-gray-500 font-normal uppercase tracking-[0.01em] truncate max-w-[70%]">
                <?php if(!empty($inst_text)): ?>
                    <span class="truncate"><?= $inst_text ?></span>
                <?php endif; ?>
              </div>
              <?php if ($html_stars): ?><div class="shrink-0"><?= $html_stars ?></div><?php endif; ?>
          </div>
      <?php endif; ?>
  </div>
</a>
        <?php
        return ob_get_clean();
    }
}
