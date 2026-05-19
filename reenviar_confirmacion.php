<?php
require_once '../app/conexion.php';
require_once '../app/correo.php';

$mensaje = '';
$tipo = 'is-info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $correo = trim($_POST['correo']);

    $sql = "SELECT id, nombre, confirmado FROM alumnos WHERE correo = ?";
    $stmt = $conexion->prepare($sql);
    $stmt->bind_param("s", $correo);
    $stmt->execute();
    $resultado = $stmt->get_result();

    if ($resultado->num_rows === 1) {
        $usuario = $resultado->fetch_assoc();

        if ($usuario['confirmado'] == 1) {
            $mensaje = "✅ Esta cuenta ya fue confirmada. Puedes iniciar sesión.";
            $tipo = "is-success";
        } else {
            $token = bin2hex(random_bytes(16));

            $update = $conexion->prepare("UPDATE alumnos SET token = ? WHERE id = ?");
            $update->bind_param("si", $token, $usuario['id']);
            $update->execute();

            if (enviarCorreoConfirmacion($correo, $usuario['nombre'], $token)) {
                $mensaje = "📧 Se ha reenviado el correo de confirmación a $correo.";
                $tipo = "is-success";
            } else {
                $mensaje = "❌ Hubo un error al reenviar el correo.";
                $tipo = "is-danger";
            }

            $update->close();
        }
    } else {
        $mensaje = "⚠️ No se encontró una cuenta con ese correo.";
        $tipo = "is-warning";
    }

    $stmt->close();
    $conexion->close();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reenviar confirmación</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@0.9.4/css/bulma.min.css">
</head>
<body>
<section class="section">
    <div class="container" style="max-width: 500px;">
        <h1 class="title has-text-centered">Reenviar correo de confirmación</h1>

        <?php if ($mensaje): ?>
            <div class="notification <?php echo $tipo; ?>">
                <?php echo $mensaje; ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="field">
                <label class="label">Correo institucional</label>
                <div class="control">
                    <input class="input" type="email" name="correo" placeholder="ejemplo@uc.cl" required>
                </div>
            </div>

            <div class="field is-grouped is-justify-content-center">
                <div class="control">
                    <button type="submit" class="button is-link">📩 Reenviar correo</button>
                </div>
                <div class="control">
                    <a href="login.html" class="button is-light">← Volver</a>
                </div>
            </div>
        </form>
    </div>
</section>
</body>
</html>
