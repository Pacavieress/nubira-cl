<div id="modal-explora" class="hidden fixed inset-0 z-[70] flex items-end md:items-center justify-center bg-gray-900/40 backdrop-blur-sm transition-opacity select-none" style="-webkit-tap-highlight-color: transparent;">
    
    <div id="explora-card" class="bg-white rounded-t-[1.75rem] md:rounded-2xl border border-[#f0f0f0] shadow-[0_1px_3px_rgba(0,0,0,0.04)] p-5 w-full md:w-80 transform translate-y-full opacity-0 transition-all duration-300 pb-[calc(1.25rem+env(safe-area-inset-bottom))]">
        
        <div class="w-10 h-1 bg-gray-200 rounded-full mx-auto mb-4 md:hidden"></div>
        
        <div class="font-medium text-lg mb-4 text-center text-[#222222] tracking-[-0.01em]">Explorar Nubira</div>
        
        <div class="grid grid-cols-3 gap-2">
            
            <a href="/vitrina-apuntes" class="relative flex flex-col items-center justify-center p-3 bg-gray-50 rounded-[1.25rem] transition-all duration-150 active:scale-95 active:bg-orange-50 active:border-orange-100 text-center h-28 border border-[#f0f0f0] outline-none">

                <div class="w-10 h-10 rounded-full bg-white flex items-center justify-center text-orange-400 mb-2 border border-[#f0f0f0]">
                    <?= icon('publish-doc', 'w-5 h-5') ?>
                </div>

                <span class="block font-medium text-[#222222] text-sm leading-tight">Ver Apuntes</span>
            </a>

            <a href="/clases-servicios" class="relative flex flex-col items-center justify-center p-3 bg-gray-50 rounded-[1.25rem] transition-all duration-150 active:scale-95 active:bg-blue-50 active:border-blue-100 text-center h-28 border border-[#f0f0f0] outline-none">

                <div class="w-10 h-10 rounded-full bg-white flex items-center justify-center text-[#54A6D8] mb-2 border border-[#f0f0f0]">
                    <?= icon('publish-class', 'w-5 h-5') ?>
                </div>

                <span class="block font-medium text-[#222222] text-sm leading-tight">Ver Clases o Servicios</span>
            </a>

            <a href="/guias" class="relative flex flex-col items-center justify-center p-3 bg-gray-50 rounded-[1.25rem] transition-all duration-150 active:scale-95 active:bg-gray-100 text-center h-28 border border-[#f0f0f0] outline-none">

                <div class="w-10 h-10 rounded-full bg-white flex items-center justify-center text-gray-500 mb-2 border border-[#f0f0f0]">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5" width="20" height="20">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />
                    </svg>
                </div>

                <span class="block font-medium text-[#222222] text-sm leading-tight">Ver Recursos</span>
            </a>

        </div>
        
        <button id="explora-close" class="mt-4 w-full py-3 bg-gray-50 rounded-xl font-medium text-gray-500 transition-colors duration-150 active:scale-[0.98] active:bg-gray-200 border border-[#f0f0f0] outline-none text-sm">
            Cerrar
        </button>
        
    </div>
</div>