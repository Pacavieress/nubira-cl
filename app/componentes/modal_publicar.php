<div id="modal-quick" class="hidden fixed inset-0 z-[70] flex items-end md:items-center justify-center bg-gray-900/40 backdrop-blur-sm transition-opacity select-none" style="-webkit-tap-highlight-color: transparent;">

    <div id="quick-card" class="bg-white rounded-t-[1.75rem] md:rounded-2xl border border-[#f0f0f0] shadow-[0_1px_3px_rgba(0,0,0,0.04)] p-5 w-full md:w-80 transform translate-y-full opacity-0 transition-all duration-300 pb-[calc(1.25rem+env(safe-area-inset-bottom))]">

        <div class="w-10 h-1 bg-gray-200 rounded-full mx-auto mb-4 md:hidden"></div>

        <h3 class="font-medium text-lg mb-4 text-center text-[#222222] tracking-[-0.01em]">¿Qué vas a publicar?</h3>

        <div class="grid grid-cols-2 gap-3">

            <a href="/formulario-subir-apunte" class="flex flex-col items-center justify-center bg-blue-50 rounded-[1.25rem] transition-all duration-150 active:scale-95 text-center h-28 border border-blue-100 outline-none">
                <div class="w-10 h-10 rounded-full bg-white flex items-center justify-center text-[#54A6D8] mb-2 border border-blue-100">
                    <?= icon('publish-doc', 'w-5 h-5') ?>
                </div>
                <span class="block font-medium text-[#54A6D8] text-sm leading-tight">Apunte</span>
            </a>

            <a href="/publicar-servicio" class="flex flex-col items-center justify-center bg-[#54A6D8] rounded-[1.25rem] transition-all duration-150 active:scale-95 text-center h-28 outline-none">
                <div class="w-10 h-10 rounded-full bg-white/20 flex items-center justify-center text-white mb-2">
                    <?= icon('publish-class', 'w-5 h-5') ?>
                </div>
                <span class="block font-medium text-white text-sm leading-tight">Clase o Servicio</span>
            </a>

        </div>

        <button id="quick-close" class="mt-4 w-full py-3 bg-gray-50 rounded-xl font-medium text-gray-500 transition-colors duration-150 active:scale-[0.98] active:bg-gray-200 border border-[#f0f0f0] outline-none text-sm">
            Cancelar
        </button>

    </div>
</div>
