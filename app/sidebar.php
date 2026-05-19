<?php
// === ICONOS ===
if (!function_exists('icon')) {
    require_once __DIR__ . '/iconos.php';
}

// === ACTIVE CLASS ===
if (!function_exists('nav_class')) {
    function nav_class(string $path): string {
        $ruta_actual = $_SERVER['REQUEST_URI'] ?? '';
        $base = 'group flex items-center gap-3 px-3 py-2.5 rounded-xl border-l-4 transition';
        $active = ' border-[#54A6D8] bg-[#54A6D8]/10 text-[#54A6D8]';
        $inactive = ' border-transparent text-gray-600 hover:border-[#54A6D8] hover:bg-[#54A6D8]/5 hover:text-gray-900';

        return $base . (strpos($ruta_actual, $path) !== false ? $active : $inactive);
    }
}
?>

<aside class="hidden md:flex md:flex-col fixed top-16 left-0 h-[calc(100%-4rem)] w-64 bg-white border-r border-gray-100 z-40">
  <div class="p-6">
    <nav class="flex flex-col space-y-1">

      <!-- INICIO -->
      <a href="/vitrina" class="<?= nav_class('/vitrina') ?>">
        <?= icon('home', 'w-5 h-5') ?>
        <span>Inicio</span>
      </a>

      <!-- CLASES & SERVICIOS -->
      <a href="/clases-servicios" class="<?= nav_class('/clases-servicios') ?>">
        <?= icon('publish-class', 'w-5 h-5') ?>
        <span>Explorar Clases o Servicios</span>
      </a>

      <!-- APUNTES -->
      <a href="/vitrina-apuntes" class="<?= nav_class('/vitrina-apuntes') ?>">
        <?= icon('publish-doc', 'w-5 h-5') ?>
        <span>Explorar Apuntes</span>
      </a>

      <div class="h-px bg-gray-100 my-2"></div>

      <!-- PERFIL -->
      <a href="/dashboard" class="<?= nav_class('/dashboard') ?>">
        <?= icon('user', 'w-5 h-5') ?>
        <span>Mi Perfil</span>
      </a>

      <!-- CHATS -->
      <a href="#" onclick="abrirMisChats(); return false;" class="<?= nav_class('/mis-chats') ?>">
        <div class="flex items-center justify-between w-full">
          <div class="flex items-center gap-3">
            <?= icon('chat-outline', 'w-5 h-5') ?>
            <span>Chats</span>
          </div>
          <span id="badge-chats-sidebar" class="bg-nubira text-white text-[10px] font-bold px-1.5 py-0.5 rounded-full hidden">0</span>
        </div>
      </a>

    </nav>
  </div>
</aside>
