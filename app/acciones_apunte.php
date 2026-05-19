<?php
session_start();
var_dump($_SESSION); // Así ves si el rol/tipo es 'admin'
var_dump($_POST);    // Así ves qué datos llegan del form

require_once __DIR__ . '/conexion.php';

/* ✅ Solo permitir POST (evita toggles por GET) */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header("Location: /login");
    exit;
}

/* ✅ CSRF opcional: solo exige si existe token en sesión y en POST */
if (!empty($_SESSION['csrf']) && isset($_POST['csrf'])) {
    if (!hash_equals($_SESSION['csrf'], (string)$_POST['csrf'])) {
        http_response_code(403);
        exit('CSRF inválido');
    }
}

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'admin') {
    header("Location: /login");
    exit;
}

/* 🔒 Normaliza y valida entrada */
$id       = $_POST['id'] ?? null;
$accion   = trim($_POST['accion'] ?? '');
$motivo   = trim($_POST['motivo_rechazo'] ?? ''); // ⬅️ para rechazar
$id_int   = is_numeric($id) ? (int)$id : 0;

/* 🧯 Prepara mensaje flash (feedback UI) */
if (!isset($_SESSION)) { session_start(); }

/* 🧩 Helper: crear JPG 600px desde la mejor fuente disponible */
function crearMiniaturaEmailApunte(int $id_apunte, ?string $portadaBD = null): void {
    $docroot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(__FILE__, 2), '/');
    $destDir = $docroot . '/upload/email-apuntes';
    if (!is_dir($destDir)) { @mkdir($destDir, 0777, true); }
    $destFile = $destDir . '/' . $id_apunte . '.jpg';

    // Candidatas de origen (en orden de preferencia)
    $candidatas = [];
    if (!empty($portadaBD)) {
        $candidatas[] = $docroot . '/upload/preview/' . basename($portadaBD);
    }
    $candidatas[] = $docroot . "/upload/preview/{$id_apunte}.webp";
    $candidatas[] = $docroot . "/upload/preview/{$id_apunte}_thumb.webp";
    $candidatas[] = $docroot . "/upload/preview/{$id_apunte}.png";
    // Fallback genérico
    $fallback = $docroot . '/upload/email-apuntes/email-card-default.jpg';

    $srcPath = null;
    foreach ($candidatas as $c) {
        if (is_file($c)) { $srcPath = $c; break; }
    }
    if (!$srcPath && is_file($fallback)) { $srcPath = $fallback; }

    if (!$srcPath || !is_file($srcPath)) { return; } // nada que hacer

    $data = @file_get_contents($srcPath);
    if ($data === false) { return; }

    $im = @imagecreatefromstring($data);
    if ($im === false) { return; }

    $w = imagesx($im); $h = imagesy($im);
    if ($w <= 0 || $h <= 0) { imagedestroy($im); return; }
    $ratio = $w / $h;

    $new_w = 600;
    $new_h = (int)round($new_w / $ratio);

    $canvas = imagecreatetruecolor($new_w, $new_h);
    imagecopyresampled($canvas, $im, 0, 0, 0, 0, $new_w, $new_h, $w, $h);
    imagejpeg($canvas, $destFile, 82);

    imagedestroy($canvas);
    imagedestroy($im);
}

if ($id_int > 0) {

    /* 🧾 Inicia transacción para que todo sea atómico */
    $conn->begin_transaction();
    try {
        /* ============================================================
           🟢 APROBAR APUNTE
           ============================================================ */
        if ($accion === 'aprobar') {
            // Traer portada (para generar miniatura email)
            $stmt = $conn->prepare("SELECT portada FROM apuntes WHERE id = ?");
            $stmt->bind_param("i", $id_int);
            $stmt->execute();
            $stmt->bind_result($portadaBD);
            $stmt->fetch();
            $stmt->close();

            // Marcar como aprobado (y opcionalmente hacerlo visible)
            $stmt = $conn->prepare("
                UPDATE apuntes
                SET estado = 'aprobado',
                    motivo_rechazo = NULL,
                    fecha_revision = NOW(),
                    publico = 1
                WHERE id = ?
            ");
            $stmt->bind_param("i", $id_int);
            $stmt->execute();
            $stmt->close();

            // Generar miniatura de email 600px
            crearMiniaturaEmailApunte($id_int, $portadaBD);

            $_SESSION['flash'] = '✅ Apunte aprobado correctamente.';
        }

        /* ============================================================
           🔴 RECHAZAR APUNTE
           ============================================================ */
        if ($accion === 'rechazar') {
            if ($motivo === '') {
                throw new Exception('Falta motivo de rechazo');
            }
            $stmt = $conn->prepare("
                UPDATE apuntes
                SET estado = 'rechazado',
                    motivo_rechazo = ?,
                    fecha_revision = NOW(),
                    publico = 0
                WHERE id = ?
            ");
            $stmt->bind_param("si", $motivo, $id_int);
            $stmt->execute();
            $stmt->close();

            // Opcional: limpiar miniatura email si existía
            $rutaEmailJpg = ($_SERVER['DOCUMENT_ROOT'] ?? dirname(__FILE__, 2)) . '/upload/email-apuntes/' . $id_int . '.jpg';
            if (is_file($rutaEmailJpg)) { @unlink($rutaEmailJpg); }

            $_SESSION['flash'] = '🚫 Apunte rechazado.';
        }

        /* ============================================================
           🗑️ ELIMINAR APUNTE
           ============================================================ */
        if ($accion === 'eliminar') {
            // Obtener nombre de archivo y rutas físicas
            $stmt = $conn->prepare("SELECT archivo, portada FROM apuntes WHERE id = ?");
            $stmt->bind_param("i", $id_int);
            $stmt->execute();
            $stmt->bind_result($archivo, $portadaBD);
            $stmt->fetch();
            $stmt->close();

            if ($archivo) {
                // Eliminar archivo original
                $ruta_apunte = ($_SERVER['DOCUMENT_ROOT'] ?? dirname(__FILE__, 2)) . '/upload/apuntes/' . $archivo;
                if (file_exists($ruta_apunte)) { @unlink($ruta_apunte); }

                // Eliminar miniatura (compatibilidad .png legacy)
                $docroot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(__FILE__, 2), '/');
                $ruta_preview_png = $docroot . '/upload/preview/' . $id_int . '.png';
                if (file_exists($ruta_preview_png)) { @unlink($ruta_preview_png); }

                // ✅ Eliminar variantes WEBP generadas
                $basePrev = $docroot . '/upload/preview/' . $id_int;
                @unlink($basePrev . '.webp');
                @unlink($basePrev . '_thumb.webp');
                @unlink($basePrev . '_medium.webp');

                // ✅ Si la portada en BD es un archivo distinto, bórralo también
                if (!empty($portadaBD)) {
                    $rutaPortada = $docroot . '/upload/preview/' . basename($portadaBD);
                    if (file_exists($rutaPortada)) { @unlink($rutaPortada); }
                }

                // ✅ Miniatura para email (si existe)
                $rutaEmailJpg = $docroot . '/upload/email-apuntes/' . $id_int . '.jpg';
                if (file_exists($rutaEmailJpg)) { @unlink($rutaEmailJpg); }
            }

            // Borrar likes asociados
            $stmt = $conn->prepare("DELETE FROM likes WHERE id_apunte = ?");
            $stmt->bind_param("i", $id_int);
            $stmt->execute();
            $stmt->close();

            // Borrar compras asociadas
            $stmt = $conn->prepare("DELETE FROM compras WHERE id_apunte = ?");
            $stmt->bind_param("i", $id_int);
            $stmt->execute();
            $stmt->close();

            // Borrar el apunte
            $stmt = $conn->prepare("DELETE FROM apuntes WHERE id = ?");
            $stmt->bind_param("i", $id_int);
            $stmt->execute();
            $stmt->close();

            $_SESSION['flash'] = '✅ Apunte eliminado correctamente.';
        }

        /* ============================================================
           👁️ ALTERNAR VISIBILIDAD
           ============================================================ */
        if ($accion === 'alternar') {
            $stmt = $conn->prepare("UPDATE apuntes SET publico = NOT publico WHERE id = ?");
            $stmt->bind_param("i", $id_int);
            $stmt->execute();
            $stmt->close();

            $_SESSION['flash'] = '👁️ Visibilidad actualizada.';
        }

        $conn->commit();

    } catch (Throwable $e) {
        $conn->rollback();
        error_log("[acciones_apunte] Error ID {$id_int}: " . $e->getMessage());
        $_SESSION['flash'] = '⚠️ Ocurrió un error. Intenta nuevamente.';
    }
}

$conn->close();

/* 🔁 Redirige de vuelta a la página de origen si existe, o al panel */
$redir = !empty($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '/admin/apuntes';
header("Location: $redir");
exit;

/* ===========================================
   ⬇️ CÓDIGO ORIGINAL (NO ELIMINADO) ⬇️
   (Se mantiene por compatibilidad y para cumplir "no elimines nada")
   =========================================== */

// Nota: Las líneas siguientes quedan inalcanzables por el exit anterior,
// pero se conservan tal cual para no eliminar nada del aporte original.

/*
$id = $_POST['id'] ?? null;
$accion = $_POST['accion'] ?? '';

if ($id && is_numeric($id)) {
    // Eliminar apunte
    if ($accion === 'eliminar') {
        // Obtener nombre de archivo y rutas físicas
        $stmt = $conn->prepare("SELECT archivo FROM apuntes WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->bind_result($archivo);
        $stmt->fetch();
        $stmt->close();

        if ($archivo) {
            // Eliminar archivo original
            $ruta_apunte = $_SERVER['DOCUMENT_ROOT'] . '/upload/apuntes/' . $archivo;
            if (file_exists($ruta_apunte)) {
                @unlink($ruta_apunte);
            }
            // Eliminar miniatura
            $ruta_preview = $_SERVER['DOCUMENT_ROOT'] . '/upload/preview/' . $id . '.png';
            if (file_exists($ruta_preview)) {
                @unlink($ruta_preview);
            }
        }

        // Borrar likes asociados
        $stmt = $conn->prepare("DELETE FROM likes WHERE id_apunte = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();

        // Borrar compras asociadas
        $stmt = $conn->prepare("DELETE FROM compras WHERE id_apunte = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();

        // Borrar el apunte
        $stmt = $conn->prepare("DELETE FROM apuntes WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
    }

    // Alternar visibilidad
    if ($accion === 'alternar') {
        $stmt = $conn->prepare("UPDATE apuntes SET publico = NOT publico WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
    }
}

$conn->close();
header("Location: /admin/apuntes");
exit;
*/
