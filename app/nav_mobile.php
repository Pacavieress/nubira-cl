<?php
// Aseguramos icon() disponible
if (!function_exists('icon')) {
    require_once __DIR__ . '/iconos.php';
}
?>

<!-- NAV MÓVIL -->
<nav class="md:hidden fixed bottom-0 left-0 right-0 z-50 bg-white border-t border-gray-100 pb-safe shadow-[0_-4px_6px_-1px_rgba(0,0,0,0.05)]">
  <ul class="grid grid-cols-5 text-[10px] text-gray-500 font-medium text-center pt-2 pb-3">

    <li>
        <a href="/vitrina" class="flex flex-col items-center gap-1 
            <?= (strpos($_SERVER['REQUEST_URI'],'vitrina') !== false ? 'text-nubira' : '') ?>">
            <div class="w-6 h-6 flex items-center justify-center"><?= icon('home','w-5 h-5') ?></div>
            <span>Inicio</span>
        </a>
    </li>

    <li>
        <button id="btn-explora" class="flex flex-col items-center gap-1 w-full hover:text-nubira">
            <div class="w-6 h-6 flex items-center justify-center"><?= icon('search','w-5 h-5') ?></div>
            <span>Explora</span>
        </button>
    </li>

    <li>
        <button id="btn-publicar" class="flex flex-col items-center gap-1 w-full text-nubira">
            <div class="w-10 h-10 bg-nubira rounded-full flex items-center justify-center text-white shadow-lg -mt-4 border-2 border-white">
                <?= icon('plus-outline','w-6 h-6') ?>
            </div>
        </button>
    </li>

    <li>
        <a href="#" onclick="abrirMisChats();return false;" 
           class="flex flex-col items-center gap-1 relative hover:text-nubira">
            <div class="w-6 h-6 flex items-center justify-center"><?= icon('chat-outline','w-5 h-5') ?></div>
            <span>Chats</span>
            <span id="badge-chats-bottom" 
                  class="absolute top-0 right-3 bg-red-500 text-white font-bold text-[8px] px-1 rounded-full hidden">0</span>
        </a>
    </li>

    <li>
        <a href="/dashboard" class="flex flex-col items-center gap-1 hover:text-nubira">
            <div class="w-6 h-6 flex items-center justify-center"><?= icon('user','w-5 h-5') ?></div>
            <span>Perfil</span>
        </a>
    </li>

  </ul>
</nav>


<!-- MODAL: PUBLICAR -->
<div id="modal-quick" class="hidden fixed inset-0 z-[60] flex items-end md:items-center justify-center
    bg-gray-900/40 backdrop-blur-sm">

    <div id="quick-card" 
         class="bg-white rounded-t-2xl md:rounded-xl p-6 w-full md:w-96 translate-y-full opacity-0 
         transition-all duration-300 shadow-2xl">

        <h3 class="font-bold text-lg mb-4 text-center text-gray-900">¿Qué quieres publicar?</h3>

        <div class="grid grid-cols-2 gap-4">

            <a href="/formulario-subir-apunte" 
               class="relative flex flex-col items-center justify-center p-4 bg-gradient-to-br
               from-orange-300 to-orange-500 rounded-2xl h-36 shadow-md group">

                <span class="absolute -top-3 bg-orange-600 text-[9px] text-white font-bold px-2 py-0.5 rounded-full">
                    POPULAR
                </span>

                <div class="w-12 h-12 bg-white/20 rounded-full flex items-center justify-center text-white mb-3">
                    <?= icon('publish-doc','w-6 h-6') ?>
                </div>

                <span class="text-white font-bold text-sm">Publicar Apunte</span>
                <span class="text-[10px] text-orange-50 mt-1">Resúmenes y guías</span>
            </a>

            <a href="/publicar-servicio" 
               class="relative flex flex-col items-center justify-center p-4 bg-gradient-to-br
               from-sky-400 to-[#54A6D8] rounded-2xl h-36 shadow-md group">

                <span class="absolute -top-3 bg-blue-600 text-[9px] text-white font-bold px-2 py-0.5 rounded-full">
                    NUEVO
                </span>

                <div class="w-12 h-12 bg-white/20 rounded-full flex items-center justify-center text-white mb-3">
                    <?= icon('publish-class','w-6 h-6') ?>
                </div>

                <span class="text-white font-bold text-sm">Publicar Clase o Servicio</span>
                <span class="text-[10px] text-blue-50 mt-1">Tutorías y servicios</span>
            </a>

        </div>

        <button id="quick-close" 
                class="mt-6 w-full py-3.5 bg-gray-100 rounded-xl font-bold text-gray-600 hover:bg-gray-200">
            Cancelar
        </button>
    </div>
</div>


<!-- MODAL: EXPLORA -->
<div id="modal-explora" 
     class="hidden fixed inset-0 z-[60] flex items-end md:items-center justify-center
     bg-gray-900/40 backdrop-blur-sm">

    <div id="explora-card" 
         class="bg-white rounded-t-2xl md:rounded-xl p-6 w-full md:w-96 translate-y-full opacity-0 
         transition-all duration-300 shadow-2xl">

        <h3 class="font-bold text-lg mb-4 text-center text-gray-900">Explorar Nubira</h3>

        <div class="grid grid-cols-2 gap-4">

            <a href="/vitrina-apuntes" 
               class="flex flex-col items-center justify-center p-4 bg-gradient-to-br
               from-orange-300 to-orange-500 rounded-2xl h-36 shadow-md">
                <div class="w-12 h-12 bg-white/20 rounded-full flex items-center justify-center text-white mb-3">
                    <?= icon('publish-doc','w-6 h-6') ?>
                </div>
                <span class="text-white font-bold text-sm">Explorar Apuntes</span>
            </a>

            <a href="/clases-servicios" 
               class="flex flex-col items-center justify-center p-4 bg-gradient-to-br
               from-sky-400 to-[#54A6D8] rounded-2xl h-36 shadow-md">
                <div class="w-12 h-12 bg-white/20 rounded-full flex items-center justify-center text-white mb-3">
                    <?= icon('publish-class','w-6 h-6') ?>
                </div>
                <span class="text-white font-bold text-sm">Explorar Clases</span>
            </a>

        </div>

        <button id="explora-close" 
                class="mt-6 w-full py-3.5 bg-gray-100 rounded-xl font-bold text-gray-600 hover:bg-gray-200">
            Cerrar
        </button>
    </div>
</div>


<!-- JS GLOBAL DEL NAV MÓVIL -->
<script>

// Sistema de modales
function setupModal(triggerId, modalId, cardId, closeId) {
    const btn = document.getElementById(triggerId),
          modal = document.getElementById(modalId),
          card = document.getElementById(cardId),
          close = document.getElementById(closeId);

    if (!btn || !modal) return;

    const open = () => {
        modal.classList.remove('hidden');
        requestAnimationFrame(() => card.classList.remove('translate-y-full','opacity-0'));
        document.body.style.overflow = 'hidden';
    };

    const shut = () => {
        card.classList.add('translate-y-full','opacity-0');
        setTimeout(() => {
            modal.classList.add('hidden');
            document.body.style.overflow = '';
        }, 300);
    };

    btn.onclick = open;
    close.onclick = shut;
    modal.onclick = e => { if (e.target === modal) shut(); };
}

setupModal('btn-publicar','modal-quick','quick-card','quick-close');
setupModal('btn-explora','modal-explora','explora-card','explora-close');

// Badge de chats
async function actualizarBadgeChats() {
    try {
        const r = await fetch('/app/contar_mensajes_nuevos.php');
        const d = await r.json();
        const total = parseInt(d.total || 0);

        const el = document.getElementById('badge-chats-bottom');

        if (total > 0) {
            el.innerText = total;
            el.classList.remove('hidden');
        } else {
            el.classList.add('hidden');
        }
    } catch {}
}

setInterval(actualizarBadgeChats, 8000);
actualizarBadgeChats();

function abrirMisChats() {
    window.open(
        "/app/mis_chats.php",
        "mis_chats",
        "width=450,height=650,resizable=yes,scrollbars=yes"
    );
}
</script>
