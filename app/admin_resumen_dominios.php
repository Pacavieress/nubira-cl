<?php
session_start();
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'admin') {
    header("Location: login.php");
    exit;
}
require_once 'conexion.php';

// Consulta: usuarios por dominio
$sql = "SELECT dominio, COUNT(*) as total FROM alumnos GROUP BY dominio ORDER BY total DESC";
$res = $conn->query($sql);
$dominios = [];
$total_usuarios = 0;
while ($row = $res->fetch_assoc()) {
    $dominios[] = $row;
    $total_usuarios += $row['total'];
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <title>Resumen por Dominio</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-gray-100 min-h-screen flex">

     
<!-- Sidebar fijo (solo escritorio) -->
<aside class="hidden md:flex md:flex-col fixed top-16 left-0 h-[calc(100%-4rem)] w-64 
             bg-white border-r border-gray-200 shadow-lg z-30">
  <div class="p-6">
    <nav class="flex flex-col space-y-3">
            <a href="/vitrina" class="text-gray-700 hover:text-[#54A6D8]">🏠 Inicio</a>
      <a href="/dashboard" class="text-gray-700 hover:text-[#54A6D8]">⚙️ Perfil</a>
<a href="/vitrina-apuntes" class="text-gray-700 hover:text-[#54A6D8]">📘 Explorar Apuntes</a>
<a href="/clases-servicios" class="text-gray-700 hover:text-[#54A6D8]">🧑‍🏫 Explorar Servicios</a>
<a href="/oportunidades" class="text-gray-700 hover:text-[#54A6D8]">🎯 Explorar Oportunidades</a>
    </nav>
  </div>
</aside>

  <!-- HEADER -->
 <header class="fixed top-0 left-0 right-0 z-40 bg-gradient-to-b from-[#C8E8F8]/80 to-white border-b border-white">
  <div class="max-w-6xl mx-auto flex items-center justify-start h-16 px-4">
    <span class="text-[#54A6D8] font-bold text-lg">Resumen de Usuarios por Dominio</span>
  </div>
</header>

  <!-- CONTENIDO PRINCIPAL -->
<main class="flex-1 flex flex-col items-center justify-start pt-20 pb-10 px-4">
    <div class="w-full max-w-2xl bg-white rounded-xl shadow p-8">
      
      
      <!-- Contador General con botón -->
      <div class="flex items-center justify-between mb-6">
        <span class="text-gray-500 text-lg">Total usuarios:</span>
        <span class="text-4xl font-extrabold text-green-600 tracking-widest" id="contadorAnimado" aria-live="polite"><?= $total_usuarios ?></span>
        <a href="/contador_global" target="_blank"
           class="ml-4 bg-pink-600 hover:bg-pink-700 text-white px-4 py-2 rounded font-bold text-sm shadow transition flex items-center gap-1"
           title="Abrir contador a pantalla completa">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M4 8V6a2 2 0 012-2h2m8 0h2a2 2 0 012 2v2m0 8v2a2 2 0 01-2 2h-2m-8 0H6a2 2 0 01-2-2v-2" />
          </svg>
          Pantalla completa
        </a>
      </div>

      <!-- Tabla resumen -->
      <div class="overflow-x-auto mb-8">
        <table class="w-full text-sm border">
          <thead>
            <tr class="bg-blue-50">
              <th class="py-2 px-3 text-left">Dominio</th>
              <th class="py-2 px-3 text-right">Usuarios</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($dominios as $d): ?>
              <tr>
                <td class="py-1 px-3"><?= htmlspecialchars($d['dominio']) ?></td>
                <td class="py-1 px-3 text-right"><?= $d['total'] ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Gráfico -->
      <div class="h-64">
        <canvas id="graficoDominios"></canvas>
      </div>
    </div>
  </main>

 <!-- Bottom nav (móvil) -->
<nav class="md:hidden fixed bottom-0 left-0 right-0 z-40 bg-gradient-to-t from-[#C8E8F8]/80 to-white border-t">
  <ul class="grid grid-cols-4 text-xs text-gray-600 text-center">
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
      <!-- Explora abre popup -->
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

<!-- Modal "Explora" -->
<div id="modal-explora" class="hidden fixed inset-0 z-50 flex items-end md:items-center justify-center bg-black/20">
  <div id="explora-card"
       class="bg-white rounded-2xl shadow-xl border mx-4 p-4 w-full sm:w-[420px] mb-20 md:mb-0
              opacity-0 translate-y-3 transition duration-150">
    <div class="relative">
      <button id="explora-close" class="absolute -top-2 -right-2 hit-48 rounded-full hover:bg-gray-100" aria-label="Cerrar">
        <svg class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path d="M6 18L18 6M6 6l12 12" stroke-linecap="round"/>
        </svg>
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

  <!-- Popup rápido (publicar) -->
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

  <script>
    const passiveOpts = { passive:true };

    // Drawer (solo escritorio)
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
      document.addEventListener('keydown', (e)=>{ if(e.key==='Escape') close(); }, passiveOpts);
    })();

    // Popup rápido: abrir con "Publicar" (móvil/desktop)
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

  <script>
    // Animación contador
    const final = <?= $total_usuarios ?>;
    let current = 0, speed = Math.max(10, Math.floor(2000 / final));
    const el = document.getElementById('contadorAnimado');
    function animate() {
      current += Math.ceil((final - current) / 5);
      if (current > final) current = final;
      el.textContent = current.toLocaleString('es-CL');
      if (current < final) setTimeout(animate, speed);
    }
    animate();

    // Gráfico
    const labels = <?= json_encode(array_column($dominios, 'dominio')) ?>;
    const data = <?= json_encode(array_column($dominios, 'total')) ?>;
    const colors = ['#54A6D8','#60A5FA','#FBBF24','#34D399','#F87171','#A78BFA','#F472B6','#10B981'];

    const ctx = document.getElementById('graficoDominios').getContext('2d');
    new Chart(ctx, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          label: 'Usuarios por dominio',
          data: data,
          backgroundColor: colors.slice(0, labels.length)
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false }},
        scales: {
          y: { beginAtZero: true, ticks: { stepSize: 1 } }
        }
      }
    });
  </script>
</body>
</html>
