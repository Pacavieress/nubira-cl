<?php
/**
 * NUBIRA 2.0 - ADMIN: MARKETING / CARDS
 * Fusión Fase 2: tabs "Servicios" (grilla + carrusel descargable) y "Novedades"
 * (redactar anuncios de plataforma + preview + historial), vía ?tab=servicios|novedades.
 * Recarga real de página al cambiar de tab (sin JS de show/hide) — cada tab ejecuta
 * y renderiza solo su propio bloque PHP/HTML/JS.
 */

// 1. DETECCIÓN INTELIGENTE DE RUTA
if (file_exists(__DIR__ . '/init_sesion.php')) {
    require_once __DIR__ . '/init_sesion.php';
    $app_dir = __DIR__;
} else {
    require_once __DIR__ . '/app/init_sesion.php';
    $app_dir = __DIR__ . '/app';
}

require_once $app_dir . '/iconos.php';

// 2. CANDADO ESTRICTO DE SESIÓN
if (function_exists('proteger_ruta')) {
    proteger_ruta();
} else {
    die("Error de seguridad: No se pudo cargar el control de sesión.");
}

// 2.5 CANDADO DE ROL ADMIN
if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] ?? '') !== 'admin') {
    header("Location: /login"); exit;
}

// 3. CONEXIÓN Y HELPERS
if (!isset($conn)) require_once $app_dir . '/conexion.php';
require_once $app_dir . '/seguridad_url.php'; // nubira_encriptar_id()
require_once $app_dir . '/helpers/imagen_compartir.php'; // nb_version_imagen_servicio()

// 4. TAB ACTIVO
$tab = in_array($_GET['tab'] ?? '', ['novedades', 'copiloto']) ? $_GET['tab'] : 'servicios';

if ($tab === 'servicios') {
    // FILTROS (GET, sin AJAX — panel de bajo tráfico, mismo criterio que admin_cuentas.php)
    $filtro_categoria   = trim($_GET['categoria'] ?? '');
    $filtro_institucion = trim($_GET['institucion'] ?? '');
    $filtro_con_video   = ($_GET['con_video'] ?? '') === '1';
    $filtro_fecha_desde = trim($_GET['fecha_desde'] ?? '');
    $filtro_fecha_hasta = trim($_GET['fecha_hasta'] ?? '');

    // Preselección al llegar desde "Armar carrusel" (tab Copiloto, sección Tutores por
    // promocionar) — no es un filtro de la grilla, solo indica qué checkbox marcar.
    $preseleccionar_id = (int)($_GET['servicio_id'] ?? 0);

    $condicion    = ["s.estado = 'aprobado'", "COALESCE(s.visible,1) = 1"];
    $param_types  = '';
    $param_values = [];

    if ($filtro_categoria !== '') {
        $condicion[]    = 's.categoria = ?';
        $param_types   .= 's';
        $param_values[] = $filtro_categoria;
    }
    if ($filtro_institucion !== '') {
        $condicion[]    = 's.institucion = ?';
        $param_types   .= 's';
        $param_values[] = $filtro_institucion;
    }
    if ($filtro_con_video) {
        $condicion[] = "s.video_estado = 'aprobado'";
    }
    if ($filtro_fecha_desde !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filtro_fecha_desde)) {
        $condicion[]    = 's.fecha_publicacion >= ?';
        $param_types   .= 's';
        $param_values[] = $filtro_fecha_desde . ' 00:00:00';
    }
    if ($filtro_fecha_hasta !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filtro_fecha_hasta)) {
        $condicion[]    = 's.fecha_publicacion <= ?';
        $param_types   .= 's';
        $param_values[] = $filtro_fecha_hasta . ' 23:59:59';
    }

    $where = 'WHERE ' . implode(' AND ', $condicion);

    $sql = "SELECT s.id, s.titulo, s.categoria, s.institucion, s.fecha_publicacion, s.video_estado,
                   a.nombre AS tutor_nombre
            FROM servicios s
            JOIN alumnos a ON s.alumno_id = a.id
            $where
            ORDER BY s.fecha_publicacion DESC";

    $stmt = $conn->prepare($sql);
    if ($param_types !== '') {
        $stmt->bind_param($param_types, ...$param_values);
    }
    $stmt->execute();
    $resultado = $stmt->get_result();
    $servicios = $resultado ? $resultado->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    $total_servicios = count($servicios);

    // Preparar hash + URL de imagen por servicio (mismo endpoint que ya usa el sheet de compartir)
    foreach ($servicios as &$s) {
        $hash = function_exists('nubira_encriptar_id') ? nubira_encriptar_id((int)$s['id']) : (string)$s['id'];
        $v = nb_version_imagen_servicio((int)$s['id']);
        $s['img_url'] = "/api/img/servicio/{$hash}/post.jpg?v={$v}";
    }
    unset($s);

    // Opciones de filtro (independientes del filtro activo, para no vaciar el dropdown)
    $categorias_disponibles   = [];
    $resCat = $conn->query("SELECT DISTINCT categoria FROM servicios WHERE estado = 'aprobado' AND categoria IS NOT NULL AND categoria != '' ORDER BY categoria ASC");
    if ($resCat) { while ($r = $resCat->fetch_assoc()) $categorias_disponibles[] = $r['categoria']; }

    $instituciones_disponibles = [];
    $resInst = $conn->query("SELECT DISTINCT institucion FROM servicios WHERE estado = 'aprobado' AND institucion IS NOT NULL AND institucion != '' ORDER BY institucion ASC");
    if ($resInst) { while ($r = $resInst->fetch_assoc()) $instituciones_disponibles[] = $r['institucion']; }
} elseif ($tab === 'novedades') {
    // Auto-migración: mismo criterio que admin_guardar_novedad.php / img_novedad.php —
    // nunca asumir que otro archivo ya se ejecutó antes y creó la tabla.
    $conn->query("CREATE TABLE IF NOT EXISTS novedades (
        id INT AUTO_INCREMENT PRIMARY KEY,
        titulo VARCHAR(120) NOT NULL,
        cuerpo TEXT NOT NULL,
        icono VARCHAR(10) NULL,
        creado_en DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Historial (últimas 50, sin editar/eliminar en esta fase)
    $novedades = [];
    $res = $conn->query("SELECT id, titulo, cuerpo, creado_en FROM novedades ORDER BY creado_en DESC LIMIT 50");
    if ($res) {
        while ($n = $res->fetch_assoc()) {
            $hash = nubira_encriptar_id((int)$n['id']);
            $n['post_url']    = "/api/img/novedad/{$hash}/post.jpg";
            $n['history_url'] = "/api/img/novedad/{$hash}/history.jpg";
            $novedades[] = $n;
        }
    }
} else {
    // $tab === 'copiloto' — Fase 1 Pieza 3: SOLO lee lo que ya dejó el cron
    // (app/cron/copiloto_recolector.php). No recalcula ninguna señal ni
    // llama a Gemini desde acá — eso es responsabilidad exclusiva del cron.
    $copiloto_historial = [];
    try {
        $res = $conn->query("SELECT * FROM copiloto_snapshots ORDER BY fecha DESC LIMIT 14");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $copiloto_historial[] = $row;
            }
        }
    } catch (Throwable $e) {
        // Tabla copiloto_snapshots todavía no existe (el cron nunca corrió) —
        // se resuelve como "sin datos", mismo criterio que el resto del panel.
        $copiloto_historial = [];
    }

    $copiloto_snapshot  = $copiloto_historial[0] ?? null; // más reciente (hoy, si el cron ya corrió hoy)
    $copiloto_anterior  = $copiloto_historial[1] ?? null; // snapshot inmediatamente anterior, para los deltas
    $copiloto_deltas    = [];
    $copiloto_oferta    = [];
    $copiloto_demanda   = ['servicio' => [], 'apunte' => []];
    $copiloto_busquedas = [];

    if ($copiloto_snapshot) {
        if ($copiloto_anterior) {
            $copiloto_deltas = [
                'dormidos_total'      => (int)$copiloto_snapshot['dormidos_total']       - (int)$copiloto_anterior['dormidos_total'],
                'leads_sin_contactar' => (int)$copiloto_snapshot['leads_sin_contactar']  - (int)$copiloto_anterior['leads_sin_contactar'],
                'contratos_7d'        => (int)$copiloto_snapshot['contratos_7d']         - (int)$copiloto_anterior['contratos_7d'],
                'contratos_30d'       => (int)$copiloto_snapshot['contratos_30d']        - (int)$copiloto_anterior['contratos_30d'],
                'monto_contratos_30d' => (float)$copiloto_snapshot['monto_contratos_30d'] - (float)$copiloto_anterior['monto_contratos_30d'],
            ];
        }

        $copiloto_oferta    = json_decode($copiloto_snapshot['oferta_por_categoria'] ?? '{}', true) ?: [];
        $copiloto_demanda   = json_decode($copiloto_snapshot['demanda_vistas_por_categoria'] ?? '{}', true) ?: ['servicio' => [], 'apunte' => []];
        $copiloto_busquedas = json_decode($copiloto_snapshot['busquedas_fallidas_top'] ?? '{}', true) ?: [];

        // Orden desc por valor para las listas — defensivo (el cron ya las
        // guarda ordenadas, esto solo protege contra un futuro cambio ahí).
        arsort($copiloto_oferta);
        if (!empty($copiloto_demanda['servicio'])) arsort($copiloto_demanda['servicio']);
        if (!empty($copiloto_demanda['apunte']))   arsort($copiloto_demanda['apunte']);
        arsort($copiloto_busquedas);
    }

    // Convierte el Markdown básico que devuelve Gemini (negrita ** y lista
    // numerada "N. ") a HTML seguro. CRÍTICO: primero se escapa TODO el texto
    // crudo con htmlspecialchars — recién sobre ese texto YA escapado se
    // aplican las transformaciones de Markdown. El brief viene de Gemini,
    // nunca se confía en él como HTML.
    function nb_brief_markdown_a_html(string $texto_crudo): string {
        $escapado = htmlspecialchars($texto_crudo, ENT_QUOTES, 'UTF-8');

        // **negrita** -> <strong>, sobre el texto ya escapado (los ** no son
        // afectados por htmlspecialchars, así que el regex sigue calzando).
        $escapado = preg_replace('/\*\*(.+?)\*\*/s', '<strong class="text-gray-900 font-semibold">$1</strong>', $escapado);

        $lineas = preg_split('/\r\n|\r|\n/', $escapado);

        $html         = '';
        $en_lista     = false;
        $parrafo_actual = [];

        $cerrar_parrafo = function () use (&$html, &$parrafo_actual) {
            if (!empty($parrafo_actual)) {
                $html .= '<p class="text-sm text-gray-700 leading-relaxed mb-4 last:mb-0">' . implode(' ', $parrafo_actual) . '</p>';
                $parrafo_actual = [];
            }
        };

        foreach ($lineas as $linea) {
            $linea = trim($linea);

            if ($linea === '') {
                // Una línea en blanco separa párrafos, pero NO corta una lista
                // en curso (Gemini a veces deja espacio entre ítems numerados).
                $cerrar_parrafo();
                continue;
            }

            if (preg_match('/^\d+\.\s+(.*)$/', $linea, $m)) {
                $cerrar_parrafo();
                if (!$en_lista) {
                    $html .= '<ol class="list-decimal list-outside pl-5 space-y-2 mb-4 text-sm text-gray-700">';
                    $en_lista = true;
                }
                $html .= '<li class="pl-1 leading-relaxed">' . $m[1] . '</li>';
            } else {
                if ($en_lista) {
                    $html .= '</ol>';
                    $en_lista = false;
                }
                $parrafo_actual[] = $linea;
            }
        }

        $cerrar_parrafo();
        if ($en_lista) $html .= '</ol>';

        return $html;
    }

    // Helper de presentación del delta (↑/↓ + color) — vive acá, no en la
    // vista, para que el bloque HTML de abajo se quede solo con marcado.
    function copiloto_delta_html($valor): string {
        if ($valor === null) return '<span class="text-[10px] text-gray-300">sin dato previo</span>';
        if ($valor == 0) return '<span class="text-[10px] text-gray-400">sin cambio</span>';
        $subio  = $valor > 0;
        $color  = $subio ? 'text-emerald-600' : 'text-red-500';
        $flecha = $subio ? '↑' : '↓';
        $texto  = number_format(abs($valor), 0, ',', '.');
        return '<span class="text-[10px] font-bold ' . $color . '">' . $flecha . ' ' . htmlspecialchars($texto, ENT_QUOTES, 'UTF-8') . ' vs. anterior</span>';
    }

    // Instagram (Fase 2, Pieza 2C) — SOLO LECTURA del snapshot más reciente
    // de copiloto_instagram_snapshots. try/catch por si la tabla no existe
    // todavía (copiloto_instagram.php nunca corrió) — se resuelve como "sin
    // datos", mismo criterio que copiloto_snapshots arriba.
    $ig_historial = [];
    try {
        $res_ig = $conn->query("SELECT * FROM copiloto_instagram_snapshots ORDER BY fecha DESC LIMIT 14");
        if ($res_ig) {
            while ($row_ig = $res_ig->fetch_assoc()) {
                $ig_historial[] = $row_ig;
            }
        }
    } catch (Throwable $e) {
        $ig_historial = [];
    }

    $ig_snapshot  = $ig_historial[0] ?? null;
    $ig_anterior  = $ig_historial[1] ?? null;
    $ig_deltas    = [];
    $ig_datos_perfil = [];
    $ig_top_posts = [];

    if ($ig_snapshot) {
        if ($ig_anterior) {
            $ig_deltas = [
                'followers_count' => (int)$ig_snapshot['followers_count'] - (int)$ig_anterior['followers_count'],
                'media_count'     => (int)$ig_snapshot['media_count']     - (int)$ig_anterior['media_count'],
                'reach_dia'       => ($ig_snapshot['reach_dia'] !== null && $ig_anterior['reach_dia'] !== null)
                    ? (int)$ig_snapshot['reach_dia'] - (int)$ig_anterior['reach_dia']
                    : null,
            ];
        }

        $ig_datos_perfil = json_decode($ig_snapshot['datos_perfil'] ?? '{}', true) ?: [];

        $ig_top_posts = json_decode($ig_snapshot['top_posts'] ?? '[]', true) ?: [];
        // Top 5 por reach — posts sin reach (insight falló para ese post puntual) quedan al final.
        usort($ig_top_posts, fn($a, $b) => (int)($b['reach'] ?? -1) <=> (int)($a['reach'] ?? -1));
        $ig_top_posts = array_slice($ig_top_posts, 0, 5);
    }

    // Calendario semanal de Instagram (Fase 3, Pieza 2) — SOLO LECTURA del
    // más reciente en copiloto_calendario. Lo genera app/admin_generar_calendario.php
    // bajo demanda (botón), no un cron — por eso ORDER BY generado_en, no fecha.
    $calendario_reciente = null;
    $calendario_semana   = [];
    try {
        $res_cal = $conn->query("SELECT * FROM copiloto_calendario ORDER BY generado_en DESC LIMIT 1");
        if ($res_cal) {
            $calendario_reciente = $res_cal->fetch_assoc() ?: null;
        }
    } catch (Throwable $e) {
        $calendario_reciente = null; // tabla todavía no existe
    }

    if ($calendario_reciente) {
        $calendario_data   = json_decode($calendario_reciente['contenido'] ?? '{}', true) ?: [];
        $calendario_semana = $calendario_data['semana'] ?? [];
    }

    // Tutores por promocionar (rotación) — servicios activos ordenados por
    // "hace más tiempo sin promocionarse" (nunca promocionados primero).
    // try/catch por si copiloto_promociones todavía no existe (nadie ha
    // usado "Marcar como publicados" en tab=servicios todavía).
    $tutores_por_promocionar = [];
    try {
        $res_rot = $conn->query("
            SELECT s.id, s.titulo, s.categoria, a.nombre AS tutor_nombre, p.ultima
            FROM servicios s
            JOIN alumnos a ON a.id = s.alumno_id
            LEFT JOIN (
                SELECT servicio_id, MAX(fecha_promocionado) AS ultima
                FROM copiloto_promociones
                GROUP BY servicio_id
            ) p ON p.servicio_id = s.id
            WHERE s.estado = 'aprobado' AND COALESCE(s.visible,1) = 1
            ORDER BY p.ultima IS NOT NULL, p.ultima ASC
            LIMIT 10
        ");
        if ($res_rot) {
            while ($row_rot = $res_rot->fetch_assoc()) {
                $tutores_por_promocionar[] = $row_rot;
            }
        }
    } catch (Throwable $e) {
        $tutores_por_promocionar = []; // tabla copiloto_promociones todavía no existe
    }

    // Alerta del token de Instagram (auto-refresh) — mismo umbral que
    // contar_alertas_sistema.php: error registrado O menos de 10 días reales
    // de margen antes de expirar. try/catch por si la tabla todavía no existe
    // (cron/copiloto_ig_refresh.php nunca corrió).
    $ig_token_alerta_msg = null;
    try {
        $res_igt = $conn->query("SELECT ultimo_error, intentos_fallidos, expira_estimado_en FROM copiloto_ig_token WHERE id = 1 LIMIT 1");
        $row_igt = $res_igt ? $res_igt->fetch_assoc() : null;
        if ($row_igt) {
            $dias_restantes_igt = (strtotime($row_igt['expira_estimado_en']) - time()) / 86400;
            $tiene_error_igt    = !empty($row_igt['ultimo_error']);
            $por_vencer_igt     = $dias_restantes_igt <= 10;

            if ($tiene_error_igt || $por_vencer_igt) {
                $partes = [];
                if ($tiene_error_igt) {
                    $intentos = (int)$row_igt['intentos_fallidos'];
                    $partes[] = "último error: \"{$row_igt['ultimo_error']}\" ({$intentos} intento" . ($intentos === 1 ? '' : 's') . " fallido" . ($intentos === 1 ? '' : 's') . " seguido" . ($intentos === 1 ? '' : 's') . ")";
                }
                if ($por_vencer_igt) {
                    $dias_txt = max(0, round($dias_restantes_igt));
                    $partes[] = "vence en aprox. {$dias_txt} día" . ($dias_txt == 1 ? '' : 's');
                }
                $ig_token_alerta_msg = 'Token de Instagram con problemas — ' . implode('; ', $partes) . '. Revisa cron/copiloto_ig_refresh.php.';
            }
        }
    } catch (Throwable $e) {
        $ig_token_alerta_msg = null; // tabla todavía no existe — el cron de refresh nunca corrió
    }

    // Contador de gasto de fondos IA del mes actual — SOLO cuenta llamadas que
    // realmente le costaron plata a Google: cache_hit=0 (fue a Gemini, no sirvió de
    // caché) Y exito=1 (Google no cobra las llamadas fallidas). Precio como constante
    // para poder ajustarlo el día que cambie el pricing del modelo.
    if (!defined('NB_PRECIO_FONDO_IA_USD')) define('NB_PRECIO_FONDO_IA_USD', 0.0336);
    $fondos_generados_mes = 0;
    try {
        $res_gasto = $conn->query("
            SELECT COUNT(*) AS n FROM copiloto_fondos_generados
            WHERE cache_hit = 0 AND exito = 1
              AND fecha >= DATE_FORMAT(NOW(), '%Y-%m-01')
        ");
        if ($res_gasto) {
            $fondos_generados_mes = (int)($res_gasto->fetch_assoc()['n'] ?? 0);
        }
    } catch (Throwable $e) {
        $fondos_generados_mes = 0; // tabla todavía no existe — nunca se generó un fondo
    }
    $gasto_estimado_mes_usd = $fondos_generados_mes * NB_PRECIO_FONDO_IA_USD;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Admin - Marketing / Cards | Nubira</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0, viewport-fit=cover" />
  <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
    body { font-family: 'Inter', sans-serif; background-color: #F9FAFB; }
  </style>
</head>

<body class="bg-gray-50 text-gray-900 antialiased overflow-x-hidden selection:bg-blue-100 selection:text-blue-700">

<?php
require_once $app_dir . '/componentes/header.php';
require_once $app_dir . '/componentes/sidebar.php';
?>

<main class="pt-20 pb-40 md:pb-24 lg:ml-64 px-4 max-w-[1600px] mx-auto md:px-8">

    <div class="mb-6">
        <span class="inline-block py-1 px-3 rounded-full bg-blue-50 text-[#54A6D8] text-[10px] md:text-xs font-bold mb-2 border border-blue-100">
            🛡️ Panel de Administración
        </span>
        <h1 class="text-3xl font-bold text-gray-900 tracking-tight">Marketing / Cards</h1>
    </div>

    <!-- Tabs -->
    <div class="flex items-center gap-2 mb-6 border-b border-gray-200">
        <a href="/admin/marketing-cards?tab=servicios"
           class="px-4 py-2.5 text-sm font-bold border-b-2 -mb-px transition-colors <?= $tab === 'servicios' ? 'border-[#54A6D8] text-[#54A6D8]' : 'border-transparent text-gray-400 hover:text-gray-600' ?>">
            Servicios
        </a>
        <a href="/admin/marketing-cards?tab=novedades"
           class="px-4 py-2.5 text-sm font-bold border-b-2 -mb-px transition-colors <?= $tab === 'novedades' ? 'border-[#54A6D8] text-[#54A6D8]' : 'border-transparent text-gray-400 hover:text-gray-600' ?>">
            Novedades
        </a>
        <a href="/admin/marketing-cards?tab=copiloto"
           class="px-4 py-2.5 text-sm font-bold border-b-2 -mb-px transition-colors <?= $tab === 'copiloto' ? 'border-[#54A6D8] text-[#54A6D8]' : 'border-transparent text-gray-400 hover:text-gray-600' ?>">
            Copiloto
        </a>
    </div>

    <?php if ($tab === 'servicios'): ?>

        <p class="text-gray-500 text-sm mt-1 mb-4">
            Selecciona servicios y arma un carrusel de imágenes para redes sociales. Total con estos filtros: <strong><?= $total_servicios ?></strong>
        </p>

        <!-- Barra de filtros -->
        <form method="GET" class="bg-white border border-gray-100 rounded-2xl shadow-sm p-4 mb-6">
            <input type="hidden" name="tab" value="servicios">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                <div>
                    <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-wide mb-1">Categoría</label>
                    <select name="categoria" class="w-full px-3 py-2.5 rounded-xl border border-gray-200 text-sm bg-white focus:ring-2 focus:ring-[#54A6D8] outline-none">
                        <option value="">Todas</option>
                        <?php foreach ($categorias_disponibles as $c): ?>
                            <option value="<?= htmlspecialchars($c) ?>" <?= $filtro_categoria === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-wide mb-1">Institución</label>
                    <select name="institucion" class="w-full px-3 py-2.5 rounded-xl border border-gray-200 text-sm bg-white focus:ring-2 focus:ring-[#54A6D8] outline-none">
                        <option value="">Todas</option>
                        <?php foreach ($instituciones_disponibles as $i): ?>
                            <option value="<?= htmlspecialchars($i) ?>" <?= $filtro_institucion === $i ? 'selected' : '' ?>><?= htmlspecialchars($i) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-wide mb-1">Desde</label>
                    <input type="date" name="fecha_desde" value="<?= htmlspecialchars($filtro_fecha_desde) ?>"
                           class="w-full px-3 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-[#54A6D8] outline-none">
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-wide mb-1">Hasta</label>
                    <input type="date" name="fecha_hasta" value="<?= htmlspecialchars($filtro_fecha_hasta) ?>"
                           class="w-full px-3 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-[#54A6D8] outline-none">
                </div>
                <div class="flex items-end gap-2">
                    <label class="inline-flex items-center gap-2 px-3 py-2.5 rounded-xl border border-gray-200 text-sm bg-white cursor-pointer select-none w-full">
                        <input type="checkbox" name="con_video" value="1" <?= $filtro_con_video ? 'checked' : '' ?> class="w-4 h-4 rounded accent-[#54A6D8]">
                        Solo con video
                    </label>
                </div>
            </div>
            <div class="flex items-center gap-3 mt-3">
                <button type="submit" class="px-4 py-2.5 rounded-xl bg-[#54A6D8] hover:bg-blue-600 text-white text-sm font-bold transition-colors">
                    Filtrar
                </button>
                <a href="/admin/marketing-cards?tab=servicios" class="px-4 py-2.5 rounded-xl border border-gray-200 text-gray-500 text-sm font-bold hover:bg-gray-50 transition-colors">
                    Limpiar filtros
                </a>
            </div>
        </form>

        <!-- Control de selección -->
        <?php if ($total_servicios > 0): ?>
        <div class="flex items-center gap-3 mb-4">
            <label class="inline-flex items-center gap-2 text-sm text-gray-600 cursor-pointer select-none">
                <input type="checkbox" id="check-all" class="w-4 h-4 rounded accent-[#54A6D8] cursor-pointer">
                Seleccionar todos los visibles
            </label>
        </div>
        <?php endif; ?>

        <!-- Grilla de cards -->
        <?php if ($total_servicios === 0): ?>
            <div class="bg-white border border-dashed border-gray-200 rounded-2xl p-12 text-center text-gray-400">
                No hay servicios que coincidan con estos filtros.
            </div>
        <?php else: ?>
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-4">
                <?php foreach ($servicios as $s): ?>
                    <div class="mkt-card relative bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden group"
                         data-id="<?= (int)$s['id'] ?>"
                         data-img-url="<?= htmlspecialchars($s['img_url'], ENT_QUOTES, 'UTF-8') ?>"
                         data-titulo="<?= htmlspecialchars($s['titulo'], ENT_QUOTES, 'UTF-8') ?>">

                        <label class="absolute top-2 left-2 z-10 w-6 h-6 rounded-md bg-white/90 backdrop-blur-sm border border-gray-200 flex items-center justify-center cursor-pointer shadow-sm">
                            <input type="checkbox" class="mkt-check w-4 h-4 rounded accent-[#54A6D8] cursor-pointer" value="<?= (int)$s['id'] ?>">
                        </label>

                        <?php if ($s['video_estado'] === 'aprobado'): ?>
                            <span class="absolute top-2 right-2 z-10 bg-black/60 text-white text-[9px] font-bold uppercase tracking-wide px-2 py-1 rounded-full flex items-center gap-1">
                                <i class="fa-solid fa-video"></i> Video
                            </span>
                        <?php endif; ?>

                        <div class="w-full aspect-square bg-gray-100">
                            <img src="<?= htmlspecialchars($s['img_url'], ENT_QUOTES, 'UTF-8') ?>"
                                 loading="lazy" decoding="async" alt="<?= htmlspecialchars($s['titulo'], ENT_QUOTES, 'UTF-8') ?>"
                                 class="w-full h-full object-cover">
                        </div>

                        <div class="p-3">
                            <p class="text-xs font-bold text-gray-900 line-clamp-2 leading-snug mb-1"><?= htmlspecialchars($s['titulo'], ENT_QUOTES, 'UTF-8') ?></p>
                            <p class="text-[10px] text-gray-400 truncate"><?= htmlspecialchars($s['tutor_nombre'], ENT_QUOTES, 'UTF-8') ?></p>
                            <div class="flex items-center justify-between mt-2">
                                <span class="text-[9px] font-bold uppercase tracking-wide text-[#54A6D8] bg-blue-50 border border-blue-100 px-2 py-0.5 rounded-full truncate max-w-[70%]"><?= htmlspecialchars($s['categoria'], ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="text-[9px] text-gray-400 shrink-0"><?= date('d/m/Y', strtotime($s['fecha_publicacion'])) ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    <?php elseif ($tab === 'novedades'): ?>

        <div class="max-w-[1100px] mx-auto">
            <p class="text-gray-500 text-sm mt-1 mb-4">Redacta anuncios de plataforma y genera sus imágenes para redes sociales.</p>

            <!-- Formulario nueva novedad -->
            <section class="bg-white border border-gray-100 rounded-2xl shadow-sm p-6 mb-8">
                <h2 class="text-base font-bold text-gray-900 mb-4">Nueva novedad</h2>

                <div class="space-y-4">
                    <div>
                        <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-wide mb-1">Título</label>
                        <input id="f-titulo" type="text" maxlength="120" placeholder="Ej: Nuevo: Métricas para tus publicaciones"
                               class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-[#54A6D8] outline-none"
                               oninput="document.getElementById('f-counter-titulo').textContent = this.value.length + ' / 120'">
                        <p id="f-counter-titulo" class="text-[11px] text-gray-400 text-right mt-1">0 / 120</p>
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-wide mb-1">Cuerpo</label>
                        <textarea id="f-cuerpo" maxlength="280" rows="4" placeholder="Describe la novedad en un par de frases..."
                                  class="w-full px-4 py-3 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-[#54A6D8] outline-none resize-none"
                                  oninput="document.getElementById('f-counter-cuerpo').textContent = this.value.length + ' / 280'"></textarea>
                        <p id="f-counter-cuerpo" class="text-[11px] text-gray-400 text-right mt-1">0 / 280</p>
                    </div>

                    <p id="f-error" class="hidden text-sm text-red-600 bg-red-50 border border-red-100 rounded-xl px-4 py-2.5"></p>

                    <button id="btn-guardar" type="button"
                            class="px-5 py-2.5 rounded-xl bg-[#54A6D8] hover:bg-blue-600 text-white text-sm font-bold transition-colors flex items-center gap-2">
                        <i class="fa-solid fa-floppy-disk"></i> Guardar y generar imágenes
                    </button>
                </div>
            </section>

            <!-- Preview de la novedad recién creada -->
            <section id="preview-novedad" class="hidden bg-white border border-gray-100 rounded-2xl shadow-sm p-6 mb-8">
                <h2 class="text-base font-bold text-gray-900 mb-4">Imágenes generadas</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                    <div class="text-center">
                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wide mb-2">Post (4:5)</p>
                        <img id="preview-post-img" src="" alt="Preview POST" class="w-full max-w-[320px] mx-auto rounded-xl border border-gray-100 aspect-[4/5] object-cover bg-gray-50">
                        <div class="flex items-center justify-center gap-2 mt-3">
                            <button type="button" class="btn-compartir px-3 py-2 rounded-xl border border-gray-200 text-[#54A6D8] hover:bg-blue-50 text-xs font-bold flex items-center gap-1.5" data-formato="post">
                                <i class="fa-solid fa-share-nodes"></i> Compartir
                            </button>
                            <a id="preview-post-descarga" href="" download class="px-3 py-2 rounded-xl border border-gray-200 text-[#54A6D8] hover:bg-blue-50 text-xs font-bold flex items-center gap-1.5">
                                <i class="fa-solid fa-download"></i> Descargar
                            </a>
                        </div>
                    </div>
                    <div class="text-center">
                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wide mb-2">History (9:16)</p>
                        <img id="preview-history-img" src="" alt="Preview HISTORY" class="w-full max-w-[220px] mx-auto rounded-xl border border-gray-100 aspect-[9/16] object-cover bg-gray-50">
                        <div class="flex items-center justify-center gap-2 mt-3">
                            <button type="button" class="btn-compartir px-3 py-2 rounded-xl border border-gray-200 text-[#54A6D8] hover:bg-blue-50 text-xs font-bold flex items-center gap-1.5" data-formato="history">
                                <i class="fa-solid fa-share-nodes"></i> Compartir
                            </button>
                            <a id="preview-history-descarga" href="" download class="px-3 py-2 rounded-xl border border-gray-200 text-[#54A6D8] hover:bg-blue-50 text-xs font-bold flex items-center gap-1.5">
                                <i class="fa-solid fa-download"></i> Descargar
                            </a>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Historial -->
            <section>
                <h2 class="text-base font-bold text-gray-900 mb-4">Historial</h2>
                <ul id="historial-lista" class="space-y-2">
                    <?php foreach ($novedades as $n): ?>
                        <li class="flex items-center gap-3 bg-white border border-gray-100 rounded-xl p-3"
                            data-post-url="<?= htmlspecialchars($n['post_url'], ENT_QUOTES, 'UTF-8') ?>"
                            data-history-url="<?= htmlspecialchars($n['history_url'], ENT_QUOTES, 'UTF-8') ?>"
                            data-id="<?= (int)$n['id'] ?>">
                            <img src="<?= htmlspecialchars($n['post_url'], ENT_QUOTES, 'UTF-8') ?>" loading="lazy" decoding="async"
                                 alt="" class="w-14 h-14 rounded-lg object-cover border border-gray-200 bg-gray-50 shrink-0">
                            <div class="flex-1 min-w-0">
                                <p class="text-xs font-bold text-gray-800 truncate"><?= htmlspecialchars($n['titulo'], ENT_QUOTES, 'UTF-8') ?></p>
                                <p class="text-[10px] text-gray-400"><?= date('d/m/Y H:i', strtotime($n['creado_en'])) ?></p>
                            </div>
                            <div class="flex items-center gap-1.5 shrink-0">
                                <button type="button" class="btn-compartir-historial w-9 h-9 rounded-full bg-white border border-gray-200 text-[#54A6D8] hover:bg-blue-50 flex items-center justify-center transition-colors" title="Compartir POST" aria-label="Compartir POST" data-formato="post">
                                    <i class="fa-solid fa-share-nodes text-xs"></i>
                                </button>
                                <a href="<?= htmlspecialchars($n['post_url'], ENT_QUOTES, 'UTF-8') ?>" download="nubira-novedad-<?= (int)$n['id'] ?>-post.jpg"
                                   class="w-9 h-9 rounded-full bg-white border border-gray-200 text-[#54A6D8] hover:bg-blue-50 flex items-center justify-center transition-colors" title="Descargar POST" aria-label="Descargar POST">
                                    <i class="fa-solid fa-download text-xs"></i>
                                </a>
                                <a href="<?= htmlspecialchars($n['history_url'], ENT_QUOTES, 'UTF-8') ?>" download="nubira-novedad-<?= (int)$n['id'] ?>-history.jpg"
                                   class="w-9 h-9 rounded-full bg-white border border-gray-200 text-[#54A6D8] hover:bg-blue-50 flex items-center justify-center transition-colors" title="Descargar HISTORY" aria-label="Descargar HISTORY">
                                    <i class="fa-solid fa-file-arrow-down text-xs"></i>
                                </a>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if (empty($novedades)): ?>
                    <div class="bg-white border border-dashed border-gray-200 rounded-2xl p-12 text-center text-gray-400 text-sm">
                        Todavía no hay novedades creadas.
                    </div>
                <?php endif; ?>
            </section>
        </div>

    <?php else: ?>

        <?php if ($ig_token_alerta_msg): ?>
            <div class="max-w-[1600px] mx-auto mb-4">
                <div class="flex items-start gap-2 bg-red-50 border border-red-200 text-red-700 text-xs rounded-xl px-4 py-3">
                    <i class="fa-solid fa-triangle-exclamation mt-0.5"></i>
                    <span><?= htmlspecialchars($ig_token_alerta_msg, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </div>
        <?php endif; ?>

        <div class="max-w-[1600px] mx-auto mb-4">
            <p class="text-[11px] text-gray-400">
                Fondos generados este mes: <span class="font-bold text-gray-600"><?= $fondos_generados_mes ?></span> reales
                (~$<?= number_format($gasto_estimado_mes_usd, 2) ?> USD estimado)
            </p>
        </div>

        <?php if (!$copiloto_snapshot): ?>
            <div class="bg-white border border-dashed border-gray-200 rounded-2xl p-12 text-center text-gray-400">
                Aún no se ha generado el primer brief. El cron diario (<code class="text-xs">app/cron/copiloto_recolector.php</code>) todavía no ha corrido.
            </div>
        <?php else: ?>

            <div class="max-w-[1600px] mx-auto">

                <!-- Header del brief -->
                <div class="flex items-center justify-between flex-wrap gap-2 mb-4">
                    <div>
                        <h2 class="text-lg font-bold text-gray-900">Brief del día</h2>
                        <p class="text-xs text-gray-400">
                            <?= date('d/m/Y', strtotime($copiloto_snapshot['fecha'])) ?>
                            <?php if (!empty($copiloto_snapshot['brief_generado_en'])): ?>
                                · generado a las <?= date('H:i', strtotime($copiloto_snapshot['brief_generado_en'])) ?>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>

                <?php if (!empty($copiloto_snapshot['brief_error'])): ?>
                    <div class="flex items-start gap-2 bg-amber-50 border border-amber-200 text-amber-700 text-xs rounded-xl px-4 py-3 mb-4">
                        <i class="fa-solid fa-triangle-exclamation mt-0.5"></i>
                        <span>El brief no se pudo generar automáticamente hoy (<?= htmlspecialchars($copiloto_snapshot['brief_error'], ENT_QUOTES, 'UTF-8') ?>). Las señales de abajo sí están al día.</span>
                    </div>
                <?php endif; ?>

                <!-- Brief destacado -->
                <?php if (!empty($copiloto_snapshot['brief_texto'])): ?>
                    <section class="bg-white border border-gray-100 rounded-2xl shadow-sm p-6 mb-8">
                        <div class="flex items-center gap-2 mb-4">
                            <div class="w-8 h-8 rounded-full bg-blue-50 text-[#54A6D8] flex items-center justify-center shrink-0">
                                <i class="fa-solid fa-wand-magic-sparkles text-xs"></i>
                            </div>
                            <h3 class="text-sm font-bold text-gray-900">Diagnóstico del analista</h3>
                        </div>
                        <div class="leading-relaxed"><?= nb_brief_markdown_a_html($copiloto_snapshot['brief_texto']) ?></div>
                    </section>
                <?php elseif (empty($copiloto_snapshot['brief_error'])): ?>
                    <div class="bg-gray-50 border border-dashed border-gray-200 rounded-2xl p-8 text-center text-gray-400 text-sm mb-8">
                        Sin brief para este snapshot.
                    </div>
                <?php endif; ?>

                <!-- Métricas clave -->
                <?php
                $copiloto_metricas = [
                    ['label' => 'Dormidos',            'valor' => (int)$copiloto_snapshot['dormidos_total'],      'delta' => $copiloto_deltas['dormidos_total'] ?? null],
                    ['label' => 'Leads sin contactar',  'valor' => (int)$copiloto_snapshot['leads_sin_contactar'], 'delta' => $copiloto_deltas['leads_sin_contactar'] ?? null],
                    ['label' => 'Contratos 7d',         'valor' => (int)$copiloto_snapshot['contratos_7d'],        'delta' => $copiloto_deltas['contratos_7d'] ?? null],
                    ['label' => 'Contratos 30d',        'valor' => (int)$copiloto_snapshot['contratos_30d'],       'delta' => $copiloto_deltas['contratos_30d'] ?? null],
                    ['label' => 'Monto 30d (CLP)',      'valor' => '$' . number_format((float)$copiloto_snapshot['monto_contratos_30d'], 0, ',', '.'), 'delta' => $copiloto_deltas['monto_contratos_30d'] ?? null],
                ];
                ?>
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3 mb-8">
                    <?php foreach ($copiloto_metricas as $m): ?>
                        <div class="bg-white border border-gray-100 rounded-2xl shadow-sm p-4">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wide mb-1"><?= htmlspecialchars($m['label'], ENT_QUOTES, 'UTF-8') ?></p>
                            <p class="text-2xl font-bold text-gray-900 mb-1"><?= is_string($m['valor']) ? htmlspecialchars($m['valor'], ENT_QUOTES, 'UTF-8') : number_format($m['valor'], 0, ',', '.') ?></p>
                            <?= copiloto_delta_html($m['delta']) ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Señales detalladas -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-8">

                    <section class="bg-white border border-gray-100 rounded-2xl shadow-sm p-5">
                        <h3 class="text-xs font-bold text-gray-900 uppercase tracking-wide mb-3">Oferta por categoría</h3>
                        <?php if (empty($copiloto_oferta)): ?>
                            <p class="text-xs text-gray-400">Sin datos suficientes.</p>
                        <?php else: ?>
                            <ul class="space-y-1.5">
                                <?php foreach ($copiloto_oferta as $cat => $n): ?>
                                    <li class="flex items-center justify-between text-xs">
                                        <span class="text-gray-600 truncate pr-2"><?= htmlspecialchars((string)$cat, ENT_QUOTES, 'UTF-8') ?></span>
                                        <span class="font-bold text-gray-900 shrink-0"><?= (int)$n ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </section>

                    <section class="bg-white border border-gray-100 rounded-2xl shadow-sm p-5">
                        <h3 class="text-xs font-bold text-gray-900 uppercase tracking-wide mb-3">Demanda (vistas 30d)</h3>
                        <?php if (empty($copiloto_demanda['servicio']) && empty($copiloto_demanda['apunte'])): ?>
                            <p class="text-xs text-gray-400">Sin datos suficientes.</p>
                        <?php else: ?>
                            <?php if (!empty($copiloto_demanda['servicio'])): ?>
                                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wide mb-1">Servicios</p>
                                <ul class="space-y-1.5 mb-3">
                                    <?php foreach ($copiloto_demanda['servicio'] as $cat => $n): ?>
                                        <li class="flex items-center justify-between text-xs">
                                            <span class="text-gray-600 truncate pr-2"><?= htmlspecialchars((string)$cat, ENT_QUOTES, 'UTF-8') ?></span>
                                            <span class="font-bold text-gray-900 shrink-0"><?= (int)$n ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <?php if (!empty($copiloto_demanda['apunte'])): ?>
                                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wide mb-1">Apuntes</p>
                                <ul class="space-y-1.5">
                                    <?php foreach ($copiloto_demanda['apunte'] as $cat => $n): ?>
                                        <li class="flex items-center justify-between text-xs">
                                            <span class="text-gray-600 truncate pr-2"><?= htmlspecialchars((string)$cat, ENT_QUOTES, 'UTF-8') ?></span>
                                            <span class="font-bold text-gray-900 shrink-0"><?= (int)$n ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        <?php endif; ?>
                    </section>

                    <section class="bg-white border border-gray-100 rounded-2xl shadow-sm p-5">
                        <h3 class="text-xs font-bold text-gray-900 uppercase tracking-wide mb-3">Búsquedas sin resultado</h3>
                        <?php if (empty($copiloto_busquedas)): ?>
                            <p class="text-xs text-gray-400">Sin datos suficientes.</p>
                        <?php else: ?>
                            <ul class="space-y-1.5">
                                <?php foreach ($copiloto_busquedas as $termino => $n): ?>
                                    <li class="flex items-center justify-between text-xs">
                                        <span class="text-gray-600 truncate pr-2">"<?= htmlspecialchars((string)$termino, ENT_QUOTES, 'UTF-8') ?>"</span>
                                        <span class="font-bold text-gray-900 shrink-0"><?= (int)$n ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </section>

                </div>

                <!-- Instagram -->
                <?php if (!$ig_snapshot): ?>
                    <section class="bg-white border border-dashed border-gray-200 rounded-2xl p-8 text-center text-gray-400 text-sm mb-8">
                        Aún no hay datos de Instagram. El cron diario (<code class="text-xs">app/cron/copiloto_instagram.php</code>) todavía no ha corrido.
                    </section>
                <?php else: ?>
                    <section class="mb-8">
                        <div class="flex items-center gap-2 mb-3">
                            <div class="w-8 h-8 rounded-full bg-gradient-to-tr from-amber-400 via-pink-500 to-purple-600 flex items-center justify-center shrink-0">
                                <i class="fa-brands fa-instagram text-white text-xs"></i>
                            </div>
                            <h3 class="text-sm font-bold text-gray-900">
                                Instagram<?= !empty($ig_datos_perfil['username']) ? ' · @' . htmlspecialchars($ig_datos_perfil['username'], ENT_QUOTES, 'UTF-8') : '' ?>
                            </h3>
                        </div>

                        <?php
                        $ig_metricas = [
                            ['label' => 'Seguidores',    'valor' => (int)$ig_snapshot['followers_count'], 'delta' => $ig_deltas['followers_count'] ?? null],
                            ['label' => 'Publicaciones', 'valor' => (int)$ig_snapshot['media_count'],     'delta' => $ig_deltas['media_count'] ?? null],
                            ['label' => 'Reach de hoy',  'valor' => $ig_snapshot['reach_dia'] !== null ? (int)$ig_snapshot['reach_dia'] : 'N/D', 'delta' => $ig_deltas['reach_dia'] ?? null],
                        ];
                        ?>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
                            <?php foreach ($ig_metricas as $m): ?>
                                <div class="bg-white border border-gray-100 rounded-2xl shadow-sm p-4">
                                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wide mb-1"><?= htmlspecialchars($m['label'], ENT_QUOTES, 'UTF-8') ?></p>
                                    <p class="text-2xl font-bold text-gray-900 mb-1"><?= is_string($m['valor']) ? htmlspecialchars($m['valor'], ENT_QUOTES, 'UTF-8') : number_format($m['valor'], 0, ',', '.') ?></p>
                                    <?= copiloto_delta_html($m['delta']) ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="bg-white border border-gray-100 rounded-2xl shadow-sm p-5">
                            <h4 class="text-xs font-bold text-gray-900 uppercase tracking-wide mb-3">Top publicaciones por alcance</h4>
                            <?php if (empty($ig_top_posts)): ?>
                                <p class="text-xs text-gray-400">Sin datos suficientes.</p>
                            <?php else: ?>
                                <ul class="space-y-3">
                                    <?php foreach ($ig_top_posts as $post): ?>
                                        <li class="flex items-start justify-between gap-3 pb-3 border-b border-gray-50 last:border-0 last:pb-0">
                                            <div class="min-w-0">
                                                <p class="text-xs text-gray-700 line-clamp-2 leading-snug mb-1"><?= htmlspecialchars((string)($post['caption'] ?? '(sin descripción)'), ENT_QUOTES, 'UTF-8') ?></p>
                                                <p class="text-[10px] text-gray-400">
                                                    <?= (int)($post['like_count'] ?? 0) ?> likes · <?= (int)($post['comments_count'] ?? 0) ?> comments<?= isset($post['reach']) && $post['reach'] !== null ? ' · ' . (int)$post['reach'] . ' reach' : '' ?>
                                                </p>
                                            </div>
                                            <?php if (!empty($post['permalink'])): ?>
                                                <a href="<?= htmlspecialchars($post['permalink'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="shrink-0 text-[#54A6D8] hover:text-blue-600 text-xs font-bold whitespace-nowrap">
                                                    Ver <i class="fa-solid fa-arrow-up-right-from-square text-[9px]"></i>
                                                </a>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <!-- Calendario de Instagram -->
                <section class="mb-8">
                    <div class="flex items-center justify-between flex-wrap gap-3 mb-4">
                        <div>
                            <h3 class="text-sm font-bold text-gray-900">Calendario de Instagram</h3>
                            <?php if ($calendario_reciente): ?>
                                <p class="text-xs text-gray-400">
                                    Semana del <?= date('d/m/Y', strtotime($calendario_reciente['semana_inicio'])) ?>
                                    · generado <?= date('d/m/Y H:i', strtotime($calendario_reciente['generado_en'])) ?>
                                </p>
                            <?php else: ?>
                                <p class="text-xs text-gray-400">Todavía no se ha generado ningún calendario.</p>
                            <?php endif; ?>
                        </div>
                        <button type="button" id="btn-generar-calendario"
                                class="px-4 py-2.5 rounded-xl bg-[#54A6D8] hover:bg-blue-600 text-white text-sm font-bold transition-colors flex items-center gap-2 disabled:opacity-60">
                            <i class="fa-solid fa-calendar-days"></i>
                            <span id="btn-generar-calendario-texto"><?= $calendario_reciente ? 'Regenerar' : 'Generar calendario semanal' ?></span>
                        </button>
                    </div>

                    <?php if (empty($calendario_semana)): ?>
                        <div class="bg-white border border-dashed border-gray-200 rounded-2xl p-12 text-center text-gray-400">
                            Aún no hay calendario generado para esta semana. Usa el botón de arriba.
                        </div>
                    <?php else: ?>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                            <?php foreach ($calendario_semana as $dia): ?>
                                <?php
                                if (!is_array($dia)) continue;
                                $dia_nombre    = (string)($dia['dia'] ?? '');
                                $dia_tema      = (string)($dia['tema'] ?? '');
                                $dia_formato   = (string)($dia['formato'] ?? '');
                                $dia_objetivo  = (string)($dia['objetivo'] ?? '');
                                $dia_copy      = (string)($dia['copy'] ?? '');
                                $dia_hashtags  = is_array($dia['hashtags'] ?? null) ? $dia['hashtags'] : [];
                                $dia_horario   = (string)($dia['horario_sugerido'] ?? '');
                                $dia_categoria = $dia['categoria_nubira'] ?? null;
                                $dia_seccion   = $dia['seccion_nubira'] ?? null;
                                $dia_receta_reel = $dia['receta_reel'] ?? null;
                                $dia_slides    = is_array($dia['slides'] ?? null)
                                    ? array_values(array_filter($dia['slides'], fn($sl) => is_array($sl) && !empty(trim((string)($sl['texto'] ?? '')))))
                                    : [];
                                $objetivo_es_crecer = strtolower($dia_objetivo) === 'crecer';
                                ?>
                                <div class="bg-white border border-gray-100 rounded-2xl shadow-sm p-4 flex flex-col" data-dia-copy="<?= htmlspecialchars($dia_copy, ENT_QUOTES, 'UTF-8') ?>" data-calendario-id="<?= (int)$calendario_reciente['id'] ?>" data-dia="<?= htmlspecialchars($dia_nombre, ENT_QUOTES, 'UTF-8') ?>">
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="text-xs font-bold text-gray-900 uppercase tracking-wide"><?= htmlspecialchars($dia_nombre, ENT_QUOTES, 'UTF-8') ?></span>
                                        <div class="flex items-center gap-1">
                                            <?php if ($dia_formato !== ''): ?>
                                                <span class="text-[9px] font-bold uppercase tracking-wide px-2 py-0.5 rounded-full bg-gray-100 text-gray-600"><?= htmlspecialchars($dia_formato, ENT_QUOTES, 'UTF-8') ?></span>
                                            <?php endif; ?>
                                            <?php if ($dia_objetivo !== ''): ?>
                                                <span class="text-[9px] font-bold uppercase tracking-wide px-2 py-0.5 rounded-full <?= $objetivo_es_crecer ? 'bg-emerald-50 text-emerald-600' : 'bg-blue-50 text-[#54A6D8]' ?>"><?= htmlspecialchars($dia_objetivo, ENT_QUOTES, 'UTF-8') ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <p class="text-xs font-semibold text-gray-800 mb-2 leading-snug"><?= htmlspecialchars($dia_tema, ENT_QUOTES, 'UTF-8') ?></p>

                                    <?php if (!empty($dia_receta_reel)): ?>
                                        <div class="bg-purple-50 border border-purple-100 rounded-xl p-2.5 mb-2 flex items-start gap-2">
                                            <i class="fa-solid fa-clapperboard text-purple-500 text-xs mt-0.5 shrink-0"></i>
                                            <p class="text-[11px] text-purple-700 leading-relaxed"><?= htmlspecialchars((string)$dia_receta_reel, ENT_QUOTES, 'UTF-8') ?></p>
                                        </div>
                                    <?php endif; ?>

                                    <div class="bg-gray-50 border border-gray-100 rounded-xl p-3 mb-2 flex-1">
                                        <p class="text-[11px] text-gray-600 leading-relaxed whitespace-pre-line line-clamp-6"><?= htmlspecialchars($dia_copy, ENT_QUOTES, 'UTF-8') ?></p>
                                    </div>

                                    <button type="button" class="btn-copiar-copy self-start text-[11px] font-bold text-[#54A6D8] hover:text-blue-600 mb-3 flex items-center gap-1">
                                        <i class="fa-solid fa-copy"></i> <span class="btn-copiar-texto">Copiar</span>
                                    </button>

                                    <?php if (!empty($dia_hashtags)): ?>
                                        <div class="flex flex-wrap gap-1 mb-3">
                                            <?php foreach ($dia_hashtags as $tag): ?>
                                                <span class="text-[9px] font-medium text-gray-500 bg-gray-50 border border-gray-100 px-1.5 py-0.5 rounded-full"><?= htmlspecialchars((string)$tag, ENT_QUOTES, 'UTF-8') ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($dia_formato === 'carrusel' && !empty($dia_slides)): ?>
                                        <div class="mb-3 pt-2 border-t border-gray-50 space-y-2">
                                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wide">Slides del carrusel</p>
                                            <?php foreach ($dia_slides as $i => $slide): ?>
                                                <?php
                                                $numSlide   = $i + 1;
                                                $tipoSlide  = in_array($slide['tipo'] ?? '', ['portada', 'contenido', 'cierre'], true) ? $slide['tipo'] : 'contenido';
                                                $etiqueta   = ['portada' => 'Portada', 'contenido' => 'Contenido', 'cierre' => 'Cierre'][$tipoSlide];
                                                $textoSlide = trim((string)($slide['texto'] ?? ''));
                                                $limiteSlide = ['portada' => 60, 'contenido' => 40, 'cierre' => 60][$tipoSlide];
                                                $subtextoSlide = trim((string)($slide['subtexto'] ?? ''));
                                                $limiteSubtexto = ['portada' => 90, 'contenido' => 140, 'cierre' => 140][$tipoSlide];
                                                ?>
                                                <div class="bg-gray-50 border border-gray-100 rounded-xl p-2.5 slide-editable" data-numero-slide="<?= $numSlide ?>">
                                                    <div class="flex items-center justify-between gap-2 mb-1">
                                                        <span class="text-[10px] font-bold text-gray-500">Slide <?= $numSlide ?> · <?= htmlspecialchars($etiqueta, ENT_QUOTES, 'UTF-8') ?></span>
                                                        <div class="flex items-center gap-2 shrink-0">
                                                            <span class="slide-contador text-[9px] font-medium text-gray-400"><?= mb_strlen($textoSlide, 'UTF-8') ?>/<?= $limiteSlide ?></span>
                                                            <button type="button" class="btn-guardar-slide text-[10px] font-bold text-emerald-600 hover:text-emerald-700 flex items-center gap-1">
                                                                <i class="fa-solid fa-floppy-disk"></i> <span class="btn-guardar-slide-texto">Guardar</span>
                                                            </button>
                                                            <button type="button" class="btn-copiar-slide text-[10px] font-bold text-[#54A6D8] hover:text-blue-600 flex items-center gap-1">
                                                                <i class="fa-solid fa-copy"></i> <span class="btn-copiar-slide-texto">Copiar</span>
                                                            </button>
                                                        </div>
                                                    </div>
                                                    <textarea class="input-slide-texto w-full text-[11px] text-gray-700 leading-relaxed bg-white border border-gray-200 rounded-lg p-2 resize-none focus:outline-none focus:ring-1 focus:ring-[#54A6D8]" rows="2" maxlength="<?= $limiteSlide ?>" data-limite="<?= $limiteSlide ?>" data-original="<?= htmlspecialchars($textoSlide, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($textoSlide, ENT_QUOTES, 'UTF-8') ?></textarea>
                                                    <div class="flex items-center justify-between gap-2 mt-1.5 mb-1">
                                                        <span class="text-[9px] font-bold text-gray-400 uppercase tracking-wide">Apoyo (opcional)</span>
                                                        <span class="slide-subtexto-contador text-[9px] font-medium text-gray-400"><?= mb_strlen($subtextoSlide, 'UTF-8') ?>/<?= $limiteSubtexto ?></span>
                                                    </div>
                                                    <textarea class="input-slide-subtexto w-full text-[11px] text-gray-500 leading-relaxed bg-white border border-gray-200 rounded-lg p-2 resize-none focus:outline-none focus:ring-1 focus:ring-[#54A6D8]" rows="2" maxlength="<?= $limiteSubtexto ?>" data-limite="<?= $limiteSubtexto ?>" data-original="<?= htmlspecialchars($subtextoSlide, ENT_QUOTES, 'UTF-8') ?>" placeholder="Complementa el título, sin repetirlo..."><?= htmlspecialchars($subtextoSlide, ENT_QUOTES, 'UTF-8') ?></textarea>
                                                    <p class="slide-aviso-editado hidden text-[10px] text-amber-600 font-semibold mt-1 flex items-center gap-1">
                                                        <i class="fa-solid fa-triangle-exclamation"></i> Texto editado, genera de nuevo para actualizar la imagen
                                                    </p>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>

                                        <div class="mb-3">
                                            <button type="button" class="btn-generar-fondos w-full text-[11px] font-bold text-white bg-[#54A6D8] hover:bg-blue-600 rounded-xl py-2 flex items-center justify-center gap-1.5 transition-colors disabled:opacity-60">
                                                <i class="fa-solid fa-wand-magic-sparkles"></i>
                                                <span class="btn-generar-fondos-texto">Generar imágenes del carrusel</span>
                                            </button>
                                            <div class="btn-generar-fondos-resultado hidden grid grid-cols-3 gap-1.5 mt-2"></div>
                                        </div>
                                    <?php endif; ?>

                                    <div class="flex items-center justify-between gap-2 text-[10px] text-gray-400 mt-auto pt-2 border-t border-gray-50">
                                        <span class="whitespace-nowrap"><i class="fa-regular fa-clock"></i> <?= htmlspecialchars($dia_horario, ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php if (!empty($dia_categoria)): ?>
                                            <?php $dia_es_apuntes = strtolower((string)$dia_seccion) === 'apuntes'; ?>
                                            <a href="/admin/marketing-cards?tab=servicios&categoria=<?= urlencode((string)$dia_categoria) ?>" class="font-bold text-[#54A6D8] hover:text-blue-600 truncate">
                                                <?= $dia_es_apuntes ? 'Ver apuntes de' : 'Ver tutores de' ?> <?= htmlspecialchars((string)$dia_categoria, ENT_QUOTES, 'UTF-8') ?>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <!-- Tutores por promocionar -->
                <section class="bg-white border border-gray-100 rounded-2xl shadow-sm p-5 mb-8">
                    <h3 class="text-xs font-bold text-gray-900 uppercase tracking-wide mb-1">Tutores por promocionar</h3>
                    <p class="text-xs text-gray-400 mb-3">Sugiere a qué tutores destacar en tus próximas publicaciones de Instagram, priorizando a quienes nunca se han promocionado o llevan más tiempo sin aparecer, para repartir la visibilidad de forma pareja.</p>
                    <?php if (empty($tutores_por_promocionar)): ?>
                        <p class="text-xs text-gray-400">No hay servicios activos en este momento.</p>
                    <?php else: ?>
                        <ul class="space-y-2">
                            <?php foreach ($tutores_por_promocionar as $t): ?>
                                <?php
                                $dias_desde = null;
                                $fecha_exacta_ultima = '';
                                if (!empty($t['ultima'])) {
                                    $ts_ultima = strtotime($t['ultima']);
                                    $dias_desde = (int)floor((time() - $ts_ultima) / 86400);
                                    // Formato "12 ago 2026" — mismo arreglo de meses en español ya usado
                                    // en otras partes del sitio (ej. mis_contratos.php), en minúscula.
                                    $meses_es = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
                                    $fecha_exacta_ultima = (int)date('j', $ts_ultima) . ' ' . $meses_es[(int)date('n', $ts_ultima) - 1] . ' ' . date('Y', $ts_ultima);
                                }
                                ?>
                                <li class="flex items-center justify-between gap-3 py-2 border-b border-gray-50 last:border-0">
                                    <div class="min-w-0">
                                        <p class="text-xs font-bold text-gray-800 truncate"><?= htmlspecialchars($t['titulo'], ENT_QUOTES, 'UTF-8') ?></p>
                                        <p class="text-[10px] text-gray-400 truncate"><?= htmlspecialchars($t['tutor_nombre'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($t['categoria'], ENT_QUOTES, 'UTF-8') ?></p>
                                    </div>
                                    <div class="flex items-center gap-2 shrink-0">
                                        <?php if ($dias_desde === null): ?>
                                            <span class="text-[9px] font-bold uppercase tracking-wide px-2 py-0.5 rounded-full bg-red-50 text-red-500">Nunca promocionado</span>
                                        <?php else: ?>
                                            <span class="text-[9px] font-bold uppercase tracking-wide px-2 py-0.5 rounded-full bg-gray-100 text-gray-500" title="<?= htmlspecialchars($fecha_exacta_ultima, ENT_QUOTES, 'UTF-8') ?>">Hace <?= $dias_desde ?> <?= $dias_desde === 1 ? 'día' : 'días' ?></span>
                                        <?php endif; ?>
                                        <a href="/admin/marketing-cards?tab=servicios&categoria=<?= urlencode((string)$t['categoria']) ?>&servicio_id=<?= (int)$t['id'] ?>" class="text-[11px] font-bold text-[#54A6D8] hover:text-blue-600 whitespace-nowrap">
                                            Armar carrusel
                                        </a>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>

                <!-- Historial -->
                <section class="bg-white border border-gray-100 rounded-2xl shadow-sm p-5 mb-6">
                    <h3 class="text-xs font-bold text-gray-900 uppercase tracking-wide mb-3">Historial (últimos <?= count($copiloto_historial) ?> días)</h3>
                    <?php if (count($copiloto_historial) <= 1): ?>
                        <p class="text-xs text-gray-400">Todavía no hay suficiente historial para ver una tendencia.</p>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full text-xs">
                                <thead>
                                    <tr class="text-left text-[10px] text-gray-400 uppercase tracking-wide border-b border-gray-100">
                                        <th class="py-2 pr-4">Fecha</th>
                                        <th class="py-2 pr-4 text-right">Dormidos</th>
                                        <th class="py-2 pr-4 text-right">Leads</th>
                                        <th class="py-2 pr-4 text-right">Contratos 7d</th>
                                        <th class="py-2 text-right">Contratos 30d</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-50">
                                    <?php foreach ($copiloto_historial as $h): ?>
                                        <tr>
                                            <td class="py-2 pr-4 text-gray-500"><?= date('d/m', strtotime($h['fecha'])) ?></td>
                                            <td class="py-2 pr-4 text-right font-bold text-gray-800"><?= (int)$h['dormidos_total'] ?></td>
                                            <td class="py-2 pr-4 text-right font-bold text-gray-800"><?= (int)$h['leads_sin_contactar'] ?></td>
                                            <td class="py-2 pr-4 text-right font-bold text-gray-800"><?= (int)$h['contratos_7d'] ?></td>
                                            <td class="py-2 text-right font-bold text-gray-800"><?= (int)$h['contratos_30d'] ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>

                <p class="text-[11px] text-gray-400 text-center">Generado automáticamente por el cron diario. Las cifras son aproximadas y orientativas.</p>
            </div>

        <?php endif; ?>

    <?php endif; ?>

</main>

<?php if ($tab === 'servicios'): ?>
<!-- Barra de selección fija -->
<div id="mkt-action-bar" class="hidden fixed bottom-0 left-0 right-0 lg:left-64 z-40 bg-white border-t border-gray-200 shadow-[0_-4px_12px_rgba(0,0,0,0.06)]">
    <div class="max-w-[1600px] mx-auto px-4 md:px-8 py-4 flex items-center justify-between gap-4">
        <p class="text-sm font-bold text-gray-700">
            <span id="mkt-bar-count">0</span> <span id="mkt-bar-plural">servicios</span> seleccionados
        </p>
        <div class="flex items-center gap-2">
            <button type="button" id="mkt-btn-marcar-publicados"
                    class="px-4 py-2.5 rounded-xl border border-gray-200 text-gray-600 hover:bg-gray-50 text-sm font-bold transition-colors flex items-center gap-2 disabled:opacity-60">
                <i class="fa-solid fa-check"></i> <span class="hidden sm:inline">Marcar como publicados</span>
            </button>
            <button type="button" id="mkt-btn-carrusel"
                    class="px-5 py-2.5 rounded-xl bg-[#54A6D8] hover:bg-blue-600 text-white text-sm font-bold transition-colors flex items-center gap-2">
                <i class="fa-solid fa-images"></i> Ver como carrusel
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
if (file_exists($app_dir . '/componentes/nav_bottom.php')) require_once $app_dir . '/componentes/nav_bottom.php';
if ($tab === 'servicios') require_once $app_dir . '/componentes/modal_carrusel_marketing.php';
?>

<script>
<?php if ($tab === 'servicios'): ?>
const CSRF_TOKEN = '<?= $_SESSION['csrf_token'] ?>';
const preseleccionarId = <?= $preseleccionar_id ?>;

(function () {
    const checkAll   = document.getElementById('check-all');
    const rowChecks  = () => [...document.querySelectorAll('.mkt-check')];
    const actionBar  = document.getElementById('mkt-action-bar');
    const barCount   = document.getElementById('mkt-bar-count');
    const barPlural  = document.getElementById('mkt-bar-plural');
    const btnCarrusel = document.getElementById('mkt-btn-carrusel');
    const btnMarcar  = document.getElementById('mkt-btn-marcar-publicados');
    const navBottom  = document.getElementById('nav-bottom');

    function mostrarToast(msg, esError) {
        const toast = document.createElement('div');
        toast.className = `fixed bottom-24 lg:bottom-6 left-1/2 -translate-x-1/2 z-[200] px-4 py-3 rounded-xl shadow-lg text-sm font-bold ${esError ? 'bg-red-50 text-red-600 border border-red-200' : 'bg-emerald-50 text-emerald-700 border border-emerald-200'}`;
        toast.textContent = msg;
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 4000);
    }

    function syncBar() {
        const marcados = rowChecks().filter(c => c.checked);
        const n = marcados.length;
        barCount.textContent = n;
        barPlural.textContent = n === 1 ? 'servicio' : 'servicios';
        actionBar.classList.toggle('hidden', n === 0);
        // Evita que nav_bottom (z-[60]) tape la barra de selección (z-40) en móvil —
        // ambas son fixed bottom-0 de ancho completo y compiten por el mismo espacio.
        // Se oculta solo mientras hay selección activa; navegación normal intacta el resto del tiempo.
        if (navBottom) navBottom.classList.toggle('hidden', n > 0);
    }

    if (checkAll) {
        checkAll.addEventListener('change', () => {
            rowChecks().forEach(c => { c.checked = checkAll.checked; });
            syncBar();
        });
    }

    document.querySelectorAll('.mkt-check').forEach(c => c.addEventListener('change', syncBar));

    // Preselección al llegar desde "Armar carrusel" (tab Copiloto). Si el servicio_id de
    // la URL no está en la grilla actual (categoría sin resultados, servicio ya no visible),
    // querySelector devuelve null y no se hace nada — no rompe la tab, solo no preselecciona.
    // El ring de resaltado queda puesto mientras la card esté preseleccionada, no se retira.
    if (preseleccionarId > 0) {
        const chkPreseleccionado = document.querySelector(`.mkt-check[value="${preseleccionarId}"]`);
        if (chkPreseleccionado) {
            chkPreseleccionado.checked = true;
            syncBar();
            const cardPreseleccionada = chkPreseleccionado.closest('.mkt-card');
            if (cardPreseleccionada) {
                cardPreseleccionada.classList.add('ring-2', 'ring-[#54A6D8]');
                cardPreseleccionada.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }
    }

    btnCarrusel.addEventListener('click', () => {
        const items = rowChecks()
            .filter(c => c.checked)
            .map(c => {
                const card = c.closest('.mkt-card');
                return {
                    id: card.dataset.id,
                    url: card.dataset.imgUrl,
                    titulo: card.dataset.titulo,
                };
            });

        if (items.length === 0) return;

        if (typeof window.abrirCarruselMarketing === 'function') {
            window.abrirCarruselMarketing(items, { permitirMarcarPromocion: true });
        } else {
            // [PENDIENTE] modal_carrusel_marketing.php aún no está incluido
            console.warn('abrirCarruselMarketing() no está definida todavía — falta incluir modal_carrusel_marketing.php');
        }
    });

    // "Marcar como publicados" es una acción DELIBERADA y separada de "Ver
    // como carrusel" — armar/descargar el carrusel no implica que el admin
    // ya subió el contenido a Instagram de verdad.
    if (btnMarcar) {
        btnMarcar.addEventListener('click', async () => {
            const ids = rowChecks().filter(c => c.checked).map(c => c.value);
            if (ids.length === 0) return;

            btnMarcar.disabled = true;
            btnMarcar.classList.add('opacity-60');

            try {
                const body = new URLSearchParams();
                body.append('csrf_token', CSRF_TOKEN);
                ids.forEach(id => body.append('servicio_ids[]', id));

                const r = await fetch('/app/admin_marcar_promocionados.php', { method: 'POST', body });
                const data = await r.json();

                if (!data.ok) {
                    mostrarToast(data.error || 'No se pudo registrar la promoción.', true);
                    return;
                }

                mostrarToast(`${data.marcados} ${data.marcados === 1 ? 'tutor marcado' : 'tutores marcados'} como publicados`, false);

                // Desmarca todo tras registrar — evita marcar dos veces por error.
                rowChecks().forEach(c => { c.checked = false; });
                if (checkAll) checkAll.checked = false;
                syncBar();
            } catch (e) {
                mostrarToast('Error de conexión. Intenta de nuevo.', true);
            } finally {
                btnMarcar.disabled = false;
                btnMarcar.classList.remove('opacity-60');
            }
        });
    }
})();
<?php elseif ($tab === 'novedades'): ?>
const CSRF_TOKEN = '<?= $_SESSION['csrf_token'] ?>';

(function () {
    const fTitulo = document.getElementById('f-titulo');
    const fCuerpo = document.getElementById('f-cuerpo');
    const fError = document.getElementById('f-error');
    const btnGuardar = document.getElementById('btn-guardar');

    const preview = document.getElementById('preview-novedad');
    const previewPostImg = document.getElementById('preview-post-img');
    const previewHistoryImg = document.getElementById('preview-history-img');
    const previewPostDescarga = document.getElementById('preview-post-descarga');
    const previewHistoryDescarga = document.getElementById('preview-history-descarga');

    const historialLista = document.getElementById('historial-lista');

    function mostrarError(msg) {
        fError.textContent = msg;
        fError.classList.remove('hidden');
    }
    function limpiarError() {
        fError.classList.add('hidden');
        fError.textContent = '';
    }

    function crearItemHistorial(n) {
        const li = document.createElement('li');
        li.className = 'flex items-center gap-3 bg-white border border-gray-100 rounded-xl p-3';
        li.dataset.postUrl = n.post_url;
        li.dataset.historyUrl = n.history_url;
        li.dataset.id = n.id;
        li.innerHTML = `
            <img src="${n.post_url}" loading="lazy" decoding="async" alt="" class="w-14 h-14 rounded-lg object-cover border border-gray-200 bg-gray-50 shrink-0">
            <div class="flex-1 min-w-0">
                <p class="historial-titulo text-xs font-bold text-gray-800 truncate"></p>
                <p class="text-[10px] text-gray-400">${n.fecha}</p>
            </div>
            <div class="flex items-center gap-1.5 shrink-0">
                <button type="button" class="btn-compartir-historial w-9 h-9 rounded-full bg-white border border-gray-200 text-[#54A6D8] hover:bg-blue-50 flex items-center justify-center transition-colors" title="Compartir POST" aria-label="Compartir POST" data-formato="post">
                    <i class="fa-solid fa-share-nodes text-xs"></i>
                </button>
                <a href="${n.post_url}" download="nubira-novedad-${n.id}-post.jpg" class="w-9 h-9 rounded-full bg-white border border-gray-200 text-[#54A6D8] hover:bg-blue-50 flex items-center justify-center transition-colors" title="Descargar POST" aria-label="Descargar POST">
                    <i class="fa-solid fa-download text-xs"></i>
                </a>
                <a href="${n.history_url}" download="nubira-novedad-${n.id}-history.jpg" class="w-9 h-9 rounded-full bg-white border border-gray-200 text-[#54A6D8] hover:bg-blue-50 flex items-center justify-center transition-colors" title="Descargar HISTORY" aria-label="Descargar HISTORY">
                    <i class="fa-solid fa-file-arrow-down text-xs"></i>
                </a>
            </div>
        `;
        li.querySelector('.historial-titulo').textContent = n.titulo;
        return li;
    }

    btnGuardar.addEventListener('click', async () => {
        limpiarError();
        const titulo = fTitulo.value.trim();
        const cuerpo = fCuerpo.value.trim();

        if (!titulo || titulo.length > 120) { mostrarError('El título es obligatorio y debe tener máximo 120 caracteres.'); return; }
        if (!cuerpo || cuerpo.length > 280) { mostrarError('El cuerpo es obligatorio y debe tener máximo 280 caracteres.'); return; }

        btnGuardar.disabled = true;
        btnGuardar.classList.add('opacity-60');

        try {
            const fd = new FormData();
            fd.append('titulo', titulo);
            fd.append('cuerpo', cuerpo);
            fd.append('csrf_token', CSRF_TOKEN);

            const r = await fetch('/app/admin_guardar_novedad.php', { method: 'POST', body: fd });
            const data = await r.json();

            if (!data.success) {
                mostrarError(data.error || 'No se pudo guardar la novedad.');
                return;
            }

            // Preview
            previewPostImg.src = data.post_url;
            previewHistoryImg.src = data.history_url;
            previewPostDescarga.href = data.post_url;
            previewPostDescarga.setAttribute('download', `nubira-novedad-${data.id}-post.jpg`);
            previewHistoryDescarga.href = data.history_url;
            previewHistoryDescarga.setAttribute('download', `nubira-novedad-${data.id}-history.jpg`);
            preview.classList.remove('hidden');
            preview.scrollIntoView({ behavior: 'smooth', block: 'start' });

            // Historial: se agrega arriba de la lista, sin recargar la página
            const ahora = new Date();
            const fecha = ahora.toLocaleDateString('es-CL') + ' ' + ahora.toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit' });
            historialLista.prepend(crearItemHistorial({
                id: data.id, titulo, fecha,
                post_url: data.post_url, history_url: data.history_url,
            }));

            // Reset del formulario
            fTitulo.value = '';
            fCuerpo.value = '';
            document.getElementById('f-counter-titulo').textContent = '0 / 120';
            document.getElementById('f-counter-cuerpo').textContent = '0 / 280';
        } catch (e) {
            mostrarError('Error de conexión. Intenta de nuevo.');
        } finally {
            btnGuardar.disabled = false;
            btnGuardar.classList.remove('opacity-60');
        }
    });

    // Compartir (preview grande + historial): fetch+Blob para navigator.share(), mismo
    // patrón que modal_carrusel_marketing.php. Si no hay soporte, cae a click() del <a download>.
    async function compartir(url, filename, tituloCompartir) {
        if (typeof navigator.share !== 'function' || typeof navigator.canShare !== 'function') {
            descargarDirecto(url, filename);
            return;
        }
        try {
            const resp = await fetch(url);
            const blob = await resp.blob();
            const file = new File([blob], filename, { type: blob.type || 'image/jpeg' });
            if (navigator.canShare({ files: [file] })) {
                await navigator.share({ files: [file], title: tituloCompartir });
                return;
            }
        } catch (err) {
            if (err && err.name === 'AbortError') return;
        }
        descargarDirecto(url, filename);
    }

    function descargarDirecto(url, filename) {
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        a.remove();
    }

    document.addEventListener('click', (e) => {
        const btnPreview = e.target.closest('#preview-novedad .btn-compartir');
        if (btnPreview) {
            const formato = btnPreview.dataset.formato;
            const img = formato === 'post' ? previewPostImg : previewHistoryImg;
            compartir(img.src, `nubira-novedad-${formato}.jpg`, fTitulo.value || 'Novedad Nubira');
            return;
        }
        const btnHist = e.target.closest('.btn-compartir-historial');
        if (btnHist) {
            const li = btnHist.closest('li');
            const formato = btnHist.dataset.formato;
            const url = formato === 'post' ? li.dataset.postUrl : li.dataset.historyUrl;
            const titulo = li.querySelector('p.font-bold')?.textContent || 'Novedad Nubira';
            compartir(url, `nubira-novedad-${li.dataset.id}-${formato}.jpg`, titulo);
        }
    });
})();
<?php elseif ($tab === 'copiloto'): ?>
const CSRF_TOKEN = '<?= $_SESSION['csrf_token'] ?>';

(function () {
    const btnGenerar = document.getElementById('btn-generar-calendario');
    const btnGenerarTexto = document.getElementById('btn-generar-calendario-texto');

    function mostrarToast(msg, esError) {
        const toast = document.createElement('div');
        toast.className = `fixed bottom-6 left-1/2 -translate-x-1/2 z-[200] px-4 py-3 rounded-xl shadow-lg text-sm font-bold ${esError ? 'bg-red-50 text-red-600 border border-red-200' : 'bg-emerald-50 text-emerald-700 border border-emerald-200'}`;
        toast.textContent = msg;
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 4000);
    }

    if (btnGenerar) {
        btnGenerar.addEventListener('click', async () => {
            const textoOriginal = btnGenerarTexto.textContent;
            btnGenerar.disabled = true;
            btnGenerarTexto.textContent = 'Generando...';

            try {
                const r = await fetch('/app/admin_generar_calendario.php', {
                    method: 'POST',
                    body: new URLSearchParams({ csrf_token: CSRF_TOKEN }),
                });
                const data = await r.json();

                if (!data.ok) {
                    mostrarToast(data.error || 'No se pudo generar el calendario.', true);
                    btnGenerar.disabled = false;
                    btnGenerarTexto.textContent = textoOriginal;
                    return;
                }

                // Recarga para mostrar el calendario nuevo desde la BD —
                // mismo criterio "solo lectura" del resto del tab Copiloto.
                location.reload();
            } catch (e) {
                mostrarToast('Error de conexión. Intenta de nuevo.', true);
                btnGenerar.disabled = false;
                btnGenerarTexto.textContent = textoOriginal;
            }
        });
    }

    // navigator.clipboard solo existe en contextos seguros (HTTPS o localhost) —
    // en HTTP plano (ej. nubira.local) es undefined, así que SIEMPRE hay que
    // caer al fallback clásico de <textarea> + execCommand('copy') ahí. También
    // se cae al fallback si el clipboard API existe pero falla (ej. permiso
    // denegado) — "Error" solo se muestra si AMBOS caminos fallan de verdad.
    async function copiarAlPortapapeles(texto) {
        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            try {
                await navigator.clipboard.writeText(texto);
                return true;
            } catch (err) {
                // Sigue al fallback en vez de fallar acá.
            }
        }
        try {
            const textarea = document.createElement('textarea');
            textarea.value = texto;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.focus();
            textarea.select();
            const ok = document.execCommand('copy');
            textarea.remove();
            return ok;
        } catch (err) {
            return false;
        }
    }

    // Copiar copy: lee el texto crudo desde data-dia-copy (el navegador ya
    // decodifica las entidades HTML al leer dataset), lo manda al portapapeles.
    document.addEventListener('click', async (e) => {
        const btnCopiar = e.target.closest('.btn-copiar-copy');
        if (!btnCopiar) return;

        const card = btnCopiar.closest('[data-dia-copy]');
        const textoSpan = btnCopiar.querySelector('.btn-copiar-texto');
        if (!card || !textoSpan) return;

        const original = textoSpan.textContent;
        const ok = await copiarAlPortapapeles(card.dataset.diaCopy);
        textoSpan.textContent = ok ? 'Copiado' : 'Error';
        setTimeout(() => { textoSpan.textContent = original; }, 2000);
    });

    // Copiar 1 slide del carrusel: mismo patrón que "Copiar" del copy, reutilizando
    // copiarAlPortapapeles() tal cual — acá el texto se lee del VALOR ACTUAL del textarea
    // (no de un data-attribute fijo), así que si editaste pero no has guardado, copia
    // exactamente lo que se ve en pantalla.
    document.addEventListener('click', async (e) => {
        const btnCopiarSlide = e.target.closest('.btn-copiar-slide');
        if (!btnCopiarSlide) return;

        const wrapper = btnCopiarSlide.closest('.slide-editable');
        const textarea = wrapper ? wrapper.querySelector('.input-slide-texto') : null;
        const textoSpanSlide = btnCopiarSlide.querySelector('.btn-copiar-slide-texto');
        if (!textarea || !textoSpanSlide) return;

        const originalSlide = textoSpanSlide.textContent;
        const okSlide = await copiarAlPortapapeles(textarea.value);
        textoSpanSlide.textContent = okSlide ? 'Copiado' : 'Error';
        setTimeout(() => { textoSpanSlide.textContent = originalSlide; }, 2000);
    });

    // Contador de caracteres + aviso de "texto editado" — se actualiza con cada tecla.
    // El aviso se muestra apenas hay una edición y queda visible incluso después de
    // guardar (guardar solo persiste el texto, no regenera la imagen) — solo se oculta
    // cuando "Generar imágenes del carrusel" corre de nuevo para ese día (más abajo).
    document.addEventListener('input', (e) => {
        const textarea = e.target.closest('.input-slide-texto');
        if (!textarea) return;

        const wrapper = textarea.closest('.slide-editable');
        const contador = wrapper ? wrapper.querySelector('.slide-contador') : null;
        const aviso = wrapper ? wrapper.querySelector('.slide-aviso-editado') : null;
        if (contador) contador.textContent = `${textarea.value.length}/${textarea.dataset.limite}`;
        if (aviso) aviso.classList.remove('hidden');
    });

    // Mismo patrón para el subtítulo de portada (contador propio, mismo aviso compartido
    // de la tarjeta de la slide).
    document.addEventListener('input', (e) => {
        const textarea = e.target.closest('.input-slide-subtexto');
        if (!textarea) return;

        const wrapper = textarea.closest('.slide-editable');
        const contador = wrapper ? wrapper.querySelector('.slide-subtexto-contador') : null;
        const aviso = wrapper ? wrapper.querySelector('.slide-aviso-editado') : null;
        if (contador) contador.textContent = `${textarea.value.length}/${textarea.dataset.limite}`;
        if (aviso) aviso.classList.remove('hidden');
    });

    // Guardar el texto editado de 1 slide — persiste en copiloto_calendario.contenido,
    // NUNCA genera imagen (eso es acción exclusiva de "Generar imágenes del carrusel").
    document.addEventListener('click', async (e) => {
        const btnGuardarSlide = e.target.closest('.btn-guardar-slide');
        if (!btnGuardarSlide) return;

        const card = btnGuardarSlide.closest('[data-dia-copy]');
        const wrapper = btnGuardarSlide.closest('.slide-editable');
        const textarea = wrapper ? wrapper.querySelector('.input-slide-texto') : null;
        const contador = wrapper ? wrapper.querySelector('.slide-contador') : null;
        // Solo existe en slides tipo portada — null en contenido/cierre, manejado abajo.
        const textareaSub = wrapper ? wrapper.querySelector('.input-slide-subtexto') : null;
        const contadorSub = wrapper ? wrapper.querySelector('.slide-subtexto-contador') : null;
        const textoSpanGuardar = btnGuardarSlide.querySelector('.btn-guardar-slide-texto');
        if (!card || !wrapper || !textarea || !textoSpanGuardar) return;

        const original = textoSpanGuardar.textContent;
        btnGuardarSlide.disabled = true;
        textoSpanGuardar.textContent = 'Guardando...';

        try {
            const body = new URLSearchParams({
                csrf_token: CSRF_TOKEN,
                calendario_id: card.dataset.calendarioId,
                dia: card.dataset.dia,
                numero_slide: wrapper.dataset.numeroSlide,
                texto_nuevo: textarea.value,
                subtexto_nuevo: textareaSub ? textareaSub.value : '',
            });
            const r = await fetch('/app/admin_guardar_slide_calendario.php', { method: 'POST', body });
            const data = await r.json();

            if (!data.ok) {
                textoSpanGuardar.textContent = 'Error';
                mostrarToast(data.error || 'No se pudo guardar el texto.', true);
                setTimeout(() => { textoSpanGuardar.textContent = original; }, 2500);
                btnGuardarSlide.disabled = false;
                return;
            }

            // Si nb_limpiar_texto_slide() cambió algo (ej. quitó un emoji), reflejarlo en
            // el textarea para que se vea exactamente lo que va a salir en la imagen.
            textarea.value = data.texto_guardado;
            textarea.dataset.original = data.texto_guardado;
            if (contador) contador.textContent = `${data.texto_guardado.length}/${textarea.dataset.limite}`;
            if (textareaSub && data.subtexto_guardado !== null && data.subtexto_guardado !== undefined) {
                textareaSub.value = data.subtexto_guardado;
                textareaSub.dataset.original = data.subtexto_guardado;
                if (contadorSub) contadorSub.textContent = `${data.subtexto_guardado.length}/${textareaSub.dataset.limite}`;
            }
            textoSpanGuardar.textContent = data.limpio_cambio ? 'Guardado (se limpió texto)' : 'Guardado';
            btnGuardarSlide.disabled = false;
            setTimeout(() => { textoSpanGuardar.textContent = original; }, 3000);
        } catch (err) {
            textoSpanGuardar.textContent = 'Error de conexión';
            btnGuardarSlide.disabled = false;
            setTimeout(() => { textoSpanGuardar.textContent = original; }, 2500);
        }
    });

    // Generar imágenes del carrusel de UN día — llama al endpoint por-día (nunca los 7
    // juntos). Deshabilita el botón mientras corre (puede tardar, hasta 5 llamadas a
    // Gemini en el peor caso), reporta ok/de-caché/generadas/fallidas en el propio botón
    // y deja las imágenes que sí salieron bien como miniaturas descargables en la tarjeta.
    document.addEventListener('click', async (e) => {
        const btnGenFondos = e.target.closest('.btn-generar-fondos');
        if (!btnGenFondos) return;

        const card = btnGenFondos.closest('[data-dia-copy]');
        const textoSpanFondos = btnGenFondos.querySelector('.btn-generar-fondos-texto');
        const resultadoDiv = card ? card.querySelector('.btn-generar-fondos-resultado') : null;
        if (!card || !textoSpanFondos || !resultadoDiv) return;

        const original = textoSpanFondos.textContent;
        btnGenFondos.disabled = true;
        textoSpanFondos.textContent = 'Generando...';
        resultadoDiv.classList.add('hidden');
        resultadoDiv.innerHTML = '';

        try {
            const body = new URLSearchParams({
                csrf_token: CSRF_TOKEN,
                calendario_id: card.dataset.calendarioId,
                dia: card.dataset.dia,
            });
            const r = await fetch('/app/admin_generar_fondos_carrusel.php', { method: 'POST', body });
            const data = await r.json();

            if (!data.ok) {
                textoSpanFondos.textContent = 'Error';
                mostrarToast(data.error || 'No se pudieron generar las imágenes.', true);
                setTimeout(() => { textoSpanFondos.textContent = original; }, 2500);
                btnGenFondos.disabled = false;
                return;
            }

            const partes = [`${data.de_cache} de caché`, `${data.generadas} nuevas`];
            if (data.fallidas > 0) partes.push(`${data.fallidas} fallidas`);
            textoSpanFondos.textContent = `Listo: ${data.ok_count}/${data.total} (${partes.join(', ')})`;
            btnGenFondos.disabled = false;

            // Las imágenes recién generadas ya reflejan el texto actual (guardado) de cada
            // slide — el aviso de "texto editado, genera de nuevo" ya no aplica.
            card.querySelectorAll('.slide-aviso-editado').forEach(el => el.classList.add('hidden'));

            const slidesOk = (data.slides || []).filter(sl => sl.ok && sl.url);
            if (slidesOk.length > 0) {
                resultadoDiv.classList.remove('hidden');
                slidesOk.forEach(sl => {
                    const a = document.createElement('a');
                    a.href = sl.url;
                    a.download = `nubira-carrusel-${card.dataset.dia.toLowerCase()}-${sl.numero}.jpg`;
                    const img = document.createElement('img');
                    img.src = sl.url;
                    img.loading = 'lazy';
                    img.decoding = 'async';
                    img.alt = '';
                    img.className = 'w-full aspect-[4/5] object-cover rounded-lg border border-gray-200 bg-gray-50';
                    a.appendChild(img);
                    resultadoDiv.appendChild(a);
                });
            }

            setTimeout(() => { textoSpanFondos.textContent = original; }, 8000);
        } catch (err) {
            textoSpanFondos.textContent = 'Error de conexión';
            btnGenFondos.disabled = false;
            setTimeout(() => { textoSpanFondos.textContent = original; }, 2500);
        }
    });
})();
<?php endif; ?>
</script>

</body>
</html>
