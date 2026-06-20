<?php
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header('Location: /login');
    exit;
}
require_once '../app/conexion.php';

$institucion = $_SESSION['institucion'] ?? null;
$rol         = $_SESSION['rol']        ?? 'alumno';
$nombre      = $_SESSION['nombre']     ?? 'Usuario';


$qs_tipo      = trim($_GET['tipo'] ?? '');
$qs_estado    = trim($_GET['estado'] ?? '');
$qs_inst      = trim($_GET['institucion'] ?? '');
$qs_ver_todas = true;


$initial_params = [
  'pagina' => 1
];
if ($qs_tipo)   $initial_params['tipo'] = $qs_tipo;
if ($qs_estado) $initial_params['estado'] = $qs_estado;

if ($rol === 'admin') {
  if ($qs_inst) {
    $initial_params['institucion'] = $qs_inst;
  }
} else {
  if ($qs_inst) {
    $initial_params['institucion'] = $qs_inst;
  } else {
    $initial_params['ver_todas'] = '1';
  }
}

$initial_src = '/app/cargar_oportunidades.php?' . http_build_query($initial_params);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
  <?php require_once __DIR__ . '/helpers/seo.php'; echo nubira_seo_meta('Oportunidades para estudiantes universitarios | Nubira Chile', 'Becas, prácticas, ayudantías y oportunidades laborales para universitarios chilenos. Publicadas por estudiantes, para estudiantes.'); ?>
  <?php require_once __DIR__ . '/helpers/seo.php'; echo nubira_canonical_tag(); ?>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    :root{ --nubira:#54A6D8; }
    .text-nubira{ color: var(--nubira); }
    .bg-nubira{ background-color: var(--nubira); }
    .compact-root > *{ border-radius:.75rem; background:#fff; box-shadow:0 1px 8px rgba(0,0,0,.06); overflow:hidden; min-height:100px; transition:box-shadow .15s; }
    .compact-root > *:hover{ box-shadow:0 2px 14px rgba(0,0,0,.08); }
  </style>
</head>
<body class="bg-white min-h-screen text-gray-800">

<aside class="hidden md:flex md:flex-col fixed top-16 left-0 h-[calc(100%-4rem)] w-64 bg-white border-r border-gray-200 shadow-lg z-30">
  <div class="p-6">
    <nav class="flex flex-col space-y-3">
     <a href="/vitrina" class="text-gray-700 hover:text-[#54A6D8]">🏠 Inicio</a>
      <a href="/dashboard" class="text-gray-700 hover:text-[#54A6D8]">⚙️ Perfil</a>
<a href="/vitrina-apuntes" class="text-gray-700 hover:text-[#54A6D8]">📘 Explorar Apuntes</a>
<a href="/clases-servicios" class="text-gray-700 hover:text-[#54A6D8]">🧑‍🏫 Explorar Servicios</a>

<a href="#" 
   onclick="abrirMisChats(); return false;"
   class="flex justify-between items-center text-gray-700 hover:text-[#54A6D8]">
  <span>💬 Mis Chats</span>
  <span id="badge-chats-sidebar"
        class="ml-2 bg-[#54A6D8] text-white text-[10px] font-bold px-2 py-0.5 rounded-full hidden">
    0
  </span>
</a>

<a href="/oportunidades" class="text-gray-700 hover:text-[#54A6D8]">🎯 Explorar Oportunidades</a>
    </nav>
  </div>
</aside>

<header class="fixed top-0 left-0 right-0 z-40 bg-gradient-to-b from-[#C8E8F8]/80 to-white border-b border-white">
  <div class="max-w-6xl mx-auto flex items-center justify-start h-16 px-4">
    <span class="text-[#54A6D8] font-bold text-lg">Oportunidades</span>
  </div>
</header>

<main class="pt-20 pb-28 w-full md:ml-72">

 <div class="px-4 md:pl-6 md:max-w-[calc(100%-18rem)] w-full">
  <form id="form-filtros" 
        class="bg-white border shadow rounded-lg p-4 mb-4 w-full max-w-[340px] md:max-w-none mx-auto md:mx-0" 
        autocomplete="off">
    <div class="grid grid-cols-2 md:grid-cols-5 gap-3 text-sm">

        <select name="tipo" class="border pill px-3 py-2 bg-gray-50">
          <option value="">Tipo</option>
          <option value="beca" <?= $qs_tipo==='beca'?'selected':'' ?>>Beca</option>
          <option value="practica" <?= $qs_tipo==='practica'?'selected':'' ?>>Práctica</option>
          <option value="concurso" <?= $qs_tipo==='concurso'?'selected':'' ?>>Concurso</option>
          <option value="evento" <?= $qs_tipo==='evento'?'selected':'' ?>>Evento</option>
        </select>

   
        <select name="estado" class="border pill px-3 py-2 bg-gray-50">
          <option value="">Estado</option>
          <option value="vigente" <?= $qs_estado==='vigente'?'selected':'' ?>>Vigente</option>
          <option value="caducado" <?= $qs_estado==='caducado'?'selected':'' ?>>Caducado</option>
        </select>


        <select name="institucion" class="border pill px-3 py-2 bg-gray-50">
          <option value="">Todas las instituciones</option>
          <option value="uc" <?= $qs_inst==='uc'?'selected':'' ?>>UC</option>
          <option value="duoc" <?= $qs_inst==='duoc'?'selected':'' ?>>Duoc</option>
          <option value="aiep" <?= $qs_inst==='aiep'?'selected':'' ?>>AIEP</option>
          <option value="santotomas" <?= $qs_inst==='santotomas'?'selected':'' ?>>Santo Tomás</option>
        </select>

        <div class="col-span-2 md:col-span-1">
          <button type="submit" class="w-full bg-nubira text-white rounded px-4 py-2 font-semibold hover:bg-blue-600">Filtrar</button>
        </div>
      </div>
    </form>
  </div>

  <div class="px-4 md:pl-6 md:max-w-[calc(100%-18rem)]">
    <div id="contenedor-oportunidades"
         class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-3 gap-6 compact-root w-full"
         data-src="<?= htmlspecialchars($initial_src, ENT_QUOTES) ?>">
      <?php for($i=0;$i<4;$i++): ?><div class="h-[220px] skeleton"></div><?php endfor; ?>
    </div>
  </div>
</main>

<nav class="md:hidden fixed bottom-0 left-0 right-0 z-40 bg-gradient-to-t from-[#C8E8F8]/80 to-white border-t">
  <ul class="grid grid-cols-5 text-xs text-gray-600 text-center">
    <li>
      <a href="/vitrina" class="flex flex-col items-center justify-center py-2 w-full focus:outline-none">
         <svg class="w-6 h-6 mb-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" 
                d="M3 9.75L12 3l9 6.75V20a1 1 0 0 1-1 1h-5.25v-6h-5.5v6H4a1 1 0 0 1-1-1V9.75z"/>
        </svg>
        Inicio
      </a>
    </li>
    <li>
      <button id="btn-explora" class="flex flex-col items-center justify-center py-2 w-full focus:outline-none">
        <svg class="w-6 h-6 mb-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path d="M21 21l-4.35-4.35"/><circle cx="10" cy="10" r="7"/>
        </svg>
        Explora
      </button>
    </li>
    <li>
      <button id="btn-publicar" class="flex flex-col items-center justify-center py-2 w-full focus:outline-none text-nubira">
        <svg class="w-6 h-6 mb-0.5" fill="none" stroke="#54A6D8" stroke-width="2" viewBox="0 0 24 24">
          <path d="M12 5v14M5 12h14" stroke-linecap="round"/>
        </svg>
        Publicar
      </button>
    </li>
    <li class="relative">
  <a href="/app/mis_chats.php" class="flex flex-col items-center justify-center py-2 w-full focus:outline-none relative">
    <svg class="w-6 h-6 mb-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
      <path d="M21 11.5a8.38 8.38 0 01-1.9 5.4L21 21l-4.6-1.9a8.5 8.5 0 111.6-7.6"/>
    </svg>
    Chats

    <span id="badge-chats-bottom"
          class="absolute top-1 right-6 bg-[#54A6D8] text-white text-[10px] font-bold px-1.5 py-0.5 rounded-full hidden">
      0
    </span>
  </a>
</li>
    <li>
      <a href="/dashboard" class="flex flex-col items-center justify-center py-2 w-full focus:outline-none">
        <svg class="w-6 h-6 mb-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path d="M5.12 17.8A9 9 0 0 1 12 15c2.29 0 4.38.87 5.88 2.30"/>
          <path d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
        </svg>
        Perfil
      </a>
    </li>
  </ul>
</nav>


<div id="modal-quick" class="hidden fixed inset-0 z-50 flex items-end md:items-center justify-center bg-black/20">
  <div id="quick-card"
       class="bg-white rounded-2xl shadow-xl border mx-4 p-4 w-full sm:w-[420px] mb-20 md:mb-0
              opacity-0 translate-y-3 transition duration-150">
    <div class="relative">
      <button id="quick-close" class="absolute -top-2 -right-2 hit-48 rounded-full hover:bg-gray-100" aria-label="Cerrar">
        <svg class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M6 18L18 6M6 6l12 12" stroke-linecap="round"/></svg>
      </button>
      <h3 class="text-base md:text-lg font-bold text-nubira mb-3 text-center">¿Qué quieres publicar hoy?</h3>
      <div class="grid grid-cols-3 gap-3">
        <a href="/formulario-subir-apunte" class="border rounded-xl p-3 text-center hover:shadow-md transition active:scale-[.99]">
          <div class="text-green-600 font-semibold text-sm">Apunte</div>
          <div class="text-[11px] text-gray-500">PDF / Word</div>
        </a>
        <a href="/publicar-servicio" class="border rounded-xl p-3 text-center hover:shadow-md transition active:scale-[.99]">
          <div class="text-yellow-600 font-semibold text-sm">Servicio</div>
          <div class="text-[11px] text-gray-500">Clases</div>
        </a>
        <a href="/crear-oportunidad" class="border rounded-xl p-3 text-center hover:shadow-md transition active:scale-[.99]">
          <div class="text-purple-600 font-semibold text-sm">Oportunidad</div>
          <div class="text-[11px] text-gray-500">Becas</div>
        </a>
      </div>
    </div>
  </div>
</div>


<div id="modal-explora" class="hidden fixed inset-0 z-50 flex items-end md:items-center justify-center bg-black/20">
  <div id="explora-card"
       class="bg-white rounded-2xl shadow-xl border mx-4 p-4 w-full sm:w-[420px] mb-20 md:mb-0
              opacity-0 translate-y-3 transition duration-150">
    <div class="relative">
      <button id="explora-close" class="absolute -top-2 -right-2 hit-48 rounded-full hover:bg-gray-100" aria-label="Cerrar">
        <svg class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M6 18L18 6M6 6l12 12" stroke-linecap="round"/></svg>
      </button>
      <h3 class="text-base md:text-lg font-bold text-nubira mb-3 text-center">¿Qué quieres explorar?</h3>
      <div class="grid grid-cols-3 gap-3">
        <a href="/vitrina-apuntes" class="border rounded-xl p-3 text-center hover:shadow-md transition active:scale-[.99]">
          <div class="text-blue-600 font-semibold text-sm">Apuntes</div>
          <div class="text-[11px] text-gray-500">Encuentra material</div>
        </a>
        <a href="/clases-servicios" class="border rounded-xl p-3 text-center hover:shadow-md transition active:scale-[.99]">
          <div class="text-yellow-600 font-semibold text-sm">Servicios</div>
          <div class="text-[11px] text-gray-500">Clases / ayuda</div>
        </a>
        <a href="/oportunidades" class="border rounded-xl p-3 text-center hover:shadow-md transition active:scale-[.99]">
          <div class="text-purple-600 font-semibold text-sm">Anuncios</div>
          <div class="text-[11px] text-gray-500">Becas / prácticas</div>
        </a>
      </div>
    </div>
  </div>
</div>

<!-- JS: Drawer (escritorio) -->
<script>
(function(){
  const openBtn=document.getElementById('openMenu');
  const closeBtn=document.getElementById('closeMenu');
  const sheet=document.getElementById('sheet-menu');
  const overlay=document.getElementById('sheet-overlay');
  function open(){ sheet.style.transform='translateX(0)'; overlay.classList.remove('hidden'); document.body.style.overflow='hidden'; openBtn?.setAttribute('aria-expanded','true'); }
  function close(){ sheet.style.transform='translateX(-100%)'; overlay.classList.add('hidden'); document.body.style.overflow=''; openBtn?.setAttribute('aria-expanded','false'); }
  openBtn?.addEventListener('click', open);
  closeBtn?.addEventListener('click', close);
  overlay?.addEventListener('click', close);
})();
</script>


<!-- JS: Buscador en vivo (debounce) -->
<script>
const form = document.getElementById('form-filtros');
const ftipo = form.querySelector('[name="tipo"]');
const festado = form.querySelector('[name="estado"]');
const finstitucion = form.querySelector('[name="institucion"]');

function buildURL(){
  const p = new URLSearchParams();
  p.set('pagina','1');

  if (ftipo && ftipo.value.trim()) {
    p.set('tipo', ftipo.value.trim());
  }
  if (festado && festado.value.trim()) {
    p.set('estado', festado.value.trim());
  }
  if (finstitucion && finstitucion.value.trim()) {
    p.set('institucion', finstitucion.value.trim());
  } else {
    p.set('ver_todas','1');
  }

  return `/app/cargar_oportunidades.php?${p.toString()}`;
}

let t = null;
function trigger(){
  clearTimeout(t);
  t = setTimeout(()=> cargarOportunidades(buildURL()), 280);
}

form.addEventListener('submit', (e)=>{ e.preventDefault(); trigger(); });
ftipo?.addEventListener('change', trigger);
festado?.addEventListener('change', trigger);
finstitucion?.addEventListener('change', trigger);

</script>

<!-- JS: Modal "Publicar" -->
<script>
(function () {
  const btnPublicar = document.getElementById('btn-publicar');
  const modal       = document.getElementById('modal-quick');
  const card        = document.getElementById('quick-card');
  const btnClose    = document.getElementById('quick-close');

  if (!btnPublicar || !modal || !card || !btnClose) return;

  function openQuick() {
    modal.classList.remove('hidden');
    requestAnimationFrame(() => {
      card.classList.remove('opacity-0', 'translate-y-3');
      document.body.style.overflow = 'hidden';
    });
  }
  function closeQuick() {
    card.classList.add('opacity-0', 'translate-y-3');
    setTimeout(() => {
      modal.classList.add('hidden');
      document.body.style.overflow = '';
    }, 120);
  }

  btnPublicar.addEventListener('click', (e) => { e.preventDefault(); openQuick(); });
  btnClose.addEventListener('click', closeQuick);
  modal.addEventListener('click', (e) => { if (e.target === modal) closeQuick(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !modal.classList.contains('hidden')) closeQuick(); });
})();
</script>


<script>
(function () {
  const btnExplora = document.getElementById('btn-explora');
  const modal      = document.getElementById('modal-explora');
  const card       = document.getElementById('explora-card');
  const btnClose   = document.getElementById('explora-close');

  if (!btnExplora || !modal || !card || !btnClose) return;

  function open() {
    modal.classList.remove('hidden');
    requestAnimationFrame(() => {
      card.classList.remove('opacity-0', 'translate-y-3');
      document.body.style.overflow = 'hidden';
    });
  }
  function close() {
    card.classList.add('opacity-0', 'translate-y-3');
    setTimeout(() => {
      modal.classList.add('hidden');
      document.body.style.overflow = '';
    }, 120);
  }

  btnExplora.addEventListener('click', (e) => { e.preventDefault(); open(); });
  btnClose.addEventListener('click', close);
  modal.addEventListener('click', (e) => { if (e.target === modal) close(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !modal.classList.contains('hidden')) close(); });
})();
</script>


<!-- SCROLL INFINITO CON IO -->
<script>
const cont = document.getElementById('contenedor-oportunidades');
const io = ('IntersectionObserver' in window) ? new IntersectionObserver(async entries => {
  for (const e of entries) {
    if (!e.isIntersecting) continue;
    io.unobserve(e.target);
    await cargarOportunidades(e.target.dataset.src);
  }
}, { rootMargin: '200px' }) : null;

async function cargarOportunidades(url){
  try{
    const r = await fetch(url, { headers:{'X-Requested-With':'fetch','Accept':'text/html'} });
    const html = await r.text();
    cont.innerHTML = html.trim() ? html : '<div class="col-span-full text-center text-sm text-gray-500">No hay resultados.</div>';
    cont.classList.add('compact-root');
  }catch{
    cont.innerHTML = '<div class="col-span-full text-center text-sm text-gray-500">No se pudo cargar contenido.</div>';
  }
}

if (io) io.observe(cont); else cargarOportunidades(cont.dataset.src);
</script>
<script>
async function actualizarBadgeChats() {
  try {
    const res = await fetch('/app/contar_mensajes_nuevos.php', { cache: 'no-store' });
    if (!res.ok) return;

    const data = await res.json();
    const total = parseInt(data.total || 0);

    const badgeSidebar = document.getElementById('badge-chats-sidebar');
    const badgeBottom  = document.getElementById('badge-chats-bottom');

    [badgeSidebar, badgeBottom].forEach(badge => {
      if (!badge) return;
      if (total > 0) {
        badge.textContent = total;
        badge.classList.remove('hidden');
      } else {
        badge.classList.add('hidden');
      }
    });
  } catch (err) {
    console.error('Error al actualizar badge:', err);
  }
}

// 🔁 Actualiza cada 10 segundos y al cargar
setInterval(actualizarBadgeChats, 10000);
document.addEventListener('DOMContentLoaded', actualizarBadgeChats);
</script>

<script>
function abrirMisChats() {
  const url = "/app/mis_chats.php";
  const ancho = 440;
  const alto = 640;
  const left = (screen.width / 2) - (ancho / 2);
  const top = (screen.height / 2) - (alto / 2);

  const opciones = `
    width=${ancho},
    height=${alto},
    top=${top},
    left=${left},
    resizable=yes,
    scrollbars=yes,
    menubar=no,
    toolbar=no,
    location=no,
    status=no
  `;

  if (window.chatVentana && !window.chatVentana.closed) {
    window.chatVentana.focus();
  } else {
    window.chatVentana = window.open(url, "mis_chats", opciones);
  }
}
</script>

</body>
</html>
