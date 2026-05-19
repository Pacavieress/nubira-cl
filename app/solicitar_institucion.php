<?php
session_start();

// Al estar ambos en /app, la ruta es directa:
require_once __DIR__ . '/conexion.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Sanitizar
    $institucion = htmlspecialchars(trim($_POST['institucion'] ?? ''));
    $email = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);

    if ($institucion && $email) {
        // 2. Insertar en la tabla REAL: solicitudes_instituciones
        // Ajustamos la query a las columnas que vimos en la auditoría:
        // id, institucion, email, fecha, estado, correo_enviado
        $stmt = $conn->prepare("INSERT INTO solicitudes_instituciones (institucion, email, fecha, estado, correo_enviado) VALUES (?, ?, NOW(), 'pendiente', 0)");
        $stmt->bind_param("ss", $institucion, $email);
        
        if ($stmt->execute()) {
            // Éxito: Redirigir al registro (asumiendo que register está en la raíz)
            // Si register.php también está en app, cambia la ruta a /app/register.php
            header("Location: /register?solicitud=ok");
        } else {
            error_log("Error DB Solicitud: " . $stmt->error);
            header("Location: /register?error=db");
        }
        $stmt->close();
    } else {
        header("Location: /register?error=datos");
    }
} else {
    // Si intentan entrar directo, mandar al home
    header("Location: /");
}
$conn->close();
?>