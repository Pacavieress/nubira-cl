<?php
session_start();
if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] ?? '') !== 'admin') {
    header("Location: /login"); exit;
}

// Ruta del log
$logFile = __DIR__ . '/../logs/errores.log';
$errores = [];

// Verifica si existe
if (file_exists($logFile)) {
    $lineas = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lineas as $linea) {
        if (preg_match('/\[(.*?)\] ERROR (\d+) \| URL: (.*?) \| IP: (.*?) \| User Agent: (.*)/', $linea, $match)) {
            $errores[] = [
                'fecha' => $match[1],
                'codigo' => $match[2],
                'url' => $match[3],
                'ip' => $match[4],
                'user_agent' => $match[5]
            ];
        }
    }
}

// Ordenar por fecha descendente
usort($errores, fn($a, $b) => strtotime($b['fecha']) - strtotime($a['fecha']));

// Filtro por código
$codigoFiltrado = $_GET['codigo'] ?? 'Todos';
$errores_filtrados = ($codigoFiltrado === 'Todos')
    ? $errores
    : array_filter($errores, fn($e) => $e['codigo'] === $codigoFiltrado);

// Limpiar log
if (isset($_GET['limpiar']) && $_GET['limpiar'] === '1') {
    file_put_contents($logFile, '');
    header("Location: admin_ver_logs.php");
    exit;
}

// Obtener códigos únicos
$codigos = array_unique(array_column($errores, 'codigo'));
sort($codigos);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Visor de Logs de Errores - Nubira</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 text-gray-800 min-h-screen p-6">
    <div class="max-w-4xl mx-auto">
        <h1 class="text-2xl font-bold mb-6">🧾 Visor de Logs de Errores - Nubira</h1>

        <!-- Filtro por código -->
        <form method="get" class="flex flex-wrap items-center gap-2 mb-6">
            <label class="text-sm font-medium">Código:</label>
            <select name="codigo" class="px-3 py-2 rounded border border-gray-300" onchange="this.form.submit()">
                <option value="Todos" <?= $codigoFiltrado === 'Todos' ? 'selected' : '' ?>>Todos</option>
                <?php foreach ($codigos as $c): ?>
                    <option value="<?= $c ?>" <?= $codigoFiltrado === $c ? 'selected' : '' ?>><?= $c ?></option>
                <?php endforeach; ?>
            </select>

            <a href="?limpiar=1" class="ml-auto text-sm text-red-600 hover:underline flex items-center gap-1">
                🧹 Limpiar log
            </a>
        </form>

        <!-- Resultado -->
        <?php if (empty($errores_filtrados)): ?>
            <p class="text-sm text-gray-500">No hay registros de errores.</p>
        <?php else: ?>
            <p class="mb-4 text-sm text-gray-500">
                Mostrando <?= count($errores_filtrados) ?> errores
                <?= $codigoFiltrado !== 'Todos' ? "con código $codigoFiltrado" : '' ?>.
            </p>
            <?php foreach ($errores_filtrados as $datos): ?>
                <?php
                $color = match ($datos['codigo']) {
                    '404' => 'bg-yellow-100 text-yellow-700',
                    '500' => 'bg-red-100 text-red-700',
                    '403' => 'bg-purple-100 text-purple-700',
                    '401' => 'bg-blue-100 text-blue-700',
                    default => 'bg-gray-100 text-gray-700',
                };
                ?>
                <div class="bg-white rounded-xl shadow p-4 mb-4 border border-gray-200">
                    <p class="text-sm text-gray-600 mb-2 font-semibold">
                        <span class="inline-block px-2 py-1 <?= $color ?> rounded-full text-xs mr-2">
                            <?= htmlspecialchars($datos['codigo']) ?>
                        </span>
                        <?= htmlspecialchars($datos['fecha']) ?>
                    </p>
                    <p class="text-sm"><span class="font-bold text-gray-700">URL:</span> <?= htmlspecialchars($datos['url']) ?></p>
                    <p class="text-sm"><span class="font-bold text-gray-700">IP:</span> <?= htmlspecialchars($datos['ip']) ?></p>
                    <p class="text-sm"><span class="font-bold text-gray-700">User Agent:</span> <span class="break-all"><?= htmlspecialchars($datos['user_agent']) ?></span></p>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</body>
</html>
