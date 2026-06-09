<?php
session_start();
if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['rol']) || $_SESSION['rol'] !== 'admin') {
    header("Location: /login.php");
    exit;
}
$correo = $_SESSION['correo'] ?? '';
$es_admin = ($_SESSION['rol'] === 'admin');
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Panel Administrador | Nubira</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body { min-height: 100vh; }
    .list-none { list-style: none; padding-left: 0; }
    .rotate-180 { transform: rotate(180deg); }
  </style>
</head>
<body class="bg-gray-50 flex min-h-screen">

<!-- SIDEBAR ADMIN -->
<aside id="sidebar" class="fixed top-0 left-0 z-40 w-72 h-full bg-white border-r p-6 flex flex-col overflow-y-auto justify-between">
  <div>
    <!-- BOTÓN INICIO -->
    <a href="/vitrina"
  class="flex items-center gap-2 mb-6 px-2 py-2 rounded font-bold text-lg text-blue-800 bg-blue-100 hover:bg-blue-200 transition">
  <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
    <path d="M3 12l9-9 9 9M4 10v10a1 1 0 001 1h3m10-11v11a1 1 0 001 1h3m-14 0h10" stroke-linecap="round" stroke-linejoin="round"/>
  </svg>
  Inicio
</a>

<div class="mb-6 text-xs text-blue-500 break-words font-mono select-text"><?= htmlspecialchars($correo) ?></div>
<ul class="space-y-2 text-sm border-l-4 border-blue-100 pl-3 list-none">

  <!-- Gestión de Plataforma -->
  <li>
    <button type="button" class="flex items-center w-full gap-2 text-blue-700 font-bold text-base px-1 py-2 rounded hover:bg-blue-50 transition"
      onclick="toggleAccordion('admin-gestion')">
      <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>
      </svg>
      Gestión de Plataforma
      <svg id="icon-admin-gestion" class="ml-auto w-4 h-4 transition-transform duration-150" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path d="M19 9l-7 7-7-7" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
    </button>
    <ul id="admin-gestion" class="ml-8 mt-1 space-y-1 hidden list-none">
      <li><a href="/admin/usuarios" class="block pl-2 py-1 text-blue-700 font-medium hover:underline">Gestionar Usuarios</a></li>
      <li><a href="/admin/dominios" class="block pl-2 py-1 text-blue-700 font-medium hover:underline">Gestionar Dominios</a></li>
      <li><a href="/admin/instituciones" class="block pl-2 py-1 text-blue-700 font-medium hover:underline">Agregar Instituciones</a></li>
    </ul>
  </li>
  <!-- Contenidos y Publicaciones -->
  <li>
    <button type="button" class="flex items-center w-full gap-2 text-blue-700 font-bold text-base px-1 py-2 rounded hover:bg-blue-50 transition"
      onclick="toggleAccordion('admin-contenidos')">
      <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <rect x="4" y="4" width="16" height="16" rx="2"/><path d="M8 8h8v8H8z"/>
      </svg>
      Contenidos y Publicaciones
      <svg id="icon-admin-contenidos" class="ml-auto w-4 h-4 transition-transform duration-150" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path d="M19 9l-7 7-7-7" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
    </button>
    <ul id="admin-contenidos" class="ml-8 mt-1 space-y-1 hidden list-none">
      <li><a href="/admin/apuntes" class="block pl-2 py-1 text-blue-700 font-medium hover:underline">Gestionar Apuntes</a></li>
      <li><a href="/admin/servicios" class="block pl-2 py-1 text-blue-700 font-medium hover:underline">Aprobar Servicios</a></li>
      <li><a href="/admin/oportunidades" class="block pl-2 py-1 text-blue-700 font-medium hover:underline">Aprobar Oportunidades</a></li>
      <li><a href="/admin/empleos" class="block pl-2 py-1 text-blue-700 font-medium hover:underline">Aprobar Empleos</a></li>
    </ul>
  </li>
      <!-- Finanzas y Operaciones -->
<li>
  <button type="button" class="flex items-center w-full gap-2 text-blue-700 font-bold text-base px-1 py-2 rounded hover:bg-blue-50 transition"
    onclick="toggleAccordion('admin-finanzas')">
    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
      <rect x="2" y="7" width="20" height="10" rx="2"/><path d="M12 12v-3m0 0l-2 2m2-2l2 2"/>
    </svg>
    Finanzas y Operaciones
    <svg id="icon-admin-finanzas" class="ml-auto w-4 h-4 transition-transform duration-150" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
      <path d="M19 9l-7 7-7-7" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
  </button>
  <ul id="admin-finanzas" class="ml-8 mt-1 space-y-1 hidden list-none">
    <li><a href="/admin/agregar-banco" class="block pl-2 py-1 text-blue-700 font-medium hover:underline">Gestionar Bancos</a></li>
    <li><a href="/admin/retiros" class="block pl-2 py-1 text-blue-700 font-medium hover:underline">Gestionar Retiros</a></li>
    <li><a href="/admin/banners" class="block pl-2 py-1 text-blue-700 font-medium hover:underline">Gestionar Publicidad</a></li>
  </ul>
</li>
<!-- Soporte y Feedback -->
<li>
  <button type="button" class="flex items-center w-full gap-2 text-blue-700 font-bold text-base px-1 py-2 rounded hover:bg-blue-50 transition"
    onclick="toggleAccordion('admin-soporte')">
    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
      <circle cx="12" cy="12" r="10"/><path d="M8 12h8"/>
    </svg>
    Soporte y Feedback
    <svg id="icon-admin-soporte" class="ml-auto w-4 h-4 transition-transform duration-150" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
      <path d="M19 9l-7 7-7-7" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
  </button>
  <ul id="admin-soporte" class="ml-8 mt-1 space-y-1 hidden list-none">
    <li><a href="/admin/soporte" class="block pl-2 py-1 text-blue-700 font-medium hover:underline">Solicitudes Soporte</a></li>
    <li><a href="/admin/reclamos" class="block pl-2 py-1 text-blue-700 font-medium hover:underline">Gestionar Reclamos</a></li>
  </ul>
</li>
<!-- Correo y Notificaciones -->
<li>
  <button type="button" class="flex items-center w-full gap-2 text-blue-700 font-bold text-base px-1 py-2 rounded hover:bg-blue-50 transition"
    onclick="toggleAccordion('admin-correos')">
    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
      <rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>
    </svg>
    Correo y Notificaciones
    <svg id="icon-admin-correos" class="ml-auto w-4 h-4 transition-transform duration-150" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
      <path d="M19 9l-7 7-7-7" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
  </button>
  <ul id="admin-correos" class="ml-8 mt-1 space-y-1 hidden list-none">
    <li><a href="/admin/correos" class="block pl-2 py-1 text-blue-700 font-medium hover:underline">Gestionar Correos</a></li>
    <li><a href="/admin/logs-correo" class="block pl-2 py-1 text-blue-700 font-medium hover:underline">Ver Logs de Correo</a></li>
  </ul>
</li>
<!-- Auditoría y Seguridad -->
<li>
  <button type="button" class="flex items-center w-full gap-2 text-blue-700 font-bold text-base px-1 py-2 rounded hover:bg-blue-50 transition"
    onclick="toggleAccordion('admin-seguridad')">
    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
      <circle cx="12" cy="12" r="10"/><path d="M12 8v4m0 4h.01" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
    Auditoría y Seguridad
    <svg id="icon-admin-seguridad" class="ml-auto w-4 h-4 transition-transform duration-150" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
      <path d="M19 9l-7 7-7-7" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
  </button>
  <ul id="admin-seguridad" class="ml-8 mt-1 space-y-1 hidden list-none">
    <li><a href="/admin/solicitudes" class="block pl-2 py-1 text-blue-700 font-medium hover:underline">Administrar Solicitudes</a></li>
    <li><a href="/admin/excepciones" class="block pl-2 py-1 text-blue-700 font-medium hover:underline">Excepciones Gmail</a></li>
    <li><a href="/admin/login-fallos" class="block pl-2 py-1 text-blue-700 font-medium hover:underline">Login Fallos</a></li>
    <li><a href="/admin/reporte-servicios" class="block pl-2 py-1 text-blue-700 font-medium hover:underline">Reportes Servicios</a></li>
   <li><a href="/app/admin_config_precios.php" class="block pl-2 py-1 text-blue-700 font-medium hover:underline">Configurar precio de contacto</a></li>
  </ul>
</li>

  <!-- BOTÓN CERRAR SESIÓN -->
  <div class="mt-10">
    <a href="/logout"
      class="flex items-center gap-2 px-3 py-2 rounded text-red-600 font-semibold hover:bg-red-50 hover:text-red-700 transition">
      <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path d="M17 16l4-4m0 0l-4-4m4 4H7" stroke-linecap="round" stroke-linejoin="round"/>
        <path d="M7 8v8" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
      Cerrar sesión
    </a>
  </div>
</aside>

<!-- CONTENIDO PRINCIPAL -->
<main class="flex-1 ml-72 p-8">
  <div class="max-w-5xl mx-auto bg-white rounded-2xl shadow-lg p-8 border border-purple-100">
    <h1 class="text-3xl font-bold text-purple-800 mb-2">Panel de Administración</h1>
    <p class="text-gray-600 mb-6">Accede rápidamente a las secciones de gestión, contenidos, finanzas, soporte y más. Elige una categoría del menú lateral para comenzar.</p>
    <!-- Aquí puedes mostrar widgets, métricas, accesos directos, o dejar solo este mensaje -->
  </div>
</main>

<script>
function toggleAccordion(id) {
  const submenu = document.getElementById(id);
  const icon = document.getElementById('icon-' + id);
  if (submenu.classList.contains('hidden')) {
    submenu.classList.remove('hidden');
    icon.classList.add('rotate-180');
  } else {
    submenu.classList.add('hidden');
    icon.classList.remove('rotate-180');
  }
}
</script>
</body>
</html>
