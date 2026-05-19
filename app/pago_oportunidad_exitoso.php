<?php
session_start();
require_once 'conexion.php';

// 1. Validar sesión y parámetro
if (!isset($_SESSION['usuario_id'])) {
    header("Location: /login");
    exit;
}

$usuario_id = $_SESSION['usuario_id'];
$id_oportunidad = intval($_GET['id'] ?? 0);

if ($id_oportunidad <= 0) {
    die("ID de oportunidad inválido.");
}

// 2. Verificar que la oportunidad exista y sea del usuario
$stmt = $conn->prepare("SELECT * FROM oportunidades WHERE id = ? AND usuario_id = ?");
$stmt->bind_param("ii", $id_oportunidad, $usuario_id);
$stmt->execute();
$result = $stmt->get_result();
$oportunidad = $result->fetch_assoc();
$stmt->close();

if (!$oportunidad) {
    die("No existe la oportunidad o no tienes permiso.");
}

// 3. Si ya estaba pagada, no repetir
if ($oportunidad['pagado']) {
    $ya_pagada = true;
} else {
    // 4. Marcar como pagada en BD
    $stmt = $conn->prepare("UPDATE oportunidades SET pagado = 1 WHERE id = ?");
    $stmt->bind_param("i", $id_oportunidad);
    $stmt->execute();
    $stmt->close();
    $ya_pagada = false;
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Pago exitoso - Oportunidad</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <div class="flex min-h-screen justify-center items-center">
        <div class="bg-white p-8 rounded shadow-lg text-center max-w-md w-full">
            <h1 class="text-2xl font-bold text-green-700 mb-4">✅ ¡Pago realizado con éxito!</h1>
            <?php if ($ya_pagada): ?>
                <p class="mb-4 text-gray-700">Esta oportunidad ya había sido pagada antes.</p>
            <?php else: ?>
                <p class="mb-4 text-gray-700">Tu oportunidad <span class="font-semibold"><?= htmlspecialchars($oportunidad['titulo']) ?></span> ha sido marcada como <span class="font-semibold text-green-700">Pagada</span>.</p>
            <?php endif; ?>
            <a href="/crear_oportunidad?id=<?= $id_oportunidad ?>" class="inline-block mt-4 px-6 py-2 bg-blue-600 text-white rounded hover:bg-blue-800">Volver a la oportunidad</a>
            <a href="/dashboard" class="inline-block mt-4 ml-2 px-6 py-2 bg-gray-500 text-white rounded hover:bg-gray-700">Ir al panel</a>
        </div>
    </div>
</body>
</html>
