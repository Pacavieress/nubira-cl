<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['usuario_id'])) {
    header('Location: /login');
    exit;
}

require_once __DIR__ . '/conexion.php';

// --- Santiago ---
date_default_timezone_set('America/Santiago');

// --- CSRF token ---
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token     = $_SESSION['csrf_token'];
$usuario_id     = (int)($_SESSION['usuario_id'] ?? 0);
$institucion    = $_SESSION['institucion'] ?? '';
$mensaje        = '';
$id_oportunidad = isset($_GET['id']) ? intval($_GET['id']) : null;

// Valores por defecto
$titulo=$descripcion=$tipo=$fecha_inicio=$fecha_termino='';
$organizador=$enlace='';
$estado='Borrador';
$pagado=0; $aprobado=0;
$imagen_subida='';
$defaultImage='default.png';

// --- Carga para edición ---
if ($id_oportunidad && !isset($_GET['ok'])) {
    $stmt = $conn->prepare("
        SELECT titulo, descripcion, tipo, fecha_inicio, fecha_termino,
               organizador, enlace, imagen, estado, aprobado
          FROM oportunidades
         WHERE id=? AND usuario_id=?
    ");
    $stmt->bind_param("ii", $id_oportunidad, $usuario_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: die("❌ Sin permiso o no existe.");
    foreach (['titulo','descripcion','tipo','fecha_inicio','fecha_termino','organizador','enlace','imagen','estado','aprobado'] as $f) {
        ${$f} = $row[$f];
    }
    $imagen_subida = $imagen ?: $defaultImage;
    $stmt->close();
}

// --- Procesamiento ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        die("❌ Error CSRF");
    }
    // Limitar por seguridad
    $titulo        = mb_substr(trim($_POST['titulo'] ?? ''), 0, 80);
    $descripcion   = mb_substr(trim($_POST['descripcion'] ?? ''), 0, 500);
    $tipo          = trim($_POST['tipo'] ?? '');
    $fecha_inicio  = $_POST['fecha_inicio'] ?? '';
    $fecha_termino = $_POST['fecha_termino'] ?? '';
    $organizador   = mb_substr(trim($_POST['organizador'] ?? ''), 0, 60);
    $enlace        = mb_substr(trim($_POST['enlace'] ?? ''), 0, 250);

    $errores = [];
    if (!$titulo || !$descripcion || !$tipo || !$fecha_inicio || !$fecha_termino || !$organizador) {
        $errores[] = 'Completa todos los campos obligatorios.';
    }
    if ($fecha_inicio && $fecha_termino && $fecha_termino < $fecha_inicio) {
        $errores[] = 'Fecha término anterior a inicio.';
    }

    // Imagen upload
    if (!empty($_FILES['imagen']['name'])) {
        $info = @getimagesize($_FILES['imagen']['tmp_name']);
        if (!$info) {
            $errores[] = 'No es una imagen válida.';
        } else {
            [$w,$h] = $info;
            if ($w < 800 || $h < 600) {
                $errores[] = "La imagen debe ser mínimo 800×600 px (actual: {$w}×{$h}).";
            }
        }
        $ext = strtolower(pathinfo($_FILES['imagen']['name'], PATHINFO_EXTENSION));
        $permit = ['jpg','jpeg','png','gif','webp'];
        if (!in_array($ext, $permit)) {
            $errores[] = 'Formato no permitido (usa JPG, PNG, GIF o WEBP).';
        }
        if ($_FILES['imagen']['size'] > 2 * 1024 * 1024) {
            $errores[] = 'Máx 2 MB.';
        }
        if (empty($errores)) {
            $dir = __DIR__ . '/../upload/oportunidades';
            if (!is_dir($dir) && !mkdir($dir,0755,true)) $errores[] = 'Error creando carpeta.';
            if (!is_writable($dir)) $errores[] = 'Carpeta no escribible.';
            if (empty($errores)) {
                $file = uniqid('oport_') . '.' . $ext;
                if (move_uploaded_file($_FILES['imagen']['tmp_name'], "$dir/$file")) {
                    $imagen_subida = $file;
                } else {
                    $errores[] = 'Error moviendo la imagen.';
                }
            }
        }
    } elseif (!$imagen_subida) {
        $imagen_subida = $defaultImage;
    }

    // Insert/Update
    if (empty($errores)) {
        if ($id_oportunidad && $aprobado===0) {
            $stmt = $conn->prepare("
                UPDATE oportunidades SET
                  titulo=?, descripcion=?, tipo=?, fecha_inicio=?, fecha_termino=?,
                  organizador=?, enlace=?, imagen=?, estado=?
                WHERE id=? AND usuario_id=?
            ");
            $stmt->bind_param(
                'sssssssssii',
                $titulo, $descripcion, $tipo, $fecha_inicio, $fecha_termino,
                $organizador, $enlace, $imagen_subida, $estado,
                $id_oportunidad, $usuario_id
            );
            $stmt->execute();
            $mensaje = $stmt->affected_rows>0
                     ? '✅ Actualizado y pendiente aprobación.'
                     : '❌ Error: '.$stmt->error;
            $stmt->close();
        }
        elseif (!$id_oportunidad) {
            $fecha_publicacion = date('Y-m-d H:i:s');
            $stmt = $conn->prepare("
                INSERT INTO oportunidades
                  (usuario_id,institucion,titulo,descripcion,tipo,
                   fecha_inicio,fecha_termino,organizador,enlace,
                   imagen,estado,pagado,aprobado,fecha_publicacion)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->bind_param(
                'issssssssssiis',
                $usuario_id, $institucion, $titulo, $descripcion, $tipo,
                $fecha_inicio, $fecha_termino, $organizador, $enlace,
                $imagen_subida, $estado, $pagado, $aprobado, $fecha_publicacion
            );
            if ($stmt->execute()) {
                header("Location: /crear-oportunidad?ok=1");
                exit;
            }
            $mensaje = '❌ Error: '.$stmt->error;
            $stmt->close();
        }
        else {
            $mensaje = '❌ No puedes editar aprobado.';
        }
    } else {
        $mensaje = '❌ '. implode('<br>❌ ',$errores);
    }
}

// --- Limpiar formulario si ok=1 ---
if (isset($_GET['ok'])) {
    $mensaje = '✅ ¡Publicado! Pendiente aprobación.';
    $titulo=$descripcion=$tipo=$fecha_inicio=$fecha_termino=$organizador=$enlace='';
    $imagen_subida='';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <title>Publicar Oportunidad</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"/>
</head>
<body class="min-h-screen bg-gray-50">

  <!-- Loader -->
  <div id="loader" class="fixed inset-0 flex items-center justify-center bg-white z-50">
    <div class="animate-spin rounded-full h-12 w-12 border-4 border-t-transparent" style="border-color:#54A6D8"></div>
  </div>

<div id="contenido" class="hidden"><!-- wrapper para loader -->

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

<!-- HEADER -->
<header class="fixed top-0 left-0 right-0 z-40 bg-gradient-to-b from-[#C8E8F8]/80 to-white border-b border-white">
  <div class="max-w-6xl mx-auto flex items-center justify-start h-16 px-4">
    <span class="text-[#54A6D8] font-bold text-lg">Publicar Oportunidad</span>
  </div>
</header>


  <main class="pt-20 pb-28 flex-1 md:ml-64">
  <div class="w-full max-w-3xl mx-auto px-4 md:px-12">
      
    <div class="bg-white border border-gray-200 rounded-lg shadow p-6 sm:p-8">


        <!-- Encabezado -->
        <header class="space-y-1">
          <p class="text-sm text-gray-600">Publica becas, talleres, concursos, voluntariados, prácticas o eventos de tu institución.</p>
        </header>

        <!-- Toast -->
        <?php if ($mensaje): ?>
          <div id="toast" class="fixed top-4 right-4 z-50 border <?= strpos($mensaje,'✅')!==false ? 'bg-green-100 text-green-800 border-green-300' : 'bg-red-100 text-red-800 border-red-300' ?> px-4 py-3 rounded-lg shadow flex items-center gap-2" role="alert" aria-live="assertive">
            <span><?= $mensaje ?></span>
            <button class="ml-2 text-sm underline" onclick="document.getElementById('toast').remove()">Cerrar</button>
          </div>
        <?php endif; ?>

        <!-- Mensaje accesible -->
        <?php if ($mensaje): ?>
          <div class="border <?= strpos($mensaje,'✅')!==false ? 'bg-green-50 text-green-800 border-green-200' : 'bg-red-50 text-red-800 border-red-200' ?> p-4 rounded" role="alert" aria-live="assertive" tabindex="-1" id="alerta-form">
            <?= $mensaje ?>
          </div>
        <?php endif; ?>

        <!-- FORMULARIO -->
        <form id="form-oportunidad" method="POST" enctype="multipart/form-data" class="space-y-10" novalidate autocomplete="off" data-ok="<?= isset($_GET['ok']) ? '1' : '0' ?>">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

          <!-- Información básica -->
          <fieldset class="space-y-6">
            <legend class="text-lg font-semibold text-gray-800">Información básica</legend>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
              <div class="md:col-span-2">
                <label for="titulo" class="font-semibold block">Título <span class="text-red-500">*</span></label>
                <input type="text" name="titulo" id="titulo" maxlength="80" value="<?= htmlspecialchars($titulo) ?>" required class="w-full border px-4 py-3 rounded-lg text-lg focus:ring-2 focus:ring-blue-200">
                <p class="text-xs text-gray-500 text-right" id="titulo-contador">0/80</p>
              </div>
              <div>
                <label for="organizador" class="font-semibold block">Organizador <span class="text-red-500">*</span></label>
                <input type="text" name="organizador" id="organizador" maxlength="60" value="<?= htmlspecialchars($organizador) ?>" required class="w-full border px-4 py-3 rounded-lg text-lg focus:ring-2 focus:ring-blue-200">
                <p class="text-xs text-gray-500 text-right" id="organizador-contador">0/60</p>
              </div>
            </div>

            <div>
              <label for="descripcion" class="font-semibold block">Descripción <span class="text-red-500">*</span></label>
              <textarea id="descripcion" name="descripcion" maxlength="500" rows="6" required class="w-full border px-4 py-3 rounded-lg text-lg resize-none focus:ring-2 focus:ring-blue-200"><?= htmlspecialchars($descripcion) ?></textarea>
              <p class="text-xs text-gray-500 text-right" id="descripcion-contador">0/500</p>
            </div>

            <div>
              <label for="tipo" class="font-semibold block">Tipo <span class="text-red-500">*</span></label>
              <select id="tipo" name="tipo" required class="w-full border px-4 py-3 rounded-lg text-lg focus:ring-2 focus:ring-blue-200">
                <option value="">Selecciona...</option>
                <?php foreach(['Beca','Taller','Concurso','Voluntariado','Práctica','Internado','Evento'] as $opt): ?>
                  <option value="<?= $opt ?>" <?= $tipo === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </fieldset>

        <!-- Fechas y enlace -->
<fieldset class="space-y-6">
  <legend class="text-lg font-semibold text-gray-800">Fechas y enlace</legend>
  <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
    <div>
      <label for="fecha_inicio" class="font-semibold block">Fecha inicio <span class="text-red-500">*</span></label>
      <input id="fecha_inicio" type="date" name="fecha_inicio" value="<?= htmlspecialchars($fecha_inicio) ?>" required class="w-full border px-4 py-3 rounded-lg text-lg focus:ring-2 focus:ring-blue-200">
    </div>
    <div>
      <label for="fecha_termino" class="font-semibold block">Fecha término <span class="text-red-500">*</span></label>
      <input id="fecha_termino" type="date" name="fecha_termino" value="<?= htmlspecialchars($fecha_termino) ?>" required class="w-full border px-4 py-3 rounded-lg text-lg focus:ring-2 focus:ring-blue-200">
    </div>
  </div>
  <div>
    <label for="enlace" class="font-semibold block">Enlace (opcional)</label>
    <input id="enlace" type="url" name="enlace" maxlength="250" value="<?= htmlspecialchars($enlace) ?>" 
           class="w-full border px-4 py-3 rounded-lg text-lg focus:ring-2 focus:ring-blue-200" placeholder="https://...">
    <p class="text-xs text-gray-500 text-right" id="enlace-contador">0/250</p>
  </div>

  <!-- Enlaces extra -->
  <div>
    <label for="enlace2" class="font-semibold block">Enlace adicional 1 (opcional)</label>
    <input id="enlace2" type="url" name="enlace2" maxlength="250" value="" 
           class="w-full border px-4 py-3 rounded-lg text-lg focus:ring-2 focus:ring-blue-200" placeholder="https://...">
  </div>
  <div>
    <label for="enlace3" class="font-semibold block">Enlace adicional 2 (opcional)</label>
    <input id="enlace3" type="url" name="enlace3" maxlength="250" value="" 
           class="w-full border px-4 py-3 rounded-lg text-lg focus:ring-2 focus:ring-blue-200" placeholder="https://...">
  </div>
</fieldset>


          <!-- Imagen y publicación -->
          <fieldset class="space-y-6">
            <legend class="text-lg font-semibold text-gray-800">Imagen y publicación</legend>

            <div>
              <label for="input-imagen" class="font-semibold block">
                Imagen/logo (opcional, máx 2MB)
                <small class="block text-gray-500">Rectangular <b>mínimo 800×600 px</b>, ideal <b>1200×900 px</b>. JPG, PNG, WEBP.</small>
              </label>
              <input type="file" name="imagen" accept="image/*" class="w-full border px-4 py-2 rounded" id="input-imagen">
              <!-- Preview -->
              <div id="preview" class="mt-3 <?= $imagen_subida ? '' : 'hidden' ?>">
                <div class="text-sm text-gray-600 mb-1">Vista previa:</div>
                <div class="border rounded p-2 overflow-hidden">
                  <?php if ($imagen_subida): ?>
                    <img src="/upload/oportunidades/<?= htmlspecialchars($imagen_subida) ?>" alt="Imagen actual" class="max-h-48 rounded">
                  <?php else: ?>
                    <img id="preview-img" class="max-h-48 rounded hidden" alt="Vista previa">
                  <?php endif; ?>
                </div>
              </div>
            </div>

            <div class="flex justify-end">
              <button id="btn-submit" type="submit"
                class="relative flex items-center gap-2 bg-[#54A6D8] text-white font-semibold px-8 py-3 rounded-2xl
                       hover:bg-[#3d91c7] transition duration-200 shadow-md focus:ring-2 focus:ring-[#54A6D8] focus:outline-none disabled:opacity-70 disabled:cursor-not-allowed"
                aria-label="Enviar formulario">
                <svg id="icon-ok" class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2"
                     viewBox="0 0 24 24" aria-hidden="true">
                  <path stroke-linecap="round" d="M5 13l4 4L19 7"></path>
                </svg>
                <svg id="icon-spin" class="w-5 h-5 hidden animate-spin" viewBox="0 0 24 24" aria-hidden="true">
                  <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none" opacity="0.25"></circle>
                  <path d="M4 12a8 8 0 018-8" fill="currentColor"></path>
                </svg>
                <span id="btn-text"><?= $id_oportunidad ? 'Actualizar' : 'Publicar' ?></span>
              </button>
            </div>

            <!-- Alert inline accesible -->
            <div id="alerta" class="hidden bg-red-100 text-red-800 border border-red-300 rounded px-4 py-2" role="alert" aria-live="assertive"></div>
          </fieldset>
        </form>
      </div>
    </main>
  </div>

  <!-- Bottom nav (móvil) -->
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

  <!-- Popup rápido (solo al presionar Publicar) -->
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
  <!-- Scripts -->
  <script>
    // Mostrar contenido
    window.addEventListener('load', ()=>{
      setTimeout(()=>{
        document.getElementById('loader').classList.add('hidden');
        document.getElementById('contenido').classList.remove('hidden');
      }, 350);
    });

    // === Tu script de Explora (tal cual) ===
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

  <!-- JS extra (tal cual pegaste) + scroll infinito -->
  <script>
    const passiveOpts = { passive:true };

    // === Menu sheet (si no existe, no hace nada gracias a ?.) ===
    (function(){
      const openBtn=document.getElementById('openMenu');
      const closeBtn=document.getElementById('closeMenu');
      const sheet=document.getElementById('sheet-menu');
      const overlay=document.getElementById('sheet-overlay');

      function open(){ sheet && (sheet.style.transform='translateX(0)'); overlay?.classList.remove('hidden'); document.body.style.overflow='hidden'; openBtn?.setAttribute('aria-expanded','true'); }
      function close(){ sheet && (sheet.style.transform='translateX(-100%)'); overlay?.classList.add('hidden'); document.body.style.overflow=''; openBtn?.setAttribute('aria-expanded','false'); }

      openBtn?.addEventListener('click', open);
      closeBtn?.addEventListener('click', close);
      overlay?.addEventListener('click', close);
      document.addEventListener('keydown', (e)=>{ if(e.key==='Escape') close(); }, passiveOpts);
    })();

    // Banners (tal cual)
    (function(){
      const w=document.getElementById('banWrap');
      if(!w) return;
      const prev=document.getElementById('banPrev');
      const next=document.getElementById('banNext');
      const prefersReduced=window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      const smooth=prefersReduced?'auto':'smooth';
      const step=()=> w.firstElementChild ? w.firstElementChild.getBoundingClientRect().width+16 : 320;

      prev?.addEventListener('click', ()=> w.scrollBy({left:-step(), behavior:smooth}));
      next?.addEventListener('click', ()=> w.scrollBy({left: step(), behavior:smooth}));

      let startX=0,isDown=false,scrolled=0;
      w.addEventListener('pointerdown',(e)=>{isDown=true;startX=e.clientX;scrolled=0;w.setPointerCapture(e.pointerId);}, passiveOpts);
      w.addEventListener('pointermove',(e)=>{ if(!isDown) return; const dx=e.clientX-startX; if(Math.abs(dx)>5){ w.scrollLeft-=dx; startX=e.clientX; scrolled+=Math.abs(dx);} }, {passive:false});
      w.addEventListener('pointerup', ()=>{ if(!isDown) return; isDown=false; if(scrolled>20){ const s=step(); const mod=w.scrollLeft%s; w.scrollBy({left:(mod>s/2)?(s-mod):(-mod), behavior:smooth}); } }, passiveOpts);
      w.addEventListener('pointercancel', ()=>{ isDown=false; }, passiveOpts);

      function track(el){
        try{
          const id=el?.dataset?.bannerId, pos=el?.dataset?.bannerPos||'';
          if(!id) return;
          fetch('/app/track_banner_click.php',{
            method:'POST',
            headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:new URLSearchParams({id, pos})
          }).catch(()=>{});
        }catch{}
      }
      document.querySelectorAll('[data-banner-id]').forEach(a=> a.addEventListener('click', ()=> track(a), passiveOpts));
    })();

    // Cargar secciones (no usadas acá, lo dejo igual)
    async function hydrateHTML(container){
      const src = container.getAttribute('data-src');
      if(!src) return;
      try{
        const r = await fetch(src, { credentials:'same-origin', headers:{ 'X-Requested-With':'fetch', 'Accept':'text/html' } });
        if (r.status === 204) throw new Error('204');
        if (!r.ok) throw new Error(String(r.status));
        const html = await r.text();
        if (!html.trim()) throw new Error('empty');
        container.innerHTML = html;
      }catch{
        container.innerHTML = `<div class="col-span-full text-sm text-gray-600 p-3 bg-white/80 rounded-lg shadow">No hay resultados por ahora.</div>`;
      }
    }

    // Popup rápido: abrir solo con "Publicar" (tal cual)
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

      btnPublicar.addEventListener('click', (e) => {
        e.preventDefault();
        openQuick();
      });
      btnClose.addEventListener('click', closeQuick);
      modal.addEventListener('click', (e) => { if (e.target === modal) closeQuick(); });
      document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !modal.classList.contains('hidden')) closeQuick(); });
    })();

    // ========= Infinite scroll para oportunidades =========
    let pagina=1,cargando=false,fin=false;
    function cargarOportunidades(reset=false){
      const cont = document.getElementById('contenedor-oportunidades');
      const loaderMore = document.getElementById('loader-more');
      if(!cont) return;

      if(reset){ cont.innerHTML=''; pagina=1; fin=false; }
      if(cargando || fin) return;
      cargando=true; loaderMore?.classList.remove('hidden');

      const f = document.getElementById('form-filtros');
      const datos = new URLSearchParams(f ? new FormData(f) : undefined);
      const limit = 8;
      datos.set('limit', String(limit));
      datos.set('offset', String((pagina-1)*limit));
      datos.set('_ts', Date.now().toString());

      const url = '../app/cargar_oportunidades.php?' + datos.toString();

      fetch(url, {headers:{'X-Requested-With':'fetch'}, credentials:'same-origin'})
        .then(r => r.status===204 ? '' : r.text())
        .then(html => {
          if(!html || !html.trim()){
            fin = true;
            document.getElementById('fin-mensaje')?.classList.remove('hidden');
          } else {
            cont.insertAdjacentHTML('beforeend', html);
            pagina++;
          }
        })
        .finally(()=>{ cargando=false; loaderMore?.classList.add('hidden'); });
    }

    const sentinel=document.getElementById('sentinel');
    if('IntersectionObserver' in window && sentinel){
      new IntersectionObserver(entries=>{
        entries.forEach(e=>{ if(e.isIntersecting) cargarOportunidades(); });
      }, {root:null, rootMargin:'400px 0px', threshold:0}).observe(sentinel);
    } else {
      window.addEventListener('scroll', ()=>{ if(window.innerHeight + window.scrollY >= document.body.offsetHeight - 300) cargarOportunidades(); });
    }

    // Start
    cargarOportunidades();
  </script>

  <!-- Scripts -->
  <script>
    // Loader
    window.addEventListener('load', () => {
      setTimeout(() => {
        document.getElementById('loader').classList.add('hidden');
        document.getElementById('contenido').classList.remove('hidden');
        // Foco accesible si hay mensajes
        const foco = document.getElementById('alerta-dev') || document.getElementById('alerta-dia');
        if (foco) foco.focus();
      }, 350);
    });

    // (Opcional) overlay para futuros modales
    const overlay  = document.getElementById('sidebar-overlay');

    // Habilitar/deshabilitar select modalidad de precio
    function togglePrecioModalidad() {
      const precio = document.getElementById('precio').value;
      const modalidad = document.getElementById('precio_modalidad');
      const disabled = !precio || Number(precio) <= 0;
      modalidad.disabled = disabled;
      if (disabled) modalidad.value = '';
    }
    document.addEventListener('DOMContentLoaded', togglePrecioModalidad);

    // Contadores de caracteres en vivo
    function contarCaracteres(input, spanId, max) {
      if (!input) return;
      const actual = input.value.length;
      const span = document.getElementById(spanId);
      if (span) span.textContent = actual + '/' + max;
      if (actual > max) input.value = input.value.slice(0, max);
    }
    window.addEventListener('DOMContentLoaded', () => {
      contarCaracteres(document.getElementById('titulo'), 'contador-titulo', 70);
      contarCaracteres(document.getElementById('preview'), 'contador-preview', 80);
      contarCaracteres(document.getElementById('descripcion'), 'contador-descripcion', 800);
    });

    // Validar que la descripción NO contenga datos de contacto
    document.getElementById('form-servicio')?.addEventListener('submit', function(e) {
      const descripcion = (document.getElementById('descripcion')?.value || '').toLowerCase();
      const alerta = document.getElementById('alerta');
      const reglas = [
        {patron: /@/g, texto: 'arroba (correo)'},
        {patron: /\b(gmail|hotmail|outlook|yahoo|uc\.cl|duoc|aiep|uss|udp|ipchile|mail)\b/g, texto: 'correo o dominio institucional'},
        {patron: /\b\d{8,}\b/g, texto: 'número largo (posible teléfono)'},
        {patron: /(fono|tel[ée]fono|whats?app|ws|contacto|celular|direcci[oó]n|address|wsp)/g, texto: 'palabra de contacto'},
        {patron: /\+56/g, texto: '+56 (prefijo telefónico)'}
      ];
      for (let r of reglas) {
        const m = descripcion.match(r.patron);
        if (m) {
          e.preventDefault();
          alerta.classList.remove('hidden');
          alerta.textContent = `No puedes ingresar datos de contacto en la descripción. Revisa: "${m[0]}" (${r.texto}).`;
          alerta.focus();
          return false;
        }
      }

      // Validación básica de WhatsApp (9 dígitos y sin ceros iniciales)
      const wsp = document.getElementById('whatsapp')?.value || '';
      if (!/^[1-9][0-9]{8}$/.test(wsp)) {
        e.preventDefault();
        alerta.classList.remove('hidden');
        alerta.textContent = "El WhatsApp debe tener 9 dígitos (ej: 987654321) sin +56 ni espacios.";
        alerta.focus();
        return false;
      }

      // Estado de envío (spinner + bloqueo)
      const btn = document.getElementById('btn-submit');
      const iconOk = document.getElementById('icon-ok');
      const iconSpin = document.getElementById('icon-spin');
      const btnText = document.getElementById('btn-text');
      btn.disabled = true;
      iconOk.classList.add('hidden');
      iconSpin.classList.remove('hidden');
      btnText.textContent = "Publicando...";
    });

    // ------- Auto-guardado (localStorage)
    (function setupDraft(){
      const form = document.getElementById('form-servicio');
      if (!form) return;
      const uid = "<?= $usuario_id ?>";
      const KEY = `nubira_publicar_servicio_draft_v1_user_${uid}`;

      const fields = {
        titulo: document.getElementById('titulo'),
        preview: document.getElementById('preview'),
        descripcion: document.getElementById('descripcion'),
        categoria: document.getElementById('categoria'),
        area: document.getElementById('area'),
        modalidad: document.getElementById('modalidad'),
        ubicacion: document.getElementById('ubicacion'),
        precio: document.getElementById('precio'),
        precio_modalidad: document.getElementById('precio_modalidad'),
        whatsapp: document.getElementById('whatsapp')
      };

      // Restaurar
      try {
        const raw = localStorage.getItem(KEY);
        if (raw) {
          const data = JSON.parse(raw);
          Object.keys(fields).forEach(k => { if (fields[k] && data[k] !== undefined) fields[k].value = data[k]; });
          // actualizar helpers/contadores
          contarCaracteres(fields.titulo, 'contador-titulo', 70);
          contarCaracteres(fields.preview, 'contador-preview', 80);
          contarCaracteres(fields.descripcion, 'contador-descripcion', 800);
          togglePrecioModalidad();
        }
      } catch(e){}

      // Guardar (throttle 250ms)
      const save = () => {
        const data = {};
        Object.keys(fields).forEach(k => { if (fields[k]) data[k] = fields[k].value; });
        try { localStorage.setItem(KEY, JSON.stringify(data)); } catch(e){}
      };
      let t = null;
      Object.values(fields).forEach(el => {
        el?.addEventListener('input', () => { clearTimeout(t); t = setTimeout(save, 250); });
        el?.addEventListener('change', () => { clearTimeout(t); t = setTimeout(save, 250); });
      });

      // Limpiar draft si viene ok desde el server (?ok=...)
      const ok = form.getAttribute('data-ok') === '1';
      if (ok) { try { localStorage.removeItem(KEY); } catch(e){} }
    })();
  </script>
  <!-- Scripts -->
  <script>
    // Loader + focus accesible
    window.addEventListener('load', () => {
      setTimeout(() => {
        document.getElementById('loader').classList.add('hidden');
        document.getElementById('contenido').classList.remove('hidden');
        const alerta = document.getElementById('alerta-form');
        if (alerta) alerta.focus();
      }, 300);
    });

    // Contadores
    function updateCounter(id, max) {
      const input = document.getElementById(id);
      const counter = document.getElementById(id + "-contador");
      if (!input || !counter) return;
      const show = () => { counter.textContent = input.value.length + "/" + max; };
      input.addEventListener('input', show);
      show();
    }
    document.addEventListener('DOMContentLoaded', function() {
      updateCounter('titulo', 80);
      updateCounter('descripcion', 500);
      updateCounter('organizador', 60);
      updateCounter('enlace', 250);
    });

    // Validación de imagen (cliente) + preview
    (function setupImageValidation(){
      const input = document.getElementById('input-imagen');
      if (!input) return;
      input.addEventListener('change', function() {
        const file = this.files?.[0];
        if (!file) return;

        const alerta = document.getElementById('alerta');
        alerta.classList.add('hidden'); alerta.textContent = '';

        const ext = (file.name.split('.').pop() || '').toLowerCase();
        const allowed = ['jpg','jpeg','png','gif','webp'];
        if (!allowed.includes(ext)) {
          this.value = '';
          alerta.classList.remove('hidden');
          alerta.textContent = 'Formato no permitido (usa JPG, PNG, GIF o WEBP).';
          return;
        }
        if (file.size > 2*1024*1024) {
          this.value = '';
          alerta.classList.remove('hidden');
          alerta.textContent = 'La imagen supera 2 MB.';
          return;
        }

        const img = new Image();
        img.onload = function() {
          if (img.width < 800 || img.height < 600) {
            input.value = '';
            alerta.classList.remove('hidden');
            alerta.textContent = `La imagen debe ser mínimo 800×600 px. Actual: ${img.width}×${img.height}.`;
            return;
          }
          // Preview
          const previewWrap = document.getElementById('preview');
          let previewImg = document.getElementById('preview-img');
          if (!previewImg) {
            previewImg = document.createElement('img');
            previewImg.id = 'preview-img';
            previewImg.className = 'max-h-48 rounded';
            const container = document.querySelector('#preview .border') || document.getElementById('preview');
            container.appendChild(previewImg);
          }
          previewImg.src = URL.createObjectURL(file);
          previewImg.classList.remove('hidden');
          previewWrap.classList.remove('hidden');
        };
        img.src = URL.createObjectURL(file);
      });
    })();

    // Validaciones de fechas y URL + submit robusto
    (function setupSubmit(){
      const form = document.getElementById('form-oportunidad');
      const btn  = document.getElementById('btn-submit');
      const iconOk = document.getElementById('icon-ok');
      const iconSpin = document.getElementById('icon-spin');
      const btnText = document.getElementById('btn-text');
      const alerta  = document.getElementById('alerta');

      if (!form) return;

      form.addEventListener('submit', (e) => {
        alerta.classList.add('hidden'); alerta.textContent = '';

        // Fechas
        const fi = document.getElementById('fecha_inicio')?.value;
        const ft = document.getElementById('fecha_termino')?.value;
        if (fi && ft && fi > ft) {
          e.preventDefault();
          alerta.classList.remove('hidden');
          alerta.textContent = 'La fecha de término no puede ser anterior a la de inicio.';
          alerta.focus();
          return false;
        }

        // URL
        const url = document.getElementById('enlace')?.value.trim();
        if (url && !/^https?:\/\/.+/i.test(url)) {
          e.preventDefault();
          alerta.classList.remove('hidden');
          alerta.textContent = 'El enlace debe comenzar con http:// o https://';
          alerta.focus();
          return false;
        }

        // Estado de envío
        btn.disabled = true;
        iconOk.classList.add('hidden');
        iconSpin.classList.remove('hidden');
        btnText.textContent = "Enviando...";
      });
    })();

    // Auto-guardado (localStorage)
    (function setupDraft(){
      const form = document.getElementById('form-oportunidad');
      if (!form) return;
      const uid = "<?= $usuario_id ?>";
      const KEY = `nubira_crear_oportunidad_draft_v1_user_${uid}`;

      const fields = {
        titulo: document.getElementById('titulo'),
        organizador: document.getElementById('organizador'),
        descripcion: document.getElementById('descripcion'),
        tipo: document.getElementById('tipo'),
        fecha_inicio: document.getElementById('fecha_inicio'),
        fecha_termino: document.getElementById('fecha_termino'),
        enlace: document.getElementById('enlace')
      };

      // restaurar
      try {
        const raw = localStorage.getItem(KEY);
        if (raw) {
          const data = JSON.parse(raw);
          Object.keys(fields).forEach(k => { if (fields[k] && data[k] !== undefined) fields[k].value = data[k]; });
          // actualizar contadores
          ['titulo','descripcion','organizador','enlace'].forEach(id=>{
            const el=document.getElementById(id);
            const max={titulo:80,descripcion:500,organizador:60,enlace:250}[id];
            if(el && max) document.getElementById(id+'-contador').textContent = el.value.length + '/' + max;
          });
        }
      } catch(e){}

      // guardar (throttle)
      const save = () => {
        const data = {};
        Object.keys(fields).forEach(k => { if (fields[k]) data[k] = fields[k].value; });
        try { localStorage.setItem(KEY, JSON.stringify(data)); } catch(e){}
      };
      let t = null;
      Object.values(fields).forEach(el => {
        el?.addEventListener('input', () => { clearTimeout(t); t = setTimeout(save, 250); });
        el?.addEventListener('change', () => { clearTimeout(t); t = setTimeout(save, 250); });
      });

      // Limpiar draft si viene ok desde el server (?ok=1)
      const ok = form.getAttribute('data-ok') === '1';
      if (ok) { try { localStorage.removeItem(KEY); } catch(e){} }
    })();
  </script>
  <script>
document.addEventListener("DOMContentLoaded", () => {
  const form = document.getElementById("form-oportunidad");
  if (form && form.dataset.ok === "1") {
    form.reset(); // limpia el formulario después de publicar
  }
});
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
