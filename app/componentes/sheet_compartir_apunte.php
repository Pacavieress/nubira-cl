<?php
// Bottom sheet de compartir (sin imagen). Hereda $url_canonical, $share_txt, $es_dueno de ver_apunte.
?>
<div id="sheet-compartir" class="hidden fixed inset-0 z-[110] flex items-end justify-center bg-gray-900/50 backdrop-blur-sm">
  <div id="sheet-card" class="bg-white w-full sm:max-w-[480px] rounded-t-3xl sm:rounded-3xl sm:mb-6 shadow-xl translate-y-full transition-transform duration-300">
    <div class="flex items-center justify-between px-5 pt-4 pb-2">
      <h3 class="text-base font-bold text-gray-900"><?= $es_dueno ? 'Comparte tu apunte' : 'Compartir este apunte' ?></h3>
      <button id="sheet-close" type="button" aria-label="Cerrar" class="w-9 h-9 rounded-full flex items-center justify-center text-gray-400 hover:bg-gray-100"><?= icon('x-mark','w-5 h-5') ?></button>
    </div>
    <div class="px-4 pb-[calc(1rem+env(safe-area-inset-bottom))] pt-1 space-y-2">
      <a href="https://api.whatsapp.com/send?text=<?= $share_txt ?>%20<?= urlencode($url_canonical) ?>" target="_blank"
         class="flex items-center gap-4 px-4 min-h-[52px] rounded-xl hover:bg-gray-50 transition" data-track-click="share:whatsapp">
        <span class="w-10 h-10 rounded-full bg-[#25D366]/10 text-[#25D366] flex items-center justify-center"><i class="fab fa-whatsapp text-lg"></i></span>
        <span class="font-semibold text-gray-800 text-sm">WhatsApp</span>
      </a>
      <a href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode($url_canonical) ?>" target="_blank"
         class="flex items-center gap-4 px-4 min-h-[52px] rounded-xl hover:bg-gray-50 transition" data-track-click="share:facebook">
        <span class="w-10 h-10 rounded-full bg-[#1877F2]/10 text-[#1877F2] flex items-center justify-center"><i class="fab fa-facebook-f text-lg"></i></span>
        <span class="font-semibold text-gray-800 text-sm">Facebook</span>
      </a>
      <a href="https://www.linkedin.com/sharing/share-offsite/?url=<?= urlencode($url_canonical) ?>" target="_blank"
         class="flex items-center gap-4 px-4 min-h-[52px] rounded-xl hover:bg-gray-50 transition" data-track-click="share:linkedin">
        <span class="w-10 h-10 rounded-full bg-[#0A66C2]/10 text-[#0A66C2] flex items-center justify-center"><i class="fab fa-linkedin-in text-lg"></i></span>
        <span class="font-semibold text-gray-800 text-sm">LinkedIn</span>
      </a>
      <button id="btn-copiar-enlace" data-url="<?= htmlspecialchars($url_canonical) ?>" type="button"
              class="w-full flex items-center gap-4 px-4 min-h-[52px] rounded-xl hover:bg-gray-50 transition text-left" data-track-click="share:copy">
        <span class="w-10 h-10 rounded-full bg-gray-100 text-gray-500 flex items-center justify-center"><i class="fas fa-link text-lg" id="copy-icon"></i></span>
        <span class="font-semibold text-gray-800 text-sm">Copiar enlace</span>
      </button>
    </div>
  </div>
</div>
<script>
(function(){
  const sheet = document.getElementById('sheet-compartir');
  const card  = document.getElementById('sheet-card');
  const close = document.getElementById('sheet-close');
  if(!sheet || !card) return;

  const open = () => { sheet.classList.remove('hidden'); requestAnimationFrame(()=>card.classList.remove('translate-y-full')); document.body.style.overflow='hidden'; };
  const shut = () => { card.classList.add('translate-y-full'); setTimeout(()=>{ sheet.classList.add('hidden'); document.body.style.overflow=''; },300); };

  document.querySelectorAll('.js-abrir-sheet-compartir').forEach((b)=> b.addEventListener('click', (e)=>{ e.preventDefault(); open(); }));
  if(close) close.onclick = shut;
  sheet.onclick = (e)=>{ if(e.target===sheet) shut(); };

  // Copiar enlace: clipboard moderno con fallback execCommand para contextos sin HTTPS (ej. nubira.local)
  const bc = document.getElementById('btn-copiar-enlace');
  if(bc) {
    bc.addEventListener('click', async function(e){
      e.preventDefault();
      const url = this.getAttribute('data-url');
      const confirmar = () => { const i=document.getElementById('copy-icon'); if(i){ i.className='fa-solid fa-check text-green-500'; setTimeout(()=>i.className='fas fa-link',2000);} };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        try { await navigator.clipboard.writeText(url); confirmar(); return; } catch(e) {}
      }
      try {
        const tmp = document.createElement('textarea');
        tmp.value = url; tmp.style.position='fixed'; tmp.style.opacity='0';
        document.body.appendChild(tmp); tmp.focus(); tmp.select();
        document.execCommand('copy'); document.body.removeChild(tmp);
        confirmar();
      } catch(e) {}
    });
  }
})();
</script>
