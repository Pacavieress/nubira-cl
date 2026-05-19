<?php
session_start();
require_once __DIR__ . '/app/conexion.php';

$id_servicio = $_SESSION['servicio_pendiente_pago'] ?? null;
$usuario_id = $_SESSION['usuario_id'] ?? null;

if (!$id_servicio || !$usuario_id) {
    echo "No hay publicación pendiente.";
    exit;
}

// Cambia el estado a 'aprobado'
$stmt = $conn->prepare("UPDATE servicios SET estado='aprobado' WHERE id=?");
$stmt->bind_param("i", $id_servicio);
$stmt->execute();
$stmt->close();

// Suma la publicación a la cuenta del usuario
$stmt = $conn->prepare("UPDATE alumnos SET publicaciones_gratis_utilizadas = publicaciones_gratis_utilizadas + 1 WHERE id = ?");
$stmt->bind_param("i", $usuario_id);
$stmt->execute();
$stmt->close();

// Limpia variable de sesión
unset($_SESSION['servicio_pendiente_pago']);

// Obtiene título para mostrar en la página
$stmt = $conn->prepare("SELECT titulo FROM servicios WHERE id = ?");
$stmt->bind_param("i", $id_servicio);
$stmt->execute();
$stmt->bind_result($titulo);
$stmt->fetch();
$stmt->close();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>¡Publicación exitosa! | Nubira</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-tr from-blue-100 via-purple-100 to-green-100 min-h-screen flex items-center justify-center">
    <div class="bg-white shadow-lg rounded-xl p-8 max-w-lg mx-auto text-center">
        <div class="mb-4">
            <span class="inline-block bg-green-100 rounded-full p-4 mb-2">
                <svg class="w-10 h-10 text-green-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                </svg>
            </span>
            <h2 class="text-2xl font-bold text-green-700 mb-2">¡Publicación exitosa!</h2>
            <p class="text-gray-700 mb-3">Tu clase o servicio <b><?= htmlspecialchars($titulo) ?></b> ya está <b>visible</b> para toda la comunidad.</p>
        </div>
        <div class="bg-green-100 border border-green-300 text-green-900 rounded p-4 mb-5">
            <p><b>¡Gracias por aportar a Nubira!</b> Si tienes dudas o consultas, estamos para ayudarte.</p>
        </div>
        <div class="mb-5 flex flex-col md:flex-row gap-2 justify-center">
            <a href="/clases-servicios" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded font-semibold shadow transition">
                Volver a Clases y Servicios
            </a>
            <a href="/publicar-servicio" class="bg-green-600 hover:bg-green-700 text-white px-5 py-2 rounded font-semibold shadow transition">
                Publicar otro servicio
            </a>
        </div>
        <div class="text-gray-600 text-sm">
            <p>¿Tienes dudas o problemas? <a href="/app/soporte.php" class="text-blue-600 underline">Contacta soporte</a></p>
        </div>
    </div>
</body>
</html>
