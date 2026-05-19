<div id="modal-explora" class="hidden fixed inset-0 z-[70] flex items-end md:items-center justify-center bg-gray-900/40 backdrop-blur-sm transition-opacity select-none" style="-webkit-tap-highlight-color: transparent;">
    
    <div id="explora-card" class="bg-white rounded-t-[1.75rem] md:rounded-2xl p-5 w-full md:w-80 transform translate-y-full opacity-0 transition-all duration-300 pb-[calc(1.25rem+env(safe-area-inset-bottom))]">
        
        <div class="w-10 h-1 bg-gray-200 rounded-full mx-auto mb-4 md:hidden"></div>
        
        <h3 class="font-bold text-lg mb-4 text-center text-gray-900 tracking-tight">Explorar Nubira</h3>
        
        <div class="grid grid-cols-2 gap-3">
            
            <a href="/vitrina-apuntes" class="relative flex flex-col items-center justify-center p-3 bg-gray-50 rounded-[1.25rem] transition-all duration-150 active:scale-95 active:bg-orange-50 active:border-orange-100 text-center h-28 border border-gray-100 outline-none">
                
                <div class="w-10 h-10 rounded-full bg-white flex items-center justify-center text-orange-400 mb-2 border border-gray-100">
                    <?= icon('publish-doc', 'w-5 h-5') ?>
                </div>
                
                <span class="block font-bold text-gray-700 text-sm leading-tight">Ver Apuntes</span>
            </a>

            <a href="/clases-servicios" class="relative flex flex-col items-center justify-center p-3 bg-gray-50 rounded-[1.25rem] transition-all duration-150 active:scale-95 active:bg-blue-50 active:border-blue-100 text-center h-28 border border-gray-100 outline-none">
                
                <div class="w-10 h-10 rounded-full bg-white flex items-center justify-center text-[#54A6D8] mb-2 border border-gray-100">
                    <?= icon('publish-class', 'w-5 h-5') ?>
                </div>
                
                <span class="block font-bold text-gray-700 text-sm leading-tight">Ver Clases o Servicios</span>
            </a>
            
        </div>
        
        <button id="explora-close" class="mt-4 w-full py-3 bg-gray-50 rounded-xl font-bold text-gray-500 transition-colors duration-150 active:scale-[0.98] active:bg-gray-200 border border-gray-100 outline-none text-sm">
            Cerrar
        </button>
        
    </div>
</div>