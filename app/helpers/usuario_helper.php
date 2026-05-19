<?php
// app/helpers/usuario_helper.php

define('USER_HELPER_LOADED', true);

// Nubira 2.0: Inicio de sesión seguro
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Devuelve el código de institución del usuario logueado.
 */
function obtenerInstitucionUsuario(): ?string {
    // Validación estricta Nubira: Asegurarse de que el usuario_id también exista
    if (!isset($_SESSION['usuario_id'])) {
        return null;
    }
    return isset($_SESSION['institucion']) && is_string($_SESSION['institucion'])
        ? trim($_SESSION['institucion'])
        : null;
}

/**
 * ============================================================================
 * MOTOR DE GAMIFICACIÓN NUBIRA 2.0 (CURVA EXPONENCIAL)
 * Actualiza el score_nubira de un servicio en base al perfil y aportes del tutor.
 * ============================================================================
 */
function actualizar_score_servicio(mysqli $conn, int $id_servicio): bool {
    // 1. Obtener datos actuales del servicio y del tutor
    $sql = "SELECT s.id, a.foto_perfil, a.bio, s.descripcion, s.alumno_id 
            FROM servicios s 
            INNER JOIN alumnos a ON s.alumno_id = a.id 
            WHERE s.id = ?";
            
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Nubira Error - Gamificación (Select): " . $conn->error);
        return false;
    }
    
    $stmt->bind_param("i", $id_servicio);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($res->num_rows === 0) {
        $stmt->close();
        return false;
    }
    
    $row = $res->fetch_assoc();
    $stmt->close();
    
    $score = 0;
    
    // --- NIVEL FÁCIL (El "Gancho") ---

    // [+20 pts] FÁCIL: Foto de perfil
    $fotos_prohibidas = ['default.png', 'default_avatar.webp', 'default_avatar.png', ''];
    if (!in_array(trim($row['foto_perfil'] ?? ''), $fotos_prohibidas)) {
        $score += 20;
    }
    
    // [+20 pts] FÁCIL: Biografía (mínimo 60 caracteres reales)
    $bio = trim($row['bio'] ?? '');
    if (mb_strlen($bio, 'UTF-8') >= 60) {
        $score += 20;
    }
    
    // --- NIVEL HARDCORE (El "Filtro de Calidad") ---

    // [+20 pts] ESTRICTO: Descripción del servicio (mínimo 300 caracteres)
    $desc = trim($row['descripcion'] ?? '');
    if (mb_strlen($desc, 'UTF-8') >= 300) {
        $score += 20;
    }
    
    // [+20 pts] ESTRICTO: Aporte masivo (mínimo 1 apunte público)
    // FIX NUBIRA: La columna correcta en la tabla apuntes es id_alumno, y el estado debe ser 'aprobado'
    $sql_apuntes = "SELECT COUNT(id) as total FROM apuntes WHERE id_alumno = ? AND estado = 'aprobado' AND bloqueado = 0";
    $check_apunte = $conn->prepare($sql_apuntes);
    if ($check_apunte) {
        $check_apunte->bind_param("i", $row['alumno_id']);
        $check_apunte->execute();
        $res_ap = $check_apunte->get_result()->fetch_assoc();
        // Bajamos el requisito a 1 apunte para que sea lograble (como en tu UI)
        if ($res_ap && (int)$res_ap['total'] >= 1) { 
            $score += 20;
        }
        $check_apunte->close();
    }
    
    // [+20 pts] ESTRICTO: Prueba Social (mínimo 3 reseñas como vendedor)
    $sql_val = "SELECT COUNT(id) as total FROM valoraciones WHERE id_evaluado = ? AND rol_evaluado = 'vendedor'";
    $check_val = $conn->prepare($sql_val);
    if ($check_val) {
        $check_val->bind_param("i", $row['alumno_id']);
        $check_val->execute();
        $res_val = $check_val->get_result()->fetch_assoc();
        if ($res_val && (int)$res_val['total'] >= 3) {
            $score += 20;
        }
        $check_val->close();
    }

    // 3. Persistir el score final en la base de datos
    $update = $conn->prepare("UPDATE servicios SET score_nubira = ? WHERE id = ?");
    if ($update) {
        $update->bind_param("ii", $score, $id_servicio);
        $update->execute();
        $update->close();
    } else {
        error_log("Nubira Error - Gamificación (Update): " . $conn->error);
        return false;
    }
    
    return true;
}