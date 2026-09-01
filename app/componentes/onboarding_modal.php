<?php
/**
 * COMPONENTE: MODAL ONBOARDING "Cómo funciona Nubira"
 * ESTADO: BLOQUE 2 — solo HTML/CSS estático. La navegación JS llega en BLOQUE 3.
 *
 * - Los 5 slides están en el DOM; solo el .active se muestra (CSS).
 * - El modal arranca con clase 'hidden'; el JS del BLOQUE 3 lo abrirá/cerrará.
 * - IDs/clases pensados como ganchos para el BLOQUE 3:
 *     #onboarding-modal, #onboarding-card, #onboarding-close,
 *     .onboarding-slide(.active), #onboarding-counter, #onboarding-prev,
 *     #onboarding-next, #onboarding-cta, .onboarding-dot
 */

// Blindaje: asegura el sistema de íconos SVG aunque la página padre no lo haya cargado.
if (!function_exists('icon')) { require_once __DIR__ . '/../iconos.php'; }

// Contenido de los 5 slides (hardcodeado — ver decisión técnica en CLAUDE.md)
// 'icono': nombre del sistema icon() SVG del proyecto (app/iconos.php).
$onboarding_slides = [
    ['icono' => 'search',       'titulo' => 'Encuentra tu tutor',     'texto' => 'Busca por materia o universidad y elige el tutor que se ajuste a ti'],
    ['icono' => 'chat-outline', 'titulo' => 'Iniciar chat',            'texto' => 'Resuelve tus dudas con el tutor por chat antes de contratar el servicio'],
    ['icono' => 'shield-check', 'titulo' => 'Contratar servicio',      'texto' => 'Elige el día y hora que más te acomode. Tu pago queda protegido con Garantía Nubira hasta que tomes la clase'],
    ['icono' => 'laptop',       'titulo' => 'Toma tu clase online',    'texto' => 'Dentro de la plataforma, en el Mini Aula con video, chat y pizarra'],
    ['icono' => 'star-solid',   'titulo' => 'Califica la experiencia', 'texto' => 'Comparte tu opinión y ayuda a otros estudiantes a encontrar el mejor tutor'],
];
$onboarding_total = count($onboarding_slides);
?>

<style>
    /* Solo el slide activo es visible (la navegación JS llega en BLOQUE 3) */
    .onboarding-slide { display: none; }
    .onboarding-slide.active { display: flex; }
</style>

<!-- Modal oculto por defecto (lo gestiona el JS del BLOQUE 3) -->
<div id="onboarding-modal" class="hidden fixed inset-0 z-[120] flex items-center justify-center p-4 bg-gray-900/40 backdrop-blur-md">
    <div id="onboarding-card"
         class="bg-white w-[95%] max-w-[640px] rounded-2xl shadow-xl border border-gray-200 overflow-hidden transform transition-all duration-300 scale-95 opacity-0 max-h-[90vh] flex flex-col">

        <!-- Header con botón cerrar -->
        <div class="flex items-center justify-end px-4 pt-4 shrink-0">
            <button id="onboarding-close" type="button" aria-label="Cerrar"
                    class="w-9 h-9 rounded-full flex items-center justify-center text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition-all">
                <?= icon('x-mark', 'w-5 h-5') ?>
            </button>
        </div>

        <!-- Body: los 5 slides (solo se muestra el .active) -->
        <div class="px-6 pb-2 pt-2 overflow-y-auto flex-1">
            <?php foreach ($onboarding_slides as $idx => $slide): ?>
            <div class="onboarding-slide <?= $idx === 0 ? 'active' : '' ?> flex-col items-center text-center gap-4 py-6"
                 data-slide="<?= $idx + 1 ?>">
                <?= icon($slide['icono'], 'w-16 h-16 text-[#54A6D8]') ?>
                <div class="text-2xl font-bold text-gray-900 tracking-tight">
                    <?= htmlspecialchars($slide['titulo']) ?>
                </div>
                <p class="text-base text-gray-600 leading-relaxed max-w-sm mx-auto">
                    <?= htmlspecialchars($slide['texto']) ?>
                </p>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Dots de paginación -->
        <div class="flex items-center justify-center gap-1.5 pb-2 shrink-0">
            <?php for ($i = 0; $i < $onboarding_total; $i++): ?>
            <button type="button"
                    class="onboarding-dot w-1.5 h-1.5 rounded-full transition-all <?= $i === 0 ? 'bg-[#54A6D8] w-4' : 'bg-gray-300' ?>"
                    data-dot="<?= $i + 1 ?>"></button>
            <?php endfor; ?>
        </div>

        <!-- Footer: navegación + contador + CTA final -->
        <div class="px-6 pb-5 pt-2 flex items-center justify-between gap-4 shrink-0">
            <!-- Anterior -->
            <button id="onboarding-prev" type="button"
                    class="px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-900 transition-all flex items-center gap-1.5 disabled:opacity-0 disabled:pointer-events-none"
                    disabled>
                <?= icon('arrow-left', 'w-4 h-4') ?>
                <span>Anterior</span>
            </button>

            <!-- Contador -->
            <p id="onboarding-counter" class="text-[13px] font-medium text-gray-400">
                <span id="onboarding-counter-actual">1</span> de <?= $onboarding_total ?>
            </p>

            <!-- Siguiente (slides 1-4) -->
            <button id="onboarding-next" type="button"
                    class="px-4 py-2 text-sm font-medium text-[#54A6D8] hover:text-blue-600 transition-all flex items-center gap-1.5">
                <span>Siguiente</span>
                <?= icon('arrow-right', 'w-4 h-4') ?>
            </button>

            <!-- CTA final (solo slide 5; oculto por defecto) -->
            <button id="onboarding-cta" type="button"
                    class="hidden px-6 py-2.5 bg-[#54A6D8] hover:bg-blue-600 text-white text-[13px] font-semibold rounded-lg transition-all active:scale-[0.98]">
                Comenzar
            </button>
        </div>
    </div>
</div>

<script>
(function () {
    // Estado de sesión + CSRF inyectados desde PHP (mismo patrón que marcar_aviso_leido.php)
    const ONBOARDING_LOGUEADO = <?= isset($_SESSION['usuario_id']) ? 'true' : 'false' ?>;
    const ONBOARDING_CSRF = '<?= $_SESSION['csrf_token'] ?? '' ?>';
    const ONBOARDING_TOTAL = <?= (int)$onboarding_total ?>;
    const ONBOARDING_LS_KEY = 'nubira_onboarding_visto';

    const modal = document.getElementById('onboarding-modal');
    const card  = document.getElementById('onboarding-card');
    if (!modal || !card) return;

    const slides   = modal.querySelectorAll('.onboarding-slide');
    const dots     = modal.querySelectorAll('.onboarding-dot');
    const counter  = document.getElementById('onboarding-counter-actual');
    const btnPrev  = document.getElementById('onboarding-prev');
    const btnNext  = document.getElementById('onboarding-next');
    const btnCta   = document.getElementById('onboarding-cta');
    const btnClose = document.getElementById('onboarding-close');
    const btnAbrir = document.getElementById('btn-abrir-onboarding');

    let currentSlide = 0; // 0 a (TOTAL-1)

    function mostrarSlide(n) {
        if (n < 0) n = 0;
        if (n > ONBOARDING_TOTAL - 1) n = ONBOARDING_TOTAL - 1;
        currentSlide = n;

        // Slides: solo el activo visible
        slides.forEach((s) => s.classList.remove('active'));
        const activo = modal.querySelector('.onboarding-slide[data-slide="' + (n + 1) + '"]');
        if (activo) activo.classList.add('active');

        // Contador
        if (counter) counter.textContent = (n + 1);

        // Dots
        dots.forEach((d) => {
            const idx = parseInt(d.dataset.dot, 10) - 1;
            const esActivo = idx === n;
            d.classList.toggle('bg-[#54A6D8]', esActivo);
            d.classList.toggle('w-4', esActivo);
            d.classList.toggle('bg-gray-300', !esActivo);
        });

        // Anterior: deshabilitado en el primer slide
        if (btnPrev) btnPrev.disabled = (n === 0);

        // Siguiente visible en 1-4; CTA visible solo en el último
        const esUltimo = (n === ONBOARDING_TOTAL - 1);
        if (btnNext) btnNext.classList.toggle('hidden', esUltimo);
        if (btnCta)  btnCta.classList.toggle('hidden', !esUltimo);
    }

    function abrirOnboarding() {
        currentSlide = 0;
        mostrarSlide(0);
        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
        setTimeout(() => {
            card.classList.remove('scale-95', 'opacity-0');
            card.classList.add('scale-100', 'opacity-100');
        }, 10);
    }

    function marcarVisto() {
        if (ONBOARDING_LOGUEADO) {
            const fd = new FormData();
            fd.append('csrf_token', ONBOARDING_CSRF);
            fetch('/app/marcar_onboarding_visto.php', { method: 'POST', body: fd })
                .catch((e) => console.error('Error al marcar onboarding visto:', e));
        } else {
            try { localStorage.setItem(ONBOARDING_LS_KEY, '1'); } catch (e) {}
        }
    }

    function cerrarOnboarding() {
        card.classList.remove('scale-100', 'opacity-100');
        card.classList.add('scale-95', 'opacity-0');
        setTimeout(() => {
            modal.classList.add('hidden');
            document.body.style.overflow = '';
        }, 300);
        marcarVisto();
    }

    // Navegación
    if (btnNext)  btnNext.addEventListener('click', () => mostrarSlide(currentSlide + 1));
    if (btnPrev)  btnPrev.addEventListener('click', () => mostrarSlide(currentSlide - 1));
    dots.forEach((d) => {
        d.addEventListener('click', () => mostrarSlide(parseInt(d.dataset.dot, 10) - 1));
    });

    // Cierre (X y CTA marcan visto)
    if (btnClose) btnClose.addEventListener('click', cerrarOnboarding);
    if (btnCta)   btnCta.addEventListener('click', cerrarOnboarding);

    // Reabrir desde el topbar (NO marca visto al reabrir manualmente)
    if (btnAbrir) btnAbrir.addEventListener('click', abrirOnboarding);

    // Exponer para auto-mostrar desde la página (BLOQUE 3.4)
    window.abrirOnboarding = abrirOnboarding;
})();
</script>
