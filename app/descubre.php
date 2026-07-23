<?php
session_start();
if (!isset($_SESSION['usuario_id'])) { header('Location: /login'); exit; }
require_once __DIR__ . '/conexion.php';

$csrf = bin2hex(random_bytes(16));
$_SESSION['csrf_descubre'] = $csrf;
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <?php require_once __DIR__ . '/helpers/seo.php'; echo nubira_seo_meta('Descubre tutores y apuntes destacados | Nubira Chile', 'Explora los tutores y apuntes más populares de la comunidad universitaria chilena. Filtra por carrera, universidad y materia.'); ?>
  <?php require_once __DIR__ . '/helpers/seo.php'; echo nubira_canonical_tag(); ?>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
  <script src="https://cdn.tailwindcss.com"></script>

  <style>
    /* Fondo y scroll como vitrina */
    body { overflow-x: hidden; }
    .deck {
      position: relative;
      width: 100%;
      max-width: 380px;     /* ancho carta */
      height: 520px;        /* alto zona de juego */
      margin: 0 auto;
      touch-action: pan-y;
    }
    .card {
      position: absolute; inset: 0;
      background: white; border-radius: 16px;
      box-shadow: 0 10px 30px rgba(0,0,0,.12);
      border: 1px solid rgba(0,0,0,.06);
      will-change: transform, opacity;
      transition: transform .25s ease, opacity .25s ease;
      user-select: none;
    }
    .card img { width: 100%; height: 190px; object-fit: cover; display: block; }
    .like, .nope {
      position: absolute; top: 12px; padding: 6px 10px; border-radius: 999px;
      font-size: 12px; font-weight: 700; letter-spacing:.2px;
      transform: rotate(-12deg); opacity: 0; transition: opacity .15s ease;
    }
    .like { left: 12px; background: rgba(16,185,129,.9); color: #fff; }
    .nope { right: 12px; background: rgba(239,68,68,.9); color: #fff; transform: rotate(12deg); }
    .card.dragging { transition: none; cursor: grabbing; }
    .ghost { pointer-events: none; opacity: .4; }
  </style>
</head>
<body class="bg-gradient-to-tr from-blue-100 via-purple-100 to-green-100 text-gray-800">

  <!-- Overlay sidebar -->
  <div id="sidebar-overlay" class="fixed inset-0 bg-black bg-opacity-40 z-40 hidden"></div>

  <div id="container" class="relative flex min-h-screen">
    <!-- Sidebar -->
    <aside id="sidebar" aria-label="Menú"
           class="fixed top-0 left-0 z-50 w-64 h-full bg-white border-r p-6 transform -translate-x-full transition-transform duration-200 flex flex-col justify-between">
      <div>
        <h2 class="text-2xl font-extrabold mb-4 text-blue-700">Menú</h2>
        <ul class="space-y-3 text-sm mb-6">
          <li><a href="/vitrina" class="flex items-center gap-2 text-blue-700 font-semibold hover:underline">
            <i class="fa-solid fa-house w-5"></i> Inicio</a></li>
          <li><a href="/descubre.php" class="flex items-center gap-2 text-gray-700 hover:text-blue-700 hover:underline">
            <i class="fa-solid fa-compass w-5 text-blue-700"></i> Descubre</a></li>
        </ul>
      </div>
      <div class="mt-auto">
        <hr class="my-4 border-t border-gray-200">
        <a href="/soporte" class="flex items-center gap-2 text-blue-600 hover:underline"><i class="fa-regular fa-life-ring w-5"></i> Soporte</a>
        <a href="/logout" class="flex items-center gap-2 text-red-500 hover:text-red-600 mt-3"><i class="fa-solid fa-right-from-bracket w-5"></i> Cerrar sesión</a>
      </div>
      <button id="closeSidebar" class="absolute top-4 right-4 text-gray-600 hover:text-gray-900"><i class="fa-solid fa-xmark"></i></button>
    </aside>

    <!-- Main -->
    <main class="flex-1 px-2 sm:px-6 py-6 w-full">
      <!-- Filtros sticky -->
      <form id="filtros"
            class="sticky top-0 z-30 bg-white/80 backdrop-blur py-2 px-2 sm:px-4 flex flex-wrap items-center rounded-lg shadow-sm border mb-6"
            autocomplete="off">
        <button type="button" id="openSidebar" class="p-3 bg-white rounded-full shadow-lg mr-2">
          <i class="fa-solid fa-bars text-blue-700"></i>
        </button>
        <span class="text-2xl font-extrabold text-blue-700 ml-1 mr-6">Descubre</span>

        <div class="flex flex-wrap gap-2 sm:gap-4 items-center ml-auto">
          <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
          <input type="text" name="asignatura" placeholder="Asignatura (ej: Cálculo)"
                 class="border rounded px-3 py-2 min-w-[160px]" />
          <select name="semestre" class="border rounded px-3 py-2 w-36">
            <option value="">Semestre</option>
            <?php for($i=1;$i<=10;$i++): ?><option value="<?= $i ?>"><?= $i ?>°</option><?php endfor; ?>
          </select>
          <label class="inline-flex items-center gap-2 text-sm">
            <input type="checkbox" name="tipos[]" value="apunte" checked class="rounded border-gray-300"> Apuntes
          </label>
          <label class="inline-flex items-center gap-2 text-sm">
            <input type="checkbox" name="tipos[]" value="servicio" checked class="rounded border-gray-300"> Servicios
          </label>
          <label class="inline-flex items-center gap-2 text-sm">
            <input type="checkbox" name="tipos[]" value="oportunidad" checked class="rounded border-gray-300"> Oportunidades
          </label>
          <button type="submit" class="bg-blue-600 text-white rounded-lg px-4 py-2 text-sm hover:bg-blue-700">
            Aplicar filtros
          </button>
        </div>
        <p class="w-full mt-2 text-xs text-gray-500">Tip: ← (no me interesa) · → / Enter (me interesa).</p>
      </form>

      <!-- Zona de juego (deck) -->
      <div class="deck" id="deck">
        <!-- Las cards se inyectan por JS -->
      </div>

      <!-- Acciones globales (desktop) -->
      <div class="mt-6 hidden md:flex items-center justify-center gap-3">
        <button id="btnNo"  class="px-6 py-3 rounded-full border text-sm hover:bg-gray-100">❌ Pasar</button>
        <button id="btnSi"  class="px-6 py-3 rounded-full bg-pink-600 text-white text-sm hover:bg-pink-700">✅ Me interesa</button>
      </div>

      <div id="estado" class="mt-6 text-center text-sm text-gray-600">Cargando sugerencias…</div>
      <div class="mt-3 text-center"><button id="btnMas" class="hidden text-blue-600 hover:underline text-sm">Cargar más</button></div>
    </main>
  </div>

  <template id="tplCard">
    <div class="card">
      <span class="like">ME INTERESA</span>
      <span class="nope">PASAR</span>
      <img alt="">
      <div class="p-4">
        <div class="flex items-center gap-2 mb-2">
          <span class="tipo text-xs px-2 py-1 rounded-full bg-gray-900/80 text-white capitalize"></span>
          <span class="badge-premium hidden text-xs px-2 py-1 rounded-full bg-yellow-400 text-gray-900 font-semibold">Premium</span>
        </div>
        <h3 class="titulo text-lg font-semibold mb-1"></h3>
        <p class="descripcion text-sm text-gray-600 line-clamp-3"></p>
        <div class="mt-3 flex items-center justify-between">
          <div class="text-xs text-gray-500 info"></div>
          <a class="btn-desbloquear hidden text-xs bg-blue-600 text-white rounded-lg px-3 py-1 hover:bg-blue-700">Desbloquear</a>
        </div>
        <!-- Botones móviles -->
        <div class="mt-4 flex items-center justify-center gap-3 md:hidden">
          <button class="btn-no px-4 py-2 rounded-full border text-sm hover:bg-gray-100">❌ Pasar</button>
          <button class="btn-si px-4 py-2 rounded-full bg-pink-600 text-white text-sm hover:bg-pink-700">✅ Me interesa</button>
        </div>
      </div>
    </div>
  </template>

  <script>
    // Sidebar
    const container = document.getElementById('container'),
          openBtn = document.getElementById('openSidebar'),
          closeBtn = document.getElementById('closeSidebar'),
          sidebar = document.getElementById('sidebar'),
          overlay = document.getElementById('sidebar-overlay');
    openBtn.addEventListener('click', ()=>{ sidebar.classList.remove('-translate-x-full'); container.classList.add('ml-64'); overlay.classList.remove('hidden'); document.body.style.overflow='hidden'; openBtn.classList.add('hidden'); });
    closeBtn.addEventListener('click', ()=>{ sidebar.classList.add('-translate-x-full'); container.classList.remove('ml-64'); overlay.classList.add('hidden'); document.body.style.overflow=''; openBtn.classList.remove('hidden'); });
    overlay.addEventListener('click', ()=> closeBtn.click());

    // Estado
    let page=1, loading=false;
    const deck = document.getElementById('deck');
    const tpl = document.getElementById('tplCard');
    const estado = document.getElementById('estado');

    function setEstado(msg, show=true){ estado.textContent = msg||''; estado.classList.toggle('hidden', !show); }
    function fmtCL(n){ return Number(n).toLocaleString('es-CL'); }

    async function cargar(p=1){
      if(loading) return; loading=true; setEstado('Cargando sugerencias…', true); document.getElementById('btnMas').classList.add('hidden');
      const form = new FormData(document.getElementById('filtros')); form.append('pagina', p);
      try{
        const r = await fetch('/app/cargar_descubre.php', { method:'POST', body: form });
        const data = await r.json();
        if(!Array.isArray(data) || data.length===0){
          setEstado('No hay más sugerencias. Vuelve luego ✨', true);
          return;
        }
        setEstado('', false);
        render(data);
        document.getElementById('btnMas').classList.remove('hidden');
      }catch(e){ console.error(e); setEstado('Error cargando sugerencias.', true); }
      finally{ loading=false; }
    }

    function render(items){
      // Apilamos: la última agregada queda abajo
      items.forEach(it=>{
        const node = tpl.content.cloneNode(true);
        const card = node.querySelector('.card');
        card.dataset.id   = it.id;
        card.dataset.tipo = it.tipo;

        const img = node.querySelector('img');
        img.src = it.imagen || 'https://picsum.photos/800/480';
        img.alt = it.titulo || '';

        node.querySelector('.tipo').textContent = it.tipo;
        if(it.premium){
          node.querySelector('.badge-premium').classList.remove('hidden');
          const btnDesb = node.querySelector('.btn-desbloquear');
          btnDesb.href = it.url_desbloqueo || (`/pago/desbloquear.php?tipo=${encodeURIComponent(it.tipo)}&id=${encodeURIComponent(it.id)}`);
          btnDesb.classList.remove('hidden');
          btnDesb.textContent = it.precio ? `Desbloquear $${fmtCL(it.precio)}` : 'Desbloquear';
        }
        node.querySelector('.titulo').textContent = it.titulo || '';
        node.querySelector('.descripcion').textContent = it.descripcion || '';
        node.querySelector('.info').textContent = [it.institucion, it.asignatura, it.semestre ? `Sem ${it.semestre}`:''].filter(Boolean).join(' • ');

        // Botones móviles
        node.querySelector('.btn-no').addEventListener('click', ()=> swipe(card, 'left'));
        node.querySelector('.btn-si').addEventListener('click', ()=> swipe(card, 'right'));

        // Inserta arriba del stack (quedará visible la última)
        deck.prepend(node);
      });

      // Inicializa drag en las 3 primeras cartas (performance)
      setupTopCards();
    }

    function setupTopCards(){
      const cards = Array.from(deck.querySelectorAll('.card'));
      cards.forEach((c, idx)=>{
        c.style.zIndex = 100 - idx;
        if(idx < 3 && !c.dataset.ready){
          makeDraggable(c);
          c.dataset.ready = '1';
        }
      });
    }

    // ---- Drag & Swipe sin librerías ----
    const THRESHOLD = 90;   // px para decidir swipe
    const ROTATION  = 18;   // grados máx rotación

    function makeDraggable(card){
      let startX=0, startY=0, x=0, y=0, dragging=false;

      const like = card.querySelector('.like');
      const nope = card.querySelector('.nope');

      function onDown(e){
        const p = point(e);
        dragging = true; startX = p.x; startY = p.y; card.classList.add('dragging');
      }
      function onMove(e){
        if(!dragging) return;
        const p = point(e);
        x = p.x - startX; y = p.y - startY;
        const rot = (x / deck.clientWidth) * ROTATION;
        card.style.transform = `translate(${x}px, ${y}px) rotate(${rot}deg)`;
        like.style.opacity = x > 30 ? Math.min((x-30)/80, 1) : 0;
        nope.style.opacity = x < -30 ? Math.min((-x-30)/80, 1) : 0;
      }
      function onUp(){
        if(!dragging) return; dragging=false; card.classList.remove('dragging');

        if(Math.abs(x) > THRESHOLD){
          swipe(card, x>0 ? 'right' : 'left'); // dispara acción
        }else{
          // volver al centro
          card.style.transform = '';
          like.style.opacity = 0; nope.style.opacity = 0;
        }
        x=0; y=0;
      }

      card.addEventListener('pointerdown', onDown);
      window.addEventListener('pointermove', onMove);
      window.addEventListener('pointerup', onUp);
    }

    function point(e){ return { x: e.clientX ?? (e.touches?.[0]?.clientX || 0), y: e.clientY ?? (e.touches?.[0]?.clientY || 0) }; }

    function swipe(card, dir){
      const toX = (dir==='right' ? deck.clientWidth*1.2 : -deck.clientWidth*1.2);
      card.classList.add('ghost');
      card.style.transform = `translate(${toX}px, -40px) rotate(${dir==='right'?15:-15}deg)`;
      // Guardar match
      const accion = (dir==='right') ? 'like' : 'dislike';
      guardar(card.dataset.id, card.dataset.tipo, accion);
      // Remover y preparar la siguiente
      setTimeout(()=>{ card.remove(); setupTopCards(); if(deck.children.length===0){ setEstado('No hay más sugerencias. Vuelve luego ✨', true); } }, 220);
    }

    async function guardar(id, tipo, accion){
      try{
        const form = new URLSearchParams();
        form.set('csrf', '<?php echo $csrf; ?>');
        form.set('id_item', id); form.set('tipo', tipo); form.set('accion', accion);
        await fetch('/app/guardar_match.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: form.toString() });
      }catch(e){ console.error('guardar_match', e); }
    }

    // Acciones globales (desktop)
    document.getElementById('btnSi').addEventListener('click', ()=>{
      const top = deck.querySelector('.card'); if(top) swipe(top, 'right');
    });
    document.getElementById('btnNo').addEventListener('click', ()=>{
      const top = deck.querySelector('.card'); if(top) swipe(top, 'left');
    });

    // Teclas rápidas
    window.addEventListener('keydown', (e)=>{
      const top = deck.querySelector('.card'); if(!top) return;
      if(e.key==='ArrowLeft'){ swipe(top, 'left'); }
      if(e.key==='ArrowRight' || e.key==='Enter'){ swipe(top, 'right'); }
    });

    // Filtros
    document.getElementById('filtros').addEventListener('submit', (e)=>{
      e.preventDefault();
      deck.innerHTML = ''; page=1; cargar(page);
    });
    document.getElementById('btnMas').addEventListener('click', ()=>{ page++; cargar(page); });

    // Arranque
    cargar(page);
  </script>
</body>
</html>
