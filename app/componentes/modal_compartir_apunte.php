<?php
// Modal de compartir imagen (POST/HISTORY) para apuntes. Hereda $apunte, $id_apunte,
// $token_seguro, $publicador de ver_apunte.php. Mirror de modal_compartir_servicio.php
// (servicios) — mismo HTML/JS, cambian solo las URLs de imagen y el texto del caption.
require_once __DIR__ . '/../helpers/nombre_publico.php';
require_once __DIR__ . '/../helpers/imagen_compartir_apunte.php'; // nb_version_imagen_apunte()

$cmpA_hash = $token_seguro;
$cmpA_v    = nb_version_imagen_apunte((int)$id_apunte);
$cmpA_post = "/api/img/apunte/{$cmpA_hash}/post.jpg?v={$cmpA_v}";
$cmpA_hist = "/api/img/apunte/{$cmpA_hash}/history.jpg?v={$cmpA_v}";

$cmpA_nombre = nombre_publico_tutor((string)($publicador['nombre'] ?? ''));
$cmpA_precio = ((int)($apunte['precio'] ?? 0) > 0) ? '$' . number_format((int)$apunte['precio'], 0, ',', '.') . ' CLP' : 'Gratis';
if ($es_promo_activa) $cmpA_precio = '¡Gratis!';

$cmpA_caption =
    "📄 {$apunte['titulo']} de {$cmpA_nombre}\n\n" .
    ($apunte['asignatura'] ? "{$apunte['asignatura']} · " : '') . "{$cmpA_precio}\n\n" .
    "Descarga 👉 {$url_canonical}\n\n" .
    "#Nubira #ApuntesUniversitarios #EstudioChile";
?>
<div id="modal-compartir-apunte" class="hidden fixed inset-0 z-[120] flex items-center justify-center p-4 bg-gray-900/50 backdrop-blur-sm">
  <div id="compartir-apunte-card"
       class="bg-white w-[95%] max-w-[560px] rounded-2xl shadow-xl border border-gray-100 max-h-[92vh] overflow-y-auto translate-y-full opacity-0 transition-all duration-300">
    <div class="flex items-center justify-between px-5 pt-4">
      <h3 class="text-base font-bold text-gray-900 tracking-tight">Comparte este apunte en redes</h3>
      <button id="compartir-apunte-close" type="button" aria-label="Cerrar" class="w-9 h-9 rounded-full flex items-center justify-center text-gray-400 hover:bg-gray-100"><?= icon('x-mark','w-5 h-5') ?></button>
    </div>

    <!-- Previews -->
    <div class="flex flex-col sm:flex-row gap-4 items-center justify-center px-5 py-5">
      <img src="<?= $cmpA_post ?>" alt="Vista previa publicación" loading="lazy"
           class="w-[260px] aspect-[4/5] object-cover rounded-xl border border-gray-100 shadow-sm bg-gray-50">
      <img src="<?= $cmpA_hist ?>" alt="Vista previa historia" loading="lazy"
           class="w-[170px] aspect-[9/16] object-cover rounded-xl border border-gray-100 shadow-sm bg-gray-50">
    </div>

    <!-- Acciones -->
    <div class="px-5 pb-5 space-y-2.5">
      <div class="grid grid-cols-2 gap-2.5">
        <a href="<?= $cmpA_post ?>" download="nubira-apunte-<?= $cmpA_hash ?>-post.jpg" data-cmpa-act="post"
           class="text-center bg-[#54A6D8] hover:bg-blue-600 text-white text-sm font-bold py-3 rounded-xl transition-all">Descargar POST</a>
        <a href="<?= $cmpA_hist ?>" download="nubira-apunte-<?= $cmpA_hash ?>-historia.jpg" data-cmpa-act="history"
           class="text-center bg-[#54A6D8] hover:bg-blue-600 text-white text-sm font-bold py-3 rounded-xl transition-all">Descargar HISTORY</a>
      </div>
      <button id="cmpA-copiar" type="button" class="w-full bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-bold py-3 rounded-xl transition-all">Copiar texto</button>
      <button id="cmpA-share" type="button" class="w-full border border-[#54A6D8] text-[#54A6D8] hover:bg-blue-50 text-sm font-bold py-3 rounded-xl transition-all flex items-center justify-center gap-2"><?= icon('paper-airplane','w-4 h-4') ?> Compartir</button>
      <p class="text-[11px] text-gray-400 text-center pt-1">Descarga la imagen y súbela a tu historia o feed de Instagram.</p>
    </div>
  </div>
</div>

<script>
(function(){
  const modal = document.getElementById('modal-compartir-apunte');
  const card  = document.getElementById('compartir-apunte-card');
  const btn   = document.getElementById('btn-compartir-imagen');
  const close = document.getElementById('compartir-apunte-close');
  if(!modal || !btn) return;

  const CAPTION  = <?= json_encode($cmpA_caption, JSON_UNESCAPED_UNICODE) ?>;
  const POST_URL = <?= json_encode($cmpA_post) ?>;
  const HASH     = <?= json_encode($cmpA_hash) ?>;

  const trackShare = (formato) => {
    try {
      const body = new URLSearchParams({ id: HASH, f: formato, tipo: 'apunte' });
      if (navigator.sendBeacon) {
        navigator.sendBeacon('/app/track_share.php', body);
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

  const esStandalone = !!navigator.standalone || matchMedia('(display-mode: standalone)').matches;
  document.querySelectorAll('#modal-compartir-apunte [data-cmpa-act]').forEach((a)=>{
    a.addEventListener('click', async (e)=>{
      trackShare(a.dataset.cmpaAct);
      if (!esStandalone) return;
      e.preventDefault();
      try {
        const resp = await fetch(a.href);
        const blob = await resp.blob();
        const file = new File([blob], a.download || 'nubira.jpg', {type:'image/jpeg'});
        if (navigator.canShare && navigator.canShare({files:[file]})) {
          await navigator.share({ files:[file], text: CAPTION });
        }
      } catch(e){}
    });
  });

  const btnCopiar = document.getElementById('cmpA-copiar');
  if(btnCopiar) btnCopiar.onclick = async ()=>{
    trackShare('caption');
    try { await navigator.clipboard.writeText(CAPTION); const t=btnCopiar.textContent; btnCopiar.textContent='¡Copiado!'; setTimeout(()=>btnCopiar.textContent=t,1500); } catch(e){}
  };

  const btnShare = document.getElementById('cmpA-share');
  if(btnShare) btnShare.onclick = async ()=>{
    trackShare('share');
    try {
      const resp = await fetch(POST_URL); const blob = await resp.blob();
      const file = new File([blob], 'nubira-apunte-post.jpg', {type:'image/jpeg'});
      if (navigator.canShare && navigator.canShare({files:[file]})) {
        await navigator.share({ files:[file], text: CAPTION });
      } else {
        const a=document.createElement('a'); a.href=POST_URL; a.download='nubira-apunte-post.jpg'; a.click();
      }
    } catch(e){}
  };
})();
</script>
