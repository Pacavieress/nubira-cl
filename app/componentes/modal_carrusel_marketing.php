<?php
// app/componentes/modal_carrusel_marketing.php
// Modal genérico para armar un carrusel de imágenes de marketing a partir de la selección
// hecha en admin_marketing_cards.php. No recibe variables PHP de contexto — todo el contenido
// (cuáles servicios, en qué orden) lo arma window.abrirCarruselMarketing(items) en JS, en el
// momento en que el admin hace clic en "Ver como carrusel". El orden final del carrusel es
// literalmente el orden del DOM (cada botón ▲/▼ mueve el <li>, no hay array paralelo en JS).
?>
<div id="modal-carrusel-mkt" class="hidden fixed inset-0 z-[120] flex items-center justify-center p-4 bg-gray-900/50 backdrop-blur-sm">
  <div id="carrusel-mkt-card"
       class="bg-white w-[95%] max-w-[600px] rounded-2xl shadow-xl border border-gray-100 max-h-[88vh] flex flex-col translate-y-full opacity-0 transition-all duration-300">

    <div class="flex items-center justify-between px-5 pt-4 pb-3 border-b border-gray-100 shrink-0">
      <h3 class="text-base font-bold text-gray-900 tracking-tight">
        Carrusel de marketing (<span id="carrusel-mkt-count">0</span> imágenes)
      </h3>
      <button id="carrusel-mkt-close" type="button" aria-label="Cerrar"
              class="w-9 h-9 rounded-full flex items-center justify-center text-gray-400 hover:bg-gray-100">
        <?= icon('x-mark', 'w-5 h-5') ?>
      </button>
    </div>

    <ul id="carrusel-mkt-lista" class="flex-1 overflow-y-auto px-5 py-4 space-y-2"></ul>

    <div class="px-5 py-4 border-t border-gray-100 shrink-0">
      <button id="carrusel-mkt-btn-todas" type="button"
              class="w-full bg-[#54A6D8] hover:bg-blue-600 text-white text-sm font-bold py-3 rounded-xl transition-all flex items-center justify-center gap-2">
        <?= icon('arrow-down-tray', 'w-4 h-4') ?> Descargar todas
      </button>
      <p class="text-[11px] text-gray-400 text-center mt-2 leading-relaxed">
        El orden de descarga es el mismo orden en que aparecen abajo. Usa ▲▼ para reordenar antes de descargar.
      </p>
    </div>

  </div>
</div>

<script>
(function () {
  const modal   = document.getElementById('modal-carrusel-mkt');
  const card    = document.getElementById('carrusel-mkt-card');
  const close   = document.getElementById('carrusel-mkt-close');
  const lista   = document.getElementById('carrusel-mkt-lista');
  const contador = document.getElementById('carrusel-mkt-count');
  const btnTodas = document.getElementById('carrusel-mkt-btn-todas');
  if (!modal || !lista) return;

  // iOS Safari rompe la descarga múltiple encadenada (setTimeout + .click() en loop pierde
  // el gesto de usuario confiable, solo la primera imagen se descarga). En táctil+Web Share
  // usamos un solo navigator.share() con todos los archivos a la vez — un solo gesto real,
  // sin loop. Desktop/Android siguen con la descarga encadenada de siempre (ahí sí funciona).
  const esTactilConShare = (navigator.maxTouchPoints > 0 || 'ontouchstart' in window)
    && typeof navigator.share === 'function' && typeof navigator.canShare === 'function';

  if (esTactilConShare) {
    btnTodas.innerHTML = '<i class="fa-solid fa-share-nodes"></i> Compartir todas';
  }

  function crearItem(item, index) {
    const li = document.createElement('li');
    li.className = 'flex items-center gap-3 bg-gray-50 border border-gray-100 rounded-xl p-2.5';
    li.dataset.imgUrl = item.url;
    li.dataset.titulo = item.titulo;

    li.innerHTML = `
      <img src="${item.url}" alt="" loading="lazy" class="w-14 h-14 rounded-lg object-cover border border-gray-200 bg-white shrink-0">
      <p class="flex-1 min-w-0 text-xs font-semibold text-gray-800 truncate">${item.titulo}</p>
      <div class="flex flex-col shrink-0">
        <button type="button" class="carrusel-mkt-up w-6 h-5 flex items-center justify-center text-gray-400 hover:text-[#54A6D8]" title="Subir" aria-label="Subir"><i class="fa-solid fa-chevron-up text-[10px]"></i></button>
        <button type="button" class="carrusel-mkt-down w-6 h-5 flex items-center justify-center text-gray-400 hover:text-[#54A6D8]" title="Bajar" aria-label="Bajar"><i class="fa-solid fa-chevron-down text-[10px]"></i></button>
      </div>
      <div class="flex items-center gap-1.5 shrink-0">
        <button type="button" class="carrusel-mkt-compartir w-9 h-9 rounded-full bg-white border border-gray-200 text-[#54A6D8] hover:bg-blue-50 flex items-center justify-center transition-colors"
                title="Compartir" aria-label="Compartir">
          <i class="fa-solid fa-share-nodes text-xs"></i>
        </button>
        <a href="${item.url}" download="nubira-${item.id}-post.jpg"
           class="${esTactilConShare ? 'hidden ' : ''}w-9 h-9 rounded-full bg-white border border-gray-200 text-[#54A6D8] hover:bg-blue-50 flex items-center justify-center transition-colors"
           title="Descargar" aria-label="Descargar">
          <i class="fa-solid fa-download text-xs"></i>
        </a>
      </div>
    `;
    return li;
  }

  function actualizarContador() {
    contador.textContent = lista.children.length;
  }

  window.abrirCarruselMarketing = function (items) {
    if (!Array.isArray(items) || items.length === 0) return;

    lista.innerHTML = '';
    items.forEach((item, i) => lista.appendChild(crearItem(item, i)));
    actualizarContador();

    modal.classList.remove('hidden');
    requestAnimationFrame(() => card.classList.remove('translate-y-full', 'opacity-0'));
    document.body.style.overflow = 'hidden';
  };

  function cerrar() {
    card.classList.add('translate-y-full', 'opacity-0');
    setTimeout(() => {
      modal.classList.add('hidden');
      document.body.style.overflow = '';
    }, 300);
  }
  close.addEventListener('click', cerrar);
  modal.addEventListener('click', (e) => { if (e.target === modal) cerrar(); });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !modal.classList.contains('hidden')) cerrar();
  });

  // Reordenar: mueve el <li> completo, el orden del DOM ES el orden final (sin array paralelo)
  lista.addEventListener('click', (e) => {
    const btnUp = e.target.closest('.carrusel-mkt-up');
    const btnDown = e.target.closest('.carrusel-mkt-down');
    if (!btnUp && !btnDown) return;

    const li = (btnUp || btnDown).closest('li');
    if (btnUp && li.previousElementSibling) {
      lista.insertBefore(li, li.previousElementSibling);
    } else if (btnDown && li.nextElementSibling) {
      lista.insertBefore(li.nextElementSibling, li);
    }
  });

  // Compartir: intercepta con fetch() + Blob (mismo origen, sin problema de CORS, reutiliza
  // la imagen ya cacheada por img_servicio.php) para armar el File que exige navigator.share().
  // Si no es compatible (Firefox, desktop sin soporte de archivos, error de red, etc.) cae
  // automáticamente a la descarga, sin mostrar error al admin. Si el admin cancela el selector
  // nativo (AbortError), no hace nada.
  lista.addEventListener('click', async (e) => {
    const btnCompartir = e.target.closest('.carrusel-mkt-compartir');
    if (!btnCompartir) return;

    const li = btnCompartir.closest('li');
    const linkDescarga = li.querySelector('a[download]');
    const urlImg = li.dataset.imgUrl;
    const tituloImg = li.dataset.titulo;
    const filename = linkDescarga.getAttribute('download');

    function descargarFallback() {
      linkDescarga.click();
    }

    if (typeof navigator.share !== 'function' || typeof navigator.canShare !== 'function') {
      descargarFallback();
      return;
    }

    try {
      const resp = await fetch(urlImg);
      const blob = await resp.blob();
      const file = new File([blob], filename, { type: blob.type || 'image/jpeg' });

      if (navigator.canShare({ files: [file] })) {
        await navigator.share({ files: [file], title: tituloImg });
        return;
      }
    } catch (err) {
      if (err && err.name === 'AbortError') return;
    }

    descargarFallback();
  });

  // Descargar todas: dispara cada link de descarga en secuencia con un pequeño delay
  // (descargas simultáneas suelen ser bloqueadas/limitadas por el navegador)
  btnTodas.addEventListener('click', async () => {
    const links = [...lista.querySelectorAll('a[download]')];

    if (esTactilConShare) {
      try {
        const archivos = await Promise.all(links.map(async (a) => {
          const resp = await fetch(a.href);
          const blob = await resp.blob();
          return new File([blob], a.getAttribute('download'), { type: blob.type || 'image/jpeg' });
        }));
        // Sin title/text junto a files — en iOS Safari eso puede hacer fallar el share
        // de archivos silenciosamente (ver investigación).
        if (navigator.canShare({ files: archivos })) {
          await navigator.share({ files: archivos });
          return;
        }
      } catch (err) {
        if (err && err.name === 'AbortError') return;
      }
    }

    links.forEach((a, i) => {
      setTimeout(() => a.click(), i * 400);
    });
  });
})();
</script>
