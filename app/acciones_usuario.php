<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'conexion.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'admin') {
    header("Location: /login.html");
    exit;
}

$id = $_POST['id'] ?? null;
$accion = $_POST['accion'] ?? '';

if ($id && is_numeric($id)) {
    if ($accion === 'eliminar') {
        // 1. Eliminar likes del usuario
        $stmt = $conn->prepare("DELETE FROM likes WHERE id_alumno = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();

      // 2. Eliminar compras realizadas por el usuario (como comprador)
$stmt = $conn->prepare("DELETE FROM compras WHERE usuario_id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$stmt->close();


        // 3. Eliminar compras hechas a sus apuntes (como vendedor)
        $stmt = $conn->prepare("SELECT id FROM apuntes WHERE id_alumno = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result_apuntes = $stmt->get_result();

        while ($row = $result_apuntes->fetch_assoc()) {
            $id_apunte = $row['id'];
            $sub = $conn->prepare("DELETE FROM compras WHERE id_apunte = ?");
            $sub->bind_param("i", $id_apunte);
            $sub->execute();
            $sub->close();
        }
        $stmt->close();

        // 4. Eliminar archivos físicos (apuntes y previews)
        $stmt = $conn->prepare("SELECT archivo, id FROM apuntes WHERE id_alumno = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $archivo = $row['archivo'];
            $id_apunte = $row['id'];
            $ruta_apunte = $_SERVER['DOCUMENT_ROOT'] . "/upload/apuntes/" . $archivo;
            if (file_exists($ruta_apunte)) {
                @unlink($ruta_apunte);
            }
            $ruta_preview = $_SERVER['DOCUMENT_ROOT'] . "/upload/preview/" . $id_apunte . ".png";
            if (file_exists($ruta_preview)) {
                @unlink($ruta_preview);
            }
        }
        $stmt->close();

        // 5. Eliminar apuntes
        $stmt = $conn->prepare("DELETE FROM apuntes WHERE id_alumno = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();

        // 6. Eliminar usuario
        $stmt = $conn->prepare("DELETE FROM alumnos WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
    }

    if ($accion === 'cambiar_rol') {
        $stmt = $conn->prepare("SELECT rol FROM alumnos WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->bind_result($rol_actual);
        $stmt->fetch();
        $stmt->close();

        $nuevo_rol = $rol_actual === 'admin' ? 'alumno' : 'admin';
        $stmt = $conn->prepare("UPDATE alumnos SET rol = ? WHERE id = ?");
        $stmt->bind_param("si", $nuevo_rol, $id);
        $stmt->execute();
        $stmt->close();
    }
}

$conn->close();
header("Location: /admin/usuarios"); // Ruta limpia
exit;

