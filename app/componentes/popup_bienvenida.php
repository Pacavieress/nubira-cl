<?php if (!empty($_SESSION['mostrar_popup_bienvenida'])): ?>

<div id="popup-bienvenida" 
     class="fixed inset-0 z-50 flex items-end md:items-center justify-center 
            bg-black/40 backdrop-blur-[3px] hidden">

  <div id="bienvenida-card"
       class="bg-white/95 rounded-2xl shadow-2xl border border-white/50 
              mx-4 p-6 w-full sm:w-[420px] mb-24 md:mb-0 
              opacity-0 scale-95 translate-y-6 
              transition-all duration-500 relative backdrop-blur-md">

    <!-- Botón cerrar -->
    <button id="bienvenida-close" 
            class="absolute -top-3 -right-3 rounded-full bg-white/70 
                   hover:bg-white shadow-sm w-8 h-8 flex items-center 
                   justify-center text-gray-500">
        <svg class='w-4 h-4' viewBox='0 0 24 24' fill='none' 
             stroke='currentColor' stroke-width='2' stroke-linecap='round' 
             stroke-linejoin='round'>
            <line x1='18' y1='6' x2='6' y2='18'></line>
            <line x1='6' y1='6' x2='18' y2='18'></line>
        </svg>
    </button>

    <!-- Título -->
    <h3 class="text-lg font-bold text-center text-[#54A6D8] mb-2 md:text-xl">
        ¡Hola <?= htmlspecialchars($_SESSION['usuario_nombre'] ?? 'estudiante') ?>!
    </h3>

    <!-- Texto -->
    <p class="text-gray-600 text-sm text-center mb-6">
        Bienvenido a <strong>Nubira.cl</strong><br>
        ¿Por dónde quieres partir hoy?
    </p>

    <!-- Opciones -->
    <div class="grid grid-cols-1 gap-3">

        <a href="#" id="btn-ir-publicar"
           class="border-2 border-green-100 hover:border-green-500 
                  hover:bg-green-50 p-3 rounded-xl flex items-center 
                  justify-center gap-2 transition">
            <?= icon('plus-outline', 'w-4 h-4 text-green-500') ?> 
            <span class="text-green-700 font-bold text-sm">Publicar nuevo</span>
        </a>

        <a href="/clases-servicios"
           class="border-2 border-yellow-100 hover:border-yellow-500 
                  hover:bg-yellow-50 p-3 rounded-xl flex items-center 
                  justify-center gap-2 transition">
            <?= icon('publish-class', 'w-4 h-4 text-yellow-500') ?>
            <span class="text-yellow-700 font-bold text-sm">Explorar Clases</span>
        </a>

        <a href="/vitrina-apuntes"
           class="border-2 border-blue-100 hover:border-blue-500 
                  hover:bg-blue-50 p-3 rounded-xl flex items-center 
                  justify-center gap-2 transition">
            <?= icon('publish-doc', 'w-4 h-4 text-blue-500') ?>
            <span class="text-blue-700 font-bold text-sm">Explorar Apuntes</span>
        </a>

    </div>

  </div>
</div>

<?php unset($_SESSION['mostrar_popup_bienvenida']); endif; ?>
