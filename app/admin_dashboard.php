<?php
session_start();

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'admin') {
    header("Location: ../public/login.html");
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Panel de Administración</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@0.9.4/css/bulma.min.css">
</head>
<body>
<section class="section">
    <div class="container">
        <h1 class="title has-text-centered">👨‍💼 Panel de Administración</h1>

        <div class="buttons is-centered mt-5">
            <a href="admin_usuarios.php" class="button is-info">👥 Ver Usuarios</a>
            <a href="admin_apuntes.php" class="button is-primary">📚 Ver Apuntes</a>
            <a href="dashboard.php" class="button is-light">← Volver al panel usuario</a>
        </div>
    </div>
</section>
</body>
</html>
