<?php
$nombre = $_SESSION['nombre'] ?? 'Usuario';
?>

<aside class="w-60 bg-white border-r p-6 hidden md:flex flex-col justify-between sticky top-0 h-screen overflow-auto">
  <div>
    <h2 class="text-2xl font-extrabold mb-4 text-blue-700">Menú Simple</h2>
    <ul class="space-y-3 text-sm">
      <li><a href="/dashboard" class="text-blue-600 hover:underline inline-flex items-center gap-2">Volver Atrás</a></li>
    </ul>
  </div>

  <div class="space-y-3 text-sm pt-4 border-t mt-6">
    <button onclick="document.getElementById('modal-soporte').classList.remove('hidden')" class="text-blue-600 hover:underline text-left w-full">Soporte</button>
    <a href="/logout" class="text-red-500 hover:text-red-600 inline-flex items-center gap-2">Cerrar sesión</a>
  </div>
</aside>
