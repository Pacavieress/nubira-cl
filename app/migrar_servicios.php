<?php
/**
 * MIGRACIÓN DE FOTOS DE SERVICIOS (OPTIMIZACIÓN WHATSAPP)
 * Convierte las fotos gigantes a WebP ligero (800x600 aprox)
 */

// Configuración
$lote = 20; 
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

// Rutas
require_once __DIR__ . '/conexion.php';
// ATENCIÓN A LA RUTA: Ajustada para llegar a upload/servicios desde app/
$ruta_imagenes = dirname(__DIR__) . '/upload/servicios/';

// Estilos
echo '<body style="font-family:monospace; background:#111; color:#0f0; padding:20px;">';
echo "<h3>--- OPTIMIZANDO SERVICIOS: $offset - " . ($offset + $lote) . " ---</h3>";

// Función Mágica
function optimizarServicio($ruta_origen, $ruta_destino) {
    $info = @getimagesize($ruta_origen);
    if (!$info) return false;
    
    $tipo = $info[2];
    switch ($tipo) {
        case IMAGETYPE_JPEG: $img = imagecreatefromjpeg($ruta_origen); break;
        case IMAGETYPE_PNG:  $img = imagecreatefrompng($ruta_origen); break;
        case IMAGETYPE_WEBP: $img = imagecreatefromwebp($ruta_origen); break;
        default: return false;
    }
    if (!$img) return false;

    // Dimensiones Originales
    $w = imagesx($img);
    $h = imagesy($img);
    
    // Calcular nuevas dimensiones (Máximo 800px de ancho, manteniendo proporción)
    $max_ancho = 800;
    if ($w > $max_ancho) {
        $nuevo_w = $max_ancho;
        $nuevo_h = ($h / $w) * $max_ancho;
    } else {
        $nuevo_w = $w;
        $nuevo_h = $h;
    }

    $lienzo = imagecreatetruecolor($nuevo_w, $nuevo_h);
    imagealphablending($lienzo, false);
    imagesavealpha($lienzo, true);
    
    // Rellenar transparente (si es PNG) o blanco (si es JPG)
    // Para servicios mejor blanco de fondo si hay transparencia, para evitar bordes negros en WhatsApp
    $fondo = imagecolorallocate($lienzo, 255, 255, 255); 
    imagefill($lienzo, 0, 0, $fondo);

    // Redimensionar
    imagecopyresampled($lienzo, $img, 0, 0, 0, 0, $nuevo_w, $nuevo_h, $w, $h);
    
    // Guardar WebP (Calidad 80 es perfecta para WhatsApp)
    $res = imagewebp($lienzo, $ruta_destino, 80);
    
    imagedestroy($img);
    imagedestroy($lienzo);
    return $res;
}

// Bucle
$sql = "SELECT id, titulo, imagen FROM servicios 
        WHERE imagen IS NOT NULL 
        AND imagen != '' 
        LIMIT $lote OFFSET $offset";

$res = $conn->query($sql);

if ($res && $res->num_rows > 0) {
    while ($row = $res->fetch_assoc()) {
        $id = $row['id'];
        $archivo_viejo = $row['imagen'];
        $ruta_vieja = $ruta_imagenes . $archivo_viejo;
        
        echo "Servicio #$id... ";

        if (!file_exists($ruta_vieja)) {
            echo "<span style='color:red'>Archivo no existe ($archivo_viejo)</span><br>";
            continue;
        }

        // Si ya es webp y parece optimizada, saltar (opcional)
        if (pathinfo($archivo_viejo, PATHINFO_EXTENSION) == 'webp' && strpos($archivo_viejo, 's_') === 0) {
             echo "<span style='color:yellow'>Ya listo.</span><br>";
             continue;
        }

        // Nuevo nombre: s_ID_random.webp
        $nuevo_nombre = 's_' . $id . '_' . uniqid() . '.webp';
        $ruta_nueva = $ruta_imagenes . $nuevo_nombre;
        
        $peso_antes = filesize($ruta_vieja);

        if (optimizarServicio($ruta_vieja, $ruta_nueva)) {
            // Actualizar BD
            $conn->query("UPDATE servicios SET imagen = '$nuevo_nombre' WHERE id = $id");
            
            // Borrar vieja (Opcional: Si te da miedo, comenta esta linea)
            @unlink($ruta_vieja); 
            
            $peso_ahora = filesize($ruta_nueva);
            $ahorro = round(($peso_antes - $peso_ahora) / 1024, 1);
            
            // Alerta visual si pesa más de 300KB (WhatsApp Limit)
            $status = ($peso_ahora < 300 * 1024) ? "✅ WhatsApp Ready" : "⚠️ Aún pesado";
            
            echo "<span style='color:white'>OK! ($ahorro KB ahorrados) [$status]</span><br>";
        } else {
            echo "<span style='color:red'>Error al procesar imagen</span><br>";
        }
        
        flush();
    }

    // Siguiente página
    $next = $offset + $lote;
    echo "<br><strong>Siguiente bloque...</strong>";
    echo "<script>setTimeout(function(){ window.location.href = '?offset=$next'; }, 1000);</script>";

} else {
    echo "<h1>✅ IMÁGENES DE SERVICIOS OPTIMIZADAS</h1>";
    echo "<p>Ahora WhatsApp debería mostrarlas.</p>";
    echo "<p>Borra este archivo (migrar_servicios.php).</p>";
    echo "<a href='/vitrina' style='color:white'>Ir a Vitrina</a>";
}
?>