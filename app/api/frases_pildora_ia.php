<?php
// /app/api/frases_pildora_ia.php
// MOTOR IA NUBIRA 2.0 - ANTI-GENÉRICOS Y NEUROVENTAS + CACHÉ GLOBAL & SESSION

session_start();

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');

$paths = [__DIR__.'/../../conexion.php', __DIR__.'/../conexion.php'];
foreach($paths as $p) if(file_exists($p)){ require_once $p; break; }
if(!isset($conn)) { echo json_encode(["frases" => fallback_frases(true, '', '', '')]); exit; }

$uid = (int)($_SESSION['usuario_id'] ?? 0);
$is_guest = ($uid === 0);
$api_key = 'AIzaSyDMdKaw-D43BPGOjmH-yqxOHUeon0vGPJY'; // ⚠️ TU API KEY

// =========================================================================
// 🛡️ CACHÉ (Forzamos para reiniciar la memoria atascada)
// =========================================================================
$cache_file_invitados = sys_get_temp_dir() . '/nubira_ia_guest_cache_v8.json';

if ($is_guest) {
    if (file_exists($cache_file_invitados) && (time() - filemtime($cache_file_invitados)) < 3600) {
        $arsenal_invitados = json_decode(file_get_contents($cache_file_invitados), true);
        if (is_array($arsenal_invitados)) {
            $preguntas = array_slice($arsenal_invitados, 0, 2);
            $dolores = array_slice($arsenal_invitados, 2);
            shuffle($dolores);
            echo json_encode(["frases" => array_merge($preguntas, $dolores)]);
            exit;
        }
    }
} else {
    // 🔥 Mini-Caché Logueados V3 (Destruimos la memoria que decía "otro")
    if (isset($_SESSION['ia_pildora_cache_v3']) && isset($_SESSION['ia_pildora_time_v3']) && (time() - $_SESSION['ia_pildora_time_v3']) < 600) {
        $frases_sesion = $_SESSION['ia_pildora_cache_v3'];
        
        array_shift($frases_sesion); // Quitamos la primera
        shuffle($frases_sesion); // Revolvemos
        array_unshift($frases_sesion, "¿Eres Profesor o Tutor?"); // Inyectamos como REY absoluto
        
        echo json_encode(["frases" => $frases_sesion, "debug" => "Caché V3 activa"]);
        exit;
    }
}

// 1. CONTEXTO BÁSICO Y FILTRO "ANTI-OTRO"
$nombre_pila = 'estudiante';
$carrera_usr = '';
$inst_usr = '';

if (!$is_guest) {
    if (!empty($_SESSION['usuario_nombre'])) $nombre_pila = explode(' ', trim($_SESSION['usuario_nombre']))[0];
    $u = $conn->query("SELECT carrera, institucion FROM alumnos WHERE id=$uid")->fetch_assoc();
    if($u) { 
        // Filtro para la carrera
        $carrera_sucia = strtolower(trim($u['carrera']));
        if (!in_array($carrera_sucia, ['otro', 'otros', 'otra', 'otras', 'n/a', 'ninguna'])) {
            $carrera_usr = $u['carrera']; 
        }
        $inst_usr = $u['institucion']; 
    }
}

// 2. PERFILADOR ULTRA RÁPIDO Y FILTRO "ANTI-OTRO"
$etiqueta_usuario = "Estándar"; 
$materia_reciente = "";

if (!$is_guest) {
    $sql_fast = "SELECT COALESCE(s.categoria, a.asignatura) as tema, COALESCE(s.precio, a.precio) as precio
                 FROM nubira_behavior_logs l
                 LEFT JOIN servicios s ON (l.entidad_tipo='servicio' AND l.entidad_id=s.id)
                 LEFT JOIN apuntes a ON (l.entidad_tipo='apunte' AND l.entidad_id=a.id)
                 WHERE l.usuario_id = ? AND l.tipo_evento IN ('view', 'purchase')
                 ORDER BY l.fecha DESC LIMIT 1";
    
    $stmt = $conn->prepare($sql_fast);
    if($stmt) {
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            if (!empty($row['tema'])) {
                $tema_sucio = trim(explode('-', $row['tema'])[0]);
                // Filtro para la materia
                if (!in_array(strtolower($tema_sucio), ['otro', 'otros', 'otra', 'otras', 'general'])) {
                    $materia_reciente = $tema_sucio;
                }
            }
            if ($row['precio'] > 0 && $row['precio'] <= 4000) $etiqueta_usuario = "Busca economía";
            elseif ($row['precio'] > 12000) $etiqueta_usuario = "Premium";
        }
        $stmt->close();
    }
}

date_default_timezone_set('America/Santiago'); 
$hora_actual = (int)date('H');
if ($hora_actual >= 0 && $hora_actual < 6) $momento_dia = "madrugada";
elseif ($hora_actual >= 6 && $hora_actual < 12) $momento_dia = "mañana";
elseif ($hora_actual >= 12 && $hora_actual < 19) $momento_dia = "tarde";
else $momento_dia = "noche";

// 3. OBJETIVO DEL COPY 
if ($is_guest) {
    $estado_usuario = "Visitante Anónimo.";
    $objetivo_copy = "Genera urgencia y ataca Drive, WhatsApp y Facebook (estafas, spam, links caídos).";
} else {
    $txt_carrera = $carrera_usr ? $carrera_usr : "tus estudios";
    $txt_materia = $materia_reciente ? $materia_reciente : "tus ramos";
    $estado_usuario = "Usuario logueado: $nombre_pila. Carrera: $txt_carrera. Ramo: $txt_materia.";
    
    $objetivo_copy = "HIPER-PERSONALIZACIÓN ESTILO AIRBNB.
    1. REGLA CRÍTICA: Menciona la carrera O RAMO SOLO EN 2 o 3 FRASES máximo. Las demás deben ser frases de motivación cortas, o sobre los beneficios de estudiar en Nubira. No seas repetitivo.
    2. MÁXIMO 38 CARACTERES POR FRASE (estricto).";
}

// 5. PROMPT ENGINEERING
$prompt = "
ERES: Copywriter de Neuroventas de Nubira.cl.
ESTADO DEL USUARIO: $estado_usuario
OBJETIVO: $objetivo_copy

TAREA: Genera un array JSON de exactamente 9 frases (sin markdown).

REGLAS:
1. MAX 38 CARACTERES POR FRASE.
2. SI ES INVITADO: [0]='¿Eres Profesor o Tutor?', [1]='¿Eres estudiante?', [2]-[8]=Ataca Drive/WhatsApp/Facebook.
3. SI ES LOGUEADO: Genera 9 frases variadas. No repitas la misma idea. Menciona la carrera o ramo máximo 3 veces en total.
";

$url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=" . $api_key;
$body = [
    "contents" => [["parts" => [["text" => $prompt]]]], 
    "generationConfig" => ["temperature" => 0.9, "responseMimeType" => "application/json"]
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_TIMEOUT, 6); 
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch); 
curl_close($ch);

$debug_info = "";

if ($http === 200 && !empty($response)) {
    $decoded = json_decode($response, true);
    $raw = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? '[]';
    $raw = str_replace(['```json', '```'], '', $raw);
    $frases_ia = json_decode(trim($raw), true);

    if (is_array($frases_ia) && count($frases_ia) >= 4) {
        if ($is_guest) {
            $frases_limpias = array_filter($frases_ia, function($f) {
                return !str_contains(strtolower($f), 'profesor') && !str_contains(strtolower($f), 'estudiante') && !str_contains(strtolower($f), 'tutor');
            });
            $final_array = array_merge(["¿Eres Profesor o Tutor?", "¿Eres estudiante?"], array_slice($frases_limpias, 0, 8));
            file_put_contents($cache_file_invitados, json_encode($final_array));
            echo json_encode(["frases" => $final_array, "debug" => "Éxito Invitado"]);
        } else {
            $frases_limpias = array_filter($frases_ia, function($f) {
                return !str_contains(strtolower($f), 'profesor') && !str_contains(strtolower($f), 'tutor');
            });
            $resto = array_slice($frases_limpias, 0, 9);
            shuffle($resto);
            
            // INYECCIÓN DICTATORIAL: Index 0 garantizado
            $final_array = array_merge(["¿Eres Profesor o Tutor?"], $resto);

            $_SESSION['ia_pildora_cache_v3'] = $final_array;
            $_SESSION['ia_pildora_time_v3'] = time();

            echo json_encode(["frases" => $final_array, "debug" => "Éxito Logueado"]);
        }
        exit;
    }
}

// 7. FALLBACK SEGURO
echo json_encode([
    "frases" => fallback_frases($is_guest, $nombre_pila, $carrera_usr, $materia_reciente)
]);

function fallback_frases($guest, $nombre, $carrera, $materia) {
    if ($guest) {
        $base = [
            "¿Eres Profesor o Tutor?", 
            "¿Eres estudiante?",       
            "¿Llorando por un acceso a Drive?", 
            "Esa carpeta de Drive está vacía.", 
            "Apuntes en FB = Virus y estafas.",
            "Te dejan en visto pidiendo apuntes.",
            "Grupos de WhatsApp llenos de spam.",
            "Links caídos a un día de la prueba."
        ];
        $preguntas = array_slice($base, 0, 2);
        $dolores = array_slice($base, 2);
        shuffle($dolores);
        return array_merge($preguntas, $dolores);
    } else {
        $txt_mat = $materia ? $materia : "tus ramos";
        $txt_car = $carrera ? $carrera : "tus estudios";
        $base = [
            "¿Trasnochando por $txt_mat?", 
            "Apunta a lo más alto en $txt_car.", 
            "El resumen definitivo de $txt_mat.", 
            "Un paso más cerca del título.", 
            "Salva $txt_mat sin estrés.",
            "Estudia inteligente, $nombre."
        ];
        shuffle($base);
        array_unshift($base, "¿Eres Profesor o Tutor?");
        return array_slice($base, 0, 10); 
    }
}
?>