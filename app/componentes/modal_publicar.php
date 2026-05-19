<div id="modal-quick" class="hidden fixed inset-0 z-[70] flex items-end md:items-center justify-center bg-gray-900/40 backdrop-blur-sm transition-opacity select-none" style="-webkit-tap-highlight-color: transparent;">
    
    <div id="quick-card" class="bg-white rounded-t-[1.75rem] md:rounded-2xl p-5 w-full md:w-80 transform translate-y-full opacity-0 transition-all duration-300 pb-[calc(1.25rem+env(safe-area-inset-bottom))]">
        
        <div class="w-10 h-1 bg-gray-200 rounded-full mx-auto mb-4 md:hidden"></div>
        
        <h3 class="font-bold text-lg mb-4 text-center text-gray-900 tracking-tight">¿Qué vas a publicar?</h3>
        
        <div class="grid grid-cols-2 gap-3">
            
            <a href="/formulario-subir-apunte" class="relative flex flex-col items-center justify-center p-3 bg-gradient-to-br from-orange-300 to-orange-500 rounded-[1.25rem] transition-transform duration-150 active:scale-95 text-center h-28 border border-orange-400/30 outline-none">
                
                <div class="absolute -top-2.5 bg-orange-600 text-[9px] text-white font-bold px-2.5 py-0.5 rounded-full z-10 border border-white tracking-wide">
                    POPULAR
                </div>
                
                <div class="w-10 h-10 rounded-full bg-white/20 flex items-center justify-center text-white mb-2 backdrop-blur-sm">
                    <?= icon('publish-doc', 'w-5 h-5') ?>
                </div>
                
                <span class="block font-bold text-white text-sm leading-tight">Apunte</span>
            </a>

            <a href="/publicar-servicio" class="relative flex flex-col items-center justify-center p-3 bg-gradient-to-br from-sky-400 to-[#54A6D8] rounded-[1.25rem] transition-transform duration-150 active:scale-95 text-center h-28 border border-blue-400/30 outline-none">
                
                <div class="absolute -top-2.5 bg-blue-600 text-[9px] text-white font-bold px-2.5 py-0.5 rounded-full z-10 border border-white tracking-wide">
                    NUEVO
                </div>
                
                <div class="w-10 h-10 rounded-full bg-white/20 flex items-center justify-center text-white mb-2 backdrop-blur-sm">
                    <?= icon('publish-class', 'w-5 h-5') ?>
                </div>
                
                <span class="block font-bold text-white text-sm leading-tight">Clase o Servicio</span>
            </a>
            
        </div>
        
        <button id="quick-close" class="mt-4 w-full py-3 bg-gray-50 rounded-xl font-bold text-gray-500 transition-colors duration-150 active:scale-[0.98] active:bg-gray-200 border border-gray-100 outline-none text-sm">
            Cancelar
        </button>
        
    </div>
</div>