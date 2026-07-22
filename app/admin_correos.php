<?php
session_start();

// Protección solo admin
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'admin') {
    header("Location: ../public/login.html");
    exit;
}

require_once __DIR__ . '/correo.php';         // tus funciones de envío
require_once __DIR__ . '/conexion.php';       // conexión a la base de datos

$LOG_PATH = __DIR__ . '/log_envio.txt'; // Cambia si está en otro lado
$log_data = [];
if (file_exists($LOG_PATH)) {
    $log_data = file($LOG_PATH, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $log_data = array_reverse($log_data); // Lo más reciente arriba
}

// --- FILTRO BÁSICO ---
$busqueda = trim($_GET['buscar'] ?? '');
$filtro = [];
foreach ($log_data as $line) {
    if ($busqueda === '' || stripos($line, $busqueda) !== false) $filtro[] = $line;
}

// --- REENVIAR REAL ---
$mensaje_reenvio = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reenviar_linea'])) {
    $linea = $filtro[intval($_POST['reenviar_linea'])] ?? null;

    // Intentar extraer correo y token
    if ($linea && preg_match('/a (\S+) \/ Token: ([a-f0-9]{32,})/', $linea, $match)) {
        $correo = $match[1];
        $token  = $match[2];

        // Buscar nombre real (opcional pero recomendado)
        $stmt = $conn->prepare("SELECT nombre FROM alumnos WHERE correo = ?");
        $stmt->bind_param("s", $correo);
        $stmt->execute();
        $res = $stmt->get_result();
        $usuario = $res->fetch_assoc();
        $nombre = $usuario['nombre'] ?? 'Usuario';

        // Detectar tipo de correo a reenviar
        $tipo = 'Desconocido';
        if (stripos($linea, 'recupera') !== false || stripos($linea, 'Recupera') !== false) {
            $ok = enviarCorreoRecuperacion($correo, $nombre, $token);
            $tipo = 'Recuperación';
            $mensaje_reenvio = $ok ? "Correo de recuperación reenviado a $correo." : "Error al reenviar a $correo.";
        } elseif (stripos($linea, 'confirma') !== false || stripos($linea, 'Confirma') !== false) {
            $ok = enviarCorreoConfirmacion($correo, $nombre, $token);
            $tipo = 'Confirmación';
            $mensaje_reenvio = $ok ? "Correo de confirmación reenviado a $correo." : "Error al reenviar a $correo.";
        } else {
            $ok = enviarCorreoRecuperacion($correo, $nombre, $token);
            $mensaje_reenvio = $ok ? "(Desconocido) Se intentó reenviar recuperación a $correo." : "Error al reenviar.";
        }
    } else {
        $mensaje_reenvio = "No se pudo identificar el correo o el token para reenviar.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Admin - Gestión de Correos</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@0.9.4/css/bulma.min.css">
    <style>
        .log-ok    { color: #22c55e; font-weight: 500; }
        .log-error { color: #e11d48; font-weight: 700; }
        .log-other { color: #222; }
        .log-tipo  { font-size: 0.9em; padding: 2px 8px; border-radius: 10px; background: #f3f4f6; margin-right: 8px;}
    </style>
</head>
<body>
<section class="section">
    <div class="container">
        <h1 class="title mb-2"><span style="font-size:1.2em;">📬</span> Administración de Correos</h1>
        <a href="admin_dashboard.php" class="button is-light mb-3">← Volver al panel</a>
        <form class="mb-4" method="get" action="admin_correos.php">
            <div class="field has-addons">
                <div class="control">
                    <input class="input" type="text" name="buscar" placeholder="Buscar por email, token o error..." value="<?= htmlspecialchars($busqueda) ?>">
                </div>
                <div class="control">
                    <button class="button is-info" type="submit">🔍 Buscar</button>
                </div>
                <?php if ($busqueda): ?>
                <div class="control">
                    <a href="admin_correos.php" class="button is-light">Limpiar</a>
                </div>
                <?php endif; ?>
            </div>
        </form>
        
        <?php if ($mensaje_reenvio): ?>
            <div class="notification is-info"><?= htmlspecialchars($mensaje_reenvio) ?></div>
        <?php endif; ?>

        <div class="box" style="max-height: 500px; overflow-y: auto;">
            <?php if (empty($filtro)): ?>
                <p>No hay correos para mostrar.</p>
            <?php else: ?>
                <table class="table is-striped is-hoverable is-fullwidth">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Tipo</th>
                            <th>Detalle</th>
                            <th>Reenviar</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($filtro as $idx => $line): ?>
                        <?php
                        $class = 'log-other';
                        if (strpos($line, 'OK') !== false) $class = 'log-ok';
                        elseif (strpos($line, 'FALLÓ') !== false || strpos($line, 'ERROR') !== false) $class = 'log-error';

                        // Detectar tipo para la columna (puedes mejorar la lógica si agregas más tipos)
                        $tipo_txt = 'Desconocido';
                        if (stripos($line, 'recupera') !== false)      $tipo_txt = 'Recuperación';
                        elseif (stripos($line, 'confirma') !== false) $tipo_txt = 'Confirmación';
                        elseif (stripos($line, 'retiro') !== false)   $tipo_txt = 'Retiro';
                        ?>
                        <tr>
                            <td><?= $idx+1 ?></td>
                            <td><span class="log-tipo"><?= $tipo_txt ?></span></td>
                            <td class="<?= $class ?>" style="font-size:13px;"><?= htmlspecialchars($line) ?></td>
                            <td>
                            <?php if (strpos($line, 'Intentando enviar a') !== false): ?>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="reenviar_linea" value="<?= $idx ?>">
                                    <button type="submit" class="button is-small is-warning">↩️ Reenviar</button>
                                </form>
                            <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <a href="logs_correo.php" class="button is-small is-link mt-3">Ir a Logs de Correo</a>
    </div>
</section>
</body>
</html>
