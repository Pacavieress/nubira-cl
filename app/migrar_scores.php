<?php
require_once __DIR__ . '/conexion.php'; // Ajusta la ruta si es necesario

echo "<h3>Iniciando actualización masiva de Gamificación...</h3>";

$sql = "SELECT s.id, a.foto_perfil, a.bio, s.descripcion, s.alumno_id 
        FROM servicios s
        INNER JOIN alumnos a ON s.alumno_id = a.id";
$res = $conn->query($sql);

$actualizados = 0;

while ($row = $res->fetch_assoc()) {
    $score = 0;
    
    // 1. Foto de perfil (+25)
    if (!empty($row['foto_perfil'])) $score += 25;
    
    // 2. Biografía (+25)
    if (!empty(trim($row['bio'] ?? ''))) $score += 25;
    
    // 3. Descripción del servicio > 40 chars (+25)
    if (!empty($row['descripcion']) && strlen(trim($row['descripcion'])) > 40) $score += 25;
    
    // 4. Tiene al menos 1 valoración como vendedor (+25)
    $stmt = $conn->prepare("SELECT id FROM valoraciones WHERE id_evaluado = ? AND rol_evaluado = 'vendedor' LIMIT 1");
    $stmt->bind_param("i", $row['alumno_id']);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) $score += 25;
    $stmt->close();
    
    // Guardar el score en la base de datos
    $update = $conn->prepare("UPDATE servicios SET score_nubira = ? WHERE id = ?");
    $update->bind_param("ii", $score, $row['id']);
    $update->execute();
    $update->close();
    
    $actualizados++;
}

echo "<p>¡Éxito! Se actualizaron las notas de <b>$actualizados</b> servicios.</p>";
echo "<p>Ya puedes borrar este archivo (migrar_scores.php).</p>";
?>