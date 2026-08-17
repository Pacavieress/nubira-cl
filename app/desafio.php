<?php
/**
 * VISTA: DESAFÍO DE HOY
 * UBICACIÓN: public_html/app/desafio.php — ruta limpia /desafio
 * Solo accesible logueado (redirige a /login si no hay sesión) — mismo
 * criterio que ya se usaba en la versión modal, ahora aplicado a nivel de
 * página completa en vez de a nivel de resultado.
 */
session_start();

if (!isset($_SESSION['usuario_id'])) { header("Location: /login?redir=" . urlencode('/desafio')); exit; }

/* Rutas */
$app_dir = __DIR__;
if (!file_exists($app_dir . '/conexion.php')) {
    if (file_exists($app_dir . '/app/conexion.php')) $app_dir = $app_dir . '/app';
    elseif (file_exists(dirname($app_dir) . '/app')) $app_dir = dirname($app_dir) . '/app';
}
require_once $app_dir . '/conexion.php';
require_once $app_dir . '/iconos.php';

$dh_materias = [];
$dh_res = $conn->query("SELECT slug, nombre FROM materias WHERE activa = 1 ORDER BY orden ASC");
if ($dh_res) {
    while ($m = $dh_res->fetch_assoc()) $dh_materias[] = $m;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <title>Desafío de hoy | Nubira</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, maximum-scale=1, user-scalable=0" />
  <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
    body { font-family: 'Inter', sans-serif; background-color: #ffffff; -webkit-tap-highlight-color: transparent; }
    .pb-safe { padding-bottom: env(safe-area-inset-bottom); }
    ::-webkit-scrollbar { width: 0px; background: transparent; }
  </style>
</head>

<body class="text-gray-800 antialiased overflow-x-hidden select-none md:select-auto bg-white">

<div id="loader" class="fixed inset-0 bg-white flex items-center justify-center z-[60] transition-opacity duration-300">
  <div class="animate-spin h-7 w-7 border-4 border-gray-200 border-t-[#54A6D8] rounded-full"></div>
</div>

<?php
echo '<div class="hidden md:block">';
require_once $app_dir . '/componentes/header.php';
echo '</div>';
require_once $app_dir . '/componentes/sidebar.php';
?>

<main class="pt-4 md:pt-16 pb-32 md:pb-12 lg:ml-64 max-w-full">

  <!-- Contenedor angosto: header + selector de materia + preguntas -->
  <div class="max-w-[640px] mx-auto">
    <div class="sticky top-0 md:top-16 bg-white/95 backdrop-blur-sm z-30 border-b border-gray-100 px-4 md:px-6 py-4 flex items-center gap-3">
        <button type="button" onclick="navegacionSeguraNubira()"
                class="lg:hidden shrink-0 w-10 h-10 flex items-center justify-center rounded-full bg-gray-50 hover:bg-gray-100 border border-gray-200/60 shadow-sm active:scale-95 transition-all focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2"
                aria-label="Volver">
            <i class="fa-solid fa-arrow-left text-gray-700 text-[17px]"></i>
        </button>
        <div>
            <h1 class="text-xl md:text-2xl font-medium text-[#222222] tracking-[-0.01em]">Desafío de hoy</h1>
            <p class="text-gray-400 text-xs font-medium">3 preguntas rápidas de tu ramo.</p>
        </div>
    </div>

    <div class="px-4 md:px-6 pt-6">

      <!-- PANTALLA 1: elegir materia -->
      <div id="desafio-pantalla-materia">
        <div class="flex items-center justify-between mb-4">
          <p class="text-sm text-gray-500">Elige un ramo para empezar.</p>
          <button type="button" id="btn-compartir-desafio" class="flex items-center gap-1 text-xs font-medium text-[#54A6D8] hover:underline shrink-0">
            <?= icon('share-outline', 'w-3.5 h-3.5') ?> Compartir
          </button>
        </div>
        <div class="grid grid-cols-2 gap-2.5">
          <?php foreach ($dh_materias as $m): ?>
            <button type="button" data-materia="<?= htmlspecialchars($m['slug']) ?>"
                    class="desafio-btn-materia text-left px-4 py-3 rounded-xl border border-gray-200 hover:border-[#54A6D8] hover:bg-[#eef6fb] transition-colors text-sm font-medium text-[#222222]">
              <?= htmlspecialchars($m['nombre']) ?>
            </button>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- PANTALLA 2: preguntas -->
      <div id="desafio-pantalla-preguntas" class="hidden">
        <button id="desafio-volver-materia" type="button" class="flex items-center gap-1 text-xs text-gray-400 hover:text-[#54A6D8] mb-4"><?= icon('chevron-left', 'w-3.5 h-3.5') ?> Cambiar ramo</button>
        <div id="desafio-preguntas-contenido" class="space-y-5"></div>
        <button id="desafio-enviar" type="button" disabled
                class="mt-6 w-full py-3 rounded-2xl font-medium text-white bg-gradient-to-r from-sky-400 to-[#54A6D8] disabled:opacity-40 disabled:cursor-not-allowed transition-opacity">
          Ver resultado
        </button>
      </div>

    </div>
  </div>

  <!-- PANTALLA 3: resultado — fuera del contenedor angosto: si el resultado es "mal",
       las recomendaciones necesitan el ancho completo (mismo criterio que las listas
       de vitrina.php: max-w-[1600px], no max-w-[640px]). -->
  <div id="desafio-pantalla-resultado" class="hidden px-4 md:px-6 pt-2 md:pt-6"></div>

</main>

<script>
(function(){
  const pantallaMateria    = document.getElementById('desafio-pantalla-materia');
  const pantallaPreguntas  = document.getElementById('desafio-pantalla-preguntas');
  const pantallaResultado  = document.getElementById('desafio-pantalla-resultado');
  const preguntasContenido = document.getElementById('desafio-preguntas-contenido');
  const btnEnviar = document.getElementById('desafio-enviar');
  const btnVolver = document.getElementById('desafio-volver-materia');

  let materiaActual = null;

  function mostrarPantalla(nombre) {
    pantallaMateria.classList.toggle('hidden', nombre !== 'materia');
    pantallaPreguntas.classList.toggle('hidden', nombre !== 'preguntas');
    pantallaResultado.classList.toggle('hidden', nombre !== 'resultado');
  }

  function resetFlujo() {
    materiaActual = null;
    preguntasContenido.innerHTML = '';
    btnEnviar.disabled = true;
    mostrarPantalla('materia');
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  if (btnVolver) btnVolver.onclick = resetFlujo;

  document.querySelectorAll('.desafio-btn-materia').forEach((btn) => {
    btn.addEventListener('click', () => cargarPreguntas(btn.dataset.materia));
  });

  async function cargarPreguntas(materia) {
    materiaActual = materia;
    preguntasContenido.innerHTML = '<div class="text-sm text-gray-400 py-6 text-center">Cargando preguntas...</div>';
    mostrarPantalla('preguntas');
    btnEnviar.disabled = true;

    try {
      const resp = await fetch('/app/cargar_desafio.php?materia=' + encodeURIComponent(materia));
      const data = await resp.json();

      if (!data.ok) {
        preguntasContenido.innerHTML = '<div class="text-sm text-gray-400 py-6 text-center">Todavía no hay suficientes preguntas para este ramo. Prueba con otro.</div>';
        return;
      }

      renderPreguntas(data.preguntas);
    } catch (e) {
      preguntasContenido.innerHTML = '<div class="text-sm text-gray-400 py-6 text-center">No pudimos cargar las preguntas. Intenta de nuevo.</div>';
    }
  }

  function renderPreguntas(preguntas) {
    preguntasContenido.innerHTML = preguntas.map((p, i) => `
      <div class="desafio-pregunta" data-pregunta-id="${p.id}">
        <p class="text-sm font-medium text-[#222222] mb-2">${i + 1}. ${escapeHtml(p.enunciado)}</p>
        <div class="space-y-1.5">
          ${Object.keys(p.opciones).map((op) => `
            <label class="desafio-opcion flex items-center gap-2.5 px-3.5 py-2.5 rounded-xl border border-gray-200 cursor-pointer hover:border-gray-300 transition-colors">
              <input type="radio" name="desafio-p${p.id}" value="${op}" class="accent-[#54A6D8]">
              <span class="text-sm text-gray-700">${escapeHtml(p.opciones[op])}</span>
            </label>
          `).join('')}
        </div>
      </div>
    `).join('');

    preguntasContenido.querySelectorAll('input[type=radio]').forEach((input) => {
      input.addEventListener('change', () => {
        const grupo = input.closest('.desafio-pregunta');
        grupo.querySelectorAll('.desafio-opcion').forEach((lbl) => lbl.classList.remove('border-[#54A6D8]', 'bg-[#eef6fb]'));
        input.closest('.desafio-opcion').classList.add('border-[#54A6D8]', 'bg-[#eef6fb]');
        actualizarBotonEnviar();
      });
    });
  }

  function actualizarBotonEnviar() {
    const respondidas = preguntasContenido.querySelectorAll('.desafio-pregunta').length;
    const marcadas = new Set();
    preguntasContenido.querySelectorAll('input[type=radio]:checked').forEach((i) => marcadas.add(i.closest('.desafio-pregunta').dataset.preguntaId));
    btnEnviar.disabled = marcadas.size < respondidas;
  }

  function escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  if (btnEnviar) btnEnviar.onclick = () => {
    const respuestas = [];
    preguntasContenido.querySelectorAll('.desafio-pregunta').forEach((grupo) => {
      const marcado = grupo.querySelector('input[type=radio]:checked');
      if (marcado) respuestas.push({ pregunta_id: parseInt(grupo.dataset.preguntaId, 10), opcion: marcado.value });
    });
    if (respuestas.length !== 3) return;
    enviarRespuestas(materiaActual, respuestas);
  };

  async function enviarRespuestas(materia, respuestas) {
    mostrarPantalla('resultado');
    window.scrollTo({ top: 0, behavior: 'smooth' });
    pantallaResultado.innerHTML = '<div class="text-sm text-gray-400 py-10 text-center">Calculando resultado...</div>';

    let data;
    try {
      const resp = await fetch('/app/responder_desafio.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ materia, respuestas })
      });
      data = await resp.json();
    } catch (e) {
      data = { ok: false };
    }

    if (!data.ok) {
      pantallaResultado.innerHTML = '<div class="text-sm text-gray-400 py-10 text-center">Ocurrió un error. Intenta de nuevo.</div>';
      return;
    }

    renderResultado(data);
  }

  function renderResultado(data) {
    if (data.resultado === 'bien') {
      pantallaResultado.innerHTML = `
        <div class="max-w-[640px] mx-auto pt-1 pb-6 md:py-6 text-left md:text-center">
          <p class="text-base font-medium text-[#222222] mb-1">¡Bien hecho! ${data.aciertos}/3 correctas.</p>
          <p class="text-sm text-gray-500 mb-5">Vas por buen camino en ${nombreMateria(data.materia)}.</p>
          <button type="button" class="desafio-jugar-de-nuevo inline-flex items-center gap-1 text-sm font-bold px-4 py-2 rounded-full border border-sky-100 text-[#54A6D8] hover:bg-sky-50 transition-all">Jugar de nuevo</button>
        </div>
      `;
    } else {
      // Recomendaciones a ancho completo (mismas clases de grid que clases_servicios.php
      // y vitrina_apuntes.php en producción) — servicios/tutores primero, apuntes después.
      pantallaResultado.innerHTML = `
        <div class="max-w-[640px] mx-auto pt-1 text-left md:text-center mb-6">
          <p class="text-base font-medium text-[#222222] mb-1">${data.aciertos}/3 correctas.</p>
          <p class="text-sm text-gray-500">Un tutor o un apunte de ${nombreMateria(data.materia)} te puede ayudar a reforzar esto.</p>
        </div>

        <section class="max-w-[1600px] mx-auto mb-8">
          <h2 class="text-lg md:text-xl font-medium text-[#222222] tracking-[-0.01em] mb-3">Tutores de ${nombreMateria(data.materia)}</h2>
          <div id="desafio-recom-tutores" class="grid grid-cols-2 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4 md:gap-6 w-full min-h-[200px]"></div>
        </section>

        <section class="max-w-[1600px] mx-auto mb-8">
          <h2 class="text-lg md:text-xl font-medium text-[#222222] tracking-[-0.01em] mb-3">Apuntes de ${nombreMateria(data.materia)}</h2>
          <div id="desafio-recom-apuntes" class="grid grid-cols-2 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4 md:gap-8 w-full min-h-[200px]"></div>
        </section>

        <div class="max-w-[640px] mx-auto text-left md:text-center">
          <button type="button" class="desafio-jugar-de-nuevo inline-flex items-center gap-1 text-sm font-bold px-4 py-2 rounded-full border border-sky-100 text-[#54A6D8] hover:bg-sky-50 transition-all">Jugar de nuevo</button>
        </div>
      `;
      cargarRecomendaciones(data.materia, data.categoria_servicio);
    }

    const btnJugar = pantallaResultado.querySelector('.desafio-jugar-de-nuevo');
    if (btnJugar) btnJugar.onclick = resetFlujo;
  }

  function nombreMateria(slug) {
    const btn = document.querySelector(`.desafio-btn-materia[data-materia="${slug}"]`);
    return btn ? btn.textContent.trim() : slug;
  }

  // Servicios/tutores primero, apuntes después — mismos endpoints de siempre,
  // sin compacto=1: la card completa (mismo tamaño/info que /servicios,
  // vitrina.php y búsqueda — ver landing_categoria.php, que usa esta misma
  // card vía render_card_servicio_grid con compacto=false).
  async function cargarRecomendaciones(materia, categoriaServicio) {
    const contTutores = document.getElementById('desafio-recom-tutores');
    const contApuntes = document.getElementById('desafio-recom-apuntes');

    if (contTutores && categoriaServicio) {
      fetch('/app/cargar_servicios.php?categoria=' + encodeURIComponent(categoriaServicio) + '&pagina=1')
        .then(r => r.text()).then(html => { contTutores.innerHTML = html; }).catch(() => {});
    }
    if (contApuntes) {
      fetch('/app/cargar_apuntes.php?materia=' + encodeURIComponent(materia) + '&no_banners=1&pagina=1')
        .then(r => r.text()).then(html => { contApuntes.innerHTML = html; }).catch(() => {});
    }
  }
})();
</script>

<?php
require_once $app_dir . '/componentes/nav_bottom.php';
require_once $app_dir . '/componentes/modal_publicar.php';
require_once $app_dir . '/componentes/modal_explora.php';
require_once $app_dir . '/componentes/modal_compartir_desafio.php';
?>

<script>
window.onload = () => {
    const l = document.getElementById('loader');
    if(l){ l.classList.add('opacity-0'); setTimeout(()=>l.classList.add('hidden'),300); }
};

// [NUBIRA 2.0] Volver — mismo patrón que las demás páginas de gestión, con fallback a /perfil.
window.navegacionSeguraNubira = function() {
    if (window.history.length > 1) {
        window.history.back();
    } else {
        window.location.href = '/perfil';
    }
};

// Lógica de Modales del Nav Inferior
function setupModalNav(triggerId, modalId, cardId, closeId) {
    const btn = document.getElementById(triggerId);
    const modal = document.getElementById(modalId);
    const card = document.getElementById(cardId);
    const close = document.getElementById(closeId);

    if(!btn || !modal) return;

    const open = () => {
        modal.classList.remove('hidden');
        requestAnimationFrame(() => card.classList.remove('translate-y-full','opacity-0'));
        document.body.style.overflow = 'hidden';
    };

    const shut = () => {
        card.classList.add('translate-y-full','opacity-0');
        setTimeout(() => { modal.classList.add('hidden'); document.body.style.overflow = ''; }, 300);
    };

    btn.onclick = (e) => { e.preventDefault(); open(); };
    if(close) close.onclick = shut;
    modal.onclick = (e) => { if(e.target === modal) shut(); };
}

setupModalNav('btn-publicar', 'modal-quick', 'quick-card', 'quick-close');
setupModalNav('btn-explora', 'modal-explora', 'explora-card', 'explora-close');
</script>
</body>
</html>
