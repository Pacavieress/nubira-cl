<?php
/**
 * NUBIRA 2.0 — Sistema de Toasts y Flash Messages
 * 
 * Componente global de notificaciones.
 * Se incluye UNA SOLA VEZ desde header.php.
 * 
 * USO DESDE JS:
 *   mostrarToast('Mensaje', 'success');
 *   mostrarToast('Error procesando', 'error', 6000);
 *   Tipos: 'success' | 'error' | 'warning' | 'info'
 * 
 * USO DESDE PHP (flash, sobrevive a redirect):
 *   $_SESSION['flash_success'] = 'Contrato creado.';
 *   header('Location: /chat'); exit;
 * 
 * Pensado API-first: el contrato {message, type} es el mismo
 * que consumirá la app nativa Flutter en el futuro.
 */

// Requiere iconos.php para renderizar SVGs Nubira
if (!function_exists('icon')) {
    require_once __DIR__ . '/../iconos.php';
}

// Capturamos los flash de sesión (si existen) y los limpiamos
$flash_messages = [];
foreach (['success', 'error', 'warning', 'info'] as $tipo) {
    $key = 'flash_' . $tipo;
    if (!empty($_SESSION[$key])) {
        $flash_messages[] = ['type' => $tipo, 'message' => $_SESSION[$key]];
        unset($_SESSION[$key]);
    }
}
?>

<!-- [NUBIRA 2.0] Toast Container -->
<div id="toast-container" 
     class="fixed top-20 right-4 left-4 md:left-auto md:top-24 md:right-6 z-[80] flex flex-col gap-3 pointer-events-none max-w-md md:w-96 ml-auto"
     aria-live="polite" aria-atomic="true"></div>

<script>
(function() {
    'use strict';

    // SVGs Nubira inyectados desde iconos.php (single source of truth)
    const TOAST_ICONS = {
        success: `<?= addslashes(icon('check-circle', 'w-5 h-5')) ?>`,
        error:   `<?= addslashes(icon('x-circle', 'w-5 h-5')) ?>`,
        warning: `<?= addslashes(icon('alert-triangle', 'w-5 h-5')) ?>`,
        info:    `<?= addslashes(icon('info-circle', 'w-5 h-5')) ?>`
    };

    const TOAST_STYLES = {
        success: { border: 'border-l-emerald-500', iconColor: 'text-emerald-500', bg: 'bg-emerald-50' },
        error:   { border: 'border-l-red-500',     iconColor: 'text-red-500',     bg: 'bg-red-50' },
        warning: { border: 'border-l-amber-500',   iconColor: 'text-amber-500',   bg: 'bg-amber-50' },
        info:    { border: 'border-l-[#54A6D8]',   iconColor: 'text-[#54A6D8]',   bg: 'bg-blue-50' }
    };

    /**
     * Muestra un toast en pantalla.
     * @param {string} mensaje  - Texto del toast
     * @param {string} tipo     - 'success' | 'error' | 'warning' | 'info'
     * @param {number} duracion - ms hasta autocierre (default 4000)
     */
    window.mostrarToast = function(mensaje, tipo = 'info', duracion = 4000) {
        const container = document.getElementById('toast-container');
        if (!container) return;

        // Validación de tipo
        if (!TOAST_STYLES[tipo]) tipo = 'info';
        const style = TOAST_STYLES[tipo];

        // Construcción del toast
        const toast = document.createElement('div');
        toast.className = [
            'pointer-events-auto',
            'flex items-start gap-3',
            'bg-white border border-gray-100',
            'border-l-4', style.border,
            'rounded-2xl shadow-lg shadow-gray-200/60',
            'p-4 pr-3',
            'transform translate-x-full opacity-0',
            'transition-all duration-300 ease-out'
        ].join(' ');

        toast.setAttribute('role', tipo === 'error' ? 'alert' : 'status');

        toast.innerHTML = `
            <div class="shrink-0 w-8 h-8 rounded-full ${style.bg} ${style.iconColor} flex items-center justify-center mt-0.5">
                ${TOAST_ICONS[tipo] || TOAST_ICONS.info}
            </div>
            <div class="flex-1 text-sm text-gray-800 font-medium leading-snug pt-1">
                ${escapeHtml(mensaje)}
            </div>
            <button type="button" 
                    class="shrink-0 w-7 h-7 rounded-full hover:bg-gray-100 text-gray-400 hover:text-gray-700 flex items-center justify-center transition"
                    aria-label="Cerrar">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        `;

        container.appendChild(toast);

        // Animación de entrada
        requestAnimationFrame(() => {
            toast.classList.remove('translate-x-full', 'opacity-0');
        });

        // Cierre manual
        const btnClose = toast.querySelector('button');
        let timeoutId;

        const cerrar = () => {
            clearTimeout(timeoutId);
            toast.classList.add('translate-x-full', 'opacity-0');
            setTimeout(() => toast.remove(), 300);
        };

        btnClose.addEventListener('click', cerrar);

        // Cierre automático
        timeoutId = setTimeout(cerrar, duracion);
    };

    // Helper de escape (defensa XSS si el mensaje viene de input)
    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = String(str ?? '');
        return div.innerHTML;
    }

    // Procesar flash messages PHP al cargar
    <?php if (!empty($flash_messages)): ?>
    document.addEventListener('DOMContentLoaded', () => {
        <?php foreach ($flash_messages as $flash): ?>
        mostrarToast(
            <?= json_encode($flash['message'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
            <?= json_encode($flash['type']) ?>
        );
        <?php endforeach; ?>
    });
    <?php endif; ?>

})();
</script>