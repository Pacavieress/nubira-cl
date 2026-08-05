<?php
// app/componentes/loader_nativo.php
// [PWA] Loader inicial compartido — mismo azul de marca que el splash nativo del manifest (#54A6D8),
// para que la transición splash-nativo → loader-in-page no tenga salto de color visible.
?>
<style>
    body.fouc-lock > *:not(#loader-nativo):not(#nubira-tw-sonda) { display: none !important; }
    #loader-nativo {
        position: fixed; inset: 0; background: #54A6D8; z-index: 999999;
        display: flex; align-items: center; justify-content: center;
        transition: opacity 0.3s ease-out;
    }
    .progress-nativo {
        width: 160px; height: 4px; border-radius: 999px;
        background: rgba(255, 255, 255, 0.25); overflow: hidden; position: relative;
    }
    .progress-nativo-bar {
        position: absolute; top: 0; left: 0; height: 100%; width: 40%;
        background: #ffffff; border-radius: 999px;
        animation: progress-nativo-slide 1.2s ease-in-out infinite;
    }
    @keyframes progress-nativo-slide {
        0%   { left: -40%; }
        100% { left: 100%; }
    }
</style>
<div id="loader-nativo"><div class="progress-nativo"><div class="progress-nativo-bar"></div></div></div>
<div id="nubira-tw-sonda" class="hidden" aria-hidden="true" style="position:fixed;top:-9999px;left:-9999px;"></div>
<script>
// [TEMPORAL — quitar tras validar en producción con throttling real]
var __nubiraT0 = performance.now();
function __nubiraLog(msg) { console.log('[Nubira Loader] +' + Math.round(performance.now() - __nubiraT0) + 'ms — ' + msg); }

window.nubiraDismissLoader = function () {
    if (window.__nubiraLoaderDismissed) return;
    window.__nubiraLoaderDismissed = true;
    var l = document.getElementById('loader-nativo');
    var b = document.body;
    sessionStorage.setItem('nubira_loader_visto', '1');
    if (b) b.classList.remove('fouc-lock');
    if (l && l.style.display !== 'none') {
        l.style.opacity = '0';
        setTimeout(function () { l.style.display = 'none'; }, 300);
    }
    __nubiraLog('página revelada (fouc-lock removido)');
};

// [NUBIRA 2.0] Señal real de que Tailwind CDN ya inyectó su CSS: en vez de asumirlo
// por timing de scripts (eso ya nos falló con `defer`), verificamos directamente si
// una clase de Tailwind (.hidden) ya tiene efecto sobre un elemento de prueba.
function nubiraEsperarTailwind(callback) {
    var sonda = document.getElementById('nubira-tw-sonda');
    var inicio = performance.now();
    var MAX_ESPERA_MS = 1000;

    function chequear() {
        if (!sonda || getComputedStyle(sonda).display === 'none') {
            __nubiraLog('sonda confirmó Tailwind listo (display:none real)');
            callback();
            return;
        }
        if (performance.now() - inicio > MAX_ESPERA_MS) {
            __nubiraLog('sonda agotó su tope de ' + MAX_ESPERA_MS + 'ms SIN confirmar Tailwind — revelando de todas formas');
            callback();
            return;
        }
        requestAnimationFrame(chequear);
    }
    requestAnimationFrame(chequear);
}

(function () {
    if (sessionStorage.getItem('nubira_loader_visto')) {
        document.body.classList.remove('fouc-lock');
        var l = document.getElementById('loader-nativo');
        if (l) l.style.display = 'none';
        return;
    }

    var triggerYaLlamado = false;
    var trigger = function (origen) {
        if (triggerYaLlamado) return;
        triggerYaLlamado = true;
        __nubiraLog('trigger disparado por: ' + origen);
        nubiraEsperarTailwind(function () {
            if (typeof window.nubiraLoaderExtraWait === 'function') {
                window.nubiraLoaderExtraWait(window.nubiraDismissLoader);
            } else {
                window.nubiraDismissLoader();
            }
        });
    };

    document.addEventListener('DOMContentLoaded', function () { trigger('DOMContentLoaded'); }, { once: true });
    window.addEventListener('load', function () { trigger('window.load'); }, { once: true });
    // Failsafe subido de 1500ms a 8000ms: medimos que el script de Tailwind CDN pesa
    // ~400KB — en una 3G lenta real (50 KB/s) tarda ~7s solo en descargarse. Un
    // failsafe corto revelaría la página sin estilos DURANTE esa descarga, peor
    // que el bug original. 8000ms da margen sobre ese peor caso medido.
    setTimeout(function () { trigger('failsafe 8000ms'); }, 8000);
})();
</script>
