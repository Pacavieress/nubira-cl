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
    .spinner-nativo {
        width: 40px; height: 40px; border: 4px solid rgba(255, 255, 255, 0.3); border-top-color: #ffffff;
        border-radius: 50%; animation: spin-nativo 0.8s linear infinite;
    }
    @keyframes spin-nativo { 100% { transform: rotate(360deg); } }
</style>
<div id="loader-nativo"><div class="spinner-nativo"></div></div>
