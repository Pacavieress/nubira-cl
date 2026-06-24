<?php
/**
 * VISTA: MASTER TRACKER DE CHATS (ADMIN) — NUBIRA 2.0
 * Monitorea el ciclo completo: Pre-venta + Aula Virtual.
 * Versión: 2.1 (Hardened + UX upgrade)
 */

session_start();

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/iconos.php';

// Seguridad estricta
if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] ?? '') !== 'admin') {
    header("Location: /dashboard");
    exit;
}

// AUTO-MIGRACIÓN SILENCIOSA: Tabla dlp_intentos para registro de violaciones DLP
$check_dlp_table = $conn->query("SHOW TABLES LIKE 'dlp_intentos'");
if ($check_dlp_table && $check_dlp_table->num_rows === 0) {
    $conn->query("CREATE TABLE dlp_intentos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        conversacion_id INT NOT NULL,
        remitente_id INT NOT NULL,
        categoria VARCHAR(40) NOT NULL,
        patron_matched VARCHAR(200) NULL,
        texto_intentado TEXT NOT NULL,
        fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
        revisado_admin TINYINT(1) DEFAULT 0,
        INDEX idx_conv (conversacion_id),
        INDEX idx_remitente (remitente_id),
        INDEX idx_revisado (revisado_admin, fecha)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// AUTO-MIGRACIÓN: columna archivo_ruta_original para respaldo antes de censura
try {
    $check_col = $conn->query("SHOW COLUMNS FROM mensajes LIKE 'archivo_ruta_original'");
    if ($check_col && $check_col->num_rows === 0) {
        $conn->query("ALTER TABLE mensajes ADD COLUMN archivo_ruta_original VARCHAR(500) NULL DEFAULT NULL");
    }
} catch (Exception $e) {
    // Migración opcional — continúa si falla
}

$orden_param = $_GET['orden'] ?? 'desc';
$orden_sql = ($orden_param === 'asc') ? 'ASC' : 'DESC';
$filtros_validos = ['activos', 'cerrados', 'contrato', 'cotizacion', 'inactivos', 'alertas_dlp', 'moderacion'];
$filtro_estado_actual = in_array($_GET['estado'] ?? '', $filtros_validos) ? $_GET['estado'] : 'activos';

// =================================================================================
// AVATAR NATIVO (con fallback robusto)
// =================================================================================
if (!function_exists('avatar_nativo')) {
    function avatar_nativo($nombre, $foto, $clases = "w-10 h-10 border-2 border-white text-xs") {
        $p = array_values(array_filter(explode(' ', trim($nombre ?: 'Usuario'))));
        $ini = mb_strtoupper(mb_substr($p[0] ?? 'U', 0, 1) . mb_substr($p[1] ?? '', 0, 1), 'UTF-8');
        $colors = ['bg-sky-100 text-sky-600', 'bg-emerald-100 text-emerald-600', 'bg-rose-100 text-rose-600', 'bg-amber-100 text-amber-600', 'bg-indigo-100 text-indigo-600'];
        $col = $colors[abs(crc32($nombre ?: 'U')) % count($colors)];
        $ini_safe = htmlspecialchars($ini, ENT_QUOTES, 'UTF-8');

        // Fallback siempre presente, oculto si hay foto
        $fallback = '<div class="rounded-full shadow-sm flex items-center justify-center font-bold shrink-0 ' . $col . ' ' . $clases . '" style="display:' . (!empty($foto) ? 'none' : 'flex') . '">' . $ini_safe . '</div>';

        if (!empty($foto)) {
            $foto_path = "/app/perfil/fotos/" . htmlspecialchars($foto, ENT_QUOTES, 'UTF-8');
            return '<img src="' . $foto_path . '" class="rounded-full object-cover shadow-sm bg-gray-50 shrink-0 ' . $clases . '" loading="lazy" onerror="this.style.display=\'none\'; this.nextElementSibling.style.display=\'flex\';">' . $fallback;
        }
        return $fallback;
    }
}

// =================================================================================
// HELPER: Linkify mensajes (URLs clickeables)
// =================================================================================
// =================================================================================
// HELPER: Ofuscar ID de usuario para URL pública
// =================================================================================
if (!function_exists('ofuscar_id_perfil')) {
    function ofuscar_id_perfil($id) {
        return rtrim(base64_encode((int)$id . '-nubira_secreto'), '=');
    }
}

// =================================================================================
// HELPER: Linkify mensajes (URLs clickeables)
// =================================================================================
if (!function_exists('linkify_mensaje')) {
    function linkify_mensaje($texto) {
        $escaped = htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
        $pattern = '/(https?:\/\/[^\s<]+)/i';
        $linked = preg_replace($pattern, '<a href="$1" target="_blank" rel="noopener" class="underline font-bold hover:opacity-80">$1</a>', $escaped);
        return nl2br($linked);
    }
}

// =================================================================================
// HELPER: Burbuja de mensaje
// =================================================================================
if (!function_exists('render_mensaje_admin')) {
    function render_mensaje_admin($m, $is_blue, $avatar_html, $es_historico = false) {
        $flex_dir = $is_blue ? 'flex-row-reverse' : 'flex-row';
        $burbuja_color = $is_blue ? 'bg-[#54A6D8] text-white rounded-tr-sm' : 'bg-white text-gray-800 rounded-tl-sm border border-gray-100';
        $opacidad = $es_historico ? 'opacity-75 grayscale-[25%]' : '';
        $msg_date = $m['enviado_en'] ? date('d/m H:i', strtotime($m['enviado_en'])) : '';

        $contenido_html = '';

        if (!empty($m['archivo_ruta'])) {
            $url_archivo = '/app/ver_archivo_chat.php?m=' . (int)$m['id'];
            $tipo = $m['archivo_tipo'] ?? '';
            $nombre = htmlspecialchars($m['archivo_nombre'] ?? 'archivo', ENT_QUOTES, 'UTF-8');
            $peso_kb = round(($m['archivo_peso'] ?? 0) / 1024);

            if (strpos($tipo, 'image/') === 0) {
                $contenido_html .= '<a href="' . $url_archivo . '" target="_blank" class="block mb-2 -mx-2 -mt-1 rounded-xl overflow-hidden bg-black/5 hover:opacity-90 transition-opacity">
                    <img src="' . $url_archivo . '" alt="' . $nombre . '" class="max-w-full max-h-64 object-cover" loading="lazy">
                </a>';
            } elseif ($tipo === 'application/pdf') {
                $contenido_html .= '<a href="' . $url_archivo . '" target="_blank" class="flex items-center gap-3 p-2 -mx-1 mb-2 rounded-xl ' . ($is_blue ? 'bg-white/10 hover:bg-white/20' : 'bg-gray-50 hover:bg-gray-100') . ' transition-all">
                    <div class="w-10 h-10 rounded-xl ' . ($is_blue ? 'bg-white/20' : 'bg-rose-50') . ' flex items-center justify-center flex-shrink-0">
                        <i class="fa-solid fa-file-pdf ' . ($is_blue ? 'text-white' : 'text-rose-500') . '"></i>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-bold truncate">' . $nombre . '</p>
                        <p class="text-[10px] opacity-70">' . $peso_kb . ' KB · PDF</p>
                    </div>
                </a>';
            } else {
                $contenido_html .= '<a href="' . $url_archivo . '&dl=1" class="flex items-center gap-3 p-2 -mx-1 mb-2 rounded-xl ' . ($is_blue ? 'bg-white/10 hover:bg-white/20' : 'bg-gray-50 hover:bg-gray-100') . ' transition-all">
                    <div class="w-10 h-10 rounded-xl ' . ($is_blue ? 'bg-white/20' : 'bg-blue-50') . ' flex items-center justify-center flex-shrink-0">
                        <i class="fa-solid fa-paperclip ' . ($is_blue ? 'text-white' : 'text-[#54A6D8]') . '"></i>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-bold truncate">' . $nombre . '</p>
                        <p class="text-[10px] opacity-70">' . $peso_kb . ' KB</p>
                    </div>
                </a>';
            }
        }

        if (!empty(trim($m['mensaje'] ?? ''))) {
            $contenido_html .= linkify_mensaje($m['mensaje']);
        }

        return '
        <div class="flex ' . $flex_dir . ' items-end gap-2 mb-4 animate-fade-in-up ' . $opacidad . '" data-msg-id="' . (int)$m['id'] . '">
            ' . $avatar_html . '
            <div class="max-w-[85%] md:max-w-[70%] px-4 py-3 rounded-2xl shadow-sm text-sm leading-relaxed ' . $burbuja_color . '">
                ' . $contenido_html . '
                <div class="flex items-center justify-end mt-1.5 opacity-70 text-[10px] font-bold">
                    <span>' . $msg_date . '</span>
                </div>
            </div>
        </div>';
    }
}

// =================================================================================
// QUERY BUILDER (con filtros avanzados)
// =================================================================================
function build_listado_query($filtro_estado) {
    $where = " WHERE 1=1 ";

    switch ($filtro_estado) {
        case 'cerrados':
            $where .= " AND c.eliminado = 1 ";
            break;
        case 'contrato':
            $where .= " AND c.eliminado = 0 AND c.contrato_id IS NOT NULL ";
            break;
        case 'cotizacion':
            $where .= " AND c.eliminado = 0 AND c.contrato_id IS NULL ";
            break;
        case 'alertas_dlp':
            $where .= " AND c.id IN (SELECT DISTINCT conversacion_id FROM dlp_intentos WHERE revisado_admin = 0) ";
            break;
        case 'moderacion':
            $where .= " AND 1=0 ";
            break;
        case 'inactivos':
            $where .= " AND c.eliminado = 0 AND COALESCE(c.ultima_interaccion, c.creado_en) < DATE_SUB(NOW(), INTERVAL 7 DAY) ";
            break;
        case 'activos':
        default:
            $where .= " AND c.eliminado = 0 ";
            break;
    }

    return $where;
}

// =================================================================================
// AJAX: ACCIONES (eliminar / restaurar)
// =================================================================================
if (isset($_POST['ajax_accion'])) {
    header('Content-Type: application/json');
    $accion = $_POST['ajax_accion'];
    $chat_id = (int)($_POST['chat_id'] ?? 0);

    // Acciones de moderación usan msg_id, no chat_id — saltar el guard
    $acciones_sin_chat_id = ['aprobar_archivo', 'rechazar_archivo'];
    if ($chat_id <= 0 && !in_array($accion, $acciones_sin_chat_id, true)) {
        echo json_encode(['ok' => false, 'msg' => 'ID inválido']);
        exit;
    }

    if ($accion === 'eliminar_chat') {
        $stmt = $conn->prepare("UPDATE conversaciones SET eliminado = 1 WHERE id = ?");
        $stmt->bind_param("i", $chat_id);
        $ok = $stmt->execute();
        echo json_encode(['ok' => $ok, 'msg' => $ok ? 'Chat archivado' : 'Error al archivar']);
        exit;
    }

    if ($accion === 'restaurar_chat') {
        $stmt = $conn->prepare("UPDATE conversaciones SET eliminado = 0 WHERE id = ?");
        $stmt->bind_param("i", $chat_id);
        $ok = $stmt->execute();
        echo json_encode(['ok' => $ok, 'msg' => $ok ? 'Chat restaurado' : 'Error al restaurar']);
        exit;
    }

    if ($accion === 'marcar_revisado_dlp') {
        $stmt = $conn->prepare("UPDATE dlp_intentos SET revisado_admin = 1 WHERE conversacion_id = ?");
        $stmt->bind_param("i", $chat_id);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok]);
        exit;
    }

    if ($accion === 'aprobar_archivo') {
        $msg_id = (int)($_POST['msg_id'] ?? 0);
        $stmt = $conn->prepare("UPDATE mensajes SET visible = 1 WHERE id = ? AND archivo_ruta IS NOT NULL");
        $stmt->bind_param("i", $msg_id);
        $ok = $stmt->execute() && $stmt->affected_rows > 0;
        echo json_encode(['ok' => $ok]);
        exit;
    }

    if ($accion === 'rechazar_archivo') {
        $msg_id = (int)($_POST['msg_id'] ?? 0);
        $stmt = $conn->prepare("SELECT archivo_ruta FROM mensajes WHERE id = ? AND visible = 0 AND archivo_ruta IS NOT NULL");
        $stmt->bind_param("i", $msg_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $ruta_fisica = __DIR__ . '/chat_archivos/' . $row['archivo_ruta'];
            if (file_exists($ruta_fisica)) unlink($ruta_fisica);
            $stmt2 = $conn->prepare("UPDATE mensajes SET visible = -1, archivo_ruta = NULL WHERE id = ?");
            $stmt2->bind_param("i", $msg_id);
            $ok = $stmt2->execute();
            echo json_encode(['ok' => $ok]);
        } else {
            echo json_encode(['ok' => false, 'msg' => 'Mensaje no encontrado']);
        }
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Acción desconocida']);
    exit;
}

// =================================================================================
// AJAX: SEARCH (lista lateral)
// =================================================================================
if (isset($_GET['ajax_search'])) {
    $busqueda = trim($_GET['q'] ?? '');
    $chat_actual = isset($_GET['current_id']) ? (int)$_GET['current_id'] : 0;
    $where_base = build_listado_query($filtro_estado_actual);

    $busqueda_sql = '';
    $params = [];
    $types = '';

    if ($busqueda !== '') {
        // Detectar búsqueda por ID: "C-123", "c123", "123"
        $busqueda_id = null;
        if (preg_match('/^c?-?(\d+)$/i', $busqueda, $m)) {
            $busqueda_id = (int)$m[1];
        }

        if ($busqueda_id !== null) {
            $busqueda_sql = " AND (c.id = ? OR u1.nombre LIKE ? OR u2.nombre LIKE ? OR s.titulo LIKE ?) ";
            $like = '%' . $busqueda . '%';
            $params = [$busqueda_id, $like, $like, $like];
            $types = 'isss';
        } else {
            $busqueda_sql = " AND (u1.nombre LIKE ? OR u2.nombre LIKE ? OR s.titulo LIKE ?) ";
            $like = '%' . $busqueda . '%';
            $params = [$like, $like, $like];
            $types = 'sss';
        }
    }

    $sql = "
        SELECT
            c.id,
            c.contrato_id,
            COALESCE(c.ultima_interaccion, c.creado_en) AS fecha_orden,
            c.creado_en,
            c.ultima_interaccion,
            c.eliminado,
            u1.id AS uid1, u1.nombre AS n1, u1.foto_perfil AS f1,
            u2.id AS uid2, u2.nombre AS n2, u2.foto_perfil AS f2,
            s.titulo AS servicio_titulo
        FROM conversaciones c
        LEFT JOIN alumnos u1 ON c.comprador_id = u1.id
        LEFT JOIN alumnos u2 ON c.vendedor_id = u2.id
        LEFT JOIN servicios s ON c.servicio_id = s.id
        $where_base
        $busqueda_sql
        ORDER BY fecha_orden $orden_sql
        LIMIT 100
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo '<div class="p-4 bg-red-50 text-red-600 rounded-2xl mx-4 text-xs font-bold">Error preparando consulta</div>';
        exit;
    }

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res && $res->num_rows > 0) {
        while ($c = $res->fetch_assoc()) {
            $n1_parts = explode(' ', trim($c['n1'] ?? 'Usuario'));
            $n1_display = $n1_parts[0] . (isset($n1_parts[1]) ? ' ' . mb_substr($n1_parts[1], 0, 1) . '.' : '');
            $n2_parts = explode(' ', trim($c['n2'] ?? 'Usuario'));
            $n2_display = $n2_parts[0] . (isset($n2_parts[1]) ? ' ' . mb_substr($n2_parts[1], 0, 1) . '.' : '');

            $u_msg = $c['servicio_titulo'] ?? 'Chat sin servicio asociado';

            $fecha = $c['fecha_orden'] ? date('d/m', strtotime($c['fecha_orden'])) : '--';
            $es_en_vivo = ($c['fecha_orden'] && (time() - strtotime($c['fecha_orden']) <= 120));

            $avatar1 = avatar_nativo($n1_display, $c['f1']);
            $avatar2 = avatar_nativo($n2_display, $c['f2']);

            $activo = ($chat_actual == $c['id']);
            $bg_class = $activo ? 'bg-sky-50/50 border-sky-100' : 'bg-white border-gray-100 hover:bg-gray-50';
            $border_active = $activo ? '<div class="absolute left-0 top-0 bottom-0 w-1 bg-[#54A6D8] z-10 rounded-l-2xl"></div>' : '';

            $badges = '';
            if ($c['eliminado']) $badges .= '<span class="bg-gray-100 text-gray-500 text-[9px] px-2 py-0.5 rounded-full uppercase font-bold tracking-wider ml-2">Cerrado</span>';
            if (!empty($c['contrato_id'])) $badges .= '<span class="bg-indigo-50 text-indigo-500 border border-indigo-100 text-[9px] px-2 py-0.5 rounded-full uppercase font-bold tracking-wider ml-2">Aula</span>';

            $dot_en_vivo = ($es_en_vivo && !$c['eliminado']) ? '<span class="absolute -top-1 -right-1 flex h-3 w-3 z-20"><span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span><span class="relative inline-flex rounded-full h-3 w-3 bg-emerald-500 border-2 border-white"></span></span>' : '';

            echo '
            <div class="chat-item group flex items-center border mx-4 mb-2 rounded-2xl transition-all hover:shadow-md hover:scale-[1.01] relative cursor-pointer ' . $bg_class . '" data-chat-id="' . (int)$c['id'] . '" data-orden="' . htmlspecialchars($orden_param, ENT_QUOTES) . '" data-estado="' . htmlspecialchars($filtro_estado_actual, ENT_QUOTES) . '">
                ' . $border_active . '
                <div class="flex-1 flex items-center gap-3 p-4 min-w-0 ' . ($c['eliminado'] ? 'opacity-60 grayscale-[30%]' : '') . '">
                    <div class="relative flex -space-x-3 min-w-[4rem] flex-shrink-0">
                        ' . $dot_en_vivo . '
                        ' . $avatar1 . '
                        ' . $avatar2 . '
                    </div>
                   <div class="flex-1 min-w-0">
                        <div class="flex justify-between items-center mb-1">
                            <p class="text-sm font-extrabold text-gray-900 truncate tracking-tight">' . htmlspecialchars($n1_display, ENT_QUOTES, 'UTF-8') . ' <span class="text-gray-300 font-medium">·</span> ' . htmlspecialchars($n2_display, ENT_QUOTES, 'UTF-8') . ' ' . $badges . '</p>
                            <span class="text-[10px] text-[#54A6D8] whitespace-nowrap ml-2 font-bold">' . $fecha . '</span>
                        </div>
                       <p class="text-xs font-medium text-gray-500 truncate group-hover:text-[#54A6D8] transition-colors">' . htmlspecialchars(strip_tags($u_msg), ENT_QUOTES, 'UTF-8') . '</p>
                    </div>
                </div>
            </div>';
        }
    } else {
        echo '<div class="p-8 text-center bg-white border-2 border-dashed border-gray-200 rounded-3xl mx-4 mt-6">
                <div class="w-12 h-12 mx-auto bg-gray-50 rounded-2xl shadow-sm flex items-center justify-center mb-3">
                    <i class="fa-solid fa-ghost text-xl text-gray-300"></i>
                </div>
                <p class="text-sm font-bold text-gray-700">No se encontraron chats</p>
              </div>';
    }
    exit;
}

// =================================================================================
// AJAX: METADATA del chat (cabecera)
// =================================================================================
if (isset($_GET['ajax_metadata']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $chat_id = (int)$_GET['id'];

    $stmt = $conn->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN archivo_ruta IS NOT NULL THEN 1 ELSE 0 END) AS archivos, MIN(enviado_en) AS primero, MAX(enviado_en) AS ultimo FROM mensajes WHERE conversacion_id = ?");
    $stmt->bind_param("i", $chat_id);
    $stmt->execute();
    $meta_pre = $stmt->get_result()->fetch_assoc();

    echo json_encode([
        'total_pre' => (int)($meta_pre['total'] ?? 0),
        'archivos' => (int)($meta_pre['archivos'] ?? 0),
        'primero' => $meta_pre['primero'] ? date('d/m/Y', strtotime($meta_pre['primero'])) : null,
        'ultimo' => $meta_pre['ultimo'] ? date('d/m H:i', strtotime($meta_pre['ultimo'])) : null,
    ]);
    exit;
}

// =================================================================================
// AJAX: MENSAJES
// =================================================================================
if (isset($_GET['ajax_messages']) && isset($_GET['id'])) {
    $chat_id = (int)$_GET['id'];

    $stmt_users = $conn->prepare("SELECT c.comprador_id, c.contrato_id, u1.nombre as n1, u1.foto_perfil as f1, u2.nombre as n2, u2.foto_perfil as f2 FROM conversaciones c LEFT JOIN alumnos u1 ON c.comprador_id = u1.id LEFT JOIN alumnos u2 ON c.vendedor_id = u2.id WHERE c.id = ?");
    if (!$stmt_users) exit;

    $stmt_users->bind_param("i", $chat_id);
    $stmt_users->execute();
    $res_users_obj = $stmt_users->get_result();

    if (!$res_users_obj || $res_users_obj->num_rows === 0) exit;
    $res_users = $res_users_obj->fetch_assoc();

    $comprador_id = $res_users['comprador_id'] ?? 0;
    $id_contrato = $res_users['contrato_id'] ?? null;

    $n1_display = explode(' ', trim($res_users['n1'] ?? 'U'))[0];
    $n2_display = explode(' ', trim($res_users['n2'] ?? 'U'))[0];
    $avatar_comp = avatar_nativo($n1_display, $res_users['f1'], "w-8 h-8 border-2 border-white text-[10px] hidden md:flex");
    $avatar_vend = avatar_nativo($n2_display, $res_users['f2'], "w-8 h-8 border-2 border-white text-[10px] hidden md:flex");

    // Pre-venta
    $stmt = $conn->prepare("SELECT id, remitente_id, mensaje, archivo_nombre, archivo_ruta, archivo_tipo, archivo_peso, enviado_en FROM mensajes WHERE conversacion_id = ? ORDER BY enviado_en ASC");
    $hubo_preventa = false;
    if ($stmt) {
        $stmt->bind_param("i", $chat_id);
        $stmt->execute();
        $mensajes = $stmt->get_result();

        $es_historico = false;
        $hubo_preventa = ($mensajes && $mensajes->num_rows > 0);

        if ($mensajes) {
            while ($m = $mensajes->fetch_assoc()) {
                $is_blue = ($m['remitente_id'] == $comprador_id);
                echo render_mensaje_admin($m, $is_blue, $is_blue ? $avatar_comp : $avatar_vend, $es_historico);
            }
        }
    }

    if (!$hubo_preventa) {
        echo '<div class="flex flex-col items-center justify-center h-full text-center p-8 bg-white m-6 rounded-3xl border-2 border-dashed border-gray-200">
                <div class="w-16 h-16 bg-gray-50 rounded-2xl shadow-sm flex items-center justify-center mb-4">
                    <i class="fa-regular fa-comments text-2xl text-[#54A6D8]/50"></i>
                </div>
                <p class="text-sm font-bold text-gray-700">Aún no hay mensajes.</p>
              </div>';
    }

    // Intentos DLP bloqueados para esta conversación
    $stmt_dlp = $conn->prepare("
        SELECT d.id, d.categoria, d.texto_intentado, d.fecha, d.revisado_admin,
               a.nombre AS remitente_nombre
        FROM dlp_intentos d
        LEFT JOIN alumnos a ON d.remitente_id = a.id
        WHERE d.conversacion_id = ?
        ORDER BY d.fecha ASC
    ");
    if ($stmt_dlp) {
        $stmt_dlp->bind_param("i", $chat_id);
        $stmt_dlp->execute();
        $res_dlp = $stmt_dlp->get_result();
        $dlp_rows = [];
        while ($row = $res_dlp->fetch_assoc()) {
            $dlp_rows[] = $row;
        }
        if (!empty($dlp_rows)) {
            $pendientes = array_filter($dlp_rows, fn($r) => !$r['revisado_admin']);
            $hay_pendientes = !empty($pendientes);
            $borde = $hay_pendientes ? 'border-red-200' : 'border-gray-200';
            $fondo_header = $hay_pendientes ? 'bg-red-100/60 border-red-200' : 'bg-gray-100/60 border-gray-200';
            $texto_titulo = $hay_pendientes ? 'text-red-700' : 'text-gray-500';
            echo '<div class="mx-2 mb-4 rounded-2xl border ' . $borde . ' overflow-hidden">';
            echo '<div class="flex items-center justify-between px-4 py-3 border-b ' . $fondo_header . '">';
            echo '<span class="text-xs font-extrabold uppercase tracking-widest ' . $texto_titulo . '">🚨 Intentos bloqueados <span class="ml-1 font-bold opacity-70">(' . count($dlp_rows) . ')</span></span>';
            if ($hay_pendientes) {
                echo '<span class="text-[10px] font-bold bg-red-500 text-white px-2 py-0.5 rounded-full">' . count($pendientes) . ' pendientes</span>';
            } else {
                echo '<span class="text-[10px] font-bold bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded-full">Todos revisados</span>';
            }
            echo '</div>';
            foreach ($dlp_rows as $dlp) {
                $revisado = (bool)$dlp['revisado_admin'];
                $fecha_dlp = $dlp['fecha'] ? date('d/m/Y H:i', strtotime($dlp['fecha'])) : '--';
                $nombre_rem = htmlspecialchars($dlp['remitente_nombre'] ?? 'Desconocido', ENT_QUOTES, 'UTF-8');
                $texto = htmlspecialchars($dlp['texto_intentado'], ENT_QUOTES, 'UTF-8');
                $cat = htmlspecialchars($dlp['categoria'], ENT_QUOTES, 'UTF-8');
                $op = $revisado ? 'opacity-50' : '';
                echo '<div class="px-4 py-3 border-b border-red-100 last:border-b-0 ' . $op . '">';
                echo '<div class="flex items-center gap-2 mb-2 flex-wrap">';
                echo '<span class="text-xs font-bold text-gray-700">' . $nombre_rem . '</span>';
                echo '<span class="text-[10px] text-gray-400">' . $fecha_dlp . '</span>';
                echo '<span class="ml-auto text-[10px] font-extrabold uppercase tracking-wide bg-red-100 text-red-700 px-2 py-0.5 rounded-full border border-red-200">' . $cat . '</span>';
                if ($revisado) echo '<span class="text-[10px] font-bold bg-gray-100 text-gray-400 px-2 py-0.5 rounded-full">Revisado</span>';
                echo '</div>';
                echo '<div class="bg-white border border-red-100 rounded-xl px-3 py-2 text-xs text-gray-700 font-mono leading-relaxed break-all">' . $texto . '</div>';
                echo '</div>';
            }
            if ($hay_pendientes) {
                echo '<div class="px-4 py-3">';
                echo '<button class="btn-marcar-dlp w-full bg-red-500 hover:bg-red-600 text-white text-xs font-bold py-2.5 rounded-xl transition-all shadow-sm" data-chat-id="' . (int)$chat_id . '">Marcar todos como revisados</button>';
                echo '</div>';
            }
            echo '</div>';
        }
    }

    echo '<div id="scroll-bottom" class="h-4"></div>';
    exit;
}

// =================================================================================
// CARGA INICIAL
// =================================================================================
$res_activos = $conn->query("SELECT COUNT(*) AS n FROM conversaciones WHERE eliminado = 0");
$cnt_activos = $res_activos ? (int)$res_activos->fetch_assoc()['n'] : 0;

$res_cerrados = $conn->query("SELECT COUNT(*) AS n FROM conversaciones WHERE eliminado = 1");
$cnt_cerrados = $res_cerrados ? (int)$res_cerrados->fetch_assoc()['n'] : 0;

$res_contrato = $conn->query("SELECT COUNT(*) AS n FROM conversaciones WHERE eliminado = 0 AND contrato_id IS NOT NULL");
$cnt_contrato = $res_contrato ? (int)$res_contrato->fetch_assoc()['n'] : 0;

$res_cotizacion = $conn->query("SELECT COUNT(*) AS n FROM conversaciones WHERE eliminado = 0 AND contrato_id IS NULL");
$cnt_cotizacion = $res_cotizacion ? (int)$res_cotizacion->fetch_assoc()['n'] : 0;

$res_inactivos = $conn->query("SELECT COUNT(*) AS n FROM conversaciones WHERE eliminado = 0 AND COALESCE(ultima_interaccion, creado_en) < DATE_SUB(NOW(), INTERVAL 7 DAY)");
$cnt_inactivos = $res_inactivos ? (int)$res_inactivos->fetch_assoc()['n'] : 0;

$res_alertas_dlp = $conn->query("SELECT COUNT(DISTINCT conversacion_id) AS n FROM dlp_intentos WHERE revisado_admin = 0");
$cnt_alertas_dlp = $res_alertas_dlp ? (int)$res_alertas_dlp->fetch_assoc()['n'] : 0;

$res_moderacion = $conn->query("SELECT COUNT(*) AS n FROM mensajes WHERE visible = 0 AND archivo_ruta IS NOT NULL");
$cnt_moderacion = $res_moderacion ? (int)$res_moderacion->fetch_assoc()['n'] : 0;

$chat_seleccionado = isset($_GET['id']) ? (int)$_GET['id'] : null;
$info_chat = null;
if ($chat_seleccionado) {
    $stmt = $conn->prepare("SELECT u1.id AS uid1, u1.nombre as n1, u1.foto_perfil as f1, u2.id AS uid2, u2.nombre as n2, u2.foto_perfil as f2, c.servicio_id, c.eliminado, c.comprador_id, c.contrato_id, s.titulo AS servicio_titulo FROM conversaciones c LEFT JOIN alumnos u1 ON c.comprador_id = u1.id LEFT JOIN alumnos u2 ON c.vendedor_id = u2.id LEFT JOIN servicios s ON c.servicio_id = s.id WHERE c.id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $chat_seleccionado);
        $stmt->execute();
        $res_info = $stmt->get_result();
        if ($res_info && $res_info->num_rows > 0) {
            $info_chat = $res_info->fetch_assoc();
        } else {
            $chat_seleccionado = null;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <title>Master Tracker | Nubira Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0, viewport-fit=cover" />
    <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #f9fafb; overscroll-behavior-y: none; }
        .custom-scrollbar::-webkit-scrollbar { width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #e5e7eb; border-radius: 16px; }
        .custom-scrollbar:hover::-webkit-scrollbar-thumb { background: #d1d5db; }
        @media (max-width: 768px) { .custom-scrollbar::-webkit-scrollbar { display: none; } }
        .animate-fade-in-up { animation: fadeInUp 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
        .resizer-handle { cursor: col-resize; transition: background-color 0.2s; }
        .resizer-handle:hover, .resizer-handle.active { background-color: #54A6D8; }
        .filter-pill.active { background-color: #54A6D8; color: white; border-color: #54A6D8; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

        /* Toast */
        .toast { animation: toastIn 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
        @keyframes toastIn { from { opacity: 0; transform: translateY(-12px); } to { opacity: 1; transform: translateY(0); } }

        /* Kbd hint */
        kbd { font-family: 'Inter', sans-serif; font-size: 10px; padding: 2px 6px; background: #f3f4f6; border: 1px solid #e5e7eb; border-radius: 6px; font-weight: 700; color: #6b7280; box-shadow: 0 1px 0 #e5e7eb; }
    </style>
</head>
<body class="bg-gray-50 text-gray-900 antialiased overflow-x-hidden h-screen flex flex-col selection:bg-blue-100">

    <div id="loader" class="fixed inset-0 bg-white/90 backdrop-blur-sm flex flex-col items-center justify-center z-[80] transition-opacity duration-300">
        <div class="animate-spin h-12 w-12 border-4 border-blue-100 border-t-[#54A6D8] rounded-full shadow-sm mb-4"></div>
        <p class="text-[#54A6D8] font-bold text-sm">Cargando Master Tracker...</p>
    </div>

    <!-- Toast container -->
    <div id="toast-container" class="fixed top-20 md:top-20 right-4 z-[90] flex flex-col gap-2 pointer-events-none"></div>

    <?php
    $page_title = "Master Tracker";
    if (file_exists(__DIR__ . '/componentes/header.php')) require_once __DIR__ . '/componentes/header.php';
    if (file_exists(__DIR__ . '/componentes/sidebar.php')) require_once __DIR__ . '/componentes/sidebar.php';
    ?>

    <main class="pt-20 md:pt-16 pb-20 md:pb-0 lg:ml-64 h-full flex overflow-hidden bg-white w-full max-w-[1600px] mx-auto border-x border-gray-100 shadow-sm" id="main-container">

        <div id="sidebar-panel" class="bg-white border-r border-gray-100 flex-col h-full z-10 <?php echo $chat_seleccionado ? 'hidden md:flex' : 'flex w-full'; ?> md:w-[400px] md:min-w-[320px]">
            <div class="px-5 pt-5 pb-3">
                <div class="flex items-center justify-between mb-4">
                    <h1 class="text-2xl font-extrabold tracking-tight text-gray-900">Master Tracker</h1>
                    <span class="text-[10px] bg-indigo-50 text-indigo-600 border border-indigo-100 px-2.5 py-1 rounded-full font-extrabold uppercase tracking-widest shadow-sm">Global</span>
                </div>
                <div class="relative group">
                    <i class="fa-solid fa-search absolute left-4 top-3.5 text-gray-400 text-sm group-focus-within:text-[#54A6D8] transition-colors"></i>
                    <input type="text" id="searchInput" placeholder="Buscar ID, usuario o servicio..." class="w-full pl-11 pr-12 py-3 bg-gray-50 border border-gray-100 rounded-2xl text-sm font-medium focus:ring-2 focus:ring-[#54A6D8]/20 focus:border-[#54A6D8] transition-all outline-none shadow-sm placeholder-gray-400">
                    <kbd class="hidden md:block absolute right-4 top-1/2 -translate-y-1/2 pointer-events-none">/</kbd>
                </div>
            </div>

            <div class="relative">
                <div class="px-5 pb-3 flex gap-2 overflow-x-auto no-scrollbar border-b border-gray-50">
                    <button data-filter="activos" class="filter-pill <?php echo $filtro_estado_actual === 'activos' ? 'active' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50'; ?> text-xs font-bold px-4 py-2 rounded-full whitespace-nowrap transition-all shadow-sm">Activos <span class="ml-1 opacity-80 bg-black/10 px-1.5 rounded-full"><?php echo (int)$cnt_activos; ?></span></button>
                    <button data-filter="alertas_dlp" class="filter-pill <?php echo $filtro_estado_actual === 'alertas_dlp' ? 'active' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50'; ?> text-xs font-bold px-4 py-2 rounded-full whitespace-nowrap transition-all shadow-sm">Alertas DLP <?php if ($cnt_alertas_dlp > 0): ?><span class="ml-1 bg-red-500 text-white px-1.5 rounded-full"><?php echo (int)$cnt_alertas_dlp; ?></span><?php else: ?><span class="ml-1 opacity-80 bg-black/10 px-1.5 rounded-full">0</span><?php endif; ?></button>
                    <button data-filter="moderacion" class="filter-pill <?php echo $filtro_estado_actual === 'moderacion' ? 'active' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50'; ?> text-xs font-bold px-4 py-2 rounded-full whitespace-nowrap transition-all shadow-sm">Moderación <?php if ($cnt_moderacion > 0): ?><span class="ml-1 bg-orange-500 text-white px-1.5 rounded-full"><?php echo (int)$cnt_moderacion; ?></span><?php else: ?><span class="ml-1 opacity-80 bg-black/10 px-1.5 rounded-full">0</span><?php endif; ?></button>
                    <button data-filter="contrato" class="filter-pill <?php echo $filtro_estado_actual === 'contrato' ? 'active' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50'; ?> text-xs font-bold px-4 py-2 rounded-full whitespace-nowrap transition-all shadow-sm">Con contrato <span class="ml-1 opacity-80 bg-black/10 px-1.5 rounded-full"><?php echo (int)$cnt_contrato; ?></span></button>
                    <button data-filter="cotizacion" class="filter-pill <?php echo $filtro_estado_actual === 'cotizacion' ? 'active' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50'; ?> text-xs font-bold px-4 py-2 rounded-full whitespace-nowrap transition-all shadow-sm">Cotización <span class="ml-1 opacity-80 bg-black/10 px-1.5 rounded-full"><?php echo (int)$cnt_cotizacion; ?></span></button>
                    <button data-filter="inactivos" class="filter-pill <?php echo $filtro_estado_actual === 'inactivos' ? 'active' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50'; ?> text-xs font-bold px-4 py-2 rounded-full whitespace-nowrap transition-all shadow-sm">+7d <span class="ml-1 opacity-80 bg-black/10 px-1.5 rounded-full"><?php echo (int)$cnt_inactivos; ?></span></button>
                    <button data-filter="cerrados" class="filter-pill <?php echo $filtro_estado_actual === 'cerrados' ? 'active' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50'; ?> text-xs font-bold px-4 py-2 rounded-full whitespace-nowrap transition-all shadow-sm">Cerrados <span class="ml-1 opacity-80 bg-black/10 px-1.5 rounded-full"><?php echo (int)$cnt_cerrados; ?></span></button>
                </div>
                <div class="absolute right-0 top-0 bottom-0 w-12 pointer-events-none z-10" style="background:linear-gradient(to right,transparent,white)"></div>
            </div>

            <div class="px-5 py-3 flex justify-between items-center bg-white gap-2 flex-shrink-0 border-b border-gray-50 mb-3">
                <span class="text-[10px] font-extrabold text-gray-400 uppercase tracking-widest">Historial</span>
                <form method="GET" class="flex items-center">
                    <?php if ($chat_seleccionado): ?><input type="hidden" name="id" value="<?php echo (int)$chat_seleccionado; ?>"><?php endif; ?>
                    <input type="hidden" name="estado" value="<?php echo htmlspecialchars($filtro_estado_actual, ENT_QUOTES); ?>">
                    <select name="orden" onchange="this.form.submit()" class="bg-gray-50 text-xs font-bold text-gray-600 border border-gray-100 rounded-xl py-1.5 px-3 cursor-pointer outline-none hover:bg-gray-100 transition-colors shadow-sm">
                        <option value="desc" <?php echo ($orden_param == 'desc') ? 'selected' : ''; ?>>Recientes</option>
                        <option value="asc" <?php echo ($orden_param == 'asc') ? 'selected' : ''; ?>>Antiguos</option>
                    </select>
                </form>
            </div>

            <div id="chatsListContainer" class="flex-1 overflow-y-auto custom-scrollbar pb-6"></div>

            <!-- Hints de teclado (solo desktop) -->
            <div class="hidden md:flex items-center justify-center gap-3 px-5 py-3 border-t border-gray-50 bg-gray-50/50 text-[10px] font-bold text-gray-400">
                <span class="flex items-center gap-1"><kbd>↑</kbd><kbd>↓</kbd> Navegar</span>
                <span class="flex items-center gap-1"><kbd>/</kbd> Buscar</span>
                <span class="flex items-center gap-1"><kbd>Esc</kbd> Volver</span>
            </div>
        </div>

        <div id="drag-handle" class="hidden md:block w-[5px] bg-gray-50 border-x border-gray-100 h-full resizer-handle z-20 flex-shrink-0 hover:bg-[#54A6D8]/30 transition-colors"></div>

        <div class="flex-1 bg-white flex flex-col h-full min-w-0 relative <?php echo $chat_seleccionado ? 'flex fixed inset-0 md:static z-50' : 'hidden md:flex'; ?>">
            <?php if ($chat_seleccionado && $info_chat):
                $d_n1 = explode(' ', trim($info_chat['n1'] ?? 'Usuario'))[0];
                $d_n2 = explode(' ', trim($info_chat['n2'] ?? 'Usuario'))[0];
            ?>
                <div class="px-6 py-4 bg-white/95 backdrop-blur-md border-b border-gray-100 sticky top-0 z-20 shadow-sm">
                    <div class="flex justify-between items-start gap-4">
                        <div class="flex items-start gap-4 min-w-0 flex-1">
                            <a href="?orden=<?php echo htmlspecialchars($orden_param, ENT_QUOTES); ?>&estado=<?php echo htmlspecialchars($filtro_estado_actual, ENT_QUOTES); ?>" class="md:hidden text-[#54A6D8] hover:bg-sky-50 p-2.5 rounded-full transition-colors flex items-center justify-center shrink-0">
                                <i class="fa-solid fa-chevron-left text-lg"></i>
                            </a>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2 mb-1 flex-wrap">
                                   <h3 class="font-extrabold text-gray-900 text-lg leading-tight tracking-tight"><?php echo htmlspecialchars($d_n1, ENT_QUOTES, 'UTF-8'); ?> <span class="text-gray-300 font-medium">·</span> <?php echo htmlspecialchars($d_n2, ENT_QUOTES, 'UTF-8'); ?></h3>
                                    <?php if ($info_chat['contrato_id']): ?>
                                        <span class="bg-indigo-50 text-indigo-600 text-[9px] px-2 py-0.5 rounded-full font-extrabold border border-indigo-100 uppercase tracking-widest">Contrato #<?php echo (int)$info_chat['contrato_id']; ?></span>
                                    <?php else: ?>
                                        <span class="bg-amber-50 text-amber-700 text-[9px] px-2 py-0.5 rounded-full font-extrabold border border-amber-100 uppercase tracking-widest">Cotización</span>
                                    <?php endif; ?>
                                    <span id="live-indicator" class="hidden bg-emerald-50 text-emerald-700 text-[9px] px-2 py-0.5 rounded-full font-extrabold border border-emerald-100 uppercase tracking-widest items-center gap-1">
                                        <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-pulse"></span> En vivo
                                    </span>
                                </div>
                               <p class="text-xs text-gray-500 truncate font-medium">
                                    <a href="/perfil/<?php echo ofuscar_id_perfil($info_chat['uid1']); ?>" target="_blank" class="hover:text-[#54A6D8] hover:underline transition-colors">Ver perfil de <?php echo htmlspecialchars($d_n1, ENT_QUOTES, 'UTF-8'); ?></a>
                                    <span class="text-gray-300 mx-1">·</span>
                                    <a href="/perfil/<?php echo ofuscar_id_perfil($info_chat['uid2']); ?>" target="_blank" class="hover:text-[#54A6D8] hover:underline transition-colors">Ver perfil de <?php echo htmlspecialchars($d_n2, ENT_QUOTES, 'UTF-8'); ?></a>
                                    <?php if (!empty($info_chat['servicio_titulo'])): ?>
                                        <span class="text-gray-300 mx-1">·</span>
                                        <span class="italic"><?php echo htmlspecialchars($info_chat['servicio_titulo'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>

                        <div class="flex items-center gap-2 shrink-0">
                            <?php if ($info_chat['eliminado']): ?>
                                <button data-accion="restaurar_chat" data-chat-id="<?php echo (int)$chat_seleccionado; ?>" class="btn-accion bg-white border border-gray-200 text-emerald-600 hover:bg-emerald-50 px-4 py-2 rounded-xl text-xs font-bold transition-all shadow-sm">
                                    <i class="fa-solid fa-rotate-left mr-1.5"></i> Restaurar
                                </button>
                            <?php else: ?>
                                <button data-accion="eliminar_chat" data-chat-id="<?php echo (int)$chat_seleccionado; ?>" class="btn-accion bg-white border border-gray-200 text-gray-500 hover:text-red-600 hover:bg-red-50 hover:border-red-100 px-4 py-2 rounded-xl text-xs font-bold transition-all shadow-sm">
                                    <i class="fa-solid fa-trash-can"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Metadata bar -->
                    <div id="metadata-bar" class="flex items-center gap-4 mt-3 pt-3 border-t border-gray-50 text-[10px] font-bold text-gray-500 uppercase tracking-wider opacity-0 transition-opacity">
                        <span class="flex items-center gap-1.5">
                            <i class="fa-regular fa-comment text-[#54A6D8]"></i>
                            <span id="meta-total">0</span> mensajes
                        </span>
                        <span class="flex items-center gap-1.5">
                            <i class="fa-solid fa-paperclip text-[#54A6D8]"></i>
                            <span id="meta-archivos">0</span> archivos
                        </span>
                        <span id="meta-fechas" class="hidden md:flex items-center gap-1.5 text-gray-400">
                            <i class="fa-regular fa-clock"></i>
                            <span id="meta-primero">--</span> → <span id="meta-ultimo">--</span>
                        </span>
                    </div>
                </div>

                <div class="flex-1 overflow-y-auto p-6 space-y-4 custom-scrollbar bg-gray-50/50" id="chat-container">
                    <div class="flex justify-center items-center h-full">
                        <div class="animate-pulse flex flex-col items-center">
                            <div class="w-10 h-10 border-4 border-blue-100 border-t-[#54A6D8] rounded-full animate-spin mb-3"></div>
                            <p class="text-xs font-bold text-[#54A6D8]">Sincronizando log...</p>
                        </div>
                    </div>
                </div>
            <?php elseif ($filtro_estado_actual === 'moderacion'): ?>
                <div class="flex-1 overflow-y-auto p-6 custom-scrollbar bg-gray-50/50" id="mod-panel">
                    <h2 class="text-lg font-extrabold text-gray-900 mb-1 tracking-tight">Moderación de archivos</h2>
                    <p class="text-xs text-gray-400 font-medium mb-5">Archivos enviados por usuarios pendientes de revisión.</p>
                    <?php
                    $stmt_mod = $conn->prepare("
                        SELECT m.id, m.conversacion_id, m.archivo_ruta, m.archivo_nombre, m.archivo_tipo, m.archivo_peso, m.enviado_en,
                               a.nombre AS remitente_nombre
                        FROM mensajes m
                        JOIN alumnos a ON m.remitente_id = a.id
                        WHERE m.visible = 0 AND m.archivo_ruta IS NOT NULL
                        ORDER BY m.enviado_en ASC
                    ");
                    $stmt_mod->execute();
                    $res_mod = $stmt_mod->get_result();
                    $pending = [];
                    while ($r = $res_mod->fetch_assoc()) $pending[] = $r;
                    $stmt_mod->close();

                    if (empty($pending)):
                    ?>
                    <div class="flex flex-col items-center justify-center py-16 text-center">
                        <div class="w-16 h-16 bg-white rounded-2xl shadow-sm border border-gray-100 flex items-center justify-center mb-4">
                            <i class="fa-solid fa-shield-check text-2xl text-emerald-400"></i>
                        </div>
                        <p class="text-sm font-bold text-gray-700">Sin archivos pendientes</p>
                        <p class="text-xs text-gray-400 mt-1">Todo está al día.</p>
                    </div>
                    <?php else: ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                    <?php foreach ($pending as $pm):
                        $es_imagen = strpos($pm['archivo_tipo'] ?? '', 'image/') === 0;
                        $url_img   = '/app/ver_archivo_chat.php?m=' . (int)$pm['id'];
                        $nombre_r  = htmlspecialchars($pm['remitente_nombre'] ?? 'Desconocido', ENT_QUOTES, 'UTF-8');
                        $peso_kb   = round(($pm['archivo_peso'] ?? 0) / 1024);
                        $fecha_r   = $pm['enviado_en'] ? date('d/m/Y H:i', strtotime($pm['enviado_en'])) : '--';
                    ?>
                    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden mod-card" data-msg-id="<?php echo (int)$pm['id']; ?>">
                        <?php if ($es_imagen): ?>
                        <a href="<?php echo $url_img; ?>" target="_blank" class="block bg-gray-100 overflow-hidden" style="height:180px">
                            <img src="<?php echo $url_img; ?>" alt="<?php echo htmlspecialchars($pm['archivo_nombre'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" class="w-full h-full object-contain" loading="lazy">
                        </a>
                        <?php else: ?>
                        <div class="flex items-center justify-center bg-gray-50 border-b border-gray-100" style="height:100px">
                            <i class="fa-solid fa-file text-4xl text-gray-300"></i>
                        </div>
                        <?php endif; ?>
                        <div class="p-4">
                            <p class="text-xs font-extrabold text-gray-900 truncate"><?php echo $nombre_r; ?></p>
                            <p class="text-[10px] text-gray-400 font-medium mb-1">Chat #<?php echo (int)$pm['conversacion_id']; ?> · <?php echo $fecha_r; ?> · <?php echo $peso_kb; ?> KB</p>
                            <div class="flex gap-2 mt-3">
                                <button class="btn-aprobar flex-1 bg-emerald-500 hover:bg-emerald-600 text-white text-xs font-bold py-2 rounded-xl transition-all" data-msg-id="<?php echo (int)$pm['id']; ?>">Aprobar</button>
                                <?php if ($es_imagen): ?>
                                <a href="/app/admin_moderar_archivo.php?m=<?php echo (int)$pm['id']; ?>" target="_blank" class="flex-1 bg-amber-400 hover:bg-amber-500 text-white text-xs font-bold py-2 rounded-xl transition-all text-center">Censurar</a>
                                <?php endif; ?>
                                <button class="btn-rechazar flex-1 bg-red-500 hover:bg-red-600 text-white text-xs font-bold py-2 rounded-xl transition-all" data-msg-id="<?php echo (int)$pm['id']; ?>">Rechazar</button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="flex flex-col items-center justify-center h-full text-center p-8 bg-gray-50/50">
                    <div class="w-24 h-24 bg-white rounded-3xl flex items-center justify-center mb-6 shadow-sm border border-gray-100 rotate-3 transition-transform hover:rotate-0">
                        <i class="fa-solid fa-satellite-dish w-10 h-10 text-[#54A6D8]/60 text-4xl"></i>
                    </div>
                    <h2 class="text-2xl font-extrabold text-gray-900 mb-2 tracking-tight">Master Tracker</h2>
                    <p class="text-gray-500 text-sm max-w-sm mx-auto leading-relaxed font-medium mb-6">Selecciona una conversación para inspeccionar el historial completo con seguridad Nubira.</p>
                    <div class="hidden md:flex items-center gap-2 text-[10px] font-bold text-gray-400">
                        <kbd>↑</kbd><kbd>↓</kbd>
                        <span>navega entre chats</span>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <?php if (file_exists(__DIR__ . '/componentes/bottom_nav.php')) require_once __DIR__ . '/componentes/bottom_nav.php'; ?>

    <script>
    (function() {
        'use strict';

        // ==========================================
        // UTILS
        // ==========================================
        const $ = (sel) => document.querySelector(sel);
        const $$ = (sel) => Array.from(document.querySelectorAll(sel));

        const removerLoader = () => {
            const loader = document.getElementById('loader');
            if (loader) {
                loader.classList.add('opacity-0');
                setTimeout(() => loader.remove(), 300);
            }
        };

        const showToast = (msg, tipo = 'ok') => {
            const cont = document.getElementById('toast-container');
            if (!cont) return;
            const colors = {
                ok: 'bg-emerald-500 text-white',
                error: 'bg-red-500 text-white',
                info: 'bg-[#54A6D8] text-white'
            };
            const icons = {
                ok: 'fa-circle-check',
                error: 'fa-circle-exclamation',
                info: 'fa-circle-info'
            };
            const toast = document.createElement('div');
            toast.className = `toast pointer-events-auto px-4 py-3 rounded-2xl shadow-lg text-sm font-bold flex items-center gap-2 ${colors[tipo] || colors.info}`;
            toast.innerHTML = `<i class="fa-solid ${icons[tipo] || icons.info}"></i><span>${msg}</span>`;
            cont.appendChild(toast);
            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateY(-12px)';
                toast.style.transition = 'all 0.3s';
                setTimeout(() => toast.remove(), 300);
            }, 2800);
        };

        // Hash simple para detectar cambios reales en mensajes
        const hashString = (str) => {
            let hash = 0;
            for (let i = 0; i < str.length; i++) {
                hash = ((hash << 5) - hash) + str.charCodeAt(i);
                hash |= 0;
            }
            return hash;
        };

        // ==========================================
        // ESTADO
        // ==========================================
        const currentChatId = parseInt('<?php echo (int)($chat_seleccionado ?? 0); ?>', 10);
        const currentOrden = '<?php echo htmlspecialchars($orden_param, ENT_QUOTES); ?>';
        let currentEstado = '<?php echo htmlspecialchars($filtro_estado_actual, ENT_QUOTES); ?>';
        const requestUri = window.location.pathname;
        let searchTimeout;
        let pollingInterval = null;
        let lastMessagesHash = 0;

        // ==========================================
        // INIT: remover loader
        // ==========================================
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', removerLoader);
        } else {
            removerLoader();
        }
        setTimeout(removerLoader, 2000);

        // ==========================================
        // LISTA DE CHATS (AJAX)
        // ==========================================
        const searchInput = $('#searchInput');
        const chatsList = $('#chatsListContainer');

        const showSkeletons = () => {
            if (!chatsList) return;
            let sk = '';
            for (let i = 0; i < 5; i++) {
                sk += `<div class="flex items-center border border-gray-100 mx-4 mb-3 p-4 rounded-2xl animate-pulse bg-white shadow-sm"><div class="w-12 h-12 mr-4 rounded-full bg-gray-100"></div><div class="flex-1"><div class="h-3.5 bg-gray-200 rounded-full w-24 mb-2.5"></div><div class="h-2.5 bg-gray-100 rounded-full w-3/4"></div></div></div>`;
            }
            chatsList.innerHTML = sk;
        };

        const recargarLista = () => {
            if (!chatsList) return;
            const q = searchInput ? searchInput.value : '';
            showSkeletons();
            fetch(`${requestUri}?ajax_search=1&q=${encodeURIComponent(q)}&current_id=${currentChatId}&orden=${currentOrden}&estado=${currentEstado}`)
                .then(r => r.text())
                .then(html => {
                    chatsList.style.opacity = 0;
                    chatsList.innerHTML = html;
                    bindChatItems();
                    setTimeout(() => { chatsList.style.opacity = 1; }, 50);
                })
                .catch(e => {
                    chatsList.innerHTML = `<div class="p-4 bg-red-50 text-red-600 rounded-2xl mx-4 text-xs font-bold text-center">Error de red</div>`;
                });
        };

        const bindChatItems = () => {
            $$('.chat-item').forEach(item => {
                item.addEventListener('click', () => {
                    const id = item.dataset.chatId;
                    const orden = item.dataset.orden;
                    const estado = item.dataset.estado;
                    window.location.href = `?id=${id}&orden=${orden}&estado=${estado}`;
                });
            });
        };

        recargarLista();

        if (searchInput) {
            searchInput.addEventListener('input', () => {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(recargarLista, 350);
            });
        }

        // ==========================================
        // FILTROS (pills)
        // ==========================================
        $$('.filter-pill').forEach(pill => {
            pill.addEventListener('click', () => {
                const filter = pill.dataset.filter;
                const params = new URLSearchParams(window.location.search);
                params.set('estado', filter);
                params.delete('id'); // limpiar selección al cambiar filtro
                window.location.search = params.toString();
            });
        });

        // ==========================================
        // POLLING INTELIGENTE (preserva scroll)
        // ==========================================
        const chatContainer = $('#chat-container');

        const cargarMetadata = () => {
            if (currentChatId <= 0) return;
            fetch(`${requestUri}?ajax_metadata=1&id=${currentChatId}`)
                .then(r => r.json())
                .then(data => {
                    const bar = $('#metadata-bar');
                    if (!bar) return;
                    const total = data.total_pre || 0;
                    $('#meta-total').textContent = total;
                    $('#meta-archivos').textContent = data.archivos || 0;
                    if (data.primero) $('#meta-primero').textContent = data.primero;
                    if (data.ultimo) $('#meta-ultimo').textContent = data.ultimo;
                    bar.style.opacity = '1';

                    // Indicador "en vivo": último mensaje hace <2min
                    if (data.ultimo) {
                        const liveInd = $('#live-indicator');
                        // Parse simple del último (dd/mm HH:MM) — comparamos contra hora actual
                        const ahora = new Date();
                        const partes = data.ultimo.match(/(\d{2})\/(\d{2}) (\d{2}):(\d{2})/);
                        if (partes && liveInd) {
                            const ultimaMsg = new Date(ahora.getFullYear(), parseInt(partes[2]) - 1, parseInt(partes[1]), parseInt(partes[3]), parseInt(partes[4]));
                            if ((ahora - ultimaMsg) < 120000) {
                                liveInd.classList.remove('hidden');
                                liveInd.classList.add('inline-flex');
                            }
                        }
                    }
                })
                .catch(() => {});
        };

        const fetchMensajes = (esPrimeraCarga = false) => {
            if (currentChatId <= 0 || !chatContainer) return;
            if (document.hidden && !esPrimeraCarga) return;

            // Guardar posición de scroll antes de actualizar
            const wasAtBottom = (chatContainer.scrollHeight - chatContainer.scrollTop - chatContainer.clientHeight) < 100;
            const scrollAnterior = chatContainer.scrollTop;

            fetch(`${requestUri}?id=${currentChatId}&ajax_messages=1`)
                .then(r => r.text())
                .then(html => {
                    const nuevoHash = hashString(html);
                    if (nuevoHash === lastMessagesHash && !esPrimeraCarga) return;
                    lastMessagesHash = nuevoHash;

                    chatContainer.innerHTML = html;
                    cargarMetadata();

                    // Restaurar scroll: si estaba abajo, va abajo; si leía arriba, mantiene posición
                    if (wasAtBottom || esPrimeraCarga) {
                        const sb = document.getElementById('scroll-bottom');
                        if (sb) sb.scrollIntoView({ behavior: esPrimeraCarga ? 'auto' : 'smooth' });
                    } else {
                        chatContainer.scrollTop = scrollAnterior;
                    }
                })
                .catch(() => {});
        };

        if (currentChatId > 0 && chatContainer) {
            fetchMensajes(true);
            pollingInterval = setInterval(() => fetchMensajes(false), 5000);

            // Pausar polling al cerrar pestaña/navegar
            window.addEventListener('beforeunload', () => {
                if (pollingInterval) clearInterval(pollingInterval);
            });

            // Pausar polling cuando la pestaña no es visible (ahorro de recursos)
            document.addEventListener('visibilitychange', () => {
                if (document.hidden && pollingInterval) {
                    clearInterval(pollingInterval);
                    pollingInterval = null;
                } else if (!document.hidden && !pollingInterval && currentChatId > 0) {
                    fetchMensajes(false);
                    pollingInterval = setInterval(() => fetchMensajes(false), 5000);
                }
            });
        }

        // ==========================================
        // ACCIONES (eliminar / restaurar) — AJAX real
        // ==========================================
        $$('.btn-accion').forEach(btn => {
            btn.addEventListener('click', () => {
                const accion = btn.dataset.accion;
                const chatId = btn.dataset.chatId;
                const mensaje = accion === 'eliminar_chat'
                    ? '¿Archivar este chat por seguridad?'
                    : '¿Restaurar este chat al estado activo?';

                if (!confirm(mensaje)) return;

                btn.disabled = true;
                btn.style.opacity = '0.5';

                const formData = new FormData();
                formData.append('ajax_accion', accion);
                formData.append('chat_id', chatId);

                fetch(requestUri, { method: 'POST', body: formData })
                    .then(r => r.json())
                    .then(data => {
                        if (data.ok) {
                            showToast(data.msg, 'ok');
                            setTimeout(() => window.location.reload(), 800);
                        } else {
                            showToast(data.msg || 'Error', 'error');
                            btn.disabled = false;
                            btn.style.opacity = '1';
                        }
                    })
                    .catch(() => {
                        showToast('Error de conexión', 'error');
                        btn.disabled = false;
                        btn.style.opacity = '1';
                    });
            });
        });

        // ==========================================
        // DLP: marcar revisados (event delegation sobre chatContainer)
        // ==========================================
        if (chatContainer) {
            chatContainer.addEventListener('click', (e) => {
                const btn = e.target.closest('.btn-marcar-dlp');
                if (!btn) return;
                const chatId = btn.dataset.chatId;
                btn.disabled = true;
                btn.textContent = 'Marcando...';
                const fd = new FormData();
                fd.append('ajax_accion', 'marcar_revisado_dlp');
                fd.append('chat_id', chatId);
                fetch(requestUri, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            showToast('Intentos marcados como revisados', 'ok');
                            fetchMensajes(true);
                        } else {
                            showToast('Error al marcar', 'error');
                            btn.disabled = false;
                            btn.textContent = 'Marcar todos como revisados';
                        }
                    })
                    .catch(() => {
                        showToast('Error de conexión', 'error');
                        btn.disabled = false;
                        btn.textContent = 'Marcar todos como revisados';
                    });
            });
        }

        // ==========================================
        // MODERACIÓN: aprobar / rechazar archivos
        // ==========================================
        const modPanel = document.getElementById('mod-panel');
        if (modPanel) {
            modPanel.addEventListener('click', (e) => {
                const btnAprobar  = e.target.closest('.btn-aprobar');
                const btnRechazar = e.target.closest('.btn-rechazar');
                const btn = btnAprobar || btnRechazar;
                if (!btn) return;

                const msgId = btn.dataset.msgId;
                const esRechazo = !!btnRechazar;

                if (esRechazo && !confirm('¿Rechazar y eliminar este archivo permanentemente?')) return;

                btn.disabled = true;
                btn.style.opacity = '0.5';

                const fd = new FormData();
                fd.append('ajax_accion', esRechazo ? 'rechazar_archivo' : 'aprobar_archivo');
                fd.append('msg_id', msgId);

                fetch(requestUri, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(data => {
                        if (data.ok) {
                            const card = btn.closest('.mod-card');
                            if (card) {
                                card.style.opacity = '0';
                                card.style.transform = 'scale(0.95)';
                                card.style.transition = 'all 0.25s';
                                setTimeout(() => card.remove(), 250);
                            }
                            showToast(esRechazo ? 'Archivo rechazado y eliminado' : 'Archivo aprobado', 'ok');
                        } else {
                            showToast(data.msg || 'Error al procesar', 'error');
                            btn.disabled = false;
                            btn.style.opacity = '1';
                        }
                    })
                    .catch(() => {
                        showToast('Error de conexión', 'error');
                        btn.disabled = false;
                        btn.style.opacity = '1';
                    });
            });
        }

        // ==========================================
        // RESIZER + persistencia localStorage
        // ==========================================
        const sb_panel = $('#sidebar-panel');
        const h_drag = $('#drag-handle');
        const m_cont = $('#main-container');
        let resizing = false;

        const STORAGE_KEY = 'nubira_admin_chats_sidebar_w';

        // Restaurar ancho guardado
        if (sb_panel && window.matchMedia('(min-width: 768px)').matches) {
            const savedW = localStorage.getItem(STORAGE_KEY);
            if (savedW && !isNaN(savedW)) {
                const w = parseInt(savedW, 10);
                if (w >= 320 && w <= 800) {
                    sb_panel.style.width = w + 'px';
                }
            }
        }

        if (h_drag && sb_panel && m_cont) {
            h_drag.addEventListener('mousedown', (e) => {
                e.preventDefault();
                resizing = true;
                document.body.style.cursor = 'col-resize';
                h_drag.classList.add('active');
            });

            document.addEventListener('mousemove', (e) => {
                if (!resizing) return;
                const w = e.clientX - m_cont.getBoundingClientRect().left;
                if (w >= 320 && w <= (m_cont.offsetWidth * 0.6)) {
                    sb_panel.style.width = w + 'px';
                }
            });

            document.addEventListener('mouseup', () => {
                if (resizing) {
                    resizing = false;
                    document.body.style.cursor = '';
                    h_drag.classList.remove('active');
                    // Guardar ancho final
                    if (sb_panel.style.width) {
                        localStorage.setItem(STORAGE_KEY, parseInt(sb_panel.style.width, 10));
                    }
                }
            });
        }

        // ==========================================
        // ATAJOS DE TECLADO
        // ==========================================
        document.addEventListener('keydown', (e) => {
            // No interceptar si está tipeando en un input
            const tag = (e.target.tagName || '').toLowerCase();
            const enInput = tag === 'input' || tag === 'textarea' || tag === 'select';

            // "/" → focus en búsqueda
            if (e.key === '/' && !enInput) {
                e.preventDefault();
                if (searchInput) {
                    searchInput.focus();
                    searchInput.select();
                }
                return;
            }

            // Esc → volver a lista (móvil) o blur de input
            if (e.key === 'Escape') {
                if (enInput) {
                    e.target.blur();
                    return;
                }
                if (currentChatId > 0) {
                    window.location.href = `?orden=${currentOrden}&estado=${currentEstado}`;
                }
                return;
            }

            // ↑ ↓ navegar entre chats
            if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && !enInput) {
                const items = $$('.chat-item');
                if (items.length === 0) return;

                e.preventDefault();

                let currentIdx = items.findIndex(i => parseInt(i.dataset.chatId, 10) === currentChatId);
                if (currentIdx === -1) currentIdx = 0;

                let nextIdx;
                if (e.key === 'ArrowDown') {
                    nextIdx = (currentIdx + 1) % items.length;
                } else {
                    nextIdx = (currentIdx - 1 + items.length) % items.length;
                }

                const nextItem = items[nextIdx];
                if (nextItem) {
                    const id = nextItem.dataset.chatId;
                    const orden = nextItem.dataset.orden;
                    const estado = nextItem.dataset.estado;
                    window.location.href = `?id=${id}&orden=${orden}&estado=${estado}`;
                }
            }
        });

    })();
    </script>
<?php if (file_exists(__DIR__ . '/componentes/nav_bottom.php')) require_once __DIR__ . '/componentes/nav_bottom.php'; ?>
</body>
</html>