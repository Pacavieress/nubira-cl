<?php
// Modal de "Compartir Desafío" — invita a jugar una materia específica (no es el
// resultado del usuario). Espejo de modal_compartir_apunte.php en patrón de UI, pero
// con 2 pantallas propias porque el trigger es genérico (no hay una materia ya elegida
// como en apunte/servicio, que siempre parten de una fila conocida).
// Requiere $dh_materias (array slug=>nombre, ya cargado por desafio.php) y
// helpers/imagen_compartir_desafio.php (para nb_fingerprint_desafio, sin query extra:
// ya tenemos slug+nombre de cada materia en $dh_materias).
require_once __DIR__ . '/../helpers/imagen_compartir_desafio.php';

$cdDatos = [];
foreach ($dh_materias as $m) {
    $cdDatos[$m['slug']] = [
        'nombre' => $m['nombre'],
        'v' => nb_fingerprint_desafio($m),
    ];
}
?>
<div id="modal-compartir-desafio" class="hidden fixed inset-0 z-[120] flex items-center justify-center p-4 bg-gray-900/50 backdrop-blur-sm">
  <div id="compartir-desafio-card"
       class="bg-white w-[95%] max-w-[420px] rounded-2xl shadow-xl border border-gray-100 max-h-[92vh] overflow-y-auto translate-y-full opacity-0 transition-all duration-300">
    <div class="flex items-center justify-between px-5 pt-4">
      <h3 class="text-base font-bold text-gray-900 tracking-tight">Comparte el Desafío</h3>
      <button id="compartir-desafio-close" type="button" aria-label="Cerrar" class="w-9 h-9 rounded-full flex items-center justify-center text-gray-400 hover:bg-gray-100"><?= icon('x-mark', 'w-5 h-5') ?></button>
    </div>

    <!-- Paso 1: elegir materia a invitar -->
    <div id="compartir-desafio-paso-materia" class="px-5 py-4">
      <p class="text-sm text-gray-500 mb-3">¿Qué materia quieres invitar a jugar?</p>
      <div class="grid grid-cols-2 gap-2">
        <?php foreach ($dh_materias as $m): ?>
          <button type="button" data-slug="<?= htmlspecialchars($m['slug']) ?>"
                  class="compartir-desafio-btn-materia text-left px-3 py-2.5 rounded-xl border border-gray-200 hover:border-[#54A6D8] hover:bg-[#eef6fb] transition-colors text-sm font-medium text-[#222222]">
            <?= htmlspecialchars($m['nombre']) ?>
          </button>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Paso 2: preview + acciones -->
    <div id="compartir-desafio-paso-preview" class="hidden px-5 pb-5">
      <button id="compartir-desafio-volver" type="button" class="flex items-center gap-1 text-xs text-gray-400 hover:text-[#54A6D8] mb-3 mt-1"><?= icon('chevron-left', 'w-3.5 h-3.5') ?> Cambiar materia</button>

      <div class="flex justify-center pb-4">
        <img id="compartir-desafio-preview-img" src="" alt="Vista previa" loading="lazy"
             class="w-[240px] aspect-[4/5] object-cover rounded-xl border border-gray-100 shadow-sm bg-gray-50">
      </div>

      <div class="space-y-2.5">
        <a id="compartir-desafio-descargar" href="#" download
           class="block text-center bg-[#54A6D8] hover:bg-blue-600 text-white text-sm font-bold py-3 rounded-xl transition-all">Descargar imagen</a>
        <button id="compartir-desafio-copiar" type="button" class="w-full bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-bold py-3 rounded-xl transition-all">Copiar texto</button>
        <button id="compartir-desafio-share" type="button" class="w-full border border-[#54A6D8] text-[#54A6D8] hover:bg-blue-50 text-sm font-bold py-3 rounded-xl transition-all flex items-center justify-center gap-2"><?= icon('paper-airplane', 'w-4 h-4') ?> Compartir</button>
        <p class="text-[11px] text-gray-400 text-center pt-1">Descarga la imagen y súbela a tu historia o feed de Instagram.</p>
      </div>
    </div>

    <!-- Paso 3: compartir las 3 preguntas de ESTA sesión (trigger propio, sin pasar
         por "elegir materia" — las preguntas ya están elegidas por el juego en curso) -->
    <div id="compartir-desafio-paso-preguntas" class="hidden px-5 pb-5">
      <div class="flex justify-center pb-4 pt-1">
        <img id="compartir-desafio-preguntas-preview-img" src="" alt="Vista previa" loading="lazy"
             class="w-[220px] aspect-[9/16] object-cover rounded-xl border border-gray-100 shadow-sm bg-gray-50">
      </div>

      <div class="space-y-2.5">
        <a id="compartir-desafio-preguntas-descargar" href="#" download
           class="block text-center bg-[#54A6D8] hover:bg-blue-600 text-white text-sm font-bold py-3 rounded-xl transition-all">Descargar imagen</a>
        <button id="compartir-desafio-preguntas-copiar" type="button" class="w-full bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-bold py-3 rounded-xl transition-all">Copiar texto</button>
        <button id="compartir-desafio-preguntas-share" type="button" class="w-full border border-[#54A6D8] text-[#54A6D8] hover:bg-blue-50 text-sm font-bold py-3 rounded-xl transition-all flex items-center justify-center gap-2"><?= icon('paper-airplane', 'w-4 h-4') ?> Compartir</button>
        <p class="text-[11px] text-gray-400 text-center pt-1">Sin spoilers: la imagen no muestra cuál opción es la correcta.</p>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const DATOS = <?= json_encode($cdDatos, JSON_UNESCAPED_UNICODE) ?>;
  // Cache-busting para la card de "3 preguntas": a diferencia de la invitación por
  // materia (que sí tenía ?v=<fingerprint>), esta URL no llevaba ningún parámetro de
  // versión — el navegador podía quedarse sirviendo una respuesta cacheada de ANTES
  // de un deploy (Cache-Control: immutable, 24h) sin volver a pedirle nada al
  // servidor, invisible a cualquier fix del lado servidor (bug real, encontrado tras
  // un reporte donde el título nuevo no aparecía solo en el navegador del usuario).
  // Usamos la MISMA versión del generador (NB_IMG_VERSION_DESAFIO) que ya se usa para
  // el fingerprint de la invitación — sube en cada cambio de plantilla, forzando una
  // URL nueva y por lo tanto una petición de red nueva.
  const NB_IMG_V_DESAFIO = <?= json_encode(NB_IMG_VERSION_DESAFIO) ?>;

  const modal = document.getElementById('modal-compartir-desafio');
  const card  = document.getElementById('compartir-desafio-card');
  const btnAbrir = document.getElementById('btn-compartir-desafio');
  const btnClose = document.getElementById('compartir-desafio-close');
  const pasoMateria = document.getElementById('compartir-desafio-paso-materia');
  const pasoPreview = document.getElementById('compartir-desafio-paso-preview');
  const btnVolver = document.getElementById('compartir-desafio-volver');
  const imgPreview = document.getElementById('compartir-desafio-preview-img');
  const btnDescargar = document.getElementById('compartir-desafio-descargar');
  const btnCopiar = document.getElementById('compartir-desafio-copiar');
  const btnShare = document.getElementById('compartir-desafio-share');

  const pasoPreguntas = document.getElementById('compartir-desafio-paso-preguntas');
  const imgPreviewPreguntas = document.getElementById('compartir-desafio-preguntas-preview-img');
  const btnDescargarPreguntas = document.getElementById('compartir-desafio-preguntas-descargar');
  const btnCopiarPreguntas = document.getElementById('compartir-desafio-preguntas-copiar');
  const btnSharePreguntas = document.getElementById('compartir-desafio-preguntas-share');

  if (!modal || !btnAbrir) return;

  let slugActual = null;
  let captionActual = '';
  let materiaSlugPreguntas = null;
  let captionPreguntas = '';

  const open = () => { modal.classList.remove('hidden'); requestAnimationFrame(()=>card.classList.remove('translate-y-full','opacity-0')); document.body.style.overflow='hidden'; };
  const shut = () => { card.classList.add('translate-y-full','opacity-0'); setTimeout(()=>{ modal.classList.add('hidden'); document.body.style.overflow=''; },300); };

  function mostrarPasoMateria() {
    pasoMateria.classList.remove('hidden');
    pasoPreview.classList.add('hidden');
    pasoPreguntas.classList.add('hidden');
  }

  function mostrarPasoPreview(slug) {
    const d = DATOS[slug];
    if (!d) return;
    slugActual = slug;

    const url = '/api/img/desafio/' + encodeURIComponent(slug) + '/post.jpg?v=' + encodeURIComponent(d.v);
    imgPreview.src = url;
    btnDescargar.href = url;
    btnDescargar.setAttribute('download', 'nubira-desafio-' + slug + '.jpg');

    captionActual = '🎯 ¿Te atreves con el Desafío de ' + d.nombre + '?\n\n'
      + '3 preguntas rápidas para poner a prueba lo que sabes.\n\n'
      + 'Juega en 👉 https://nubira.cl/desafio\n\n'
      + '#Nubira #DesafioDeHoy';

    pasoMateria.classList.add('hidden');
    pasoPreview.classList.remove('hidden');
    pasoPreguntas.classList.add('hidden');
  }

  function mostrarPasoPreguntas(ids, materiaSlug, materiaNombre) {
    materiaSlugPreguntas = materiaSlug;

    const url = '/api/img/desafio-preguntas/' + ids.join('-') + '/history.jpg?v=' + encodeURIComponent(NB_IMG_V_DESAFIO);
    imgPreviewPreguntas.src = url;
    btnDescargarPreguntas.href = url;
    btnDescargarPreguntas.setAttribute('download', 'nubira-desafio-preguntas.jpg');

    captionPreguntas = '🧠 ¿Te animas con estas 3 preguntas de ' + materiaNombre + '?\n\n'
      + 'Sin spoilers — respóndelas tú también en 👉 https://nubira.cl/desafio\n\n'
      + '#Nubira #DesafioDeHoy';

    pasoMateria.classList.add('hidden');
    pasoPreview.classList.add('hidden');
    pasoPreguntas.classList.remove('hidden');
  }

  document.addEventListener('nb-compartir-desafio-preguntas', (e) => {
    const d = e.detail || {};
    if (!Array.isArray(d.ids) || d.ids.length !== 3 || !d.materiaSlug) return;
    mostrarPasoPreguntas(d.ids, d.materiaSlug, d.materiaNombre || '');
    open();
  });

  const trackSharePreguntas = () => {
    if (!materiaSlugPreguntas) return;
    try {
      const body = new URLSearchParams({ id: materiaSlugPreguntas, f: 'preguntas', tipo: 'desafio' });
      if (navigator.sendBeacon) {
        navigator.sendBeacon('/app/track_share.php', body);
      } else {
        fetch('/app/track_share.php', { method: 'POST', body, keepalive: true }).catch(()=>{});
      }
    } catch(e){}
  };

  btnDescargarPreguntas.addEventListener('click', () => trackSharePreguntas());

  const trackShare = (formato) => {
    if (!slugActual) return;
    try {
      const body = new URLSearchParams({ id: slugActual, f: formato, tipo: 'desafio' });
      if (navigator.sendBeacon) {
        navigator.sendBeacon('/app/track_share.php', body);
      } else {
        fetch('/app/track_share.php', { method: 'POST', body, keepalive: true }).catch(()=>{});
      }
    } catch(e){}
  };

  btnAbrir.onclick = (e) => { e.preventDefault(); mostrarPasoMateria(); open(); };
  if (btnClose) btnClose.onclick = shut;
  modal.onclick = (e) => { if (e.target === modal) shut(); };
  if (btnVolver) btnVolver.onclick = mostrarPasoMateria;

  document.querySelectorAll('.compartir-desafio-btn-materia').forEach((btn) => {
    btn.addEventListener('click', () => mostrarPasoPreview(btn.dataset.slug));
  });

  btnDescargar.addEventListener('click', () => trackShare('post'));

  const esStandalone = !!navigator.standalone || matchMedia('(display-mode: standalone)').matches;
  btnDescargar.addEventListener('click', async (e) => {
    if (!esStandalone) return;
    e.preventDefault();
    try {
      const resp = await fetch(btnDescargar.href);
      const blob = await resp.blob();
      const file = new File([blob], 'nubira-desafio.jpg', { type: 'image/jpeg' });
      if (navigator.canShare && navigator.canShare({ files: [file] })) {
        await navigator.share({ files: [file], text: captionActual });
      }
    } catch (e) {}
  });

  if (btnCopiar) btnCopiar.onclick = async () => {
    trackShare('caption');
    try { await navigator.clipboard.writeText(captionActual); const t = btnCopiar.textContent; btnCopiar.textContent = '¡Copiado!'; setTimeout(()=>btnCopiar.textContent=t, 1500); } catch(e){}
  };

  if (btnShare) btnShare.onclick = async () => {
    trackShare('share');
    try {
      const resp = await fetch(imgPreview.src);
      const blob = await resp.blob();
      const file = new File([blob], 'nubira-desafio-post.jpg', { type: 'image/jpeg' });
      if (navigator.canShare && navigator.canShare({ files: [file] })) {
        await navigator.share({ files: [file], text: captionActual });
      } else {
        const a = document.createElement('a'); a.href = imgPreview.src; a.download = 'nubira-desafio.jpg'; a.click();
      }
    } catch (e) {}
  };

  btnDescargarPreguntas.addEventListener('click', async (e) => {
    if (!esStandalone) return;
    e.preventDefault();
    try {
      const resp = await fetch(btnDescargarPreguntas.href);
      const blob = await resp.blob();
      const file = new File([blob], 'nubira-desafio-preguntas.jpg', { type: 'image/jpeg' });
      if (navigator.canShare && navigator.canShare({ files: [file] })) {
        await navigator.share({ files: [file], text: captionPreguntas });
      }
    } catch (e) {}
  });

  if (btnCopiarPreguntas) btnCopiarPreguntas.onclick = async () => {
    trackSharePreguntas();
    try { await navigator.clipboard.writeText(captionPreguntas); const t = btnCopiarPreguntas.textContent; btnCopiarPreguntas.textContent = '¡Copiado!'; setTimeout(()=>btnCopiarPreguntas.textContent=t, 1500); } catch(e){}
  };

  if (btnSharePreguntas) btnSharePreguntas.onclick = async () => {
    trackSharePreguntas();
    try {
      const resp = await fetch(imgPreviewPreguntas.src);
      const blob = await resp.blob();
      const file = new File([blob], 'nubira-desafio-preguntas.jpg', { type: 'image/jpeg' });
      if (navigator.canShare && navigator.canShare({ files: [file] })) {
        await navigator.share({ files: [file], text: captionPreguntas });
      } else {
        const a = document.createElement('a'); a.href = imgPreviewPreguntas.src; a.download = 'nubira-desafio-preguntas.jpg'; a.click();
      }
    } catch (e) {}
  };
})();
</script>
