<?php
session_start();

// 1. Protección: solo admin
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'admin') {
    header("Location: ../public/login.html");
    exit;
}

// Parámetros configurables
$LOG_PATH = __DIR__ . '/log_envio.txt'; // Ajusta si cambias de carpeta
$NUM_MOSTRAR = 100; // Mostrar solo los últimos 100 registros (puedes ajustar)

// Leer y procesar el log
$log_data = [];
if (file_exists($LOG_PATH)) {
    $log_data = file($LOG_PATH, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $log_data = array_reverse($log_data); // Mostrar lo más reciente arriba
    $log_data = array_slice($log_data, 0, $NUM_MOSTRAR);
}

// Descargar como CSV
if (isset($_GET['descargar']) && $log_data) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment;filename="log_envio.csv"');
    $out = fopen('php://output', 'w');
    foreach ($log_data as $line) {
        fputcsv($out, [$line]);
    }
    fclose($out);
    exit;
}

// Limpiar log si se solicita
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['limpiar'])) {
    file_put_contents($LOG_PATH, "");
    header("Location: logs_correo.php");
    exit;
}

// Estadísticas rápidas
$total = count($log_data);
$ok = 0;
$fallos = 0;
foreach ($log_data as $line) {
    if (strpos($line, 'Resultado de envío: OK') !== false) $ok++;
    if (strpos($line, 'Resultado de envío: FALLÓ') !== false) $fallos++;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Logs de Envío de Correos</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
    <meta http-equiv="refresh" content="10">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@0.9.4/css/bulma.min.css">
    <style>
        .log-ok    { color: #22c55e; font-weight: 500; }
        .log-error { color: #e11d48; font-weight: 700; }
        .log-other { color: #222; }
        .stat-card { display:inline-block;min-width:140px;margin-right:16px;padding:10px 16px;background:#f9fafb;border-radius:10px;font-size:15px;text-align:center;}
    </style>
</head>
<body>
<section class="section">
    <div class="container">
        <h1 class="title mb-2"><span style="font-size:1.5em;">📧</span> Logs de Envío de Correos</h1>
        <a href="/dashboard" class="button is-light mb-3">← Volver al panel</a>

        <!-- Estadísticas rápidas -->
        <div class="mb-3">
            <span class="stat-card">Total registros<br><b><?= $total ?></b></span>
            <span class="stat-card" style="color:#22c55e;">Envíos OK<br><b><?= $ok ?></b></span>
            <span class="stat-card" style="color:#e11d48;">Fallos<br><b><?= $fallos ?></b></span>
            <a href="?descargar=1" class="button is-small is-link ml-4">Descargar CSV</a>
        </div>

        <div class="box" style="max-height: 600px; overflow-y: auto;">
            <?php if (empty($log_data)): ?>
                <p>No hay registros de envío de correo.</p>
            <?php else: ?>
                <?php foreach ($log_data as $line): ?>
                    <?php
                    $class = 'log-other';
                    if (strpos($line, 'OK') !== false) $class = 'log-ok';
                    elseif (strpos($line, 'FALLÓ') !== false || strpos($line, 'ERROR') !== false) $class = 'log-error';
                    ?>
                    <div class="<?= $class ?>"><?= htmlspecialchars($line) ?></div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <form method="post" style="margin-top:1rem;">
            <button name="limpiar" class="button is-danger" onclick="return confirm('¿Seguro que deseas limpiar el log?');">🗑️ Limpiar Log</button>
        </form>
        <p style="font-size:12px;color:#aaa;margin-top:10px;">
            Mostrando últimos <?= $NUM_MOSTRAR ?> registros.<br>
            (Si quieres más, cambia <b>$NUM_MOSTRAR</b> en el código)
        </p>
    </div>
</section>
</body>
</html>
