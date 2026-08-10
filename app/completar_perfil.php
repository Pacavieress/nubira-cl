<?php
session_start();
require_once __DIR__ . '/conexion.php';

if (!isset($_SESSION['usuario_id'])) { header("Location: /login"); exit; }

// [NUBIRA 2.0] Auto-migración (mismo patrón que login.php) — cubre el caso de una
// sesión ya activa que llega directo acá sin volver a pasar por login.php.
try { $conn->query("ALTER TABLE alumnos ADD COLUMN intencion_uso ENUM('vender','comprar') DEFAULT NULL"); } catch (Throwable $e) {}

$usuario_id = (int)$_SESSION['usuario_id'];
$nombre = htmlspecialchars($_SESSION['usuario_nombre'] ?? '', ENT_QUOTES, 'UTF-8');

// Cargar datos actuales
$stmt = $conn->prepare("SELECT bio, tipo, carrera, universidad, anio_egreso, anios_experiencia, intencion_uso, institucion, dominio FROM alumnos WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $usuario_id);
$stmt->execute();
$stmt->bind_result($bio_actual, $tipo_actual, $carrera_actual, $univ_actual, $anio_eg_actual, $anios_exp_actual, $intencion_actual, $institucion_actual, $dominio_actual);
$stmt->fetch();
$stmt->close();

// [NUBIRA 2.0] Prellenar institución para usuarios de dominio institucional — misma
// fuente (dominios_permitidos) que usa el resto del sitio para mostrar la institución.
if (empty(trim($institucion_actual ?? '')) && !empty($dominio_actual)) {
    $stmt_dom = $conn->prepare("SELECT institucion FROM dominios_permitidos WHERE dominio = ?");
    $stmt_dom->bind_param("s", $dominio_actual);
    $stmt_dom->execute();
    $stmt_dom->bind_result($inst_dom);
    if ($stmt_dom->fetch()) { $institucion_actual = $inst_dom; }
    $stmt_dom->close();
}

// Si el perfil ya está completo (bio llena = ruta vender, o institución llena con
// intención "comprar" = ruta comprar), no quedar atrapado aquí.
$ya_completo = !empty(trim($bio_actual ?? ''))
    || ($intencion_actual === 'comprar' && !empty(trim($institucion_actual ?? '')));
if ($ya_completo) {
    header("Location: /vitrina");
    exit;
}

// CSRF
if (empty($_SESSION['csrf_completar'])) {
    $_SESSION['csrf_completar'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_completar'];

$guardado = false;
$errores  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, $_POST['csrf'] ?? '')) {
        $errores[] = 'Sesión inválida. Recarga la página e intenta nuevamente.';

    } elseif (($_POST['paso'] ?? '') === 'elegir') {
        // Paso 1: elegir intención de uso
        $intencion_post = $_POST['intencion_uso'] ?? '';
        if (!in_array($intencion_post, ['vender', 'comprar'], true)) {
            $errores[] = 'Selecciona una opción válida.';
        } else {
            $stmt = $conn->prepare("UPDATE alumnos SET intencion_uso = ? WHERE id = ?");
            $stmt->bind_param("si", $intencion_post, $usuario_id);
            $stmt->execute();
            $stmt->close();
            $intencion_actual = $intencion_post;
        }

    } elseif (($_POST['paso'] ?? '') === 'guardar' && $intencion_actual === 'comprar') {
        // Paso 2, ruta "comprar": solo institución
        $institucion_post = trim(strip_tags($_POST['institucion'] ?? ''));
        if (empty($institucion_post)) {
            $errores[] = 'Cuéntanos en qué institución estudias.';
        } else {
            $stmt = $conn->prepare("UPDATE alumnos SET institucion = ? WHERE id = ?");
            $stmt->bind_param("si", $institucion_post, $usuario_id);
            if ($stmt->execute()) {
                $guardado = true;
            } else {
                $errores[] = 'Error al guardar. Intenta nuevamente.';
            }
            $stmt->close();
        }

    } elseif (($_POST['paso'] ?? '') === 'guardar' && $intencion_actual === 'vender') {
        // Paso 2, ruta "vender": formulario completo (sin cambios respecto al original)
        $tipos_validos = ['egresado', 'profesor', 'particular'];
        $tipo     = trim($_POST['tipo'] ?? '');
        $carrera  = trim(strip_tags($_POST['carrera'] ?? ''));
        $univ     = trim(strip_tags($_POST['universidad'] ?? ''));
        $anio_eg  = isset($_POST['anio_egreso']) && $_POST['anio_egreso'] !== '' ? (int)$_POST['anio_egreso'] : null;
        $anios_ex = isset($_POST['anios_experiencia']) && $_POST['anios_experiencia'] !== '' ? (int)$_POST['anios_experiencia'] : null;
        $bio      = trim(strip_tags($_POST['bio'] ?? ''));

        if (!in_array($tipo, $tipos_validos, true))  $errores[] = 'Selecciona un tipo de cuenta válido.';
        if (empty($carrera))                          $errores[] = 'El campo "Carrera o área" es obligatorio.';
        if (mb_strlen($bio, 'UTF-8') < 100)           $errores[] = 'La bio debe tener al menos 100 caracteres (tienes ' . mb_strlen($bio, 'UTF-8') . ').';

        if (empty($errores)) {
            $stmt = $conn->prepare(
                "UPDATE alumnos SET tipo=?, carrera=?, universidad=?, anio_egreso=?, anios_experiencia=?, bio=? WHERE id=?"
            );
            $stmt->bind_param("sssiisi", $tipo, $carrera, $univ, $anio_eg, $anios_ex, $bio, $usuario_id);
            if ($stmt->execute()) {
                $guardado = true;
            } else {
                $errores[] = 'Error al guardar. Intenta nuevamente.';
            }
            $stmt->close();
        }
    }
}

$tipo_form = $_POST['tipo'] ?? $tipo_actual ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Completar Perfil | Nubira</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
    body { font-family: 'Inter', sans-serif; }
  </style>
</head>
<body class="bg-gray-50 min-h-screen flex items-start justify-center px-4 py-10">

  <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-8 md:p-10 w-full max-w-lg">

    <img src="/img/logo.webp" alt="Nubira" class="h-8 mb-8">

    <?php if ($guardado): ?>

      <div class="text-center">
        <div class="w-16 h-16 bg-emerald-50 rounded-full flex items-center justify-center mx-auto mb-5">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-8 h-8 text-emerald-500">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
          </svg>
        </div>
        <?php if ($intencion_actual === 'comprar'): ?>
          <h1 class="text-2xl font-bold text-gray-900 mb-3 tracking-tight">¡Listo!</h1>
          <p class="text-gray-500 text-sm leading-relaxed mb-8">
            Ya puedes buscar tutores y apuntes en Nubira.
          </p>
        <?php else: ?>
          <h1 class="text-2xl font-bold text-gray-900 mb-3 tracking-tight">¡Listo!</h1>
          <p class="text-gray-500 text-sm leading-relaxed mb-8">
            Ya puedes publicar tus clases o apuntes en Nubira.
          </p>
        <?php endif; ?>
        <a href="/vitrina" class="block w-full bg-[#54A6D8] hover:bg-[#4592c0] text-white font-bold py-3.5 rounded-xl transition-all text-center text-sm">
          Ir a la vitrina
        </a>
      </div>

    <?php elseif (empty($intencion_actual)): ?>

      <h1 class="text-2xl font-bold text-gray-900 mb-1 tracking-tight">¿Para qué vienes a Nubira?</h1>
      <p class="text-sm text-gray-500 mb-8 leading-relaxed">
        <?= $nombre ? "Hola $nombre. Elige" : "Elige" ?> la opción que mejor te describe.
      </p>

      <?php if (!empty($errores)): ?>
        <div class="mb-6 bg-red-50 border border-red-200 rounded-xl px-4 py-3 space-y-1">
          <?php foreach ($errores as $e): ?>
            <p class="text-sm text-red-700 font-medium"><?= htmlspecialchars($e) ?></p>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form method="POST" class="space-y-3">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
        <input type="hidden" name="paso" value="elegir">

        <button type="submit" name="intencion_uso" value="vender"
                class="w-full text-left bg-gray-50 hover:bg-blue-50 border-2 border-gray-200 hover:border-[#54A6D8] rounded-2xl px-5 py-4 transition-all">
          <span class="block font-bold text-gray-900 text-sm">Quiero enseñar / vender</span>
          <span class="block text-xs text-gray-500 mt-0.5">Publicar clases o apuntes y generar ingresos</span>
        </button>

        <button type="submit" name="intencion_uso" value="comprar"
                class="w-full text-left bg-gray-50 hover:bg-blue-50 border-2 border-gray-200 hover:border-[#54A6D8] rounded-2xl px-5 py-4 transition-all">
          <span class="block font-bold text-gray-900 text-sm">Solo quiero comprar / contratar</span>
          <span class="block text-xs text-gray-500 mt-0.5">Buscar tutores o apuntes para estudiar</span>
        </button>
      </form>

    <?php elseif ($intencion_actual === 'comprar'): ?>

      <h1 class="text-2xl font-bold text-gray-900 mb-1 tracking-tight">Cuéntanos dónde estudias</h1>
      <p class="text-sm text-gray-500 mb-8 leading-relaxed">
        <?= $nombre ? "Hola $nombre. Esto" : "Esto" ?> nos ayuda a verificar tu cuenta más rápido.
      </p>

      <?php if (!empty($errores)): ?>
        <div class="mb-6 bg-red-50 border border-red-200 rounded-xl px-4 py-3 space-y-1">
          <?php foreach ($errores as $e): ?>
            <p class="text-sm text-red-700 font-medium"><?= htmlspecialchars($e) ?></p>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form method="POST" class="space-y-5">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
        <input type="hidden" name="paso" value="guardar">

        <div>
          <label class="block text-xs font-bold text-gray-700 mb-1.5 uppercase tracking-wide">
            Institución <span class="text-red-400">*</span>
          </label>
          <input type="text" name="institucion" required maxlength="100"
                 value="<?= htmlspecialchars($_POST['institucion'] ?? $institucion_actual ?? '') ?>"
                 placeholder="Ej: USACH, UC, PUCV"
                 class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-gray-900 text-sm focus:ring-[#54A6D8] focus:border-[#54A6D8] outline-none transition">
        </div>

        <button type="submit"
                class="w-full bg-[#54A6D8] hover:bg-[#4592c0] text-white font-bold py-3.5 rounded-xl transition-all text-sm mt-2">
          Continuar
        </button>
      </form>

    <?php else: ?>

      <h1 class="text-2xl font-bold text-gray-900 mb-1 tracking-tight">Completa tu perfil</h1>
      <p class="text-sm text-gray-500 mb-8 leading-relaxed">
        <?= $nombre ? "Hola $nombre. Para" : "Para" ?> publicar servicios y apuntes en Nubira necesitamos conocerte un poco más. El equipo revisará tu perfil en las próximas 24 horas.
      </p>

      <?php if (!empty($errores)): ?>
        <div class="mb-6 bg-red-50 border border-red-200 rounded-xl px-4 py-3 space-y-1">
          <?php foreach ($errores as $e): ?>
            <p class="text-sm text-red-700 font-medium"><?= htmlspecialchars($e) ?></p>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form method="POST" class="space-y-5">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
        <input type="hidden" name="paso" value="guardar">

        <div>
          <label class="block text-xs font-bold text-gray-700 mb-1.5 uppercase tracking-wide">
            Tipo de cuenta <span class="text-red-400">*</span>
          </label>
          <select name="tipo" required
                  class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-gray-900 text-sm focus:ring-[#54A6D8] focus:border-[#54A6D8] outline-none transition appearance-none cursor-pointer">
            <option value="">Selecciona...</option>
            <option value="egresado"   <?= $tipo_form === 'egresado'   ? 'selected' : '' ?>>Egresado</option>
            <option value="profesor"   <?= $tipo_form === 'profesor'   ? 'selected' : '' ?>>Profesor</option>
            <option value="particular" <?= $tipo_form === 'particular' ? 'selected' : '' ?>>Tutor Particular</option>
          </select>
        </div>

        <div>
          <label class="block text-xs font-bold text-gray-700 mb-1.5 uppercase tracking-wide">
            Carrera o área que enseñas <span class="text-red-400">*</span>
          </label>
          <input type="text" name="carrera" required maxlength="120"
                 value="<?= htmlspecialchars($_POST['carrera'] ?? $carrera_actual ?? '') ?>"
                 placeholder="Ej: Ingeniería Civil, Matemáticas, Química"
                 class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-gray-900 text-sm focus:ring-[#54A6D8] focus:border-[#54A6D8] outline-none transition">
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-xs font-bold text-gray-700 mb-1.5 uppercase tracking-wide">Universidad</label>
            <input type="text" name="universidad" maxlength="100"
                   value="<?= htmlspecialchars($_POST['universidad'] ?? $univ_actual ?? '') ?>"
                   placeholder="Ej: USACH, UC"
                   class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-gray-900 text-sm focus:ring-[#54A6D8] focus:border-[#54A6D8] outline-none transition">
          </div>
          <div>
            <label class="block text-xs font-bold text-gray-700 mb-1.5 uppercase tracking-wide">Año de egreso</label>
            <input type="number" name="anio_egreso" min="1970" max="2030"
                   value="<?= htmlspecialchars($_POST['anio_egreso'] ?? $anio_eg_actual ?? '') ?>"
                   placeholder="2020"
                   class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-gray-900 text-sm focus:ring-[#54A6D8] focus:border-[#54A6D8] outline-none transition">
          </div>
        </div>

        <div>
          <label class="block text-xs font-bold text-gray-700 mb-1.5 uppercase tracking-wide">Años de experiencia enseñando</label>
          <input type="number" name="anios_experiencia" min="0" max="50"
                 value="<?= htmlspecialchars($_POST['anios_experiencia'] ?? $anios_exp_actual ?? '') ?>"
                 placeholder="Ej: 3"
                 class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-gray-900 text-sm focus:ring-[#54A6D8] focus:border-[#54A6D8] outline-none transition">
        </div>

        <div>
          <label class="block text-xs font-bold text-gray-700 mb-1.5 uppercase tracking-wide">
            Bio profesional <span class="text-red-400">*</span>
            <span class="text-gray-400 normal-case font-normal ml-1">(mínimo 100 caracteres)</span>
          </label>
          <textarea name="bio" required rows="5" maxlength="800" id="bio-input"
                    placeholder="Cuéntanos tu experiencia, qué enseñas y por qué te apasiona..."
                    class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-gray-900 text-sm focus:ring-[#54A6D8] focus:border-[#54A6D8] outline-none transition resize-none"><?= htmlspecialchars($_POST['bio'] ?? $bio_actual ?? '') ?></textarea>
          <div class="flex justify-between mt-1">
            <span id="bio-count-msg" class="text-[11px] font-medium text-gray-400"></span>
            <span class="text-[11px] text-gray-400"><span id="bio-count">0</span>/800</span>
          </div>
        </div>

        <button type="submit"
                class="w-full bg-[#54A6D8] hover:bg-[#4592c0] text-white font-bold py-3.5 rounded-xl transition-all text-sm mt-2">
          Enviar para revisión
        </button>
      </form>

    <?php endif; ?>

  </div>

<script>
const bioInput = document.getElementById('bio-input');
const bioCount = document.getElementById('bio-count');
const bioMsg   = document.getElementById('bio-count-msg');

function actualizarContador() {
    const len = bioInput.value.length;
    bioCount.textContent = len;
    if (len < 100) {
        bioMsg.textContent = 'Faltan ' + (100 - len) + ' caracteres mínimos';
        bioMsg.className = 'text-[11px] font-medium text-amber-500';
    } else {
        bioMsg.textContent = '✓ Cumple el mínimo';
        bioMsg.className = 'text-[11px] font-medium text-emerald-500';
    }
}

bioInput?.addEventListener('input', actualizarContador);
actualizarContador();
</script>

</body>
</html>
