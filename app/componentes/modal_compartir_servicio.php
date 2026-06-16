<?php
// Modal de compartir imagen (POST/HISTORY). Hereda $servicio, $id, $token_seguro de detalle_servicio.
require_once __DIR__ . '/../helpers/nombre_publico.php';
require_once __DIR__ . '/../helpers/link_corto.php';

if (!function_exists('nb_title_institucion')) {
    // Title Case con conectores (de/la/del/...) en minúscula. "Tutor Particular" pasa intacto.
    function nb_title_institucion(string $txt): string {
        $t = mb_convert_case(trim($txt), MB_CASE_TITLE, 'UTF-8');
        return str_replace(
            [' De ', ' Del ', ' La ', ' Las ', ' Los ', ' Y ', ' E ', ' En '],
            [' de ', ' del ', ' la ', ' las ', ' los ', ' y ', ' e ', ' en '],
            $t
        );
    }
}

$cmp_hash   = $token_seguro;
$cmp_codigo = generar_link_corto((int)$id);                 // reusa el código existente
$cmp_post   = "/api/img/servicio/{$cmp_hash}/post.jpg";
$cmp_hist   = "/api/img/servicio/{$cmp_hash}/history.jpg";

$cmp_nombre = nombre_publico_tutor((string)($servicio['nombre_alumno'] ?? ''));
$cmp_inst_raw = trim((string)($servicio['institucion_maestra'] ?? ''));
$cmp_inst   = $cmp_inst_raw !== '' ? nb_title_institucion($cmp_inst_raw) : 'Tutor Particular';
$cmp_pval   = ((float)($servicio['precio_oferta'] ?? 0) > 0) ? (float)$servicio['precio_oferta'] : (float)($servicio['precio'] ?? 0);
$cmp_precio = $cmp_pval > 0 ? '$' . number_format($cmp_pval, 0, ',', '.') . ' CLP' : 'Gratis';

// Hashtag de categoría sin acentos ni espacios
$cmp_cat_raw  = (string)($servicio['categoria'] ?? '');
$cmp_cat_hash = preg_replace('/[^A-Za-z0-9]/', '', strtr($cmp_cat_raw,
    ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','ñ'=>'n','Ñ'=>'N','ü'=>'u','Ü'=>'U']));

$cmp_caption =
    "📚 {$servicio['titulo']} con {$cmp_nombre}\n\n" .
    "{$cmp_inst} · desde {$cmp_precio}\n\n" .
    "Reserva 👉 nubira.cl/r/{$cmp_codigo}\n\n" .
    "#Nubira #TutoriasChile #ClasesParticulares" . ($cmp_cat_hash !== '' ? " #{$cmp_cat_hash}" : "");
?>
<div id="modal-compartir" class="hidden fixed inset-0 z-[120] flex items-center justify-center p-4 bg-gray-900/50 backdrop-blur-sm">
  <div id="compartir-card"
       class="bg-white w-[95%] max-w-[560px] rounded-2xl shadow-xl border border-gray-100 max-h-[92vh] overflow-y-auto translate-y-full opacity-0 transition-all duration-300">
    <div class="flex items-center justify-between px-5 pt-4">
      <h3 class="text-base font-bold text-gray-900 tracking-tight">Comparte este servicio en redes</h3>
      <button id="compartir-close" type="button" aria-label="Cerrar" class="w-9 h-9 rounded-full flex items-center justify-center text-gray-400 hover:bg-gray-100"><?= icon('x-mark','w-5 h-5') ?></button>
    </div>

    <!-- Previews -->
    <div class="flex flex-col sm:flex-row gap-4 items-center justify-center px-5 py-5">
      <img src="<?= $cmp_post ?>" alt="Vista previa publicación" loading="lazy"
           class="w-[260px] aspect-square object-cover rounded-xl border border-gray-100 shadow-sm bg-gray-50">
      <img src="<?= $cmp_hist ?>" alt="Vista previa historia" loading="lazy"
           class="w-[170px] aspect-[9/16] object-cover rounded-xl border border-gray-100 shadow-sm bg-gray-50">
    </div>

    <!-- Acciones -->
    <div class="px-5 pb-5 space-y-2.5">
      <div class="grid grid-cols-2 gap-2.5">
        <a href="<?= $cmp_post ?>" download="nubira-<?= $cmp_codigo ?>-post.jpg" data-cmp-act="post"
           class="text-center bg-[#54A6D8] hover:bg-blue-600 text-white text-sm font-bold py-3 rounded-xl transition-all">Descargar POST</a>
        <a href="<?= $cmp_hist ?>" download="nubira-<?= $cmp_codigo ?>-historia.jpg" data-cmp-act="history"
           class="text-center bg-[#54A6D8] hover:bg-blue-600 text-white text-sm font-bold py-3 rounded-xl transition-all">Descargar HISTORY</a>
      </div>
      <button id="cmp-copiar" type="button" class="w-full bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-bold py-3 rounded-xl transition-all">Copiar texto</button>
      <button id="cmp-share" type="button" class="w-full border border-[#54A6D8] text-[#54A6D8] hover:bg-blue-50 text-sm font-bold py-3 rounded-xl transition-all flex items-center justify-center gap-2"><?= icon('paper-airplane','w-4 h-4') ?> Compartir</button>
      <p class="text-[11px] text-gray-400 text-center pt-1">Descarga la imagen y súbela a tu historia o feed de Instagram.</p>
    </div>
  </div>
</div>

<script>
(function(){
  const modal = document.getElementById('modal-compartir');
  const card  = document.getElementById('compartir-card');
  const btn   = document.getElementById('btn-compartir-imagen');
  const close = document.getElementById('compartir-close');
  if(!modal || !btn) return;

  const CAPTION  = <?= json_encode($cmp_caption, JSON_UNESCAPED_UNICODE) ?>;
  const POST_URL = <?= json_encode($cmp_post) ?>;
  const HASH     = <?= json_encode($cmp_hash) ?>;

  // Tracking de share real (Opción B): un ping por acción del usuario.
  // keepalive: sobrevive a la navegación/descarga; .catch silencioso (no afecta la UX).
  const trackShare = (formato) => {
    try {
      const body = new URLSearchParams({ id: HASH, f: formato });
      if (navigator.sendBeacon) {
        navigator.sendBeacon('/app/track_share.php', body); // robusto ante descarga/navegación
      } else {
        fetch('/app/track_share.php', { method: 'POST', body, keepalive: true }).catch(()=>{});
      }
    } catch(e){}
  };

  const open  = () => { modal.classList.remove('hidden'); requestAnimationFrame(()=>card.classList.remove('translate-y-full','opacity-0')); document.body.style.overflow='hidden'; };
  const shut  = () => { card.classList.add('translate-y-full','opacity-0'); setTimeout(()=>{ modal.classList.add('hidden'); document.body.style.overflow=''; },300); };
  btn.onclick = (e)=>{ e.preventDefault(); open(); };
  if(close) close.onclick = shut;
  modal.onclick = (e)=>{ if(e.target===modal) shut(); };

  // Descargas (anchors con data-cmp-act="post"|"history"): trackear y dejar fluir la descarga
  document.querySelectorAll('#modal-compartir [data-cmp-act]').forEach((a)=>{
    a.addEventListener('click', ()=> trackShare(a.dataset.cmpAct));
  });

  const btnCopiar = document.getElementById('cmp-copiar');
  if(btnCopiar) btnCopiar.onclick = async ()=>{
    trackShare('caption');
    try { await navigator.clipboard.writeText(CAPTION); const t=btnCopiar.textContent; btnCopiar.textContent='¡Copiado!'; setTimeout(()=>btnCopiar.textContent=t,1500); } catch(e){}
  };

  const btnShare = document.getElementById('cmp-share');
  if(btnShare) btnShare.onclick = async ()=>{
    trackShare('share');
    try {
      const resp = await fetch(POST_URL); const blob = await resp.blob();
      const file = new File([blob], 'nubira-post.jpg', {type:'image/jpeg'});
      if (navigator.canShare && navigator.canShare({files:[file]})) {
        await navigator.share({ files:[file], text: CAPTION });
      } else {
        const a=document.createElement('a'); a.href=POST_URL; a.download='nubira-post.jpg'; a.click();
      }
    } catch(e){}
  };
})();
</script>
