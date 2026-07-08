<?php
// app/componentes/loader_nativo.php
// [PWA] Loader inicial compartido — mismo azul de marca que el splash nativo del manifest (#54A6D8),
// para que la transición splash-nativo → loader-in-page no tenga salto de color visible.
?>
<style>
    body.fouc-lock > *:not(#loader-nativo) { display: none !important; }
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
