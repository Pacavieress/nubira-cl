<?php
require_once(__DIR__ . '/app/conexion.php');

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $institucion = trim($_POST["institucion"] ?? '');
    $email = trim($_POST["email"] ?? '');

    if ($institucion && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $stmt = $conn->prepare("INSERT INTO solicitudes_instituciones (institucion, email) VALUES (?, ?)");
        $stmt->bind_param("ss", $institucion, $email);
        $stmt->execute();
        $stmt->close();
    }

    header("Location: register.php?solicitud=ok");
    exit();
}
?>
