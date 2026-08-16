<?php
// Modal del "Desafío de hoy". Requiere $conn (conexion.php) ya definido por
// quien lo incluya. Solo accesible logueado (el trigger vive en el panel de
// gestión del propio usuario, panel_gestion.php, que ya exige $es_propio) —
// sin rama de invitado: si el backend igual devuelve 401 (ej. sesión
// expiró a mitad del flujo), se trata como cualquier otro error genérico.
// Sin límite diario — el usuario puede reintentar libremente.
$dh_materias = [];
$dh_res = $conn->query("SELECT slug, nombre FROM materias WHERE activa = 1 ORDER BY orden ASC");
if ($dh_res) {
    while ($m = $dh_res->fetch_assoc()) $dh_materias[] = $m;
}
?>
<div id="modal-desafio" class="hidden fixed inset-0 z-[120] flex items-center justify-center p-4 bg-gray-900/50 backdrop-blur-sm">
  <div id="desafio-card"
       class="bg-white w-full max-w-[520px] rounded-2xl shadow-xl border border-gray-100 max-h-[90vh] overflow-y-auto translate-y-full opacity-0 transition-all duration-300">

    <div class="flex items-center justify-between px-5 pt-4 pb-2">
      <h3 class="text-base font-medium text-[#222222] tracking-[-0.01em]">Desafío de hoy</h3>
      <button id="desafio-close" type="button" aria-label="Cerrar" class="w-9 h-9 rounded-full flex items-center justify-center text-gray-400 hover:bg-gray-100"><?= icon('x-mark', 'w-5 h-5') ?></button>
    </div>

    <!-- PANTALLA 1: elegir materia -->
    <div id="desafio-pantalla-materia" class="px-5 pb-5">
      <p class="text-sm text-gray-500 mb-4">Elige un ramo y responde 3 preguntas rápidas.</p>
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
    <div id="desafio-pantalla-preguntas" class="hidden px-5 pb-5">
      <button id="desafio-volver-materia" type="button" class="flex items-center gap-1 text-xs text-gray-400 hover:text-[#54A6D8] mb-3"><?= icon('chevron-left', 'w-3.5 h-3.5') ?> Cambiar ramo</button>
      <div id="desafio-preguntas-contenido" class="space-y-5"></div>
      <button id="desafio-enviar" type="button" disabled
              class="mt-5 w-full py-3 rounded-2xl font-medium text-white bg-gradient-to-r from-sky-400 to-[#54A6D8] disabled:opacity-40 disabled:cursor-not-allowed transition-opacity">
        Ver resultado
      </button>
    </div>

    <!-- PANTALLA 3: resultado -->
    <div id="desafio-pantalla-resultado" class="hidden px-5 pb-5"></div>

  </div>
</div>

<script>
(function(){
  const modal = document.getElementById('modal-desafio');
  const card  = document.getElementById('desafio-card');
  const btnClose = document.getElementById('desafio-close');
  const pantallaMateria    = document.getElementById('desafio-pantalla-materia');
  const pantallaPreguntas  = document.getElementById('desafio-pantalla-preguntas');
  const pantallaResultado  = document.getElementById('desafio-pantalla-resultado');
  const preguntasContenido = document.getElementById('desafio-preguntas-contenido');
  const btnEnviar = document.getElementById('desafio-enviar');
  const btnVolver = document.getElementById('desafio-volver-materia');

  let materiaActual = null;

  const open  = () => { modal.classList.remove('hidden'); requestAnimationFrame(()=>card.classList.remove('translate-y-full','opacity-0')); document.body.style.overflow='hidden'; };
  const shut  = () => { card.classList.add('translate-y-full','opacity-0'); setTimeout(()=>{ modal.classList.add('hidden'); document.body.style.overflow=''; },300); };

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
  }

  // Trigger: el tile del panel de gestión dispara este evento (no un id fijo,
  // porque panel_gestion.php se renderiza duplicado en móvil/escritorio).
  document.addEventListener('nb:abrir-desafio', () => { resetFlujo(); open(); });

  if (btnClose) btnClose.onclick = shut;
  modal.onclick = (e) => { if (e.target === modal) shut(); };
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
          ${['a','b','c','d'].map((op) => `
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
        <div class="py-6 text-center">
          <p class="text-base font-medium text-[#222222] mb-1">¡Bien hecho! ${data.aciertos}/3 correctas.</p>
          <p class="text-sm text-gray-500 mb-5">Vas por buen camino en ${nombreMateria(data.materia)}.</p>
          <button type="button" class="desafio-jugar-de-nuevo text-sm font-medium text-[#54A6D8] hover:underline">Jugar de nuevo</button>
        </div>
      `;
    } else {
      pantallaResultado.innerHTML = `
        <div class="py-2">
          <p class="text-base font-medium text-[#222222] mb-1 text-center">${data.aciertos}/3 correctas.</p>
          <p class="text-sm text-gray-500 mb-4 text-center">Un tutor o un apunte de ${nombreMateria(data.materia)} te puede ayudar a reforzar esto.</p>

          <div id="desafio-recom-apuntes" class="flex gap-3 overflow-x-auto no-scrollbar pb-2"></div>
          <div id="desafio-recom-tutores" class="flex gap-3 overflow-x-auto no-scrollbar pb-2 mt-2"></div>

          <div class="text-center mt-3">
            <button type="button" class="desafio-jugar-de-nuevo text-sm font-medium text-[#54A6D8] hover:underline">Jugar de nuevo</button>
          </div>
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

  async function cargarRecomendaciones(materia, categoriaServicio) {
    const contApuntes = document.getElementById('desafio-recom-apuntes');
    const contTutores = document.getElementById('desafio-recom-tutores');

    if (contApuntes) {
      fetch('/app/cargar_apuntes.php?materia=' + encodeURIComponent(materia) + '&compacto=1&no_banners=1&pagina=1')
        .then(r => r.text()).then(html => { contApuntes.innerHTML = html; }).catch(() => {});
    }
    if (contTutores && categoriaServicio) {
      fetch('/app/cargar_servicios.php?categoria=' + encodeURIComponent(categoriaServicio) + '&compacto=1&pagina=1')
        .then(r => r.text()).then(html => { contTutores.innerHTML = html; }).catch(() => {});
    }
  }
})();
</script>
