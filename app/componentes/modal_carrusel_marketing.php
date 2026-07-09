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
      <a href="${item.url}" download="nubira-${item.id}-post.jpg"
         class="shrink-0 w-9 h-9 rounded-full bg-white border border-gray-200 text-[#54A6D8] hover:bg-blue-50 flex items-center justify-center transition-colors"
         title="Descargar" aria-label="Descargar">
        <i class="fa-solid fa-download text-xs"></i>
      </a>
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

  // Descargar todas: dispara cada link de descarga en secuencia con un pequeño delay
  // (descargas simultáneas suelen ser bloqueadas/limitadas por el navegador)
  btnTodas.addEventListener('click', () => {
    const links = [...lista.querySelectorAll('a[download]')];
    links.forEach((a, i) => {
      setTimeout(() => a.click(), i * 400);
    });
  });
})();
</script>
