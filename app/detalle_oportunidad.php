<?php
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header('Location: /login');
    exit;
}
require_once __DIR__ . '/../app/conexion.php';

// Obtener datos de la oportunidad
$id = intval($_GET['id'] ?? 0);
$stmt = $conn->prepare("SELECT * FROM oportunidades WHERE id=?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$o = $result->fetch_assoc();
if (!$o) {
    echo "<p class='p-4 text-center'>Oportunidad no encontrada.</p>";
    exit;
}
$stmt->close();

// Sidebar variables
$usuario_id = $_SESSION['usuario_id'];

// Limitar largo de campos para vista (visual, no en base de datos)
function limitar_texto($txt, $largo = 50) {
    if (mb_strlen($txt) > $largo) {
        return htmlspecialchars(mb_substr($txt, 0, $largo - 3)) . "...";
    }
    return htmlspecialchars($txt);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <?php
    require_once __DIR__ . '/helpers/seo.php';
    $op_title = $o['titulo'] . ' | Oportunidades Nubira';
    $op_desc  = mb_strimwidth(trim(strip_tags($o['descripcion'] ?? '')), 0, 155, '...', 'UTF-8');
    echo nubira_seo_meta($op_title, $op_desc);
  ?>
  <?php require_once __DIR__ . '/helpers/seo.php'; echo nubira_canonical_tag(); ?>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-gray-50">
    
    

  <div id="loader" class="fixed inset-0 flex items-center justify-center bg-white z-50">
    <div class="animate-spin rounded-full h-12 w-12 border-t-4 border-blue-600"></div>
  </div>


 <aside class="hidden md:flex md:flex-col fixed top-16 left-0 h-[calc(100%-4rem)] w-64 
             bg-white border-r border-gray-200 shadow-lg z-30">
  <div class="p-6">
    <nav class="flex flex-col space-y-3">
      <a href="/vitrina" class="text-gray-700 hover:text-[#54A6D8]">🏠 Inicio</a>
      <a href="/dashboard" class="text-gray-700 hover:text-[#54A6D8]">⚙️ Perfil</a>
<a href="/vitrina-apuntes" class="text-gray-700 hover:text-[#54A6D8]">📘 Explorar Apuntes</a>
<a href="/clases-servicios" class="text-gray-700 hover:text-[#54A6D8]">🧑‍🏫 Explorar Servicios</a>
<!-- 💬 Mis Chats con badge -->
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
    <span class="text-[#54A6D8] font-bold text-lg">Detalle Oportunidad</span>
  </div>
</header>


<main id="contenido" class="hidden flex-1 px-2 sm:px-10 py-8 lg:ml-64 overflow-x-hidden">
  <div class="w-full max-w-6xl mx-auto bg-white rounded-2xl shadow p-10 space-y-8">

    


      <div class="w-full h-96 bg-gray-100 rounded-md overflow-hidden">
      <img src="<?= $o['imagen'] && file_exists(__DIR__ . '/../upload/oportunidades/' . $o['imagen'])
    ? '/upload/oportunidades/' . htmlspecialchars($o['imagen'])
    : '/upload/oportunidades/default.webp' ?>"

             alt="<?= limitar_texto($o['titulo'], 60) ?>" class="object-contain w-full h-full" />
      </div>


      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm text-gray-700">
        <div>
          <span class="font-semibold">Título:</span>
          <span class="break-all block max-w-full truncate" title="<?= htmlspecialchars($o['titulo']) ?>">
            <?= limitar_texto($o['titulo'], 60) ?>
          </span>
        </div>
        <div>
          <span class="font-semibold">Tipo:</span>
          <span><?= htmlspecialchars($o['tipo']) ?></span>
        </div>
        <div>
          <span class="font-semibold">Organizador:</span>
          <span class="break-all block max-w-full truncate" title="<?= htmlspecialchars($o['organizador']) ?>">
            <?= limitar_texto($o['organizador'], 40) ?>
          </span>
        </div>
        <div>
          <span class="font-semibold">Enlace:</span>
          <?php if ($o['enlace']) : ?>
            <a href="<?= htmlspecialchars($o['enlace']) ?>" target="_blank" rel="noopener"
               class="break-all text-blue-700 underline block max-w-full truncate"
               title="<?= htmlspecialchars($o['enlace']) ?>">
              <?= limitar_texto($o['enlace'], 60) ?>
            </a>
          <?php else: ?>
            <span class="text-gray-400">—</span>
          <?php endif; ?>
        </div>
        <div>
          <span class="font-semibold">Fecha Inicio:</span>
          <span><?= htmlspecialchars($o['fecha_inicio']) ?></span>
        </div>
        <div>
          <span class="font-semibold">Fecha Término:</span>
          <span><?= htmlspecialchars($o['fecha_termino']) ?></span>
        </div>
        <div>
          <span class="font-semibold">Aprobado:</span>
          <span><?= $o['aprobado'] ? 'Sí' : 'No' ?></span>
        </div>
      </div>

      <div>
        <h3 class="font-semibold text-lg mb-2">Descripción</h3>
        <div class="text-gray-700 whitespace-pre-line break-words">
          <?= nl2br(htmlspecialchars($o['descripcion'])) ?>
        </div>
      </div>
    </div>
  </main>

  <nav class="md:hidden fixed bottom-0 left-0 right-0 z-40 bg-gradient-to-t from-[#C8E8F8]/80 to-white border-t safe-pb">
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
    <!-- 🔵 Badge dinámico -->
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


  <div id="modal-soporte" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50" aria-hidden="true">
    <div class="bg-white p-6 rounded-lg shadow-lg w-full max-w-md mx-4">
      <h2 class="text-xl font-bold mb-4">Contacto de Soporte</h2>
      <form action="/enviar-soporte" method="POST">
        <label for="msg-soporte" class="sr-only">Mensaje para soporte</label>
        <textarea id="msg-soporte" name="mensaje" rows="4" required class="w-full border rounded px-3 py-2 mb-4" placeholder="Escribe tu consulta..."></textarea>
        <div class="flex justify-end gap-2">
          <button type="button" onclick="document.getElementById('modal-soporte').classList.add('hidden')" class="px-4 py-2 bg-gray-200 rounded text-sm">Cancelar</button>
          <button type="submit" class="px-4 py-2 bg-[#54A6D8] text-white rounded text-sm">Enviar</button>
        </div>
      </form>
    </div>
  </div>


  <script>
    const passiveOpts = { passive:true };


    window.addEventListener('load', () => {
      setTimeout(() => {
        document.getElementById('loader').classList.add('hidden');
        document.getElementById('contenido').classList.remove('hidden');
        const alerta = document.getElementById('alerta-form');
        if (alerta) alerta.focus();
      }, 300);
    });


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


    function limitInput(input, max, counterId) {
      if (!input) return;
      const counter = document.getElementById(counterId);
      const update = () => {
        const len = input.value.length;
        if (len > max) input.value = input.value.slice(0, max);
        if (counter) counter.textContent = input.value.length + '/' + max;
      };
      input.addEventListener('input', update);
      update();
    }
    document.addEventListener('DOMContentLoaded', function() {
      limitInput(document.getElementById('titulo'), 80, 'titulo-count');
      limitInput(document.getElementById('sigla'), 30, 'sigla-count');
      limitInput(document.getElementById('descripcion'), 300, 'descripcion-count');
    });


    (function(){
      const inputArchivo = document.getElementById('archivo');
      const previewWrap  = document.getElementById('preview');
      const previewCt    = document.getElementById('previewContent');
      const allowedExt = ['pdf','doc','docx','xls','xlsx','ppt','pptx','txt','rtf','odt','jpg','jpeg','png','gif','bmp','svg'];
      const imgExt     = ['jpg','jpeg','png','gif','bmp','svg'];

      inputArchivo?.addEventListener('change', (e) => {
        const f = e.target.files && e.target.files[0];
        previewCt.innerHTML = '';
        previewWrap.classList.add('hidden');
        if (!f) return;

        if (f.size > 20*1024*1024) { alert("Archivo demasiado grande (máx. 20 MB)."); e.target.value = ""; return; }
        const ext = (f.name.split('.').pop() || '').toLowerCase();
        if (!allowedExt.includes(ext)) { alert("Tipo de archivo no permitido."); e.target.value = ""; return; }

        const url = URL.createObjectURL(f);
        if (imgExt.includes(ext)) {
          const img = document.createElement('img');
          img.src = url; img.alt = "Vista previa de la imagen";
          img.className = "max-h-64 rounded border";
          previewCt.appendChild(img);
        } else if (ext === 'pdf') {
          const embed = document.createElement('embed');
          embed.src = url + "#toolbar=0&navpanes=0&scrollbar=0";
          embed.type = "application/pdf";
          embed.className = "w-full h-64 border rounded";
          previewCt.appendChild(embed);
        } else {
          const p = document.createElement('p');
          p.textContent = "Archivo seleccionado: " + f.name;
          p.className = "text-sm text-gray-700";
          previewCt.appendChild(p);
        }
        previewWrap.classList.remove('hidden');
      });
    })();

    // Draft localStorage
    (function setupDraft() {
      const form = document.getElementById('form-apunte');
      if (!form) return;

      const uid = "<?php echo (int)($_SESSION['usuario_id'] ?? 0); ?>";
      const DRAFT_KEY = `nubira_subir_apunte_draft_v1_user_${uid}`;

      const fields = {
        titulo: document.getElementById('titulo'),
        sigla: document.getElementById('sigla'),
        descripcion: document.getElementById('descripcion'),
        semestre: document.getElementById('semestre'),
        anio: document.getElementById('anio'),
        precio: document.getElementById('precio')
      };

      try {
        const raw = localStorage.getItem(DRAFT_KEY);
        if (raw) {
          const data = JSON.parse(raw);
          Object.keys(fields).forEach(k => { if (fields[k] && data[k] !== undefined) fields[k].value = data[k]; });
        }
      } catch(e){}

      const save = () => {
        const data = {};
        Object.keys(fields).forEach(k => { if (fields[k]) data[k] = fields[k].value; });
        try { localStorage.setItem(DRAFT_KEY, JSON.stringify(data)); } catch(e){}
      };

      let t = null;
      Object.values(fields).forEach(el => {
        el?.addEventListener('input', () => { clearTimeout(t); t = setTimeout(save, 250); });
      });

      const exito = form.getAttribute('data-exito') === '1';
      if (exito) { try { localStorage.removeItem(DRAFT_KEY); } catch(e){} }
    })();

    // Prevenir doble submit
    (function preventDoubleSubmit(){
      const form = document.getElementById('form-apunte');
      const btn  = document.getElementById('btn-submit');
      const iconOk = document.getElementById('icon-ok');
      const iconSpin = document.getElementById('icon-spin');
      const btnText = document.getElementById('btn-text');

      if (!form || !btn) return;

      form.addEventListener('submit', () => {
        const fileOk = document.getElementById('archivo')?.files?.length > 0;
        const titulo = document.getElementById('titulo')?.value.trim();
        const sigla  = document.getElementById('sigla')?.value.trim();

        if (!fileOk || !titulo || !sigla) { return; }

        btn.disabled = true;
        iconOk.classList.add('hidden');
        iconSpin.classList.remove('hidden');
        btnText.textContent = "Publicando...";
      });
    })();

    // Modal "Explora"
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

    // Popup rápido (Publicar)
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
