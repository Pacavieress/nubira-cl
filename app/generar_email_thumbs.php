<?php
/**
 * Generador masivo de miniaturas para email
 * - Servicios aprobados  -> /upload/email/{id}.jpg
 * - Apuntes  aprobados   -> /upload/email-apuntes/{id}.jpg
 *
 * Soporta ejecución por web o CLI. Usa GD.
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(300);

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/helpers/imagen_servicio.php'; // [BANCO] path_portada() para origen físico

$force = !empty($_GET['force']); // ?force=1 para regenerar aunque exista

echo "<pre>";
echo "== Generar miniaturas email ==\n";
echo "Fecha: " . date('Y-m-d H:i:s') . "\n\n";

/* -------------------- Paths base -------------------- */
$dirServ      = __DIR__ . '/../upload/servicios/';
$dirPreview   = __DIR__ . '/../upload/preview/';       // portadas/candidatas para apuntes
$dirApuntes   = __DIR__ . '/../upload/apuntes/';       // (por si alguna portada apuntara aquí)
$dirEmailServ = __DIR__ . '/../upload/email/';
$dirEmailAp   = __DIR__ . '/../upload/email-apuntes/';

@mkdir($dirEmailServ, 0777, true);
@mkdir($dirEmailAp,   0777, true);

/* -------------------- Helpers -------------------- */
function crear_miniatura($ruta_original, $destino_email, $w_target = 600, $calidad = 82): bool {
    $data = @file_get_contents($ruta_original);
    if ($data === false) return false;
    $im = @imagecreatefromstring($data);
    if ($im === false)  return false;

    $w = imagesx($im);
    $h = imagesy($im);
    if ($w <= 0 || $h <= 0) { imagedestroy($im); return false; }

    $ratio  = $w / $h;
    $new_w  = $w_target;
    $new_h  = (int)round($new_w / $ratio);

    $canvas = imagecreatetruecolor($new_w, $new_h);
    imagecopyresampled($canvas, $im, 0, 0, 0, 0, $new_w, $new_h, $w, $h);

    $ok = imagejpeg($canvas, $destino_email, $calidad);

    imagedestroy($canvas);
    imagedestroy($im);
    return (bool)$ok;
}

function first_existing(array $candidatas): ?string {
    foreach ($candidatas as $p) {
        if ($p && is_file($p)) return $p;
    }
    return null;
}

/* =========================================================
 * 1) SERVICIOS APROBADOS
 * =======================================================*/
$sql_serv = "
    SELECT s.id, s.imagen, s.imagen_banco_id, bi.archivo AS banco_archivo
    FROM servicios s
    LEFT JOIN banco_imagenes bi ON bi.id = s.imagen_banco_id
    WHERE s.estado = 'aprobado'
";
$res_serv = $conn->query($sql_serv);

if (!$res_serv) {
    echo "❌ Error SQL servicios: {$conn->error}\n";
} else {
    echo "Servicios aprobados: {$res_serv->num_rows}\n";
    $ok = $skip = $err = 0;

    while ($row = $res_serv->fetch_assoc()) {
        $id  = (int)$row['id'];
        $img = trim((string)$row['imagen']);

        $dest = $dirEmailServ . $id . '.jpg';
        if (!$force && is_file($dest)) {
            echo "✔ Servicio #$id ya tiene miniatura, saltando\n";
            $skip++; continue;
        }

        // Candidatas de origen: banco/legacy vía helper (ruta física), luego fallback genérico
        $src = first_existing([
            path_portada($row),
            __DIR__ . '/../upload/email/email-card-default.jpg'
        ]);

        if (!$src) {
            echo "❌ Servicio #$id sin imagen válida ni fallback\n";
            $err++; continue;
        }

        $okGen = crear_miniatura($src, $dest, 600, 82);
        if ($okGen) {
            echo "OK servicio #$id -> " . basename($dest) . "\n";
            $ok++;
        } else {
            echo "❌ Error generando servicio #$id\n";
            $err++;
        }
    }

    echo "\nResumen servicios: OK=$ok, SKIP=$skip, ERR=$err\n\n";
}

/* =========================================================
 * 2) APUNTES APROBADOS
 *   - Usa estado='aprobado' (no 'aprobado=1')
 *   - Busca portada y variantes generadas en /upload/preview
 * =======================================================*/
$sql_apt = "
    SELECT id, portada
    FROM apuntes
    WHERE estado = 'aprobado'
";
$res_apt = $conn->query($sql_apt);

if (!$res_apt) {
    echo "❌ Error SQL apuntes: {$conn->error}\n";
} else {
    echo "Apuntes aprobados: {$res_apt->num_rows}\n";
    $ok = $skip = $err = 0;

    while ($row = $res_apt->fetch_assoc()) {
        $id       = (int)$row['id'];
        $portada  = trim((string)($row['portada'] ?? ''));

        $dest = $dirEmailAp . $id . '.jpg';
        if (!$force && is_file($dest)) {
            echo "✔ Apunte #$id ya tiene miniatura, saltando\n";
            $skip++; continue;
        }

        // Candidatas de origen (orden de preferencia)
        $candidatas = [];

        // Si portada trae nombre de archivo, intentar en /upload/preview primero
        if ($portada !== '') {
            $candidatas[] = $dirPreview . basename($portada);
            // por compatibilidad, si alguien guardó la portada completa junto al apunte:
            $candidatas[] = $dirApuntes . basename($portada);
        }

        // Variantes estándar generadas (WEBP/PNG legacy)
        $candidatas[] = $dirPreview . $id . '.webp';
        $candidatas[] = $dirPreview . $id . '_thumb.webp';
        $candidatas[] = $dirPreview . $id . '_medium.webp';
        $candidatas[] = $dirPreview . $id . '.png';

        // Fallback genérico
        $candidatas[] = __DIR__ . '/../upload/email-apuntes/email-card-default.jpg';

        $src = first_existing($candidatas);

        if (!$src) {
            echo "❌ Apunte #$id sin imagen válida ni fallback\n";
            $err++; continue;
        }

        $okGen = crear_miniatura($src, $dest, 600, 82);
        if ($okGen) {
            echo "OK apunte #$id -> " . basename($dest) . "\n";
            $ok++;
        } else {
            echo "❌ Error generando apunte #$id\n";
            $err++;
        }
    }

    echo "\nResumen apuntes: OK=$ok, SKIP=$skip, ERR=$err\n\n";
}

echo "Proceso completado.\n";
echo "</pre>";
