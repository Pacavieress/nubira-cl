<?php
$nombre = $_SESSION['nombre'] ?? 'Usuario';
$rol = $_SESSION['rol'] ?? 'alumno';
$es_admin = $rol === 'admin';
?>

<aside class="w-60 bg-white border-r p-6 hidden md:flex flex-col justify-between sticky top-0 h-screen overflow-auto">
  <div>
    <h2 class="text-2xl font-extrabold mb-4 text-blue-700">Inicio</h2>
    <ul class="space-y-3 text-sm">
      <li><a href="/vitrina" class="text-blue-600 hover:underline inline-flex items-center gap-2">Inicio</a></li>
      <li><a href="/dashboard" class="text-blue-600 hover:underline inline-flex items-center gap-2">Mi Panel</a></li>
      <li><a href="/vitrina_apuntes" class="text-blue-600 hover:underline inline-flex items-center gap-2">Vitrina de Apuntes</a></li>
      <li><a href="/empleos" class="text-blue-600 hover:underline inline-flex items-center gap-2">Vitrina de Empleo</a></li>
      <li><a href="/mis_ventas" class="text-blue-600 hover:underline inline-flex items-center gap-2">Emprendimiento/Proyectos</a></li>
   
      <?php if ($es_admin): ?>
        <li><a href="/admin/usuarios" class="text-purple-600 hover:underline inline-flex items-center gap-2">👥 Gestionar Usuarios</a></li>
        <li><a href="/admin/dominios" class="text-purple-600 hover:underline inline-flex items-center gap-2">⚙️ Gestionar Dominios</a></li>
        <li><a href="/admin/empleos" class="text-purple-600 hover:underline inline-flex items-center gap-2">🛠 Aprobar Empleos</a></li>
        <li><a href="/admin/apuntes" class="text-purple-600 hover:underline inline-flex items-center gap-2">📝 Gestionar Apuntes</a></li>
        <li><a href="admin/agregar_banco" class="text-purple-600 hover:underline inline-flex items-center gap-2">🏦 Bancos</a></li>
        <li><a href="/admin/retiros" class="text-purple-600 hover:underline inline-flex items-center gap-2">💰 Retiros</a></li>
        <li><a href="admin/banners" class="text-purple-600 hover:underline inline-flex items-center gap-2">📢 Banners Publicitarios</a></li>
      <?php endif; ?>
    </ul>
  </div>

  <div class="space-y-3 text-sm pt-4 border-t mt-6">
    <button onclick="document.getElementById('modal-soporte').classList.remove('hidden')" class="text-blue-600 hover:underline text-left w-full">Soporte</button>
    <a href="/logout" class="text-red-500 hover:text-red-600 inline-flex items-center gap-2">Cerrar sesión</a>
    <div class="mt-4 border-t pt-3 text-gray-500 text-xs">
      <a href="/terminos" target="_blank" class="block hover:underline" rel="noopener noreferrer">Términos y Condiciones</a>
      <a href="/privacidad" target="_blank" class="block hover:underline" rel="noopener noreferrer">Política de Privacidad</a>
    </div>
  </div>
</aside>
