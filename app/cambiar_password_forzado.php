<?php
session_start();
require_once 'conexion.php';

if (!isset($_SESSION['usuario_id'])) {
    header("Location: /login");
    exit;
}

$usuario_id = $_SESSION['usuario_id'];
$mensaje = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pass1 = $_POST['nueva_password'] ?? '';
    $pass2 = $_POST['repetir_password'] ?? '';

    if (strlen($pass1) < 8) {
        $mensaje = "La contraseña debe tener al menos 8 caracteres.";
    } elseif ($pass1 !== $pass2) {
        $mensaje = "Las contraseñas no coinciden.";
    } else {
        // Cambiar la contraseña y desactivar el flag
        $password_hash = password_hash($pass1, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("UPDATE alumnos SET password=?, debe_cambiar_password=0 WHERE id=?");
        $stmt->bind_param("si", $password_hash, $usuario_id);
        $stmt->execute();
        $stmt->close();

        // Redirigir al dashboard
        header("Location: /dashboard");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cambiar contraseña</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">
    <div class="bg-white shadow-xl rounded-2xl p-8 w-full max-w-md">
        <h1 class="text-2xl font-bold mb-4 text-blue-700">Actualiza tu contraseña</h1>
        <?php if ($mensaje): ?>
            <div class="mb-4 text-red-600"><?= htmlspecialchars($mensaje) ?></div>
        <?php endif; ?>
        <form method="POST">
            <label class="block mb-2 font-semibold">Nueva contraseña</label>
            <input type="password" name="nueva_password" required class="w-full border rounded px-3 py-2 mb-4" minlength="8">

            <label class="block mb-2 font-semibold">Repetir nueva contraseña</label>
            <input type="password" name="repetir_password" required class="w-full border rounded px-3 py-2 mb-6" minlength="8">

            <button type="submit" class="w-full bg-blue-700 text-white py-2 rounded hover:bg-blue-800 font-bold">Cambiar contraseña</button>
        </form>
    </div>
</body>
</html>
