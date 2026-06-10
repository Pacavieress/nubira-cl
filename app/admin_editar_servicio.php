<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
session_start();

// Guard admin estricto
if (empty($_SESSION['usuario_id']) || ($_SESSION['rol'] ?? '') !== 'admin') {
    header('Location: /'); exit;
}

$app_dir = __DIR__;
require_once $app_dir . '/conexion.php';
if (file_exists($app_dir . '/iconos.php')) require_once $app_dir . '/iconos.php';
else { if (!function_exists('icon')) { function icon($n,$c=''){return "<i class='fa-solid fa-$n $c'></i>";} } }

// CSRF token
if (empty($_SESSION['csrf_token_admin_editar'])) {
    $_SESSION['csrf_token_admin_editar'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token_admin_editar'];

$categorias_canonicas = ['Matemáticas','Química','Física','Biología','Programación','Idiomas','Historia','Lengua','Economía','Diseño','Derecho','Otros'];

// Cargar servicio
$id_servicio = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id_servicio <= 0) { header('Location: /admin/servicios'); exit; }

$stmt = $conn->prepare("SELECT id, titulo, categoria, imagen, imagen_banco_id FROM servicios WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $id_servicio);
$stmt->execute();
$servicio = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$servicio) { header('Location: /admin/servicios'); exit; }

$titulo   = $servicio['titulo'];
$categoria = $servicio['categoria'];

// Banco: cargar todas las activas (JS filtra por categoría)
$banco_imagenes = [];
$res_banco = $conn->query("SELECT id, categoria, archivo, descripcion FROM banco_imagenes WHERE activa = 1 ORDER BY categoria, id");
if ($res_banco) { while ($b = $res_banco->fetch_assoc()) $banco_imagenes[] = $b; }

// Preselección imagen banco
$imagen_banco_id_preseleccionado = (int)($servicio['imagen_banco_id'] ?? 0);
if ($imagen_banco_id_preseleccionado <= 0 && !empty($servicio['imagen'])) {
    $stmt_pre = $conn->prepare("SELECT id FROM banco_imagenes WHERE activa = 1 AND categoria = ? ORDER BY id LIMIT 1");
    $stmt_pre->bind_param("s", $categoria);
    $stmt_pre->execute();
    $stmt_pre->bind_result($pre_id);
    if ($stmt_pre->fetch()) $imagen_banco_id_preseleccionado = (int)$pre_id;
    $stmt_pre->close();
}

// POST handler — antes de cualquier output HTML
$mensaje = '';
$exito   = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // a) CSRF
    $token_recibido = $_POST['csrf_token'] ?? '';
    if (empty($token_recibido) || !hash_equals($_SESSION['csrf_token_admin_editar'] ?? '', $token_recibido)) {
        $mensaje = "Sesión expirada. Recarga e intenta de nuevo.";
        goto fin_post;
    }

    // b) Servicio existe (ya validado arriba, reconfirmar id del POST coincide)
    $post_id = (int)($_POST['id_servicio'] ?? 0);
    if ($post_id !== $id_servicio) {
        $mensaje = "ID de servicio inválido.";
        goto fin_post;
    }

    // c) Título
    $titulo_post = trim(strip_tags($_POST['titulo'] ?? ''));
    if ($titulo_post === '' || mb_strlen($titulo_post) > 50) {
        $mensaje = "El título es obligatorio y no puede superar 50 caracteres.";
        goto fin_post;
    }

    // d) Categoría en las 12 canónicas
    $categoria_post = trim(strip_tags($_POST['categoria'] ?? ''));
    if (!in_array($categoria_post, $categorias_canonicas, true)) {
        $mensaje = "Categoría inválida.";
        goto fin_post;
    }

    // e) imagen_banco_id activo y de la categoría enviada
    $imagen_banco_id_post = (int)($_POST['imagen_banco_id'] ?? 0);
    $banco_valido = false;
    if ($imagen_banco_id_post > 0) {
        $stmt_b = $conn->prepare("SELECT id FROM banco_imagenes WHERE id = ? AND activa = 1 AND categoria = ? LIMIT 1");
        $stmt_b->bind_param("is", $imagen_banco_id_post, $categoria_post);
        $stmt_b->execute();
        $stmt_b->store_result();
        $banco_valido = ($stmt_b->num_rows === 1);
        $stmt_b->close();
    }
    if (!$banco_valido) {
        $mensaje = "Imagen del banco inválida o no corresponde a la categoría seleccionada.";
        goto fin_post;
    }

    // UPDATE — solo titulo, categoria, imagen_banco_id. No toca estado ni otros campos.
    $stmt_u = $conn->prepare("UPDATE servicios SET titulo = ?, categoria = ?, imagen_banco_id = ? WHERE id = ?");
    $stmt_u->bind_param("ssii", $titulo_post, $categoria_post, $imagen_banco_id_post, $id_servicio);
    if ($stmt_u->execute()) {
        $stmt_u->close();
        header('Location: /admin/servicios?ok=1');
        exit;
    }
    error_log("Nubira Admin Error - admin_editar_servicio UPDATE: " . $stmt_u->error);
    $mensaje = "Error al guardar. Intenta de nuevo.";
    $stmt_u->close();

    fin_post:;
    // Reasignar variables para re-render del form con los valores enviados
    $titulo    = $titulo_post    ?? $titulo;
    $categoria = $categoria_post ?? $categoria;
    $imagen_banco_id_preseleccionado = $imagen_banco_id_post ?? $imagen_banco_id_preseleccionado;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Editar Servicio (Admin) | Nubira</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="icon" type="image/webp" href="/img/logo2.webp">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
    body { font-family: 'Inter', sans-serif; background-color: #F9FAFB; }
    .banco-scroll { -ms-overflow-style: none; scrollbar-width: none; scroll-behavior: smooth; }
    .banco-scroll::-webkit-scrollbar { display: none; }
  </style>
</head>
<body class="bg-gray-50 text-gray-900 antialiased overflow-x-hidden">

<?php
require_once $app_dir . '/componentes/header.php';
require_once $app_dir . '/componentes/sidebar.php';
?>

<main class="pt-16 md:pt-20 pb-20 lg:ml-64 px-4 max-w-[800px] mx-auto md:px-8">

  <div class="mb-6 flex items-center justify-between gap-3">
    <div>
      <span class="inline-block py-0.5 px-2.5 rounded-full bg-violet-50 text-violet-600 text-[10px] font-bold mb-1.5 border border-violet-100">
        Admin · Edición directa
      </span>
      <h1 class="text-xl md:text-2xl font-bold text-gray-900 tracking-tight">Editar Servicio #<?= $id_servicio ?></h1>
      <p class="text-gray-500 text-sm mt-0.5">Solo cambia título, categoría e imagen. El estado no se toca.</p>
    </div>
    <a href="/admin/servicios" class="shrink-0 text-sm font-semibold text-gray-500 hover:text-gray-900 transition flex items-center gap-1.5">
      <i class="fa-solid fa-arrow-left text-xs"></i> Volver
    </a>
  </div>

  <?php if ($mensaje): ?>
  <div class="mb-5 px-4 py-3 rounded-xl text-sm font-medium <?= $exito ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200' ?>">
    <?= htmlspecialchars($mensaje) ?>
  </div>
  <?php endif; ?>

  <form id="form-admin-editar" method="POST" action="/admin/editar-servicio/<?= $id_servicio ?>" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 md:p-8 space-y-6">

    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
    <input type="hidden" name="id_servicio" value="<?= $id_servicio ?>">

    <!-- Título -->
    <div>
      <label class="block text-xs font-bold text-gray-900 mb-1.5 uppercase tracking-wide">Título del anuncio</label>
      <div class="relative">
        <input type="text" name="titulo" id="titulo" required maxlength="50"
               value="<?= htmlspecialchars($titulo) ?>"
               placeholder="Ej: Clases de Cálculo / Asesoría de Tesis"
               class="w-full bg-gray-50 border border-gray-200 text-gray-900 text-sm rounded-xl focus:ring-2 focus:ring-[#54A6D8] focus:border-transparent block p-3.5 transition outline-none">
        <div class="text-right text-xs mt-1 absolute right-0 -bottom-5">
          <span id="titulo-msg" class="mr-2"></span><span id="titulo-count" class="text-gray-500">0</span>/50
        </div>
      </div>
    </div>

    <!-- Categoría -->
    <div class="pt-4">
      <label class="block text-xs font-bold text-gray-900 mb-1.5 uppercase tracking-wide">Categoría</label>
      <select name="categoria" id="categoria" required
              class="w-full bg-gray-50 border border-gray-200 text-gray-900 text-sm rounded-xl focus:ring-2 focus:ring-[#54A6D8] focus:border-transparent block p-3.5 transition outline-none">
        <option value="">— Selecciona una categoría —</option>
        <?php foreach ($categorias_canonicas as $cat): ?>
        <option value="<?= htmlspecialchars($cat) ?>" <?= ($cat === $categoria) ? 'selected' : '' ?>>
          <?= htmlspecialchars($cat) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Imagen del banco -->
    <div>
      <label class="block text-xs font-bold text-gray-900 mb-1.5 uppercase tracking-wide">Imagen de portada</label>
      <p class="text-xs text-gray-400 mb-3">Se filtra según la categoría seleccionada.</p>

      <input type="hidden" name="imagen_banco_id" id="imagen_banco_id" value="<?= $imagen_banco_id_preseleccionado ?: '' ?>">

      <div id="banco-empty" class="hidden bg-gray-50 border border-dashed border-gray-300 rounded-2xl py-10 text-center">
        <p class="text-sm font-medium text-gray-500">Elige una categoría primero</p>
      </div>

      <div id="banco-carrusel">
        <div class="flex gap-3 py-1 overflow-x-auto banco-scroll -mx-1 px-1">
          <?php foreach ($banco_imagenes as $bi):
            $es_pre = ((int)$bi['id'] === $imagen_banco_id_preseleccionado);
          ?>
          <button type="button"
                  class="banco-card group relative flex-shrink-0 w-[140px] h-[100px] rounded-xl overflow-hidden border-[3px] <?= $es_pre ? 'border-[#54A6D8]' : 'border-transparent' ?> transition-all focus:outline-none focus:ring-2 focus:ring-[#54A6D8]"
                  data-id="<?= (int)$bi['id'] ?>"
                  data-categoria="<?= htmlspecialchars($bi['categoria']) ?>"
                  title="<?= htmlspecialchars($bi['descripcion'] ?? $bi['categoria']) ?>">
            <img src="/upload/banco/<?= htmlspecialchars($bi['archivo']) ?>"
                 alt="<?= htmlspecialchars($bi['descripcion'] ?? $bi['categoria']) ?>"
                 class="w-full h-full object-cover" loading="lazy">
            <span class="banco-check absolute top-1.5 right-1.5 w-6 h-6 rounded-full bg-[#54A6D8] text-white <?= $es_pre ? 'flex' : 'hidden' ?> items-center justify-center shadow">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
            </span>
          </button>
          <?php endforeach; ?>
        </div>
        <div id="banco-sin-imagenes" class="hidden py-6 text-center text-sm text-gray-400">Sin imágenes para esta categoría.</div>
      </div>

      <p id="banco-error" class="hidden mt-2 text-xs text-red-500 font-bold">Debes elegir una imagen del banco para esta categoría.</p>
    </div>

    <!-- Submit -->
    <div class="pt-2">
      <button type="submit"
              class="w-full text-white bg-[#54A6D8] hover:bg-sky-600 font-bold rounded-2xl text-base px-5 py-4 shadow-lg shadow-blue-100 hover:shadow-blue-200 transform active:scale-[0.99] transition-all flex items-center justify-center gap-2">
        <i class="fa-solid fa-floppy-disk"></i> Guardar cambios
      </button>
    </div>

  </form>

</main>

<?php
require_once $app_dir . '/componentes/nav_bottom.php';
require_once $app_dir . '/componentes/modal_publicar.php';
require_once $app_dir . '/componentes/modal_explora.php';
?>

<script>
// Contador título con feedback de color
(function() {
    const input = document.getElementById('titulo');
    const count = document.getElementById('titulo-count');
    const msg   = document.getElementById('titulo-msg');
    if (!input || !count) return;

    function actualizar() {
        var len = input.value.length;
        count.textContent = len;
        count.classList.remove('text-green-600', 'text-amber-600', 'text-gray-500');
        if (len === 0) {
            count.classList.add('text-gray-500');
            if (msg) { msg.textContent = ''; msg.className = 'mr-2'; }
        } else if (len <= 40) {
            count.classList.add('text-green-600');
            if (msg) { msg.textContent = '✓ Se verá completo'; msg.className = 'mr-2 text-green-600'; }
        } else {
            count.classList.add('text-amber-600');
            if (msg) { msg.textContent = 'Puede recortarse en móvil'; msg.className = 'mr-2 text-amber-600'; }
        }
    }

    input.addEventListener('input', actualizar);
    input.dispatchEvent(new Event('input')); // Refleja valor precargado
})();

// Carrusel banco — mismo patrón que editar_servicio.php
(function setupBancoCarrusel() {
    const sel      = document.getElementById('categoria');
    const carrusel = document.getElementById('banco-carrusel');
    const empty    = document.getElementById('banco-empty');
    const sinImgs  = document.getElementById('banco-sin-imagenes');
    const errorMsg = document.getElementById('banco-error');
    const hidden   = document.getElementById('imagen_banco_id');
    const cards    = Array.from(document.querySelectorAll('.banco-card'));
    if (!sel || !carrusel || !hidden) return;

    function limpiar(card) {
        card.classList.remove('border-[#54A6D8]');
        card.classList.add('border-transparent');
        var chk = card.querySelector('.banco-check');
        if (chk) { chk.classList.add('hidden'); chk.classList.remove('flex'); }
    }

    function seleccionar(card) {
        cards.forEach(limpiar);
        card.classList.add('border-[#54A6D8]');
        card.classList.remove('border-transparent');
        var chk = card.querySelector('.banco-check');
        if (chk) { chk.classList.remove('hidden'); chk.classList.add('flex'); }
        hidden.value = card.dataset.id;
        if (errorMsg) errorMsg.classList.add('hidden');
    }

    cards.forEach(function(card) { card.addEventListener('click', function() { seleccionar(card); }); });

    function filtrar(reset) {
        var cat = sel.value;
        if (reset) hidden.value = '';
        if (!cat) {
            carrusel.classList.add('hidden');
            empty.classList.remove('hidden');
            return;
        }
        empty.classList.add('hidden');
        carrusel.classList.remove('hidden');
        var visibles = 0;
        cards.forEach(function(card) {
            var match = (card.dataset.categoria === cat);
            card.classList.toggle('hidden', !match);
            if (reset) limpiar(card);
            if (match) visibles++;
        });
        if (sinImgs) sinImgs.classList.toggle('hidden', visibles > 0);
    }

    sel.addEventListener('change', function() { filtrar(true); });
    filtrar(false); // init: respeta preselección de PHP

    document.getElementById('form-admin-editar')?.addEventListener('submit', function(e) {
        if (!hidden.value) {
            e.preventDefault();
            if (errorMsg) errorMsg.classList.remove('hidden');
            (empty.classList.contains('hidden') ? carrusel : empty)
                .scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });
})();
</script>

</body>
</html>
