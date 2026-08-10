<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/app/conexion.php';

$token = $_GET['token'] ?? '';
$correo_param = $_GET['e'] ?? '';

// [NUBIRA 2.0] redir llega intacto desde el link del correo (enviarCorreoConfirmacion
// en app/correo.php ya lo agrega si register.php lo recibió). Mismo filtro anti
// open-redirect que login.php, porque esta vez el valor viene de una URL externa (el correo).
$redir = $_GET['redir'] ?? '';
if (!empty($redir) && (strpos($redir, '/') !== 0 || strpos($redir, '//') === 0)) {
    $redir = '';
}
$url_final = '/login' . (!empty($redir) ? '?redir=' . urlencode($redir) : '');

$nombre_alumno = '';
$mensaje = '';
$tipo = '';
$icono = '';

if ($token) {
    // 1. Intentar confirmar por token (caso normal)
    $stmt = $conn->prepare("SELECT id, nombre FROM alumnos WHERE token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $resultado = $stmt->get_result();

    if ($resultado->num_rows > 0) {
        // Token válido → confirmar cuenta
        $fila = $resultado->fetch_assoc();
        $id_alumno = $fila['id'];
        $nombre_alumno = ucwords(strtolower($fila['nombre']));

        $stmt2 = $conn->prepare("UPDATE alumnos SET confirmado = 1, token = NULL WHERE id = ?");
        $stmt2->bind_param("i", $id_alumno);
        $stmt2->execute();
        $stmt2->close();

        $mensaje = "¡Hola <strong>$nombre_alumno</strong>!<br>Tu cuenta ha sido activada con éxito.";
        $tipo = "success";
        $icono = "fa-circle-check text-green-500";

    } else {
        // 2. Token no encontrado → verificar si ya está confirmado vía correo
        $stmt->close();
        if ($correo_param) {
            $stmt3 = $conn->prepare("SELECT nombre, confirmado FROM alumnos WHERE correo = ? AND visible = 1");
            $stmt3->bind_param("s", $correo_param);
            $stmt3->execute();
            $res3 = $stmt3->get_result();

            if ($res3->num_rows > 0) {
                $fila3 = $res3->fetch_assoc();
                if (!empty($fila3['confirmado'])) {
                    // Ya confirmado previamente
                    $nombre_alumno = ucwords(strtolower($fila3['nombre']));
                    $mensaje = "¡Hola <strong>$nombre_alumno</strong>!<br>Tu cuenta ya estaba activada. Puedes iniciar sesión normalmente.";
                    $tipo = "success";
                    $icono = "fa-circle-check text-green-500";
                } else {
                    // Existe pero sin token → caso raro
                    $mensaje = "Tu enlace de activación expiró.<br>Solicita un nuevo correo desde el inicio de sesión.";
                    $tipo = "error";
                    $icono = "fa-clock text-orange-500";
                }
            } else {
                $mensaje = "Este enlace no es válido.<br>Si ya confirmaste tu cuenta, puedes iniciar sesión.";
                $tipo = "error";
                $icono = "fa-circle-xmark text-red-500";
            }
            $stmt3->close();
        } else {
            $mensaje = "Este enlace ya fue usado o es inválido.<br>Si ya confirmaste tu cuenta, puedes iniciar sesión.";
            $tipo = "error";
            $icono = "fa-circle-xmark text-red-500";
        }
    }
} else {
    $mensaje = "Enlace de seguridad no proporcionado.";
    $tipo = "error";
    $icono = "fa-shield-halved text-orange-500";
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Confirmar cuenta | Nubira</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php require_once __DIR__ . '/app/componentes/head_common.php'; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <meta http-equiv="refresh" content="5;url=<?= htmlspecialchars($url_final, ENT_QUOTES, 'UTF-8') ?>">
    <style>
        @keyframes scaleIn { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        .animate-scale-in { animation: scaleIn 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center p-4 antialiased text-gray-800">

    <div class="bg-white p-8 md:p-10 rounded-[2rem] shadow-xl w-full max-w-md text-center border border-gray-100 animate-scale-in">
        
        <div class="mb-6">
            <img src="/img/logo.webp" alt="Nubira" class="h-10 w-auto mx-auto opacity-80">
        </div>

        <div class="w-20 h-20 rounded-full bg-gray-50 flex items-center justify-center mx-auto mb-6 shadow-sm border border-gray-100">
            <i class="fa-solid <?= $icono ?> text-4xl drop-shadow-sm"></i>
        </div>

        <h1 class="text-2xl font-extrabold text-gray-900 mb-3 tracking-tight">Verificación</h1>
        
        <p class="text-gray-500 text-sm leading-relaxed mb-8">
            <?= $mensaje ?>
        </p>

        <a href="<?= htmlspecialchars($url_final, ENT_QUOTES, 'UTF-8') ?>" class="w-full inline-flex justify-center items-center gap-2 bg-[#54A6D8] hover:bg-[#4592c0] text-white font-bold py-3.5 rounded-xl shadow-md hover:shadow-lg transition-all active:scale-[0.98]">
            Ir al inicio de sesión <i class="fa-solid fa-arrow-right text-xs"></i>
        </a>
        
        <p class="mt-6 text-xs text-gray-400 font-medium">
            Serás redirigido automáticamente en <span id="contador">5</span> segundos.
        </p>
    </div>

    <script>
        // Efecto visual del contador
        let seg = 5;
        const cont = document.getElementById('contador');
        setInterval(() => {
            if(seg > 1) { seg--; cont.innerText = seg; }
        }, 1000);
    </script>

</body>
</html>