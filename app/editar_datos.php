<?php
/**
 * VISTA: EDITAR PERFIL
 * UBICACIÓN: public_html/app/editar_datos.php
 */
session_start();

// 1. SEGURIDAD Y RUTAS
if (!isset($_SESSION['usuario_id'])) { header("Location: /login"); exit; }

$app_dir = __DIR__;
if (!file_exists($app_dir . '/conexion.php')) {
    if (file_exists($app_dir . '/app/conexion.php')) $app_dir = $app_dir . '/app';
    elseif (file_exists(dirname($app_dir) . '/app')) $app_dir = dirname($app_dir) . '/app';
}

require_once $app_dir . '/conexion.php';
require_once $app_dir . '/iconos.php';

$usuario_id = (int)$_SESSION['usuario_id'];
$rol        = $_SESSION['rol'] ?? 'alumno';

// Header Vars
$nombre_usuario = $_SESSION['usuario_nombre'] ?? 'Estudiante';
$institucion_session = strtolower(trim($_SESSION['institucion'] ?? ''));
$nombres_inst = ['uc'=>'UC','aiep'=>'AIEP','uss'=>'USS','udp'=>'UDP'];
$nombre_institucion = $nombres_inst[$institucion_session] ?? ucfirst($institucion_session);

// Carrera
$carrera_usuario = $_SESSION['carrera'] ?? '';
if (empty($carrera_usuario)) {
    $stmt = $conn->prepare("SELECT carrera FROM alumnos WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $usuario_id);
    $stmt->execute();
    $stmt->bind_result($c_db);
    if ($stmt->fetch()) { $carrera_usuario = $c_db; $_SESSION['carrera'] = $c_db; }
    $stmt->close();
}
$display_carrera = $carrera_usuario ?: 'Estudiante';

// Helper Nav
$page_title = "Editar Perfil";
if (!function_exists('nav_class')) {
    function nav_class(string $path): string {
        $ruta_actual = $_SERVER['REQUEST_URI'] ?? '/';
        $base = 'group flex items-center gap-3 px-3 py-2 text-sm font-medium rounded-lg transition-all border border-transparent';
        $activo = ' bg-blue-50 text-[#54A6D8] border-blue-100';
        $inactivo = ' text-gray-500 hover:bg-gray-50 hover:text-gray-900';
        if ($path === '/dashboard') return $base . $activo; 
        return $base . $inactivo;
    }
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$CSRF = $_SESSION['csrf_token'];

// Obtener datos actuales
$stmt = $conn->prepare("SELECT nombre, correo, carrera, password, tipo, bio, universidad, anio_egreso, anios_experiencia, verificacion_estado FROM alumnos WHERE id = ?");
$stmt->bind_param("i", $usuario_id);
$stmt->execute();
$stmt->bind_result($nombre_actual, $correo_actual, $carrera_actual, $hash_password, $tipo_actual, $bio_actual, $univ_actual, $anio_eg_actual, $anios_exp_actual, $verif_estado_actual);
$stmt->fetch();
$stmt->close();

$mensaje = '';
$exito   = false;
if (!empty($_SESSION['flash_mensaje'])) {
    $mensaje = $_SESSION['flash_mensaje'];
    $exito   = !empty($_SESSION['flash_exito']);
    unset($_SESSION['flash_mensaje'], $_SESSION['flash_exito']);
}

// --- PROCESAMIENTO ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
        $mensaje = 'Sesión inválida.';
    }
    // 1. Editar Datos
    elseif (isset($_POST['editar_datos'])) {
        $nuevo_nombre   = trim($_POST['nombre']   ?? '');
        $nueva_carrera  = trim(strip_tags($_POST['carrera']  ?? ''));
        $nuevo_tipo     = trim($_POST['tipo']      ?? '');
        $nueva_bio      = trim(strip_tags($_POST['bio']      ?? ''));
        $nueva_univ     = trim(strip_tags($_POST['universidad'] ?? ''));
        $nuevo_anio_eg  = isset($_POST['anio_egreso'])       && $_POST['anio_egreso']       !== '' ? (int)$_POST['anio_egreso']       : null;
        $nuevo_anios_ex = isset($_POST['anios_experiencia']) && $_POST['anios_experiencia'] !== '' ? (int)$_POST['anios_experiencia'] : null;

        $tipos_validos = ['estudiante', 'egresado', 'profesor', 'particular'];

        if ($nuevo_nombre === '' || $nueva_carrera === '') {
            $mensaje = 'El nombre y la carrera o área son obligatorios.';
        } elseif ($nuevo_tipo !== '' && !in_array($nuevo_tipo, $tipos_validos, true)) {
            $mensaje = 'Tipo de cuenta inválido.';
        } else {
            $tipo_guardar = $nuevo_tipo !== '' ? $nuevo_tipo : null;
            $bio_guardar  = $nueva_bio  !== '' ? $nueva_bio  : null;
            $univ_guardar = $nueva_univ !== '' ? $nueva_univ : null;

            $u = $conn->prepare("UPDATE alumnos SET nombre=?, carrera=?, tipo=?, bio=?, universidad=?, anio_egreso=?, anios_experiencia=? WHERE id=?");
            $u->bind_param("sssssiii", $nuevo_nombre, $nueva_carrera, $tipo_guardar, $bio_guardar, $univ_guardar, $nuevo_anio_eg, $nuevo_anios_ex, $usuario_id);
            if ($u->execute()) {
                $_SESSION['usuario_nombre'] = $nuevo_nombre;
                $_SESSION['carrera']        = $nueva_carrera;
                $_SESSION['flash_mensaje']  = 'Datos actualizados correctamente.';
                $_SESSION['flash_exito']    = true;
                header("Location: /configurar-cuenta");
                exit;
            } else {
                $mensaje = 'Error al actualizar.';
            }
            $u->close();
        }
    }
    // 2. Cambiar Password
    elseif (isset($_POST['cambiar_password'])) {
        $actual = $_POST['actual'] ?? '';
        $nueva  = $_POST['nueva']  ?? '';
        $nueva2 = $_POST['nueva2'] ?? '';

        if (!$actual || !$nueva || !$nueva2) {
            $mensaje = 'Completa todos los campos.';
        } elseif (!password_verify($actual, $hash_password)) {
            $mensaje = 'La contraseña actual es incorrecta.';
        } elseif ($nueva !== $nueva2) {
            $mensaje = 'Las contraseñas no coinciden.';
        } elseif (strlen($nueva) < 8) {
            $mensaje = 'Mínimo 8 caracteres.';
        } else {
            $nuevo_hash = password_hash($nueva, PASSWORD_DEFAULT);
            $u = $conn->prepare("UPDATE alumnos SET password = ? WHERE id = ?");
            $u->bind_param("si", $nuevo_hash, $usuario_id);
            if ($u->execute()) {
                $_SESSION['flash_mensaje'] = 'Contraseña actualizada.';
                $_SESSION['flash_exito']   = true;
                header("Location: /configurar-cuenta");
                exit;
            } else {
                $mensaje = 'Error al actualizar.';
            }
            $u->close();
        }
    }
    // 3. Eliminar Cuenta (Actualizado Nubira 2.0: Soft Delete)
    elseif (isset($_POST['eliminar_cuenta'])) {
        $frase  = trim($_POST['frase'] ?? '');
        $acepto = !empty($_POST['acepto_irreversible']);
        $pwd    = $_POST['pwd_confirm'] ?? '';

        if ($frase !== 'ELIMINAR MI CUENTA') {
            $mensaje = 'Frase de confirmación incorrecta.';
        } elseif (!$acepto) {
            $mensaje = 'Debes aceptar las consecuencias.';
        } elseif (!password_verify($pwd, $hash_password)) {
            $mensaje = 'Contraseña incorrecta.';
        } else {
            // [NUBIRA 2.0] Bloqueo por contrato abierto: IN explícito (no NOT IN) a
            // propósito — si el ENUM de contratos.estado gana un valor nuevo el día
            // de mañana, no bloqueará el borrado por accidente hasta que se decida
            // incluirlo. 'pendiente_pago' queda FUERA a propósito: un checkout sin
            // pagar no es un compromiso de dinero real, y como no hay cron que limpie
            // los pendiente_pago abandonados, bloquear por esos atraparía cuentas
            // legítimas por contratos basura que nadie completó. Sí bloquean
            // 'en_progreso' (la clase está activa) y 'finalizado_comprador'/
            // 'finalizado_vendedor' (una parte ya confirmó, la otra todavía no, y el
            // dinero no se ha liberado). Se mira tanto como vendedor (tutor) como
            // comprador: si quien se borra es el comprador de una clase en curso,
            // deja al tutor igual de colgado.
            $stmt_ctr = $conn->prepare("
                SELECT COUNT(*) FROM contratos
                WHERE (comprador_id = ? OR vendedor_id = ?)
                  AND estado IN ('en_progreso', 'finalizado_comprador', 'finalizado_vendedor')
            ");
            $stmt_ctr->bind_param("ii", $usuario_id, $usuario_id);
            $stmt_ctr->execute();
            $stmt_ctr->bind_result($contratos_abiertos);
            $stmt_ctr->fetch();
            $stmt_ctr->close();

            if ($contratos_abiertos > 0) {
                $mensaje = 'No puedes eliminar tu cuenta mientras tienes clases en curso. Espera a que finalicen o contáctanos.';
            } else {
                $nombre_borrado = 'Cuenta eliminada';
                $correo_borrado = 'deleted+' . $usuario_id . '@nubira.cl';
                $carrera_null   = null;
                $hash_random    = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
                $visible_cero   = 0; // Ocultar del sistema
                $bloqueado_uno  = 1; // Prevenir futuros accesos

                // Todo-o-nada: si falla ocultar servicios/apuntes, no queremos la
                // cuenta anonimizada a medias con publicaciones suyas comprables.
                $conn->begin_transaction();
                try {
                    // Actualizamos la consulta para incluir 'visible' y 'bloqueado'
                    $u = $conn->prepare("UPDATE alumnos SET nombre = ?, correo = ?, carrera = ?, password = ?, visible = ?, bloqueado = ? WHERE id = ?");
                    $u->bind_param("ssssiii", $nombre_borrado, $correo_borrado, $carrera_null, $hash_random, $visible_cero, $bloqueado_uno, $usuario_id);
                    if (!$u->execute()) throw new Exception($u->error);
                    $u->close();

                    // No debe quedar nada suyo comprable en la vitrina bajo la
                    // identidad ya anonimizada.
                    $us = $conn->prepare("UPDATE servicios SET visible = 0 WHERE alumno_id = ?");
                    $us->bind_param("i", $usuario_id);
                    if (!$us->execute()) throw new Exception($us->error);
                    $us->close();

                    $ua = $conn->prepare("UPDATE apuntes SET visible = 0 WHERE id_alumno = ?");
                    $ua->bind_param("i", $usuario_id);
                    if (!$ua->execute()) throw new Exception($ua->error);
                    $ua->close();

                    $conn->commit();
                } catch (Exception $e) {
                    $conn->rollback();
                    $mensaje = 'Error al eliminar cuenta.';
                }

                if (empty($mensaje)) {
                    session_destroy();
                    header('Location: /cuenta-eliminada');
                    exit;
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <title>Configurar Cuenta | Nubira</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
    body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }
    .pb-safe { padding-bottom: env(safe-area-inset-bottom); }
    /* NUBIRA FIX: Evitar zoom automático en móviles en los inputs */
    @media screen and (max-width: 768px) {
      input, select, textarea { font-size: 16px !important; }
    }
  </style>
</head>

<body class="bg-gray-50 min-h-screen text-gray-800 font-sans overflow-x-hidden">

<div id="loader" class="fixed inset-0 bg-white/95 flex items-center justify-center z-[60] transition-opacity duration-300">
  <div class="animate-spin h-10 w-10 border-4 border-blue-200 border-t-[#54A6D8] rounded-full"></div>
</div>

<?php
// [NUBIRA 2.0] Ocultar header global en móvil — mismo patrón que las demás páginas de gestión
echo '<div class="hidden md:block">';
require_once $app_dir . '/componentes/header.php';
echo '</div>';
require_once $app_dir . '/componentes/sidebar.php';
?>

<main class="pt-4 md:pt-16 pb-32 md:pb-12 lg:ml-64 px-4 md:px-8 mx-auto max-w-3xl xl:max-w-[1400px]">
  <div class="space-y-8">

    <div class="mb-4 flex items-center gap-3">
        <button type="button" onclick="navegacionSeguraNubira()"
                class="lg:hidden shrink-0 w-10 h-10 flex items-center justify-center rounded-full bg-gray-50 hover:bg-gray-100 border border-gray-200/60 shadow-sm active:scale-95 transition-all focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2"
                aria-label="Volver">
            <i class="fa-solid fa-arrow-left text-gray-700 text-[17px]"></i>
        </button>
        <div>
            <h1 class="text-xl md:text-2xl font-medium tracking-[-0.01em] text-[#222222]">Configuración de Cuenta</h1>
            <p class="text-gray-400 text-xs font-medium mt-0.5">Gestiona tu información personal y seguridad.</p>
        </div>
    </div>

    <?php if ($mensaje): ?>
      <div id="toast" class="rounded-xl px-4 py-3 shadow-sm flex items-center gap-3 <?= $exito ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200'; ?>">
         <?= icon($exito ? 'check-circle' : 'exclamation', 'w-5 h-5') ?>
         <span class="font-medium text-sm flex-1"><?= htmlspecialchars($mensaje) ?></span>
         <button onclick="document.getElementById('toast').remove()" class="text-sm underline hover:no-underline focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2">Cerrar</button>
      </div>
    <?php endif; ?>

    <div class="bg-white border border-gray-100 rounded-2xl shadow-sm p-6 md:p-8">
        <h2 class="text-lg font-bold text-gray-900 mb-6 flex items-center gap-2">
            <?= icon('user', 'w-5 h-5 text-[#54A6D8]') ?> Información Básica
        </h2>
        
        <form method="POST" class="space-y-6">
            <input type="hidden" name="editar_datos" value="1">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF, ENT_QUOTES) ?>">

            <div>
                <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1.5">Correo Institucional</label>
                <input type="email" value="<?= htmlspecialchars($correo_actual) ?>" readonly
                       class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-gray-500 cursor-not-allowed select-none text-sm">
                <p class="text-[10px] text-gray-400 mt-1 flex items-center gap-1"><?= icon('lock', 'w-3 h-3') ?> No editable por seguridad.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1.5">Nombre Completo</label>
                    <input type="text" name="nombre" value="<?= htmlspecialchars($nombre_actual) ?>" required
                           class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-gray-900 text-sm focus:ring-[#54A6D8] focus:border-[#54A6D8] transition outline-none">
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1.5">Carrera / Área</label>
                    <input type="text" name="carrera" value="<?= htmlspecialchars($carrera_actual ?? '') ?>" required
                           class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-gray-900 text-sm focus:ring-[#54A6D8] focus:border-[#54A6D8] transition outline-none">
                </div>
            </div>

            <div>
                <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1.5">Tipo de cuenta</label>
                <select name="tipo"
                        class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-gray-900 text-sm focus:ring-[#54A6D8] focus:border-[#54A6D8] transition outline-none appearance-none cursor-pointer">
                    <option value="">Sin especificar</option>
                    <option value="estudiante" <?= ($tipo_actual ?? '') === 'estudiante' ? 'selected' : '' ?>>Estudiante</option>
                    <option value="egresado"   <?= ($tipo_actual ?? '') === 'egresado'   ? 'selected' : '' ?>>Egresado</option>
                    <option value="profesor"   <?= ($tipo_actual ?? '') === 'profesor'   ? 'selected' : '' ?>>Profesor</option>
                    <option value="particular" <?= ($tipo_actual ?? '') === 'particular' ? 'selected' : '' ?>>Tutor Particular</option>
                </select>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1.5">Universidad / Institución</label>
                    <input type="text" name="universidad" maxlength="100"
                           value="<?= htmlspecialchars($univ_actual ?? '') ?>"
                           placeholder="Ej: USACH, UC, AIEP"
                           class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-gray-900 text-sm focus:ring-[#54A6D8] focus:border-[#54A6D8] transition outline-none">
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1.5">Año de egreso</label>
                    <input type="number" name="anio_egreso" min="1970" max="2030"
                           value="<?= htmlspecialchars($anio_eg_actual ?? '') ?>"
                           placeholder="2020"
                           class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-gray-900 text-sm focus:ring-[#54A6D8] focus:border-[#54A6D8] transition outline-none">
                </div>
            </div>

            <div>
                <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1.5">Años de experiencia enseñando</label>
                <input type="number" name="anios_experiencia" min="0" max="50"
                       value="<?= htmlspecialchars($anios_exp_actual ?? '') ?>"
                       placeholder="Ej: 3"
                       class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-gray-900 text-sm focus:ring-[#54A6D8] focus:border-[#54A6D8] transition outline-none">
            </div>

            <div>
                <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1.5">Bio profesional</label>
                <textarea name="bio" rows="4" maxlength="500"
                          placeholder="Cuéntanos tu experiencia y qué enseñas..."
                          class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-gray-900 text-sm focus:ring-[#54A6D8] focus:border-[#54A6D8] transition outline-none resize-none"><?= htmlspecialchars($bio_actual ?? '') ?></textarea>
            </div>

            <div class="flex justify-end pt-2">
                <button type="submit" class="bg-[#54A6D8] md:hover:bg-blue-600 text-white font-bold py-2.5 px-6 rounded-xl shadow-sm transition transform active:scale-95 text-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2">
                    Guardar Cambios
                </button>
            </div>
        </form>
    </div>

    <!-- Ancho escritorio: Seguridad + Eliminar Cuenta en grid de 2 columnas solo en
         xl. Información Básica arriba queda a ancho completo (es la tarjeta más
         grande). xl:space-y-0 para que el gap del grid mande, no el space-y-8 del
         contenedor padre — mismo patrón que en métricas. -->
    <div class="space-y-8 xl:space-y-0 xl:grid xl:grid-cols-2 xl:gap-8 xl:items-start">

    <div class="bg-white border border-gray-100 rounded-2xl shadow-sm p-6 md:p-8">
        <h2 class="text-lg font-bold text-gray-900 mb-6 flex items-center gap-2">
            <svg class="w-5 h-5 text-[#54A6D8]" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" /></svg>
            Seguridad
        </h2>

        <form method="POST" class="space-y-6">
            <input type="hidden" name="cambiar_password" value="1">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF, ENT_QUOTES) ?>">

            <div>
                <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1.5">Contraseña Actual</label>
                <input type="password" name="actual" required
                       class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-gray-900 text-sm focus:ring-[#54A6D8] focus:border-[#54A6D8] transition outline-none">
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1.5">Nueva Contraseña</label>
                    <input type="password" name="nueva" required minlength="8"
                           class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-gray-900 text-sm focus:ring-[#54A6D8] focus:border-[#54A6D8] transition outline-none">
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1.5">Confirmar Nueva</label>
                    <input type="password" name="nueva2" required minlength="8"
                           class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-gray-900 text-sm focus:ring-[#54A6D8] focus:border-[#54A6D8] transition outline-none">
                </div>
            </div>

            <div class="flex justify-end pt-2">
                <button type="submit" class="bg-gray-900 hover:bg-black text-white font-bold py-2.5 px-6 rounded-xl shadow-sm transition transform active:scale-95 text-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2">
                    Actualizar Contraseña
                </button>
            </div>
        </form>
    </div>

    <div class="bg-red-50 border border-red-100 rounded-2xl shadow-sm p-6 md:p-8">
        <h2 class="text-lg font-bold text-red-700 mb-2 flex items-center gap-2">
            <?= icon('exclamation', 'w-5 h-5') ?> Eliminar Cuenta
        </h2>
        <p class="text-sm text-red-600/80 mb-6 max-w-xl">
            Esta acción es <strong>irreversible</strong>. Eliminaremos tus datos personales, publicaciones y acceso a la plataforma de forma permanente.
        </p>

        <form method="POST" class="space-y-5" id="form-eliminar">
            <input type="hidden" name="eliminar_cuenta" value="1">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF, ENT_QUOTES) ?>">

            <div>
                <label class="block text-xs font-bold text-red-800 mb-1.5 uppercase tracking-wide">
                    Escribe la frase: <span class="bg-white px-1.5 py-0.5 rounded border border-red-200 font-mono select-all">ELIMINAR MI CUENTA</span>
                </label>
                <input type="text" name="frase" required placeholder="ELIMINAR MI CUENTA"
                       class="w-full bg-white border border-red-200 rounded-xl px-4 py-2.5 text-red-900 text-sm focus:ring-red-400 focus:border-red-400 transition outline-none placeholder-red-300">
            </div>

            <div>
                <label class="block text-xs font-bold text-red-800 mb-1.5 uppercase tracking-wide">Contraseña Actual</label>
                <input type="password" name="pwd_confirm" required
                       class="w-full bg-white border border-red-200 rounded-xl px-4 py-2.5 text-red-900 text-sm focus:ring-red-400 focus:border-red-400 transition outline-none">
            </div>

            <label class="flex items-start gap-3 text-sm text-red-700 cursor-pointer select-none">
                <input type="checkbox" name="acepto_irreversible" id="chk-irrev" class="mt-0.5 w-4 h-4 text-red-600 border-red-300 rounded focus:ring-red-500">
                <span>Entiendo y acepto que esta acción no se puede deshacer.</span>
            </label>

            <div class="flex justify-end pt-2">
                <button type="submit" id="btn-eliminar" disabled
                        class="bg-red-600 hover:bg-red-700 text-white font-bold py-2.5 px-6 rounded-xl shadow-sm transition opacity-50 cursor-not-allowed text-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2">
                    Eliminar Definitivamente
                </button>
            </div>
        </form>
    </div>

    </div><!-- /grid xl -->

  </div>
</main>

<?php 
require_once $app_dir . '/componentes/nav_bottom.php'; 
require_once $app_dir . '/componentes/modal_publicar.php'; 
require_once $app_dir . '/componentes/modal_explora.php'; 
?>

<script>
window.onload = () => { const l = document.getElementById('loader'); if(l){ l.classList.add('opacity-0'); setTimeout(()=>l.classList.add('hidden'),300); } };

// [NUBIRA 2.0] Volver — mismo patrón que las demás páginas de gestión, con fallback
// a /perfil (tile "Configurar Cuenta" en panel_gestion.php; también accesible por
// link directo de correo, sin historial previo).
window.navegacionSeguraNubira = function() {
    if (window.history.length > 1) {
        window.history.back();
    } else {
        window.location.href = '/perfil';
    }
};

function setupModal(triggerId, modalId, cardId, closeId) {
    const btn=document.getElementById(triggerId), modal=document.getElementById(modalId), card=document.getElementById(cardId), close=document.getElementById(closeId);
    if(!btn||!modal) return;
    const open=()=>{modal.classList.remove('hidden'); requestAnimationFrame(()=>card.classList.remove('translate-y-full','opacity-0')); document.body.style.overflow='hidden';};
    const shut=()=>{card.classList.add('translate-y-full','opacity-0'); setTimeout(()=>{modal.classList.add('hidden');document.body.style.overflow='';},300);};
    btn.onclick=(e)=>{e.preventDefault();open();}; 
    if(close) close.onclick=shut; 
    modal.onclick=(e)=>{if(e.target===modal)shut();};
}
setupModal('btn-publicar', 'modal-quick', 'quick-card', 'quick-close');
setupModal('btn-explora', 'modal-explora', 'explora-card', 'explora-close');

// Activar botón eliminar
document.getElementById('chk-irrev')?.addEventListener('change', function() {
    const btn = document.getElementById('btn-eliminar');
    if(this.checked) {
        btn.disabled = false;
        btn.classList.remove('opacity-50', 'cursor-not-allowed');
    } else {
        btn.disabled = true;
        btn.classList.add('opacity-50', 'cursor-not-allowed');
    }
});
</script>

</body>
</html>