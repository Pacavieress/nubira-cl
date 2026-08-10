<?php
session_start();
require_once(__DIR__ . '/app/conexion.php');
require_once(__DIR__ . '/app/helpers/seo.php');

// Auto-migración: sistema de verificación híbrido
try { $conn->query("ALTER TABLE alumnos ADD COLUMN verificacion_estado VARCHAR(20) DEFAULT NULL"); } catch (Throwable $e) {}
try { $conn->query("ALTER TABLE alumnos ADD COLUMN universidad VARCHAR(100) DEFAULT NULL"); } catch (Throwable $e) {}
try { $conn->query("ALTER TABLE alumnos ADD COLUMN anio_egreso INT DEFAULT NULL"); } catch (Throwable $e) {}
try { $conn->query("ALTER TABLE alumnos ADD COLUMN anios_experiencia INT DEFAULT NULL"); } catch (Throwable $e) {}
try { $conn->query("ALTER TABLE alumnos ADD COLUMN intencion_uso ENUM('vender','comprar') DEFAULT NULL"); } catch (Throwable $e) {}

// 0. CAPTURAR REDIRECCIÓN (LAZY REGISTRATION)
$redir_destino = $_GET['redir'] ?? $_SESSION['redirigir_despues_login'] ?? '';

// Filtro Anti Open-Redirect para la captura inicial
if (!empty($redir_destino) && (strpos($redir_destino, '/') !== 0 || strpos($redir_destino, '//') === 0)) {
    $redir_destino = ''; 
}

// -------------------------------------------------------------------------
// 1. AUTO-LOGIN ("RECORDARME")
// -------------------------------------------------------------------------
if (!isset($_SESSION['usuario_id']) && isset($_COOKIE['remember_token'])) {
    $token_cookie = $_COOKIE['remember_token'];
    
  // [NUBIRA 2.0] Solo permite auto-login si el usuario está visible y no bloqueado
    $stmt_auto = $conn->prepare("SELECT * FROM alumnos WHERE remember_token = ? AND visible = 1 AND bloqueado = 0");
    $stmt_auto->bind_param("s", $token_cookie);
    $stmt_auto->execute();
    $res_auto = $stmt_auto->get_result();

    if ($res_auto->num_rows === 1) {
        $usuario_auto = $res_auto->fetch_assoc();
        $correo = strtolower($usuario_auto['correo']);
        $dominio = substr(strrchr($correo, "@"), 1);
        
        $institucion = '';
        $stmt_dom = $conn->prepare("SELECT institucion FROM dominios_permitidos WHERE dominio = ?");
        $stmt_dom->bind_param("s", $dominio);
        $stmt_dom->execute();
        $res_dom = $stmt_dom->get_result();
        
        if ($res_dom->num_rows === 1) {
            $institucion = $res_dom->fetch_assoc()['institucion'];
        } else {
            $stmt_exc = $conn->prepare("SELECT id FROM excepciones_email WHERE correo = ? AND activo = 1");
            $stmt_exc->bind_param("s", $correo);
            $stmt_exc->execute();
            if ($stmt_exc->get_result()->num_rows > 0) {
                $institucion = 'Excepción Gmail';
            }
        }

        session_regenerate_id(true);
        $_SESSION['usuario_id']          = $usuario_auto['id'];
        $_SESSION['usuario_nombre']      = $usuario_auto['nombre'];
        $_SESSION['rol']                 = $usuario_auto['rol'] ?? 'alumno';
        $_SESSION['email']               = $usuario_auto['correo'];
        $_SESSION['dominio']             = $dominio;
        $_SESSION['institucion']         = $institucion;
        $_SESSION['verificacion_estado'] = $usuario_auto['verificacion_estado'] ?? null;
        // [NUBIRA 2.0] "Completo" es bio llena (ruta vender) O institución llena + eligió
        // comprar (ruta comprar, no requiere bio) — sin esto, la ruta comprar nunca se
        // marcaría completa y login volvería a mandar a /completar_perfil en cada sesión.
        $_SESSION['perfil_completo']     = !empty(trim($usuario_auto['bio'] ?? ''))
            || (($usuario_auto['intencion_uso'] ?? '') === 'comprar' && !empty(trim($usuario_auto['institucion'] ?? '')));

        // --- [NUBIRA 2.0] CACHÉ DE TUTOR Y SUGERENCIAS (AUTO-LOGIN) ---
        $_SESSION['notif_sugerencia_vista'] = (int)($usuario_auto['notif_sugerencia_vista'] ?? 0);

        $stmt_tutor = $conn->prepare("SELECT 1 FROM servicios WHERE alumno_id = ? AND estado = 'aprobado' UNION SELECT 1 FROM apuntes WHERE id_alumno = ? AND estado = 'aprobado' LIMIT 1");
        if ($stmt_tutor) {
            $stmt_tutor->bind_param("ii", $usuario_auto['id'], $usuario_auto['id']);
            $stmt_tutor->execute();
            $stmt_tutor->store_result();
            $_SESSION['es_tutor_activo'] = ($stmt_tutor->num_rows > 0);
            $stmt_tutor->close();
        } else {
            $_SESSION['es_tutor_activo'] = false;
        }
        // --------------------------------------------------------------

        $stmt_upd = $conn->prepare("UPDATE alumnos SET ultima_sesion = NOW() WHERE id = ?");
        $stmt_upd->bind_param("i", $usuario_auto['id']);
        $stmt_upd->execute();
        $stmt_upd->close();
    }
    $stmt_auto->close();
}

// -------------------------------------------------------------------------
// 2. REDIRECCIÓN SI YA HAY SESIÓN
// -------------------------------------------------------------------------
if (isset($_SESSION['usuario_id'])) {
    $est = $_SESSION['verificacion_estado'] ?? null;
    if ($est === 'pendiente' && !($_SESSION['perfil_completo'] ?? false)) { header("Location: /completar_perfil"); exit; }
    if (!empty($redir_destino)) { header("Location: " . $redir_destino); exit; }
    header("Location: " . ($est === 'pendiente' ? '/vitrina?aviso=verificacion_pendiente' : '/vitrina'));
    exit;
}

// --- CAMBIO QUIRÚRGICO AQUÍ: Capturamos alertas por $_SESSION o por $_GET ---
$mensaje = "";
if (isset($_SESSION['mensaje_login'])) {
    $mensaje = $_SESSION['mensaje_login'];
    unset($_SESSION['mensaje_login']);
} elseif (isset($_GET['mensaje']) && $_GET['mensaje'] === 'promo_agotada') {
    $mensaje = "La promo gratuita de este apunte ya se agotó. Inicia sesión para verlo con tu cuenta.";
}

// -------------------------------------------------------------------------
// 3. PROCESAR LOGIN
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $correo = strtolower(trim($_POST['correo'] ?? ''));
    $contrasena = $_POST['contrasena'] ?? '';
    $redir_post = $_POST['redir'] ?? ''; 
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    $limite_intentos = 5;  
    $tiempo_bloqueo  = 15; 

    if (!$correo || !$contrasena) {
        $mensaje = "Debes completar ambos campos.";
    } else {
        $stmt_fallos = $conn->prepare("SELECT COUNT(*) AS fallos FROM login_fallos WHERE correo = ? AND fecha > (NOW() - INTERVAL ? MINUTE)");
        $stmt_fallos->bind_param("si", $correo, $tiempo_bloqueo);
        $stmt_fallos->execute();
        $fallos = $stmt_fallos->get_result()->fetch_assoc()['fallos'] ?? 0;
        $stmt_fallos->close();

        if ($fallos >= $limite_intentos) {
            $mensaje = "Demasiados intentos. Espera $tiempo_bloqueo min.";
        } else {
          // [NUBIRA 2.0] Verificamos el usuario, pero con escudo de Soft Delete
            $stmt = $conn->prepare("SELECT * FROM alumnos WHERE correo = ?");
            $stmt->bind_param("s", $correo);
            $stmt->execute();
            $resultado = $stmt->get_result();

            if ($resultado->num_rows === 1) {
                $usuario = $resultado->fetch_assoc();
                
                // --- LÓGICA DE BLOQUEO Y SOFT DELETE NUBIRA 2.0 ---
                if ((int)$usuario['visible'] === 0) {
                    $mensaje = "Esta cuenta ha sido eliminada o desactivada.";
                }

                if (empty($mensaje) && (int)$usuario['bloqueado'] === 1) {
                    $susp_hasta = $usuario['suspendido_hasta'] ?? null;
                    if ($susp_hasta !== null && strtotime($susp_hasta) <= time()) {
                        // Suspensión vencida: desbloqueo automático
                        $motivo_prev = $usuario['motivo_suspension'] ?? null;
                        $stmt_unban  = $conn->prepare("UPDATE alumnos SET bloqueado = 0, suspendido_hasta = NULL, motivo_suspension = NULL WHERE id = ?");
                        $stmt_unban->bind_param("i", $usuario['id']);
                        $stmt_unban->execute();
                        $stmt_unban->close();
                        $meta_auto = json_encode(['motivo_anterior' => $motivo_prev, 'suspendido_hasta_anterior' => $susp_hasta]);
                        $uid_zero  = 0;
                        $stmt_aud  = $conn->prepare("INSERT INTO auditoria_admin (admin_id, accion, usuario_afectado_id, motivo, metadata) VALUES (?, 'desbloqueo_automatico', ?, NULL, ?)");
                        $stmt_aud->bind_param("iis", $uid_zero, $usuario['id'], $meta_auto);
                        $stmt_aud->execute();
                        $stmt_aud->close();
                        $usuario['bloqueado'] = 0;
                    } else {
                        $mensaje = $susp_hasta !== null
                            ? "Tu cuenta está suspendida hasta el " . date('d/m/Y', strtotime($susp_hasta)) . ". Contacta a soporte si crees que es un error."
                            : "Tu cuenta está suspendida. Contacta a soporte.";
                    }
                }

                if (empty($mensaje) && (int)$usuario['confirmado'] !== 1) {
                    $mensaje = "Cuenta no confirmada. Revisa tu correo.";
                }

                if (empty($mensaje)) {
                    if (password_verify($contrasena, $usuario['password'])) {

                        // --- LOGIN EXITOSO ---
                        session_regenerate_id(true);
                        $_SESSION['usuario_id'] = $usuario['id'];
                        $_SESSION['usuario_nombre'] = $usuario['nombre'];
                        $_SESSION['rol'] = $usuario['rol'] ?? 'alumno';
                        $_SESSION['email'] = $usuario['correo'];

                        // --- [NUBIRA 2.0] CACHÉ DE TUTOR Y SUGERENCIAS (LOGIN NORMAL) ---
                        $_SESSION['notif_sugerencia_vista'] = (int)($usuario['notif_sugerencia_vista'] ?? 0);

                        $stmt_tutor = $conn->prepare("SELECT 1 FROM servicios WHERE alumno_id = ? AND estado = 'aprobado' UNION SELECT 1 FROM apuntes WHERE id_alumno = ? AND estado = 'aprobado' LIMIT 1");
                        if ($stmt_tutor) {
                            $stmt_tutor->bind_param("ii", $usuario['id'], $usuario['id']);
                            $stmt_tutor->execute();
                            $stmt_tutor->store_result();
                            $_SESSION['es_tutor_activo'] = ($stmt_tutor->num_rows > 0);
                            $stmt_tutor->close();
                        } else {
                            $_SESSION['es_tutor_activo'] = false;
                        }
                        // ----------------------------------------------------------------

                        // LÓGICA DE LA COOKIE "RECORDARME"
                        if (isset($_POST['recordarme']) && $_POST['recordarme'] == '1') {
                            $token = bin2hex(random_bytes(32));
                            $stmt_token = $conn->prepare("UPDATE alumnos SET remember_token = ? WHERE id = ?");
                            $stmt_token->bind_param("si", $token, $usuario['id']);
                            $stmt_token->execute();
                            setcookie('remember_token', $token, time() + (86400 * 30), "/", "", true, true); // 30 días
                        }

                        // --- REDIRECCIÓN POST-LOGIN SEGÚN ESTADO DE VERIFICACIÓN ---
                        $_SESSION['verificacion_estado'] = $usuario['verificacion_estado'] ?? null;
                        // [NUBIRA 2.0] Mismo criterio que el bloque de auto-login (remember_token) más arriba.
                        $_SESSION['perfil_completo']     = !empty(trim($usuario['bio'] ?? ''))
                            || (($usuario['intencion_uso'] ?? '') === 'comprar' && !empty(trim($usuario['institucion'] ?? '')));
                        $est = $_SESSION['verificacion_estado'];

                        if ($est === 'pendiente') {
                            header("Location: " . ($_SESSION['perfil_completo'] ? '/vitrina?aviso=verificacion_pendiente' : '/completar_perfil'));
                            exit;
                        }

                        // Estado 'aprobado', 'rechazado' o NULL: flujo normal
                        $ruta_final = '/vitrina';
                        if (!empty($redir_post)) {
                            $ruta_final = filter_var($redir_post, FILTER_SANITIZE_URL);
                        } elseif (!empty($_SESSION['redirigir_despues_login'])) {
                            $ruta_final = $_SESSION['redirigir_despues_login'];
                        }
                        unset($_SESSION['redirigir_despues_login']);
                        if (strpos($ruta_final, '/') !== 0 || strpos($ruta_final, '//') === 0) {
                            $ruta_final = '/vitrina';
                        }
                        if ($ruta_final === '/perfil' || $ruta_final === '/perfil/') {
                            $ruta_final = '/perfil/' . $usuario['id'];
                        }
                        header("Location: " . $ruta_final);
                        exit;

                    } else {
                        $mensaje = "Contraseña incorrecta.";
                        // Registrar fallo
                        $stmt_ins = $conn->prepare("INSERT INTO login_fallos (correo, ip) VALUES (?, ?)");
                        $stmt_ins->bind_param("ss", $correo, $ip);
                        $stmt_ins->execute();
                    }
                }
            } else {
                $mensaje = "No existe una cuenta con este correo.";
            }
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Iniciar Sesión | Nubira</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <?= nubira_canonical_tag() ?>
  <?php if (!empty($_GET['redir'])): ?>
  <meta name="robots" content="noindex,nofollow" />
  <?php endif; ?>
  <?php require_once __DIR__ . '/app/componentes/head_common.php'; ?>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    @keyframes shake { 0%, 100% { transform: translateX(0); } 20%, 60% { transform: translateX(-4px); } 40%, 80% { transform: translateX(4px); } }
    .animate-shake { animation: shake 0.4s ease-in-out; }
  </style>
</head>

<body class="bg-white min-h-screen flex antialiased text-gray-800">

  <div class="hidden lg:flex w-1/2 bg-cover bg-center relative" 
       style="background-image: url('https://images.unsplash.com/photo-1523240795612-9a054b0db644?q=80&w=2070&auto=format&fit=crop');">
       <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-black/20 to-transparent"></div>
       <div class="absolute bottom-12 left-12 text-white pr-12 z-10">
           <h2 class="text-4xl font-extrabold mb-3 leading-tight tracking-tight">Conecta con tu<br>comunidad académica.</h2>
           <p class="text-lg opacity-90 font-medium">Tu próxima oportunidad de aprendizaje.</p>
       </div>
  </div>

  <div class="w-full lg:w-1/2 bg-white min-h-screen overflow-y-auto">
    <div class="w-full max-w-[400px] mx-auto px-6 pt-8 pb-10 md:pt-16">

        <div class="mb-6 md:mb-10 text-center md:text-left">
            <a href="/" class="inline-block transition-transform hover:scale-105">
                <img src="/img/logo.webp" alt="Logo Nubira" class="h-9 md:h-10 w-auto mx-auto md:mx-0">
            </a>
        </div>

        <h1 class="text-2xl md:text-3xl font-bold text-gray-900 mb-1 tracking-tight">¡Hola de nuevo!</h1>
        <p class="text-gray-500 mb-6 md:mb-8 text-sm">Ingresa tus datos para continuar.</p>

        <?php if ($mensaje): ?>
            <div class="mb-5 p-4 rounded-xl flex items-start gap-3 text-sm border animate-shake bg-red-50 text-red-700 border-red-100">
                <i class="fa-solid fa-circle-exclamation mt-0.5"></i>
                <div class="flex-1 font-medium leading-tight"><?= $mensaje ?></div>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-4 md:space-y-5" id="loginForm">
            
            <?php if (!empty($redir_destino)): ?>
                <input type="hidden" name="redir" value="<?= htmlspecialchars($redir_destino, ENT_QUOTES, 'UTF-8') ?>">
            <?php endif; ?>

            <div>
                <label for="correo" class="block text-xs font-bold text-gray-700 uppercase tracking-wide mb-1.5">Correo electrónico</label>
                <div class="relative">
                    <i class="fa-regular fa-envelope absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                    <input type="email" name="correo" id="correo" autocomplete="email" required
                           class="w-full pl-10 pr-4 py-3 rounded-xl border border-gray-200 bg-gray-50 focus:bg-white focus:ring-2 focus:ring-[#54A6D8] outline-none transition-all font-medium placeholder-gray-400 text-[16px]"
                           placeholder="tucorreo@ejemplo.com">
                </div>
            </div>

            <div>
                <div class="flex justify-between items-center mb-1.5">
                    <label for="contrasena" class="block text-xs font-bold text-gray-700 uppercase tracking-wide">Contraseña</label>
                    <a href="/recuperar" class="text-xs text-[#54A6D8] hover:underline font-bold">¿Olvidaste tu clave?</a>
                </div>
                <div class="relative">
                    <i class="fa-solid fa-lock absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 z-10"></i>
                    <input type="password" name="contrasena" id="contrasena" autocomplete="current-password" required
                           class="w-full pl-10 pr-12 py-3 rounded-xl border border-gray-200 bg-gray-50 focus:bg-white focus:ring-2 focus:ring-[#54A6D8] outline-none transition-all font-medium placeholder-gray-400 text-[16px]"
                           placeholder="••••••••">
                    
                    <button type="button" id="togglePassword" class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-[#54A6D8] z-10">
                        <i class="fa-solid fa-eye"></i>
                    </button>
                </div>
            </div>

            <div class="flex items-center">
                <input type="checkbox" name="recordarme" id="recordarme" value="1" class="w-4 h-4 text-[#54A6D8] border-gray-300 rounded focus:ring-[#54A6D8]">
                <label for="recordarme" class="ml-2 block text-sm text-gray-600 select-none font-medium">Mantener sesión</label>
            </div>

            <button type="submit" id="loginBtn" 
                    class="w-full bg-[#54A6D8] hover:bg-[#3d91c7] text-white font-bold py-3.5 rounded-xl shadow-md transition-all duration-200 flex justify-center items-center gap-2 text-base">
                <span>Ingresar</span>
                <i class="fa-solid fa-arrow-right"></i>
            </button>

        </form>

        <div class="mt-6 text-center border-t border-gray-100 pt-5">
            <p class="text-sm text-gray-600 font-medium">
                ¿No tienes cuenta? 
                <a href="/registro<?php echo !empty($redir_destino) ? '?redir='.urlencode($redir_destino) : ''; ?>" class="text-[#54A6D8] font-bold hover:underline ml-1">Regístrate gratis</a>
            </p>
        </div>

        <div class="mt-4 mb-2 text-center">
            <p class="text-[11px] text-gray-500 font-medium">
                ¿Necesitas ayuda? Escríbenos a <a href="mailto:contacto@nubira.cl" class="text-[#54A6D8] hover:underline font-bold">contacto@nubira.cl</a>
            </p>
        </div>

    </div>
  </div>

  <script>
    const form = document.getElementById('loginForm');
    const btn = document.getElementById('loginBtn');
    if(form){
        form.addEventListener('submit', () => {
          btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Verificando...';
          btn.disabled = true;
        });
    }
    const toggleBtn = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('contrasena');
    if(toggleBtn && passwordInput){
        toggleBtn.addEventListener('click', () => {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            toggleBtn.querySelector('i').classList.toggle('fa-eye');
            toggleBtn.querySelector('i').classList.toggle('fa-eye-slash');
        });
    }
  </script>
</body>
</html>