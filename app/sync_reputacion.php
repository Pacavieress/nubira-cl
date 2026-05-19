<?php
// HERRAMIENTA DE DIAGNÓSTICO: MI REPUTACIÓN
// Muestra paso a paso qué datos existen para el usuario logueado.

ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
require_once __DIR__ . '/conexion.php';

// 1. IDENTIFICACIÓN
if (!isset($_SESSION['usuario_id'])) die("Debes iniciar sesión primero.");
$uid = (int)$_SESSION['usuario_id'];
$nombre = $_SESSION['usuario_nombre'] ?? 'Desconocido';

echo "<div style='font-family:sans-serif; padding:20px; max-width:800px; margin:0 auto;'>";
echo "<h1 style='border-bottom:2px solid #54A6D8; color:#54A6D8'>Diagnóstico de Reputación</h1>";
echo "<p><strong>Usuario Actual:</strong> ID #$uid ($nombre)</p>";

// 2. BUSCAR EN CONTRATOS (VENDEDOR)
echo "<h3>1. Buscando en Contratos (Como Vendedor)</h3>";
$sql = "SELECT id, calificacion_comprador, estado FROM contratos WHERE vendedor_id = $uid";
$res = $conn->query($sql);
if ($res->num_rows > 0) {
    echo "<table border='1' cellpadding='5' style='border-collapse:collapse; width:100%'>";
    echo "<tr style='background:#eee'><th>ID Contrato</th><th>Nota Recibida</th><th>Estado</th><th>¿Cuenta?</th></tr>";
    while ($r = $res->fetch_assoc()) {
        $nota = (int)$r['calificacion_comprador'];
        $estado = $r['estado'];
        $cuenta = ($nota > 0 && $estado === 'finalizado') ? '<span style="color:green">SÍ</span>' : '<span style="color:red">NO (Falta nota o finalizar)</span>';
        echo "<tr><td>{$r['id']}</td><td>{$nota}</td><td>{$estado}</td><td>$cuenta</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color:orange'>No se encontraron contratos como vendedor.</p>";
}

// 3. BUSCAR EN CONTRATOS (COMPRADOR)
echo "<h3>2. Buscando en Contratos (Como Comprador)</h3>";
$sql = "SELECT id, calificacion_vendedor, estado FROM contratos WHERE comprador_id = $uid";
$res = $conn->query($sql);
if ($res->num_rows > 0) {
    echo "<table border='1' cellpadding='5' style='border-collapse:collapse; width:100%'>";
    echo "<tr style='background:#eee'><th>ID Contrato</th><th>Nota Recibida</th><th>Estado</th><th>¿Cuenta?</th></tr>";
    while ($r = $res->fetch_assoc()) {
        $nota = (int)$r['calificacion_vendedor'];
        $estado = $r['estado'];
        $cuenta = ($nota > 0 && $estado === 'finalizado') ? '<span style="color:green">SÍ</span>' : '<span style="color:red">NO</span>';
        echo "<tr><td>{$r['id']}</td><td>{$nota}</td><td>{$estado}</td><td>$cuenta</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color:orange'>No se encontraron contratos como comprador.</p>";
}

// 4. BUSCAR EN SISTEMA ANTIGUO (LEGACY)
echo "<h3>3. Buscando en 'servicio_comentarios' (Antiguo)</h3>";
$check = $conn->query("SHOW TABLES LIKE 'servicio_comentarios'");
if ($check && $check->num_rows > 0) {
    // Buscamos comentarios en servicios QUE PERTENECEN a este usuario
    $sql = "SELECT sc.rating, sc.comentario, s.titulo 
            FROM servicio_comentarios sc
            JOIN servicios s ON sc.servicio_id = s.id
            WHERE s.alumno_id = $uid";
    $res = $conn->query($sql);
    
    if ($res->num_rows > 0) {
        echo "<table border='1' cellpadding='5' style='border-collapse:collapse; width:100%'>";
        echo "<tr style='background:#eee'><th>Servicio</th><th>Nota</th><th>Comentario</th></tr>";
        while ($r = $res->fetch_assoc()) {
            echo "<tr><td>{$r['titulo']}</td><td>{$r['rating']}</td><td>{$r['comentario']}</td></tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color:red'>No se encontraron comentarios antiguos asociados a tus servicios.</p>";
        echo "<small>Nota: Esto pasa si el 'alumno_id' en la tabla 'servicios' no coincide con tu ID #$uid.</small>";
    }
} else {
    echo "<p>La tabla antigua no existe.</p>";
}

// 5. ESTADO ACTUAL EN BASE DE DATOS
echo "<h3>4. Tu Ficha en Base de Datos (Tabla 'alumnos')</h3>";
$me = $conn->query("SELECT calificacion_promedio, cantidad_votos FROM alumnos WHERE id = $uid")->fetch_assoc();
echo "<ul>";
echo "<li>Promedio guardado: <strong>{$me['calificacion_promedio']}</strong></li>";
echo "<li>Votos guardados: <strong>{$me['cantidad_votos']}</strong></li>";
echo "</ul>";

echo "</div>";
?>