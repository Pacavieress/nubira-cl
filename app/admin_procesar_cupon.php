<?php
/**
 * NUBIRA 2.0 - CONTROLADOR DE CUPONES/BECAS
 * Ubicación: public_html/app/admin_procesar_cupon.php
 * Lógica: Inserción y eliminación blindada con manejo de nulos dinámico.
 * Estado: Corregido (Column Match: creado_en)
 */
session_start();

// 1. CONEXIÓN Y RUTAS ROBUSTAS
require_once __DIR__ . '/conexion.php'; 

// 2. SEGURIDAD ESTRICTA NUBIRA 2.0
if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['rol']) || $_SESSION['rol'] !== 'admin') {
    header("Location: /vitrina");
    exit;
}

$url_redireccion = "/app/cupones.php"; 

// 3. LÓGICA DE ELIMINACIÓN
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['del'])) {
    if (!isset($_SESSION['csrf_cupones']) || !hash_equals($_SESSION['csrf_cupones'], $_GET['csrf_token'] ?? '')) {
        $_SESSION['flash'] = "Token de seguridad inválido o expirado. Intenta de nuevo.";
        header("Location: $url_redireccion");
        exit;
    }
    try {
        $id_eliminar = (int)$_GET['del'];
        $sql_del = "DELETE FROM cupones WHERE id = ?";
        $stmt = $conn->prepare($sql_del);
        
        if ($stmt) {
            $stmt->bind_param("i", $id_eliminar);
            $stmt->execute();
            $_SESSION['flash'] = "Beca eliminada correctamente de la bóveda.";
            $stmt->close();
        }
    } catch (Exception $e) {
        $_SESSION['flash'] = "Error al eliminar: " . $e->getMessage();
    }
    
    header("Location: $url_redireccion");
    exit;
}

// 4. LÓGICA DE CREACIÓN
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Captura y Sanitización Estándar Nubira
    $codigo = strtoupper(trim(htmlspecialchars(strip_tags($_POST['codigo'] ?? ''), ENT_QUOTES, 'UTF-8')));
    $descuento = (int)($_POST['porcentaje_descuento'] ?? 100);
    $usos_maximos = (int)($_POST['usos_maximos'] ?? 1);
    
    // Captura de valores opcionales para Exclusividad (Dualidad Global/Específico)
    $servicio_id = (!empty($_POST['servicio_id'])) ? (int)$_POST['servicio_id'] : null;
    $fecha_expiracion = (!empty($_POST['fecha_expiracion'])) ? trim($_POST['fecha_expiracion']) : null;

    // Validación rápida
    if (empty($codigo)) {
        $_SESSION['flash'] = "Rechazado: El código identificador es obligatorio.";
        header("Location: $url_redireccion");
        exit;
    }

    // 5. INSERCIÓN BLINDADA (Sincronizado con columna 'creado_en')
    try {
        $sql = "INSERT INTO cupones (codigo, porcentaje_descuento, usos_maximos, servicio_id, fecha_expiracion, usos_actuales, creado_en) 
                VALUES (?, ?, ?, ?, ?, 0, CURRENT_TIMESTAMP)";
        
        $stmt = $conn->prepare($sql);
        
        if ($stmt) {
            // En PHP 8.x, pasar null a bind_param inserta NULL correctamente en la DB
            $stmt->bind_param("siiis", $codigo, $descuento, $usos_maximos, $servicio_id, $fecha_expiracion);
            
            if ($stmt->execute()) {
                $_SESSION['flash'] = "¡Beca '$codigo' activada con éxito!";
            }
            $stmt->close();
        } else {
            throw new Exception("Fallo en la preparación de la bóveda SQL.");
        }
        
    } catch (mysqli_sql_exception $e) {
        // Manejo de duplicados (Error 1062)
        if ($e->getCode() == 1062) {
            $_SESSION['flash'] = "Error: El código '$codigo' ya está registrado.";
        } else {
            $_SESSION['flash'] = "Error de Base de Datos: " . $e->getMessage();
        }
    } catch (Exception $e) {
        $_SESSION['flash'] = "Error Crítico: " . $e->getMessage();
    }
    
    header("Location: $url_redireccion");
    exit;
}

header("Location: $url_redireccion");
exit;