<?php
if (!function_exists('icon')) {
    require_once __DIR__ . '/iconos.php';
}
?>

<!-- MODAL: PUBLICAR -->
<div id="modal-quick" class="hidden fixed inset-0 z-[60] flex items-end md:items-center justify-center bg-gray-900/40 backdrop-blur-sm transition-opacity">
    <div id="quick-card" class="bg-white rounded-t-2xl md:rounded-xl p-6 w-full md:w-96 transform translate-y-full opacity-0 transition-all duration-300 shadow-2xl">
        <h3 class="font-bold text-lg mb-4 text-center text-gray-900">¿Qué quieres publicar?</h3>
        
        <div class="grid grid-cols-2 gap-4">

            <a href="/formulario-subir-apunte" class="relative flex flex-col items-center justify-center p-4 bg-gradient-to-br from-orange-300 to-orange-500 rounded-2xl group text-center h-36 shadow-md border border-transparent hover:shadow-orange-100">
                <div class="absolute -top-3 bg-orange-600 text-[9px] text-white font-bold px-2 py-0.5 rounded-full shadow-md">POPULAR</div>
                <div class="w-12 h-12 rounded-full bg-white/20 flex items-center justify-center text-white mb-3 group-hover:scale-110 transition-transform">
                    <?= icon('publish-doc', 'w-6 h-6') ?>
                </div>
                <span class="font-bold text-white text-sm">Publicar Apunte</span>
                <span class="text-[10px] text-orange-50 mt-1">Resúmenes y guías</span>
            </a>

            <a href="/publicar-servicio" class="relative flex flex-col items-center justify-center p-4 bg-gradient-to-br from-sky-400 to-[#54A6D8] rounded-2xl group text-center h-36 shadow-md border border-transparent hover:shadow-blue-100">
                <div class="absolute -top-3 bg-blue-600 text-[9px] text-white font-bold px-2 py-0.5 rounded-full shadow-md">NUEVO</div>
                <div class="w-12 h-12 rounded-full bg-white/20 flex items-center justify-center text-white mb-3 group-hover:scale-110 transition-transform">
                    <?= icon('publish-class', 'w-6 h-6') ?>
                </div>
                <span class="font-bold text-white text-sm">Publicar Clase o Servicio</span>
                <span class="text-[10px] text-blue-50 mt-1">Tutorías y servicios</span>
            </a>

        </div>

        <button id="quick-close" class="mt-6 w-full py-3.5 bg-gray-100 rounded-xl font-bold text-gray-600 hover:bg-gray-200">Cancelar</button>
    </div>
</div>


<!-- MODAL: EXPLORA -->
<div id="modal-explora" class="hidden fixed inset-0 z-[60] flex items-end md:items-center justify-center bg-gray-900/40 backdrop-blur-sm transition-opacity">
    <div id="explora-card" class="bg-white rounded-t-2xl md:rounded-xl p-6 w-full md:w-96 transform translate-y-full opacity-0 transition-all duration-300 shadow-2xl">
        <h3 class="font-bold text-lg mb-4 text-center text-gray-900">Explorar Nubira</h3>

        <div class="grid grid-cols-2 gap-4">

            <a href="/vitrina-apuntes" class="flex flex-col items-center justify-center p-4 bg-gradient-to-br from-orange-300 to-orange-500 rounded-2xl group text-center h-36 shadow-md hover:shadow-orange-100">
                <div class="w-12 h-12 rounded-full bg-white/20 flex items-center justify-center text-white mb-3 group-hover:scale-110 transition-transform">
                    <?= icon('publish-doc', 'w-6 h-6') ?>
                </div>
                <span class="font-bold text-white text-sm">Explorar Apuntes</span>
            </a>

            <a href="/clases-servicios" class="flex flex-col items-center justify-center p-4 bg-gradient-to-br from-sky-400 to-[#54A6D8] rounded-2xl group text-center h-36 shadow-md hover:shadow-blue-100">
                <div class="w-12 h-12 rounded-full bg-white/20 flex items-center justify-center text-white mb-3 group-hover:scale-110 transition-transform">
                    <?= icon('publish-class', 'w-6 h-6') ?>
                </div>
                <span class="font-bold text-white text-sm">Explorar Clases</span>
            </a>

        </div>

        <button id="explora-close" class="mt-6 w-full py-3.5 bg-gray-100 rounded-xl font-bold text-gray-600 hover:bg-gray-200">Cerrar</button>
    </div>
</div>
