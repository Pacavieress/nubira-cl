<?php
session_start();

// --- ANTI-CACHÉ AGRESIVO NUBIRA 2.0 ---
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT"); // Fecha en el pasado
// ---------------------------------------

// 1. CARGA SEGURA DE CONEXIÓN (Estándar unificado Nubira 2.0)
$app_dir = file_exists(__DIR__ . '/conexion.php') ? __DIR__ : __DIR__ . '/app';
require_once $app_dir . '/conexion.php';

// =========================================================================
// 🛡️ [NUBIRA SHIELD] MIDDLEWARE ANTI-BOT (Nivel Arquitectura)
// Se ejecuta AQUÍ, antes de enviar HTML o hacer queries pesadas.
// =========================================================================
if (isset($conn)) {
    $antibot_path = $app_dir . '/middleware/antibot.php';
    if (file_exists($antibot_path)) {
        require_once $antibot_path;
        if (function_exists('check_nubira_shield')) {
            check_nubira_shield($conn); // Si es bot, corta aquí y devuelve 403 puro
        }
    }
}
// =========================================================================
require_once $app_dir . '/iconos.php';
require_once __DIR__ . '/helpers/ofertas.php';
require_once __DIR__ . '/helpers/imagen_servicio.php'; // [BANCO] resolver unificado de portada

// [NUBIRA SHIELD] Cargar enmascarador de URLs
$rutas_shield = [$app_dir . '/seguridad_url.php', $_SERVER['DOCUMENT_ROOT'] . '/app/seguridad_url.php'];
foreach ($rutas_shield as $rs) {
    if (file_exists($rs)) { require_once $rs; break; }
}

// A. Lógica de Auto-Login
if (!isset($_SESSION['usuario_id']) && !empty($_COOKIE['remember_token'])) {
    $token = $_COOKIE['remember_token'];
    $stmt = $conn->prepare("SELECT id, nombre, rol, correo, institucion, dominio FROM alumnos WHERE remember_token = ? LIMIT 1");
    if($stmt) {
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows === 1) {
            $u = $res->fetch_assoc();
            $_SESSION['usuario_id']     = $u['id'];
            session_regenerate_id(true);
            $_SESSION['usuario_nombre'] = $u['nombre'];
            $_SESSION['rol']            = $u['rol'] ?? 'alumno';
            $_SESSION['institucion']    = $u['institucion'];
            $_SESSION['dominio']        = $u['dominio'];
        } else {
            setcookie('remember_token', '', time() - 3600, '/', '', true, true);
        }
        $stmt->close();
    }
}

// B. Lógica de Lazy Registration (Nubira 2.0 - Acceso Público)
$is_guest = !isset($_SESSION['usuario_id']);
if ($is_guest) {
    $_SESSION['redirigir_despues_login'] = $_SERVER['REQUEST_URI'];
}

// B.2 ONBOARDING "Cómo funciona Nubira" (carga perezosa + caché de sesión)
// Debe ir ANTES de session_write_close() porque escribe en $_SESSION.
$debe_ver_onboarding = false;
if (!$is_guest) {
    $uid_onb = (int)$_SESSION['usuario_id'];
    if (!isset($_SESSION['onboarding_visto'])) {
        $stmt_onb = $conn->prepare("SELECT onboarding_visto FROM alumnos WHERE id = ? LIMIT 1");
        if ($stmt_onb) {
            $stmt_onb->bind_param("i", $uid_onb);
            $stmt_onb->execute();
            $stmt_onb->bind_result($onb_flag);
            $stmt_onb->fetch();
            $stmt_onb->close();
            $_SESSION['onboarding_visto'] = (int)($onb_flag ?? 0);
        }
    }
    $debe_ver_onboarding = empty($_SESSION['onboarding_visto']);
}

// OPTIMIZACIÓN DE VELOCIDAD
session_write_close();

// [SENSOR NUBIRA] REGISTRO DE ACTIVIDAD TOTAL
if (file_exists($app_dir . '/logger.php')) {
    require_once $app_dir . '/logger.php';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $is_bot = preg_match('/bot|crawl|spider|slurp|yahoo|mediapartners/i', strtolower($user_agent));
    if (!$is_bot) {
        $guest_hash = substr(md5(session_id()), 0, 8);
        $usuario_id = !$is_guest ? (int)$_SESSION['usuario_id'] : 0;
        $qs_busqueda = trim($_GET['busqueda'] ?? '');
        if (!empty($qs_busqueda)) {
            $termino = substr($qs_busqueda, 0, 100);
            if ($is_guest) {
                registrar_actividad($conn, 0, 'BUSQUEDA_GUEST', "Invitado [$guest_hash] buscó: " . $termino);
            } else {
                registrar_actividad($conn, $usuario_id, 'BUSQUEDA', "Término: " . $termino);
            }
        } else {
            if ($is_guest) {
                registrar_actividad($conn, 0, 'VER_VITRINA_PRINCIPAL_GUEST', "Invitado [$guest_hash] explorando home");
            } else {
                registrar_actividad($conn, $usuario_id, 'VER_VITRINA_PRINCIPAL', "Explorando home vitrina.php");
            }
        }
    }
}

// =========================================================================
// [NUBIRA 2.0] MOTOR DE RECOMENDACIÓN (SMART DISCOVERY + AFINIDAD)
// =========================================================================
$cat_favorita = null;
$es_recomendacion_inmediata = false;

// 1. Prioridad Máxima: Intención de Chat (Inmediata)
if (isset($_SESSION['ultimo_interes_categoria']) && !empty($_SESSION['ultimo_interes_categoria'])) {
    $cat_favorita = $_SESSION['ultimo_interes_categoria'];
    $es_recomendacion_inmediata = true;
    
    // Limpiamos la sesión para que no se quede pegada para siempre.
    // Solo le durará esta vez que entró a la vitrina recién salido del chat.
    unset($_SESSION['ultimo_interes_categoria']); 
} 
// 2. Prioridad Secundaria: Historial Tracker de Intereses (Base de Datos)
else {
    try {
        $identificador_sql = "";
        $param_identificador = "";
        $tipo_param = "";

        if (!$is_guest) {
            $identificador_sql = "usuario_id = ?";
            $param_identificador = $_SESSION['usuario_id'];
            $tipo_param = "i";
        } else {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            $huella = hash('sha256', $ip . $user_agent);
            $identificador_sql = "huella_visitante = ?";
            $param_identificador = $huella;
            $tipo_param = "s";
        }

        $sql_fav = "SELECT categoria, SUM(peso_score) as total_puntos 
                    FROM tracker_intereses 
                    WHERE $identificador_sql 
                      AND categoria != 'General' 
                      AND categoria IS NOT NULL 
                      AND categoria != ''
                    GROUP BY categoria 
                    ORDER BY total_puntos DESC LIMIT 1";

        if ($stmt_fav = $conn->prepare($sql_fav)) {
            $stmt_fav->bind_param($tipo_param, $param_identificador);
            $stmt_fav->execute();
            $res_fav = $stmt_fav->get_result()->fetch_assoc();
            if ($res_fav && !empty(trim($res_fav['categoria']))) {
                $cat_favorita = trim($res_fav['categoria']);
            }
            $stmt_fav->close();
        }
    } catch (Exception $e) {}
}
// =========================================================================





// =========================================================================
// [NUBIRA 2.0] PERSONALIZACIÓN FANTASMA (GUEST DISCOVERY SILENCIOSO)
// =========================================================================
$institucion_inferida = null;
$orden_institucion_sql = ""; // Fragmento mágico para ordenar resultados

$device_id_cookie = $_COOKIE['nubira_device_id'] ?? null;

if ($is_guest && $device_id_cookie && isset($conn)) {
    try {
        $stmt_inst = $conn->prepare("SELECT posible_institucion FROM visitantes_anonimos WHERE device_id = ? LIMIT 1");
        if ($stmt_inst) {
            $stmt_inst->bind_param("s", $device_id_cookie);
            $stmt_inst->execute();
            $res_inst = $stmt_inst->get_result();
            if ($row_inst = $res_inst->fetch_assoc()) {
                $institucion_inferida = $row_inst['posible_institucion'];
                $inst_esc = $conn->real_escape_string($institucion_inferida);
                
                // Si sabemos su U, forzamos a que SQL ponga esos resultados primero (Valor 1), y el resto después (Valor 2)
                $orden_institucion_sql = " CASE WHEN COALESCE(dp.institucion, a.institucion) LIKE '%$inst_esc%' THEN 1 ELSE 2 END, ";
            }
            $stmt_inst->close();
        }
    } catch (Exception $e) {}
}
// =========================================================================
// =========================================================================
// C. CONSULTAS OPTIMIZADAS (AHORA IMPULSADAS POR AFINIDAD)
$seed = (int)floor(time() / 1800); // Cambia cada 30 min — rota orden de carruseles

// [NUBIRA 2.0] Prioridad de perfil completo: foto real > horario configurado > criterio propio de cada sección.
require_once __DIR__ . '/helpers/usuario_helper.php';
$sql_tiene_foto_real = nb_condicion_foto_real($conn);
$sql_tiene_horario    = "CASE WHEN COALESCE(s.horarios_json, '') NOT IN ('', '{}', '[]') THEN 1 ELSE 0 END";
// [NUBIRA 2.0] Factor extra SOLO para "Recomendados" y "PAES": foto > video > horario > criterio propio.
$sql_tiene_video      = "CASE WHEN s.video_estado = 'aprobado' THEN 1 ELSE 0 END";
$res_servicios = null;
$titulo_servicios = "Clases particulares destacadas"; // Título por defecto
try {
  $sql_servicios = "SELECT s.*, s.oferta_termino,
                      COALESCE(dp.institucion, a.institucion) as institucion_maestra,
                      (SELECT COUNT(*) FROM valoraciones v WHERE v.servicio_id = s.id AND v.calificacion > 0 AND v.rol_evaluado = 'vendedor') as total_votos,
                      (SELECT AVG(v.calificacion) FROM valoraciones v WHERE v.servicio_id = s.id AND v.calificacion > 0 AND v.rol_evaluado = 'vendedor') as rating_promedio,
                      a.foto_perfil,
                      a.nombre as nombre_tutor,
                      bi.archivo as banco_archivo,
                      {$sql_tiene_foto_real} AS tiene_foto_real,
                      {$sql_tiene_horario} AS tiene_horario,
                      {$sql_tiene_video} AS tiene_video
               FROM servicios s
               INNER JOIN alumnos a ON s.alumno_id = a.id
               LEFT JOIN dominios_permitidos dp ON a.dominio = dp.dominio
               LEFT JOIN banco_imagenes bi ON bi.id = s.imagen_banco_id
               WHERE s.estado = 'aprobado' AND (s.visible = 1 OR s.visible IS NULL) AND a.bloqueado = 0 ";

 // [NUBIRA 2.0] Título fijo. La afinidad sigue activa en el ORDER BY (sin frases variables en UI).
$titulo_servicios = "Tutorías recomendadas";

if ($cat_favorita) {
    $sql_servicios .= "ORDER BY tiene_foto_real DESC, tiene_video DESC, tiene_horario DESC, CASE WHEN s.categoria = ? THEN 1 ELSE 2 END, RAND($seed) LIMIT 8";
    $stmt_serv = $conn->prepare($sql_servicios);
    $stmt_serv->bind_param("s", $cat_favorita);
    $stmt_serv->execute();
    $res_servicios = $stmt_serv->get_result();
} else {
    $sql_servicios .= "ORDER BY tiene_foto_real DESC, tiene_video DESC, tiene_horario DESC, {$orden_institucion_sql} RAND($seed) LIMIT 8";
    $res_servicios = $conn->query($sql_servicios);
}
} catch (Exception $e) {}

// [DEDUP] Pool de IDs de servicios ya mostrados en esta carga (recomendadas no excluye nada, elige primero)
$ids_servicios_usados = [0];
if ($res_servicios) {
    while ($row_dedup = $res_servicios->fetch_assoc()) {
        $ids_servicios_usados[] = (int)$row_dedup['id'];
    }
    $res_servicios->data_seek(0); // rebobinar: el render más abajo vuelve a leer este mismo result
}

// --- [NUBIRA 2.0] CONSULTA: RECIÉN LLEGADOS ---
$res_nuevos = null;
try {
    $placeholders_nuevos = implode(',', array_fill(0, count($ids_servicios_usados), '?'));
    $sql_nuevos = "SELECT s.*, s.oferta_termino,
                          COALESCE(dp.institucion, a.institucion) as institucion_maestra,
                          (SELECT COUNT(*) FROM contratos c WHERE c.servicio_id = s.id AND c.calificacion_comprador > 0) as total_votos,
                          (SELECT AVG(c.calificacion_comprador) FROM contratos c WHERE c.servicio_id = s.id AND c.calificacion_comprador > 0) as rating_promedio,
                          a.foto_perfil,
                          a.nombre as nombre_tutor,
                          bi.archivo as banco_archivo,
                          {$sql_tiene_foto_real} AS tiene_foto_real,
                          {$sql_tiene_horario} AS tiene_horario
                   FROM servicios s
                   INNER JOIN alumnos a ON s.alumno_id = a.id
                   LEFT JOIN dominios_permitidos dp ON a.dominio = dp.dominio
                   LEFT JOIN banco_imagenes bi ON bi.id = s.imagen_banco_id
WHERE s.estado = 'aprobado' AND (s.visible = 1 OR s.visible IS NULL) AND a.bloqueado = 0
                   AND s.id NOT IN ($placeholders_nuevos)
                   ORDER BY tiene_foto_real DESC, tiene_horario DESC, {$orden_institucion_sql} s.id DESC LIMIT 8";
    $stmt_nuevos = $conn->prepare($sql_nuevos);
    $stmt_nuevos->bind_param(str_repeat('i', count($ids_servicios_usados)), ...$ids_servicios_usados);
    $stmt_nuevos->execute();
    $res_nuevos = $stmt_nuevos->get_result();

    if ($res_nuevos) {
        while ($row_dedup = $res_nuevos->fetch_assoc()) {
            $ids_servicios_usados[] = (int)$row_dedup['id'];
        }
        $res_nuevos->data_seek(0);
    }
} catch (Exception $e) {
    error_log("Error cargando recientes vitrina: " . $e->getMessage());
}

// --- [NUBIRA] TUTORES QUE RESPONDEN RÁPIDO (<60 min) ---
$res_rapidos   = null;
$count_rapidos = 0;
try {
    // Gate: tutores DISTINTOS que cumplen (debe ser >= 3 para mostrar la sección)
    // Cálculo on-demand desde respuestas_tutor (reemplaza a.tiempo_respuesta_promedio, que dependía del cron parado)
    $rg = $conn->query("SELECT COUNT(DISTINCT a.id) AS n
                        FROM alumnos a
                        WHERE EXISTS (SELECT 1 FROM servicios s
                                      WHERE s.alumno_id = a.id AND s.estado = 'aprobado' AND s.visible = 1)
                          AND a.bloqueado = 0
                          AND (SELECT AVG(rt.minutos_respuesta)
                               FROM respuestas_tutor rt
                               WHERE rt.tutor_id = a.id
                                 AND rt.creado_en > (NOW() - INTERVAL 30 DAY)
                                 AND rt.minutos_respuesta <= 1440) < 60");
    $count_rapidos = $rg ? (int)$rg->fetch_assoc()['n'] : 0;

    if ($count_rapidos >= 3) {
        $placeholders_rapidos = implode(',', array_fill(0, count($ids_servicios_usados), '?'));
        $sql_rapidos = "SELECT s.*, s.oferta_termino,
                               COALESCE(dp.institucion, a.institucion) as institucion_maestra,
                               (SELECT COUNT(*) FROM contratos c WHERE c.servicio_id = s.id AND c.calificacion_comprador > 0) as total_votos,
                               (SELECT AVG(c.calificacion_comprador) FROM contratos c WHERE c.servicio_id = s.id AND c.calificacion_comprador > 0) as rating_promedio,
                               a.foto_perfil,
                               a.nombre as nombre_tutor,
                               (SELECT AVG(rt.minutos_respuesta)
                                FROM respuestas_tutor rt
                                WHERE rt.tutor_id = a.id
                                  AND rt.creado_en > (NOW() - INTERVAL 30 DAY)
                                  AND rt.minutos_respuesta <= 1440) AS tiempo_resp_calculado,
                               bi.archivo as banco_archivo,
                               {$sql_tiene_foto_real} AS tiene_foto_real,
                               {$sql_tiene_horario} AS tiene_horario
                        FROM servicios s
                        INNER JOIN alumnos a ON s.alumno_id = a.id
                        LEFT JOIN dominios_permitidos dp ON a.dominio = dp.dominio
                        LEFT JOIN banco_imagenes bi ON bi.id = s.imagen_banco_id
                        WHERE s.estado = 'aprobado' AND s.visible = 1 AND a.bloqueado = 0
                          AND s.id NOT IN ($placeholders_rapidos)
                        HAVING tiempo_resp_calculado IS NOT NULL AND tiempo_resp_calculado < 60
                        ORDER BY tiene_foto_real DESC, tiene_horario DESC, tiempo_resp_calculado ASC, RAND($seed)
                        LIMIT 12";
        $stmt_rapidos = $conn->prepare($sql_rapidos);
        $stmt_rapidos->bind_param(str_repeat('i', count($ids_servicios_usados)), ...$ids_servicios_usados);
        $stmt_rapidos->execute();
        $res_rapidos = $stmt_rapidos->get_result();

        if ($res_rapidos) {
            while ($row_dedup = $res_rapidos->fetch_assoc()) {
                $ids_servicios_usados[] = (int)$row_dedup['id'];
            }
            $res_rapidos->data_seek(0);
        }
    }
} catch (Exception $e) {
    error_log("Error cargando tutores rápidos vitrina: " . $e->getMessage());
}

// --- [NUBIRA 2.0] CLASES PAES (fija todo el año, no estacional) ---
$res_clases_paes = null;
try {
    $placeholders_paes_cl = implode(',', array_fill(0, count($ids_servicios_usados), '?'));
    $termino_paes = '%PAES%';
    $sql_clases_paes = "SELECT s.*, s.oferta_termino,
                               COALESCE(dp.institucion, a.institucion) as institucion_maestra,
                               (SELECT COUNT(*) FROM contratos c WHERE c.servicio_id = s.id AND c.calificacion_comprador > 0) as total_votos,
                               (SELECT AVG(c.calificacion_comprador) FROM contratos c WHERE c.servicio_id = s.id AND c.calificacion_comprador > 0) as rating_promedio,
                               a.foto_perfil,
                               a.nombre as nombre_tutor,
                               bi.archivo as banco_archivo,
                               {$sql_tiene_foto_real} AS tiene_foto_real,
                               {$sql_tiene_horario} AS tiene_horario,
                               {$sql_tiene_video} AS tiene_video
                        FROM servicios s
                        INNER JOIN alumnos a ON s.alumno_id = a.id
                        LEFT JOIN dominios_permitidos dp ON a.dominio = dp.dominio
                        LEFT JOIN banco_imagenes bi ON bi.id = s.imagen_banco_id
                        WHERE s.estado = 'aprobado' AND (s.visible = 1 OR s.visible IS NULL) AND a.bloqueado = 0
                          AND (s.titulo LIKE ? OR s.descripcion LIKE ? OR s.categoria LIKE ? OR s.materia LIKE ? OR s.asignatura LIKE ? OR s.area LIKE ? OR s.es_paes = 1)
                          AND s.id NOT IN ($placeholders_paes_cl)
                        ORDER BY tiene_foto_real DESC, tiene_video DESC, tiene_horario DESC, {$orden_institucion_sql} RAND($seed) LIMIT 12";
    $stmt_clases_paes = $conn->prepare($sql_clases_paes);
    $tipos_paes = 'ssssss' . str_repeat('i', count($ids_servicios_usados));
    $params_paes = array_merge([$termino_paes, $termino_paes, $termino_paes, $termino_paes, $termino_paes, $termino_paes], $ids_servicios_usados);
    $stmt_clases_paes->bind_param($tipos_paes, ...$params_paes);
    $stmt_clases_paes->execute();
    $res_clases_paes = $stmt_clases_paes->get_result();

    if ($res_clases_paes && $res_clases_paes->num_rows >= 4) {
        while ($row_dedup = $res_clases_paes->fetch_assoc()) {
            $ids_servicios_usados[] = (int)$row_dedup['id'];
        }
        $res_clases_paes->data_seek(0);
    } else {
        $res_clases_paes = null; // menos de 4 disponibles → sección no se muestra
    }
} catch (Exception $e) {
    error_log("Error cargando clases PAES vitrina: " . $e->getMessage());
}

// --- [OPT-1] APUNTES: Estándar Nubira 2.0 ---
$res_apuntes = null;

// [NUBIRA 2.0] Título fijo. La afinidad sigue activa en el ORDER BY (sin frases variables en UI).
$titulo_apuntes = "Apuntes de los que aprobaron";

try {
    $sql_apuntes = "SELECT ap.*, 
                           a.foto_perfil, 
                           a.nombre as nombre_tutor,
                           COALESCE(dp.institucion, a.institucion) as institucion_maestra,
                           ap.descargas AS ventas_totales,
                           {$sql_tiene_foto_real} AS tiene_foto_real
                    FROM apuntes ap
                    INNER JOIN alumnos a ON ap.id_alumno = a.id
                    LEFT JOIN dominios_permitidos dp ON a.dominio = dp.dominio
                   WHERE ap.estado = 'aprobado' AND ap.nivel_academico != 'paes' ";

    if ($cat_favorita) {
        $sql_apuntes .= "ORDER BY tiene_foto_real DESC, CASE WHEN ap.categoria = ? THEN 1 ELSE 2 END, RAND($seed) LIMIT 10";
        $stmt_ap = $conn->prepare($sql_apuntes);
        $stmt_ap->bind_param("s", $cat_favorita);
        $stmt_ap->execute();
        $res_apuntes = $stmt_ap->get_result();
    } else {
        // Fallback: lo más popular de todo Nubira
        $sql_apuntes .= "ORDER BY tiene_foto_real DESC, {$orden_institucion_sql} RAND($seed) LIMIT 10";
        $res_apuntes = $conn->query($sql_apuntes);
    }
} catch (Exception $e) {
    error_log("Error cargando apuntes vitrina: " . $e->getMessage());
}

// --- [NUBIRA 2.0] APUNTES RECIÉN PUBLICADOS ---
// Ordenados por fecha (id DESC). Mantiene afinidad institucional silenciosa
// para usuarios y guests con device_id detectado.
$res_apuntes_nuevos = null;
try {
    $sql_apuntes_nuevos = "SELECT ap.*, 
                                  a.foto_perfil, 
                                  a.nombre as nombre_tutor,
                                  COALESCE(dp.institucion, a.institucion) as institucion_maestra,
                                  ap.descargas AS ventas_totales,
                                  {$sql_tiene_foto_real} AS tiene_foto_real
                           FROM apuntes ap
                           INNER JOIN alumnos a ON ap.id_alumno = a.id
                           LEFT JOIN dominios_permitidos dp ON a.dominio = dp.dominio
                           WHERE ap.estado = 'aprobado'
                           ORDER BY tiene_foto_real DESC, {$orden_institucion_sql} ap.id DESC LIMIT 8";
    $res_apuntes_nuevos = $conn->query($sql_apuntes_nuevos);
} catch (Exception $e) {
    error_log("Error cargando apuntes nuevos vitrina: " . $e->getMessage());
}
// --- [NUBIRA 2.0] APUNTES PAES ---
$res_apuntes_paes = null;
$mostrar_seccion_paes = false;
if (false) { // Sección desactivada — query deshabilitada. Para reactivar: quitar este if y descomentar línea interior
try {
    $sql_paes = "SELECT ap.*,
                        a.foto_perfil,
                        a.nombre as nombre_tutor,
                        COALESCE(dp.institucion, a.institucion) as institucion_maestra,
                        ap.descargas AS ventas_totales
                 FROM apuntes ap
                 INNER JOIN alumnos a ON ap.id_alumno = a.id
                 LEFT JOIN dominios_permitidos dp ON a.dominio = dp.dominio
                 WHERE ap.estado = 'aprobado'
                   AND ap.nivel_academico = 'paes'
                 ORDER BY ap.descargas DESC, ap.id DESC LIMIT 8";
    $res_apuntes_paes = $conn->query($sql_paes);

    if ($res_apuntes_paes && $res_apuntes_paes->num_rows >= 3) {
        // $mostrar_seccion_paes = true; // OCULTO: sección Apuntes PAES removida de la home — vive en /apuntes
    }
} catch (Exception $e) {
    error_log("Error cargando apuntes PAES: " . $e->getMessage());
}
} // end if(false) PAES
// --- CONSULTA DE OFERTAS Y RELLENO PERSISTENTE ---
$lista_ofertas = [];
$tiene_ofertas_activas = false;
$ids_usados = $ids_servicios_usados; // [DEDUP] arranca con lo ya mostrado en recomendadas+nuevas+rápidas

try {
    $sql_ofertas = "SELECT s.*, s.oferta_termino,
                           COALESCE(dp.institucion, a.institucion) as institucion_maestra,
                           (SELECT COUNT(*) FROM contratos c WHERE c.servicio_id = s.id AND c.calificacion_comprador > 0) as total_votos,
                           (SELECT AVG(c.calificacion_comprador) FROM contratos c WHERE c.servicio_id = s.id AND c.calificacion_comprador > 0) as rating_promedio,
                           a.foto_perfil, a.nombre as nombre_tutor,
                           bi.archivo as banco_archivo,
                           {$sql_tiene_foto_real} AS tiene_foto_real,
                           {$sql_tiene_horario} AS tiene_horario
                    FROM servicios s
                    INNER JOIN alumnos a ON s.alumno_id = a.id
                    LEFT JOIN dominios_permitidos dp ON a.dominio = dp.dominio
                    LEFT JOIN banco_imagenes bi ON bi.id = s.imagen_banco_id
                    WHERE s.estado = 'aprobado' AND s.is_subvencionado = 1 AND a.bloqueado = 0
                      AND (s.oferta_termino IS NULL OR s.oferta_termino >= CURDATE())
                    ORDER BY tiene_foto_real DESC, tiene_horario DESC, (s.cupos_oferta > 0) DESC, RAND($seed) LIMIT 12";
    $res_ofertas = $conn->query($sql_ofertas);

    if ($res_ofertas) {
        while ($r = $res_ofertas->fetch_assoc()) {
            $lista_ofertas[] = $r;
            $ids_usados[] = (int)$r['id'];
            if (($r['cupos_oferta'] ?? 0) > 0) $tiene_ofertas_activas = true;
        }
    }

    if ($tiene_ofertas_activas && count($lista_ofertas) < 6) {
        $faltan = min(3, 6 - count($lista_ofertas));
        $placeholders = implode(',', array_fill(0, count($ids_usados), '?'));
        $types_relleno = str_repeat('i', count($ids_usados)) . 'i';
        
      $sql_relleno = "SELECT s.*, COALESCE(dp.institucion, a.institucion) as institucion_maestra,
                               (SELECT COUNT(*) FROM contratos c WHERE c.servicio_id = s.id AND c.calificacion_comprador > 0) as total_votos,
                               (SELECT AVG(c.calificacion_comprador) FROM contratos c WHERE c.servicio_id = s.id AND c.calificacion_comprador > 0) as rating_promedio,
                               a.foto_perfil, a.nombre as nombre_tutor,
                               bi.archivo as banco_archivo,
                               {$sql_tiene_foto_real} AS tiene_foto_real,
                               {$sql_tiene_horario} AS tiene_horario
                        FROM servicios s
                        INNER JOIN alumnos a ON s.alumno_id = a.id
                        LEFT JOIN dominios_permitidos dp ON a.dominio = dp.dominio
                        LEFT JOIN banco_imagenes bi ON bi.id = s.imagen_banco_id
                        WHERE s.estado = 'aprobado' AND a.bloqueado = 0 AND s.id NOT IN ($placeholders)
                        ORDER BY tiene_foto_real DESC, tiene_horario DESC, s.id ASC LIMIT ?";
        $stmt_relleno = $conn->prepare($sql_relleno);
        if ($stmt_relleno) {
            $params_relleno = array_merge($ids_usados, [$faltan]);
            $stmt_relleno->bind_param($types_relleno, ...$params_relleno);
            $stmt_relleno->execute();
            $res_relleno = $stmt_relleno->get_result();
            if($res_relleno) {
                while ($relleno = $res_relleno->fetch_assoc()) {
                    $relleno['es_falsa_oferta'] = true;
                    $lista_ofertas[] = $relleno;
                }
            }
            $stmt_relleno->close();
        }
    }
} catch (Exception $e) {}

$institucion = strtolower(trim($_SESSION['institucion'] ?? ''));
$rol = $_SESSION['rol'] ?? 'visitante';
$es_admin = ($rol === 'admin');

if (!function_exists('abreviar_conteo')) {
    function abreviar_conteo($n) {
        $n = (int)$n;
        if ($n >= 1000000) return round($n / 1000000, 1) . 'M';
        if ($n >= 1000) return round($n / 1000, 1) . 'K';
        return (string)$n;
    }
}

if (!function_exists('safe_img_path')) {
    function safe_img_path(string $p): string {
        static $cache_mtime = [];
        $p = str_replace(['\\'], '/', $p);
        if (preg_match('#^(?:https?:)?//#i', $p)) return '/img/invalid.png';
        if (!str_starts_with($p, '/')) $p = '/uploads/banners/' . ltrim($p, '/');
        $ruta_fisica = $_SERVER['DOCUMENT_ROOT'] . $p;
        // Cache estático: evita realpath() y filemtime() duplicados en la misma request
        if (isset($cache_mtime[$ruta_fisica])) {
            return $cache_mtime[$ruta_fisica];
        }
        $real = realpath($ruta_fisica);
        if ($real === false || strpos($real, realpath($_SERVER['DOCUMENT_ROOT'])) !== 0) {
            return $cache_mtime[$ruta_fisica] = '/img/invalid.png';
        }
        return $cache_mtime[$ruta_fisica] = $p . '?v=' . filemtime($real);
    }
}

$ruta_actual = $_SERVER['REQUEST_URI'] ?? '/';
if (!function_exists('nav_class')) {
    function nav_class(string $path): string {
        global $ruta_actual;
        $base = 'group flex items-center gap-3 px-3 py-2 text-sm font-medium rounded-lg transition-all border border-transparent';
        if ($path === '/explorar' && ($ruta_actual === '/explorar' || $ruta_actual === '/')) return $base . ' bg-blue-50 text-[#54A6D8] border-blue-100';
        return $base . (strpos($ruta_actual, $path) !== false ? ' bg-blue-50 text-[#54A6D8] border-blue-100' : ' text-gray-500 hover:bg-gray-50 hover:text-gray-900');
    }
}

require_once __DIR__ . '/helpers/institucion.php';

// [DEUDA FASE E] Resolvers legacy (resolver_portada_servicio / resolver_srcset_servicio):
// superados por url_portada() / srcset_portada() en helpers/imagen_servicio.php.
// Ya NO se usan en esta vista; se conservan temporalmente hasta limpiar todos los consumidores.
if (!function_exists('resolver_portada_servicio')) {
    /**
     * NUBIRA 2.0 — Resolver portada con tamaño responsivo
     * 
     * @param string|null $imagen   Nombre del archivo principal (ej: serv_xxx.webp)
     * @param string      $tamano   'thumb' (240px) | 'card' (480px) | 'main' (1200px)
     * @return string               URL relativa con cache busting
     */
    function resolver_portada_servicio(?string $imagen, string $tamano = 'thumb'): string {
        static $cache_serv = [];
        $default = 'https://nubira.cl/upload/servicios/default_clases.webp';
        
        if (empty($imagen)) return $default;
        
        $key = basename($imagen) . '|' . $tamano;
        if (isset($cache_serv[$key])) return $cache_serv[$key];
        
        $base = pathinfo(basename($imagen), PATHINFO_FILENAME);
        $docRoot = $_SERVER['DOCUMENT_ROOT'];
        
        $sufijo = ($tamano === 'main') ? '' : '_' . $tamano;
        $archivo_pref = $base . $sufijo . '.webp';
        $ruta_pref = '/upload/servicios/' . $archivo_pref;
        $ruta_fis_pref = $docRoot . $ruta_pref;
        
        if (file_exists($ruta_fis_pref)) {
            return $cache_serv[$key] = $ruta_pref . '?v=' . filemtime($ruta_fis_pref);
        }
        
        $ruta_main = '/upload/servicios/' . basename($imagen);
        $ruta_fis_main = $docRoot . $ruta_main;
        if (file_exists($ruta_fis_main)) {
            return $cache_serv[$key] = $ruta_main . '?v=' . filemtime($ruta_fis_main);
        }
        
        return $cache_serv[$key] = $default;
    }
}

/**
 * NUBIRA 2.0 — Helper srcset para servicios
 * Devuelve las 3 versiones (thumb/card/main) listas para el atributo srcset.
 * Si una versión no existe, hace fallback a la disponible.
 */
if (!function_exists('resolver_srcset_servicio')) {
    function resolver_srcset_servicio(?string $imagen): array {
        return [
            'thumb' => resolver_portada_servicio($imagen, 'thumb'),  // 240w
            'card'  => resolver_portada_servicio($imagen, 'card'),   // 480w
            'main'  => resolver_portada_servicio($imagen, 'main'),   // 1200w
        ];
    }
}
if (!function_exists('resolver_portada_apunte')) {
    function resolver_portada_apunte(array $row_ap): string {
        static $cache_ap = [];
        $default = 'https://nubira.cl/upload/servicios/default_clases.webp';
        $id_ap = (int)$row_ap['id'];
        // Cache por id de apunte dentro de la misma request
        if (isset($cache_ap[$id_ap])) return $cache_ap[$id_ap];
        $port_ap = $row_ap['portada'] ?? '';
        $arch_ap = $row_ap['archivo'] ?? '';
        $docRoot = $_SERVER['DOCUMENT_ROOT'];
        $rutas_posibles = [
            !empty($port_ap) ? "/upload/portadas/" . basename($port_ap) : "",
            !empty($port_ap) ? "/upload/preview/" . basename($port_ap) : "",
            "/upload/preview/{$id_ap}.webp",
            "/upload/preview/{$id_ap}.jpg",
            "/upload/preview/{$id_ap}.png"
        ];
        if (!empty($arch_ap) && in_array(strtolower(pathinfo($arch_ap, PATHINFO_EXTENSION)), ['jpg','jpeg','png','webp'])) {
            $rutas_posibles[] = "/upload/apuntes/" . basename($arch_ap);
        }
        foreach ($rutas_posibles as $rp) {
            if ($rp !== "" && file_exists($docRoot . $rp)) {
                return $cache_ap[$id_ap] = $rp . '?v=' . filemtime($docRoot . $rp);
            }
        }
        return $cache_ap[$id_ap] = $default;
    }
}

if (!function_exists('render_rating_html')) {
    function render_rating_html(float $rating_val, int $total_votos, string $fallback_label = 'Nuevo'): string {
        if ($total_votos > 0) {
            return '<div class="flex items-center gap-1 bg-gray-50 px-1.5 py-0.5 rounded border border-gray-100">
                        <svg class="w-3 h-3 text-gray-900 pb-[1px]" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path></svg>
                        <span class="text-[10px] font-light tracking-[0.01em] text-gray-800 leading-none">'.number_format($rating_val, 1).'</span>
                    </div>';
        }
        return '';
    }
}
// Banner Seguro
$banner_inline = null;
try {
    $sql_b = "SELECT id, titulo, imagen, enlace FROM banners WHERE activo=1 AND posicion='vitrina_inline'";
    $params = [];
    $types = "";
    if (!$es_admin && $institucion !== '') {
        $sql_b .= " AND institucion = ?";
        $params[] = $institucion;
        $types .= "s";
    }
    $sql_b .= " ORDER BY orden ASC LIMIT 1";
    $stmt_b = $conn->prepare($sql_b);
    if ($stmt_b) {
        if (!empty($params)) {
            $stmt_b->bind_param($types, ...$params);
        }
        $stmt_b->execute();
        $res_b = $stmt_b->get_result();
        if ($res_b && $res_b->num_rows > 0) {
            $banner_inline = $res_b->fetch_assoc();
        }
        $stmt_b->close();
    }
} catch(Exception $e) {}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <?php require_once __DIR__ . '/helpers/seo.php'; echo nubira_seo_meta('Nubira — Tutores, apuntes y clases particulares universitarias en Chile', 'Encuentra tutores verificados con correo institucional, apuntes universitarios y clases particulares en Chile. Pagos protegidos con Garantía Nubira.'); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
    <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
    <?php require_once __DIR__ . '/helpers/seo.php'; echo nubira_canonical_tag('/explorar'); ?>
    <!-- [NUBIRA 2.0] Preconnect al CDN de imágenes por defecto -->
<link rel="preconnect" href="https://nubira.cl" crossorigin>

<!-- [NUBIRA 2.0] Preload de imagen fallback default (la más usada) -->
<link rel="preload" as="image" href="https://nubira.cl/upload/servicios/default_clases.webp" fetchpriority="high">
    <link rel="preconnect" href="https://cdn.tailwindcss.com" crossorigin>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

   <!-- [NUBIRA 2.0] Preconnect a Tailwind CDN para acelerar descarga -->
<link rel="dns-prefetch" href="https://cdn.tailwindcss.com">
<script src="https://cdn.tailwindcss.com?plugins=forms,typography,aspect-ratio"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        nubira: '#54A6D8', 
                    }
                }
            }
        }
    </script>

    <!-- [NUBIRA 2.0] Fuente Inter optimizada - carga directa sin trick de media="print" -->
<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" onload="this.onload=null;this.rel='stylesheet'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap"></noscript>
    
   <style>
        html, body { background-color: #ffffff; }
        body { -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        .pb-safe { padding-bottom: env(safe-area-inset-bottom); }
.compact-root { align-items: stretch; }
.compact-root > * { 
    flex: 0 0 auto !important; 
    height: auto; 
    transition: transform 0.2s ease; 
    will-change: transform; 
}

/* [NUBIRA 2.0] Fix definitivo del scroll horizontal en carruseles */
/* El bug: en flex+overflow-x con cards que tienen position:relative, */
/* el navegador puede arrancar el scroll en 0 ignorando el padding-left visual. */
/* Solución: scroll-padding-inline-start fuerza el respiro al inicio del scroll. */
#carrusel-ia,
#sec-rapidos,
#sec-recientes,
#sec-nuevos,
#sec-servicios,
#sec-apuntes,
#sec-apuntes-nuevos,
#sec-apuntes-paes,
#sec-ofertas {
    scroll-padding-inline-start: 16px;
}
@media (min-width: 768px) {
    #carrusel-ia,
    #sec-rapidos,
    #sec-recientes,
    #sec-nuevos,
    #sec-servicios,
    #sec-apuntes,
    #sec-apuntes-nuevos,
    #sec-apuntes-paes,
    #sec-ofertas {
        scroll-padding-inline-start: 40px;
    }
}

/* [NUBIRA 2.0] Ajuste óptico fino de títulos en escritorio */
/* Compensa la sensación visual de desalineación entre títulos y contenido de cards. */
/* Solo aplica en escritorio; móvil queda intacto. */
@media (min-width: 768px) {
    main > section h2 {
        margin-left: -8px;
    }
}
        .animate-fade-in-up { animation: fadeInUp 0.5s ease-out forwards; }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }

        /* [NUBIRA 2.0] NITIDEZ + FEEL APP NATIVA */
        img:not(.avatar-tutor) {
            image-rendering: -webkit-optimize-contrast;
            transform: translateZ(0);
            backface-visibility: hidden;
        }
        * { -webkit-tap-highlight-color: transparent; }
        body { -webkit-overflow-scrolling: touch; overscroll-behavior-y: contain; }
        input, textarea, select { font-size: 16px; }

    </style>
</head>

<body class="bg-white text-gray-900 antialiased overflow-x-hidden fouc-lock">

    <?php require_once __DIR__ . '/componentes/loader_nativo.php'; ?>

<?php
$page_title = "Vitrina Principal";
require_once __DIR__ . '/componentes/header.php'; 
?>
    <?php require_once __DIR__ . '/componentes/sidebar.php'; ?>

<main data-track-id="home" data-track-type="vitrina"
      class="pt-16 md:pt-20 pb-36 md:pb-0 lg:ml-56 max-w-full mx-auto block">
    <div class="px-4 md:px-10 md:pl-12 pt-0 pb-0 md:pt-1 md:pb-2">
      <h1 class="sr-only md:not-sr-only text-xl md:text-2xl font-medium text-[#222222] tracking-[-0.01em]">Tutores, apuntes y clases particulares universitarias en Chile</h1>
    </div>

<?php if (!$is_guest): ?>
    <section id="sec-recientes-wrapper" class="mb-3 md:mb-5 relative animate-fade-in-up transition-all duration-500 delay-100">
      <div class="flex items-end justify-between mb-3 px-4 md:px-10 md:pl-11">
        <h2 class="text-lg md:text-xl font-medium text-[#222222] tracking-[-0.01em]">Sigue donde lo dejaste</h2>
        </div>
        <div class="relative group">
            <button onclick="scrollCarrusel('sec-recientes', -1)" class="hidden md:flex absolute left-2 opacity-0 group-hover:opacity-100 transition-opacity duration-200 top-[40%] -translate-y-1/2 w-10 h-10 bg-white rounded-full shadow-[0_1px_3px_rgba(0,0,0,0.04)] hover:shadow-[0_2px_8px_rgba(0,0,0,0.06)] items-center justify-center z-10 text-gray-400 hover:text-[#54A6D8] border border-[#f0f0f0] focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2 transition-[transform,color,box-shadow] duration-150 ease-out hover:scale-110"><i class="fa-solid fa-chevron-left text-xs"></i></button>
<div id="sec-recientes" class="flex gap-4 overflow-x-auto snap-x snap-mandatory pb-3 pt-1 no-scrollbar scroll-smooth pl-4 pr-4 md:pl-10 md:pr-10 min-h-[108px] md:min-h-[124px] items-stretch" data-src="/app/cargar_vistos.php" data-hide-target="#sec-recientes-wrapper">
                    <?php for($i=0; $i<4; $i++): ?>
                    <div class="flex-shrink-0 w-[220px] md:w-[300px] h-[96px] md:h-[112px] bg-white rounded-2xl border border-gray-100 p-2 md:p-3 flex gap-3 snap-start opacity-60 overflow-hidden">
                    <div class="w-20 h-20 md:w-24 md:h-24 rounded-xl bg-gray-100 flex-shrink-0 animate-pulse self-center"></div>
                    <div class="flex flex-col justify-center w-full gap-2">
                        <div class="h-2 bg-gray-200 rounded w-1/3 animate-pulse"></div>
                        <div class="h-2.5 bg-gray-200 rounded w-full animate-pulse"></div>
                        <div class="h-2.5 bg-gray-200 rounded w-2/3 animate-pulse mt-0.5"></div>
                    </div>
                    </div>
                    <?php endfor; ?>
            </div>
            <button onclick="scrollCarrusel('sec-recientes', 1)" class="hidden md:flex absolute right-2 opacity-0 group-hover:opacity-100 transition-opacity duration-200 top-[40%] -translate-y-1/2 w-10 h-10 bg-white rounded-full shadow-[0_1px_3px_rgba(0,0,0,0.04)] hover:shadow-[0_2px_8px_rgba(0,0,0,0.06)] items-center justify-center z-10 text-gray-400 hover:text-[#54A6D8] border border-[#f0f0f0] focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2 transition-[transform,color,box-shadow] duration-150 ease-out hover:scale-110"><i class="fa-solid fa-chevron-right text-xs"></i></button>
        </div>
    </section>
<?php endif; ?>

<section class="mb-3 md:mb-5 relative ">
<div class="flex items-end justify-between mb-3 px-4 md:px-10 md:pl-11 gap-3">
        <h2 class="text-lg md:text-xl font-medium text-[#222222] tracking-[-0.01em]"><?= $titulo_servicios ?></h2>
        <a href="/servicios" class="text-xs font-medium text-[#54A6D8] hover:underline hover:bg-[#eef6fb] transition-colors duration-150 ease-out bg-gray-50 px-3 py-1.5 rounded-2xl border border-[#f0f0f0] flex items-center gap-1 shrink-0 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2">Ver todo <?= icon('arrow-right', 'w-3 h-3') ?></a>
    </div>
    
    <div class="relative group">
        <button onclick="scrollCarrusel('sec-servicios', -1)" class="hidden md:flex absolute left-2 opacity-0 group-hover:opacity-100 transition-opacity duration-200 top-[40%] -translate-y-1/2 w-10 h-10 bg-white rounded-full shadow-[0_1px_3px_rgba(0,0,0,0.04)] hover:shadow-[0_2px_8px_rgba(0,0,0,0.06)] items-center justify-center z-10 text-gray-400 hover:text-[#54A6D8] border border-[#f0f0f0] focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2 transition-[transform,color,box-shadow] duration-150 ease-out hover:scale-110"><i class="fa-solid fa-chevron-left text-xs"></i></button>
        
        <div id="sec-servicios" class="flex gap-4 md:gap-5 overflow-x-auto snap-x snap-mandatory pb-3 no-scrollbar scroll-smooth compact-root pl-4 pr-4 md:pl-10 md:pr-10">
            <?php if ($res_servicios && $res_servicios->num_rows > 0): 
                $idx_serv = 0; // [NUBIRA 2.0] Contador para priorizar LCP en primeras cards
            ?>
                <?php while ($row = $res_servicios->fetch_assoc()): 
                    $idx_serv++;
                    $esRecomendado = ($es_recomendacion_inmediata && $row['categoria'] === $cat_favorita);
                    $es_lcp_serv = ($idx_serv <= 2); // Primeras 2 cards = prioridad alta
                    $link_hash = url_servicio((int)$row['id'], $row['slug'] ?? null);
                    $portada_set = srcset_portada($row);
$portada_url = $portada_set['card'];
                    
                    $rating_val = isset($row['rating_promedio']) ? (float)$row['rating_promedio'] : 0;
                    $total_v = isset($row['total_votos']) ? (int)$row['total_votos'] : 0;
                    
                    // --- LÓGICA DE ESCALAFONES DE STATUS (TIERS NUBIRA 2.0) ---
                    $score = (int)($row['score_nubira'] ?? 0);
                    $nivel_tutor = '';
                    $es_basico = ($score < 60);

                    if ($score >= 100 && $total_v >= 10 && $rating_val >= 4.7) {
                        $nivel_tutor = 'leyenda';
                    } elseif ($score >= 80 && $total_v >= 3 && $rating_val >= 4.0) {
                        $nivel_tutor = 'elite';
                    } elseif ($score >= 80) {
                        $nivel_tutor = 'pro';
                    } elseif ($score >= 60) {
                        $nivel_tutor = 'top';
                    }
                    
                    // --- LÓGICA DE AVATAR Y TUTOR ---
                    $nombre_completo = !empty($row['nombre_tutor']) ? $row['nombre_tutor'] : 'Profesor';
                    $partes_nombre = array_values(array_filter(explode(' ', trim((string)$nombre_completo))));
                    $tutor_nombre = "Profesor";
                    if (!empty($partes_nombre[0])) {
                        $tutor_nombre = ucwords(strtolower($partes_nombre[0]));
                        if (count($partes_nombre) >= 2) {
                            $tutor_nombre .= ' ' . strtoupper(substr($partes_nombre[count($partes_nombre)-1], 0, 1)) . '.';
                        }
                    }
                    $foto_tutor = !empty($row['foto_perfil']) ? '/app/perfil/fotos/' . $row['foto_perfil'] : "https://ui-avatars.com/api/?name=".urlencode($tutor_nombre)."&background=f1f5f9&color=64748b&size=128";

                    // [OVERLAY NUBIRA] avatar tutor + categoría sobre la portada
                    $tutor_nombre_ov = $row['nombre_tutor'] ?? '';
                    $foto_field_ov   = $row['foto_perfil'] ?? '';
                    $foto_tutor_ov   = !empty($foto_field_ov) ? '/app/perfil/fotos/' . $foto_field_ov : 'https://ui-avatars.com/api/?name=' . urlencode($tutor_nombre_ov) . '&background=54A6D8&color=fff&size=128&bold=true';
                    $categoria_overlay = $row['categoria'] ?? 'Otros';
                    $prefijo_overlay = in_array($categoria_overlay, ['Otros','Asesoría']) ? '' : 'Clase de';
                    $nombre_categoria_overlay = ($categoria_overlay === 'Otros') ? 'Clase' : $categoria_overlay;

                    $es_nuevo = false;
                    if (!empty($row['fecha_publicacion']) && $row['fecha_publicacion'] !== '0000-00-00 00:00:00' && $row['fecha_publicacion'] !== '0000-00-00') {
                        try { $es_nuevo = (new DateTime())->diff(new DateTime($row['fecha_publicacion']))->days <= 7; } catch (Throwable $e) {}
                    }
                    
                   // --- LÓGICA DE PRECIOS Y OFERTAS (NUBIRA 2.0) ---
                    $es_oferta = oferta_vigente($row);
                    $precio_normal = $row['precio'] ?? 0;
                    $pct_descuento = ($es_oferta && (int)$precio_normal > 0) ? round(((int)$precio_normal - (int)$row['precio_oferta']) / (int)$precio_normal * 100) : 0;
                    
                    if ($es_oferta) {
                        $precio_html = "<span class='line-through text-gray-400 text-[10px] md:text-xs font-medium mr-1'>$" . number_format($precio_normal, 0, ',', '.') . "</span><span class='text-gray-600 font-normal tracking-tight'>$" . number_format($row['precio_oferta'], 0, ',', '.') . "</span>" . ($pct_descuento > 0 ? "<span class='bg-green-600 text-white text-[9px] font-semibold px-1 py-px rounded ml-1.5 leading-none relative -top-0.5'>-{$pct_descuento}%</span>" : "");
                        $precio_class = "text-[#222222] font-normal tracking-[-0.01em]";
                    } else if (is_numeric($precio_normal) && $precio_normal > 0) {
                        $precio_html = "$" . number_format($precio_normal, 0, ',', '.');
                        $precio_class = "text-[#222222] font-normal tracking-[-0.01em]";
                    } else {
                        $precio_html = "Gratis";
                        $precio_class = "text-[#222222] font-normal tracking-[-0.01em]";
                    }
                    
                    $mod = strtolower($row['modalidad'] ?? '');
                    $icon_mod = '<i class="fa-solid fa-laptop text-[10px]"></i>';
                    if (strpos($mod,'online')!==false) $icon_mod = '<i class="fa-solid fa-wifi text-[10px]"></i>';
                    elseif (strpos($mod,'presencial')!==false) $icon_mod = '<i class="fa-solid fa-user-group text-[10px]"></i>';
                    
                    $html_stars = render_rating_html($rating_val, $total_v);
                    $inst_text = institucion_tutor($row['institucion_maestra'] ?? ($row['institucion'] ?? ''));
                ?>
                
                <a href="<?= $link_hash ?>" onclick="registrarClick(<?= (int)$row['id'] ?>, 'servicio')"
                   class="block flex flex-col cursor-pointer group snap-center w-[220px] md:w-[240px] flex-shrink-0 bg-transparent h-full <?php echo $es_basico ? 'opacity-90 grayscale-[15%]' : ''; ?>">

                    <div class="relative w-full aspect-[4/3] bg-gray-100 overflow-hidden rounded-2xl border border-[#f0f0f0] shadow-[0_1px_3px_rgba(0,0,0,0.04)] transition-all">
                      <img src="<?= htmlspecialchars($portada_url) ?>" 
     srcset="<?= htmlspecialchars($portada_set['thumb']) ?> 240w,
             <?= htmlspecialchars($portada_set['card']) ?> 480w,
             <?= htmlspecialchars($portada_set['main']) ?> 1200w"
     sizes="(max-width: 640px) 220px, 240px"
     alt="<?= htmlspecialchars($row['titulo']) ?>" 
     class="w-full h-full object-cover" 
     loading="<?= $es_lcp_serv ? 'eager' : 'lazy' ?>" 
     decoding="async" 
     <?= $es_lcp_serv ? 'fetchpriority="high"' : '' ?> 
     width="240" height="180"
     onerror="this.onerror=null;this.src='https://nubira.cl/upload/servicios/default_clases.webp';">

                       <?php
                       $ov_prefijo   = $prefijo_overlay;
                       $ov_categoria = $nombre_categoria_overlay;
                       $ov_foto      = $foto_tutor;
                       $ov_nombre    = $tutor_nombre;
                       $ov_size      = 'lg';
                       $ov_liviano   = true;
                       include __DIR__ . '/componentes/overlay_card_servicio.php';
                       ?>

                       <!-- Badge nivel (derecha) - oculto en ofertas para no chocar con el badge de cupos -->
                       <?php if (empty($es_oferta)): ?>
                       <div class="absolute top-1 right-1 z-10">
                            <?php if ($nivel_tutor === 'leyenda'): ?>
                                <span class="inline-flex items-center px-1.5 py-0 md:px-2 md:py-0.5 rounded-full text-[9px] md:text-[10px] font-medium bg-white/95 backdrop-blur-sm text-[#222222] border border-[#f0f0f0] shadow-[0_1px_2px_rgba(0,0,0,0.08)]">Leyenda</span>
                            <?php elseif ($nivel_tutor === 'elite'): ?>
                                <span class="inline-flex items-center px-1.5 py-0 md:px-2 md:py-0.5 rounded-full text-[9px] md:text-[10px] font-medium bg-white/95 backdrop-blur-sm text-[#222222] border border-[#f0f0f0] shadow-[0_1px_2px_rgba(0,0,0,0.08)]">Élite</span>
                            <?php elseif ($nivel_tutor === 'pro'): ?>
                                <span class="inline-flex items-center px-1.5 py-0 md:px-2 md:py-0.5 rounded-full text-[9px] md:text-[10px] font-medium bg-white/95 backdrop-blur-sm text-[#222222] border border-[#f0f0f0] shadow-[0_1px_2px_rgba(0,0,0,0.08)]">Pro</span>
                            <?php elseif ($nivel_tutor === 'top'): ?>
                                <span class="inline-flex items-center px-1.5 py-0 md:px-2 md:py-0.5 rounded-full text-[9px] md:text-[10px] font-medium bg-white/95 backdrop-blur-sm text-[#222222] border border-[#f0f0f0] shadow-[0_1px_2px_rgba(0,0,0,0.08)]">Top</span>
                            <?php endif; ?>
                       </div>
                       <?php endif; ?>

                       <!-- Badge cupos (derecha) -->
                       <?php if ($es_oferta): ?>
                       <div class="absolute top-1 right-1 z-10">
                           <span class="inline-flex items-center px-1.5 py-0 md:px-2 md:py-0.5 rounded-full text-[9px] md:text-[10px] font-medium bg-amber-100 text-amber-900 border border-amber-200">
                               <?= (int)$row['cupos_oferta'] ?> <?= (int)$row['cupos_oferta'] === 1 ? 'cupo' : 'cupos' ?>
                           </span>
                       </div>
                       <?php endif; ?>
                    </div>

                    <div class="pt-2.5 flex flex-col flex-1 text-left">
                        <h3 class="font-medium text-[14px] leading-snug tracking-[-0.01em] text-[#222222] line-clamp-2 mb-1 min-h-[40px]"><?= htmlspecialchars($row['titulo']) ?></h3>
                      <div class="text-[14px] <?= $precio_class ?> mt-auto mb-1.5 leading-none"><?= $precio_html ?></div>
                        
                        <div class="flex items-center justify-between">
    <div class="flex items-center gap-1.5 text-[10px] font-normal tracking-[0.01em] text-gray-500 uppercase truncate max-w-[65%]">
        <?php if(!empty($inst_text)): ?><span class="truncate"><?= $inst_text ?></span><?php endif; ?>
    </div>
    <div class="shrink-0 flex items-center gap-1"><?= $html_stars ?></div>
</div>
                    </div>
                </a>
                <?php endwhile; ?>
            <?php endif; ?>
        </div>
        
        <button onclick="scrollCarrusel('sec-servicios', 1)" class="hidden md:flex absolute right-2 opacity-0 group-hover:opacity-100 transition-opacity duration-200 top-[40%] -translate-y-1/2 w-10 h-10 bg-white rounded-full shadow-[0_1px_3px_rgba(0,0,0,0.04)] hover:shadow-[0_2px_8px_rgba(0,0,0,0.06)] items-center justify-center z-10 text-gray-400 hover:text-[#54A6D8] border border-[#f0f0f0] focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2 transition-[transform,color,box-shadow] duration-150 ease-out hover:scale-110"><i class="fa-solid fa-chevron-right text-xs"></i></button>
    </div>
</section>

<?php
// [NUBIRA 2.0] Sección oculta temporalmente (30/07/2026): muy pocos tutores califican con
// cualquier umbral de tiempo de respuesta (diagnóstico de esta sesión: en producción el
// volumen es insuficiente y/o repite el mismo tutor). La query de arriba y el pool de
// deduplicación ($ids_servicios_usados) siguen corriendo sin cambios — esto SOLO oculta
// el render. Para reactivar: cambiar $mostrar_seccion_rapidos a true, o reemplazar esta
// condición cuando se decida la sección de reemplazo (ver criterios evaluados: foto+horario,
// descripción larga, apuntes, ofertas, score_nubira, recién publicados).
$mostrar_seccion_rapidos = false;
if ($mostrar_seccion_rapidos && $res_rapidos && $res_rapidos->num_rows > 0): ?>
<section class="mb-3 md:mb-5 relative animate-fade-in-up">
 <div class="mb-3 px-4 md:px-10 max-w-[1600px] mx-auto">
    <h2 class="text-lg md:text-xl font-medium text-[#222222] tracking-[-0.01em]">Responden en menos de 1 hora</h2>
</div>
    <div class="relative group">
        <button onclick="scrollCarrusel('sec-rapidos', -1)" class="hidden md:flex absolute left-2 opacity-0 group-hover:opacity-100 transition-opacity duration-200 top-[40%] -translate-y-1/2 w-10 h-10 bg-white rounded-full shadow-[0_1px_3px_rgba(0,0,0,0.04)] hover:shadow-[0_2px_8px_rgba(0,0,0,0.06)] items-center justify-center z-10 text-gray-400 hover:text-[#54A6D8] border border-[#f0f0f0] focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2 transition-[transform,color,box-shadow] duration-150 ease-out hover:scale-110"><i class="fa-solid fa-chevron-left text-xs"></i></button>
<div id="sec-rapidos" class="flex gap-4 md:gap-5 overflow-x-auto snap-x snap-mandatory pb-3 no-scrollbar scroll-smooth compact-root pl-4 pr-4 md:pl-10 md:pr-10">
                <?php $idx_r = 0; while ($row_r = $res_rapidos->fetch_assoc()):
                    $idx_r++;
                    $es_lcp_r = ($idx_r <= 2);
                    $link_hash_r = url_servicio((int)$row_r['id'], $row_r['slug'] ?? null);
                    $portada_set_r = srcset_portada($row_r);
                    $portada_url_r = $portada_set_r['card'];
                    $rating_val_r = isset($row_r['rating_promedio']) ? (float)$row_r['rating_promedio'] : 0;
                    $total_v_r = isset($row_r['total_votos']) ? (int)$row_r['total_votos'] : 0;
                    $nombre_completo_r = !empty($row_r['nombre_tutor']) ? $row_r['nombre_tutor'] : 'Profesor';
                    $partes_r = array_values(array_filter(explode(' ', trim((string)$nombre_completo_r))));
                    $tutor_nombre_r = "Profesor";
                    if (!empty($partes_r[0])) {
                        $tutor_nombre_r = ucwords(strtolower($partes_r[0]));
                        if (count($partes_r) >= 2) {
                            $tutor_nombre_r .= ' ' . strtoupper(substr($partes_r[count($partes_r)-1], 0, 1)) . '.';
                        }
                    }
                    $foto_tutor_r = !empty($row_r['foto_perfil']) ? '/app/perfil/fotos/' . $row_r['foto_perfil'] : "https://ui-avatars.com/api/?name=".urlencode($tutor_nombre_r)."&background=f1f5f9&color=64748b&size=128";
                    $categoria_overlay = $row_r['categoria'] ?? 'Otros';
                    $prefijo_overlay = in_array($categoria_overlay, ['Otros','Asesoría']) ? '' : 'Clase de';
                    $nombre_categoria_overlay = ($categoria_overlay === 'Otros') ? 'Clase' : $categoria_overlay;
                    $es_oferta_r = oferta_vigente($row_r);
                    $pct_r = ($es_oferta_r && (int)($row_r['precio'] ?? 0) > 0) ? round(((int)$row_r['precio'] - (int)$row_r['precio_oferta']) / (int)$row_r['precio'] * 100) : 0;
                    $precio_normal_r = $row_r['precio'] ?? 0;
                    if ($es_oferta_r) {
                        $precio_html_r = "<span class='line-through text-gray-400 text-[10px] md:text-xs font-medium mr-1'>$" . number_format($precio_normal_r, 0, ',', '.') . "</span><span class='text-gray-600 font-normal tracking-tight'>$" . number_format($row_r['precio_oferta'], 0, ',', '.') . "</span>" . ($pct_r > 0 ? "<span class='bg-green-600 text-white text-[9px] font-semibold px-1 py-px rounded ml-1.5 leading-none relative -top-0.5'>-{$pct_r}%</span>" : "");
                        $precio_class_r = "text-[#222222] font-normal tracking-[-0.01em]";
                    } else if (is_numeric($precio_normal_r) && $precio_normal_r > 0) {
                        $precio_html_r = "$" . number_format($precio_normal_r, 0, ',', '.');
                        $precio_class_r = "text-[#222222] font-normal tracking-[-0.01em]";
                    } else {
                        $precio_html_r = "Gratis";
                        $precio_class_r = "text-[#222222] font-normal tracking-[-0.01em]";
                    }
                    $html_stars_r = render_rating_html($rating_val_r, $total_v_r);
                    $inst_text_r = institucion_tutor($row_r['institucion_maestra'] ?? ($row_r['institucion'] ?? ''));
                ?>
                <a href="<?= $link_hash_r ?>" onclick="registrarClick(<?= (int)$row_r['id'] ?>, 'servicio')"
                   class="block flex flex-col cursor-pointer group snap-center w-[220px] md:w-[240px] flex-shrink-0 bg-transparent h-full">
                    <div class="relative w-full aspect-[4/3] bg-gray-100 overflow-hidden rounded-2xl border border-[#f0f0f0] shadow-[0_1px_3px_rgba(0,0,0,0.04)] transition-all">
                        <img src="<?= htmlspecialchars($portada_url_r) ?>"
     srcset="<?= htmlspecialchars($portada_set_r['thumb']) ?> 240w,
             <?= htmlspecialchars($portada_set_r['card']) ?> 480w,
             <?= htmlspecialchars($portada_set_r['main']) ?> 1200w"
     sizes="(max-width: 640px) 220px, 240px"
     alt="<?= htmlspecialchars($row_r['titulo']) ?>"
     class="w-full h-full object-cover transition-transform duration-500 ease-out group-hover:scale-105"
     loading="<?= $es_lcp_r ? 'eager' : 'lazy' ?>"
     decoding="async"
     <?= $es_lcp_r ? 'fetchpriority="high"' : '' ?>
     width="240" height="180"
     onerror="this.onerror=null;this.src='https://nubira.cl/upload/servicios/default_clases.webp';">
                       <?php
                       $ov_prefijo   = $prefijo_overlay;
                       $ov_categoria = $nombre_categoria_overlay;
                       $ov_foto      = $foto_tutor_r;
                       $ov_nombre    = $tutor_nombre_r;
                       $ov_size      = 'lg';
                       $ov_liviano   = true;
                       include __DIR__ . '/componentes/overlay_card_servicio.php';
                       ?>
                       <?php if ($es_oferta_r): ?>
                       <div class="absolute top-1 right-1 z-10">
                           <span class="inline-flex items-center px-1.5 py-0 md:px-2 md:py-0.5 rounded-full text-[9px] md:text-[10px] font-medium bg-amber-100 text-amber-900 border border-amber-200">
                               <?= (int)$row_r['cupos_oferta'] ?> <?= (int)$row_r['cupos_oferta'] === 1 ? 'cupo' : 'cupos' ?>
                           </span>
                       </div>
                       <?php endif; ?>
                    </div>
                    <div class="pt-2.5 flex flex-col flex-1 text-left">
                        <h3 class="font-medium text-[14px] leading-snug tracking-[-0.01em] text-[#222222] line-clamp-2 mb-1 min-h-[40px]"><?= htmlspecialchars($row_r['titulo']) ?></h3>
                    <div class="text-[14px] <?= $precio_class_r ?> mt-auto mb-1.5 leading-none"><?= $precio_html_r ?></div>
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-1.5 text-[10px] font-normal tracking-[0.01em] text-gray-500 uppercase truncate max-w-[65%]">
                                <?php if(!empty($inst_text_r)): ?><span class="truncate"><?= $inst_text_r ?></span><?php endif; ?>
                            </div>
                            <div class="shrink-0 flex items-center gap-1"><?= $html_stars_r ?></div>
                        </div>
                    </div>
                </a>
                <?php endwhile; ?>
        </div>
        <button onclick="scrollCarrusel('sec-rapidos', 1)" class="hidden md:flex absolute right-2 opacity-0 group-hover:opacity-100 transition-opacity duration-200 top-[40%] -translate-y-1/2 w-10 h-10 bg-white rounded-full shadow-[0_1px_3px_rgba(0,0,0,0.04)] hover:shadow-[0_2px_8px_rgba(0,0,0,0.06)] items-center justify-center z-10 text-gray-400 hover:text-[#54A6D8] border border-[#f0f0f0] focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2 transition-[transform,color,box-shadow] duration-150 ease-out hover:scale-110"><i class="fa-solid fa-chevron-right text-xs"></i></button>
    </div>
</section>
<?php endif; ?>

<section class="mb-3 md:mb-5 relative animate-fade-in-up">
 <div class="mb-3 px-4 md:px-10 md:pl-11">
    <h2 class="text-lg md:text-xl font-medium text-[#222222] tracking-[-0.01em]">Tutorías nuevas</h2>
</div>
    
    <div class="relative group">
        <button onclick="scrollCarrusel('sec-nuevos', -1)" class="hidden md:flex absolute left-2 opacity-0 group-hover:opacity-100 transition-opacity duration-200 top-[40%] -translate-y-1/2 w-10 h-10 bg-white rounded-full shadow-[0_1px_3px_rgba(0,0,0,0.04)] hover:shadow-[0_2px_8px_rgba(0,0,0,0.06)] items-center justify-center z-10 text-gray-400 hover:text-[#54A6D8] border border-[#f0f0f0] focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2 transition-[transform,color,box-shadow] duration-150 ease-out hover:scale-110"><i class="fa-solid fa-chevron-left text-xs"></i></button>
        
<div id="sec-nuevos" class="flex gap-4 md:gap-5 overflow-x-auto snap-x snap-mandatory pb-3 no-scrollbar scroll-smooth compact-root pl-4 pr-4 md:pl-10 md:pr-10">
            <?php if ($res_nuevos && $res_nuevos->num_rows > 0): 
                $idx_n = 0;
            ?>
                <?php while ($row_n = $res_nuevos->fetch_assoc()): 
                    $idx_n++;
                    $es_lcp_n = ($idx_n <= 2);
                    $link_hash_n = url_servicio((int)$row_n['id'], $row_n['slug'] ?? null);
                  $portada_set_n = srcset_portada($row_n);
$portada_url_n = $portada_set_n['card']; // src base = 480px (mejor calidad inicial)
                    $rating_val_n = isset($row_n['rating_promedio']) ? (float)$row_n['rating_promedio'] : 0;
                    $total_v_n = isset($row_n['total_votos']) ? (int)$row_n['total_votos'] : 0;
                    
                    // --- Avatar Logic ---
                    $nombre_completo_n = !empty($row_n['nombre_tutor']) ? $row_n['nombre_tutor'] : 'Profesor';
                    $partes_n = array_values(array_filter(explode(' ', trim((string)$nombre_completo_n))));
                    $tutor_nombre_n = "Profesor";
                    if (!empty($partes_n[0])) {
                        $tutor_nombre_n = ucwords(strtolower($partes_n[0]));
                        if (count($partes_n) >= 2) {
                            $tutor_nombre_n .= ' ' . strtoupper(substr($partes_n[count($partes_n)-1], 0, 1)) . '.';
                        }
                    }
                    $foto_tutor_n = !empty($row_n['foto_perfil']) ? '/app/perfil/fotos/' . $row_n['foto_perfil'] : "https://ui-avatars.com/api/?name=".urlencode($tutor_nombre_n)."&background=f1f5f9&color=64748b&size=128";

                    // [OVERLAY NUBIRA] avatar tutor + categoría sobre la portada
                    $tutor_nombre_ov = $row_n['nombre_tutor'] ?? '';
                    $foto_field_ov   = $row_n['foto_perfil'] ?? '';
                    $foto_tutor_ov   = !empty($foto_field_ov) ? '/app/perfil/fotos/' . $foto_field_ov : 'https://ui-avatars.com/api/?name=' . urlencode($tutor_nombre_ov) . '&background=54A6D8&color=fff&size=128&bold=true';
                    $categoria_overlay = $row_n['categoria'] ?? 'Otros';
                    $prefijo_overlay = in_array($categoria_overlay, ['Otros','Asesoría']) ? '' : 'Clase de';
                    $nombre_categoria_overlay = ($categoria_overlay === 'Otros') ? 'Clase' : $categoria_overlay;

// --- LÓGICA DE PRECIOS Y OFERTAS (NUBIRA 2.0) ---
                    $es_oferta_n = oferta_vigente($row_n);
                    $pct_n = ($es_oferta_n && (int)($row_n['precio'] ?? 0) > 0) ? round(((int)$row_n['precio'] - (int)$row_n['precio_oferta']) / (int)$row_n['precio'] * 100) : 0;
                    $precio_normal_n = $row_n['precio'] ?? 0;
                    
                    if ($es_oferta_n) {
                        $precio_html_n = "<span class='line-through text-gray-400 text-[10px] md:text-xs font-medium mr-1'>$" . number_format($precio_normal_n, 0, ',', '.') . "</span><span class='text-gray-600 font-normal tracking-tight'>$" . number_format($row_n['precio_oferta'], 0, ',', '.') . "</span>" . ($pct_n > 0 ? "<span class='bg-green-600 text-white text-[9px] font-semibold px-1 py-px rounded ml-1.5 leading-none relative -top-0.5'>-{$pct_n}%</span>" : "");
                        $precio_class_n = "text-[#222222] font-normal tracking-[-0.01em]";
                    } else if (is_numeric($precio_normal_n) && $precio_normal_n > 0) {
                        $precio_html_n = "$" . number_format($precio_normal_n, 0, ',', '.');
                        $precio_class_n = "text-[#222222] font-normal tracking-[-0.01em]";
                    } else {
                        $precio_html_n = "Gratis";
                        $precio_class_n = "text-[#222222] font-normal tracking-[-0.01em]";
                    }
                    
                    $html_stars_n = render_rating_html($rating_val_n, $total_v_n);
                    $inst_text_n = institucion_tutor($row_n['institucion_maestra'] ?? ($row_n['institucion'] ?? ''));
                ?>
                
                <a href="<?= $link_hash_n ?>" onclick="registrarClick(<?= (int)$row_n['id'] ?>, 'servicio')"
                   class="block flex flex-col cursor-pointer group snap-center w-[220px] md:w-[240px] flex-shrink-0 bg-transparent h-full">

                    <div class="relative w-full aspect-[4/3] bg-gray-100 overflow-hidden rounded-2xl border border-[#f0f0f0] shadow-[0_1px_3px_rgba(0,0,0,0.04)] transition-all">
                        <img src="<?= htmlspecialchars($portada_url_n) ?>" 
     srcset="<?= htmlspecialchars($portada_set_n['thumb']) ?> 240w,
             <?= htmlspecialchars($portada_set_n['card']) ?> 480w,
             <?= htmlspecialchars($portada_set_n['main']) ?> 1200w"
     sizes="(max-width: 640px) 220px, 240px"
     alt="<?= htmlspecialchars($row_n['titulo']) ?>" 
     class="w-full h-full object-cover transition-transform duration-500 ease-out group-hover:scale-105" 
     loading="<?= $es_lcp_n ? 'eager' : 'lazy' ?>" 
     decoding="async" 
     <?= $es_lcp_n ? 'fetchpriority="high"' : '' ?> 
     width="240" height="180"
     onerror="this.onerror=null;this.src='https://nubira.cl/upload/servicios/default_clases.webp';">

                       <?php
                       $ov_prefijo   = $prefijo_overlay;
                       $ov_categoria = $nombre_categoria_overlay;
                       $ov_foto      = $foto_tutor_n;
                       $ov_nombre    = $tutor_nombre_n;
                       $ov_size      = 'lg';
                       $ov_liviano   = true;
                       include __DIR__ . '/componentes/overlay_card_servicio.php';
                       ?>

                       <!-- Badge cupos (derecha) -->
                       <?php if ($es_oferta_n): ?>
                       <div class="absolute top-1 right-1 z-10">
                           <span class="inline-flex items-center px-1.5 py-0 md:px-2 md:py-0.5 rounded-full text-[9px] md:text-[10px] font-medium bg-amber-100 text-amber-900 border border-amber-200">
                               <?= (int)$row_n['cupos_oferta'] ?> <?= (int)$row_n['cupos_oferta'] === 1 ? 'cupo' : 'cupos' ?>
                           </span>
                       </div>
                       <?php endif; ?>
                    </div>

                    <div class="pt-2.5 flex flex-col flex-1 text-left">
                        <h3 class="font-medium text-[14px] leading-snug tracking-[-0.01em] text-[#222222] line-clamp-2 mb-1 min-h-[40px]"><?= htmlspecialchars($row_n['titulo']) ?></h3>
                    <div class="text-[14px] <?= $precio_class_n ?> mt-auto mb-1.5 leading-none"><?= $precio_html_n ?></div>
                        
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-1.5 text-[10px] font-normal tracking-[0.01em] text-gray-500 uppercase truncate max-w-[65%]">
                                <?php if(!empty($inst_text_n)): ?><span class="truncate"><?= $inst_text_n ?></span><?php endif; ?>
                            </div>
                            <div class="shrink-0 flex items-center gap-1"><?= $html_stars_n ?></div>
                        </div>
                    </div>
                </a>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="p-4 text-sm text-gray-400 font-medium w-full text-center border border-dashed border-gray-200 rounded-2xl bg-gray-50 flex items-center justify-center min-h-[150px]">
    Sé el primero en ofrecer tus servicios esta semana. ¡Destaca aquí!
</div>
            <?php endif; ?>
        </div>
        
        <button onclick="scrollCarrusel('sec-nuevos', 1)" class="hidden md:flex absolute right-2 opacity-0 group-hover:opacity-100 transition-opacity duration-200 top-[40%] -translate-y-1/2 w-10 h-10 bg-white rounded-full shadow-[0_1px_3px_rgba(0,0,0,0.04)] hover:shadow-[0_2px_8px_rgba(0,0,0,0.06)] items-center justify-center z-10 text-gray-400 hover:text-[#54A6D8] border border-[#f0f0f0] focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2 transition-[transform,color,box-shadow] duration-150 ease-out hover:scale-110"><i class="fa-solid fa-chevron-right text-xs"></i></button>
    </div>
</section>

<section class="mb-3 md:mb-5 relative">
<div class="flex items-center justify-between mb-3 px-4 md:px-10 md:pl-11">
        <h2 class="text-lg md:text-xl font-medium text-[#222222] tracking-[-0.01em]"><?= $titulo_apuntes ?></h2>
        <a href="/apuntes" class="text-xs font-medium text-[#54A6D8] hover:underline hover:bg-[#eef6fb] transition-colors duration-150 ease-out bg-gray-50 px-3 py-1.5 rounded-2xl border border-[#f0f0f0] flex items-center gap-1 shrink-0 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2">Ver todo <?= icon('arrow-right', 'w-3 h-3') ?></a>
    </div>

    <div class="relative group">
        <button onclick="scrollCarrusel('sec-apuntes', -1)" class="hidden md:flex absolute left-2 opacity-0 group-hover:opacity-100 transition-opacity duration-200 top-[40%] -translate-y-1/2 w-10 h-10 bg-white rounded-full shadow-[0_1px_3px_rgba(0,0,0,0.04)] hover:shadow-[0_2px_8px_rgba(0,0,0,0.06)] items-center justify-center z-10 text-gray-400 hover:text-[#54A6D8] border border-[#f0f0f0] focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2 transition-[transform,color,box-shadow] duration-150 ease-out hover:scale-110"><i class="fa-solid fa-chevron-left text-xs"></i></button>

<div id="sec-apuntes" class="flex gap-4 md:gap-5 overflow-x-auto snap-x snap-mandatory pb-3 no-scrollbar scroll-smooth compact-root pl-4 pr-4 md:pl-10 md:pr-10">
            <?php if (isset($res_apuntes) && $res_apuntes && $res_apuntes->num_rows > 0):
                $idx_ap = 0; // [NUBIRA 2.0] Contador para priorizar LCP en primeras cards
            ?>
                <?php while ($row_ap = $res_apuntes->fetch_assoc()):
                    $idx_ap++;
                    $es_lcp_ap = ($idx_ap <= 2); // Primeras 2 cards = prioridad alta
                    $link_hash_ap = function_exists('nubira_encriptar_id') ? nubira_encriptar_id($row_ap['id']) : (int)$row_ap['id'];
                    $portada_url_ap = resolver_portada_apunte($row_ap);
                    $promo_gratis = (int)($row_ap['promo_gratis'] ?? 0);
                    $promo_limite = (int)($row_ap['promo_limite'] ?? 0);
                    $promo_contador = (int)($row_ap['promo_contador'] ?? 0);
                    $es_promo_activa = ($promo_gratis === 1 && $promo_contador < $promo_limite);
                    $descargas_restantes = $promo_limite - $promo_contador;
                    $ventas_totales = (int)($row_ap['ventas_totales'] ?? 0);
                    $ventas_txt = abreviar_conteo($ventas_totales);

                    // --- LÓGICA DE AVATAR Y NOMBRE PARA APUNTES ---
                    $nombre_completo = !empty($row_ap['nombre_tutor']) ? $row_ap['nombre_tutor'] : 'Estudiante';
                    $partes_nombre = array_values(array_filter(explode(' ', trim((string)$nombre_completo))));
                    $tutor_nombre = "Estudiante";
                    if (!empty($partes_nombre[0])) {
                        $tutor_nombre = ucwords(strtolower($partes_nombre[0]));
                        if (count($partes_nombre) >= 2) {
                            $tutor_nombre .= ' ' . strtoupper(substr($partes_nombre[count($partes_nombre)-1], 0, 1)) . '.';
                        }
                    }
                    $foto_tutor = !empty($row_ap['foto_perfil']) ? '/app/perfil/fotos/' . $row_ap['foto_perfil'] : "https://ui-avatars.com/api/?name=".urlencode($tutor_nombre)."&background=f1f5f9&color=64748b";

                    $es_nuevo_ap = false;
                    if (!empty($row_ap['fecha_subida']) && $row_ap['fecha_subida'] !== '0000-00-00 00:00:00' && $row_ap['fecha_subida'] !== '0000-00-00') {
                        try { $es_nuevo_ap = (new DateTime())->diff(new DateTime($row_ap['fecha_subida']))->days <= 7; } catch (Throwable $e) {}
                    }

                    $precio_val_ap = $row_ap['precio'] ?? 0;
                    if ($es_promo_activa) {
                        $precio_ap = "<span class='line-through text-gray-400 text-[10px] md:text-xs font-medium mr-1'>$" . number_format($precio_val_ap, 0, ',', '.') . "</span><span class='text-gray-600 font-normal tracking-tight'>¡Gratis!</span>";
                        $precio_class_ap = "text-[#222222] font-normal tracking-[-0.01em] flex items-center";
                    } else if (is_numeric($precio_val_ap) && $precio_val_ap > 0) {
                        $precio_ap = "$" . number_format($precio_val_ap, 0, ',', '.'); $precio_class_ap = "text-[#222222] font-normal tracking-[-0.01em]";
                    } else {
                        $precio_ap = "Gratis"; $precio_class_ap = "text-[#222222] font-normal tracking-[-0.01em]";
                    }
                    $inst_text_ap = abreviar_institucion($row_ap['institucion_maestra'] ?? ($row_ap['institucion'] ?? ''));
                ?>

                <a href="/apunte/<?= $link_hash_ap ?>" onclick="registrarClick(<?= (int)$row_ap['id'] ?>, 'apunte')"
                   class="block flex flex-col cursor-pointer group snap-center w-[150px] md:w-[170px] flex-shrink-0 bg-transparent h-full">

                   <div class="relative w-full aspect-[4/3] bg-gray-100 overflow-hidden rounded-2xl border border-[#f0f0f0] shadow-[0_1px_3px_rgba(0,0,0,0.04)] transition-all">
                       <img src="<?= htmlspecialchars($portada_url_ap) ?>"
     alt="<?= htmlspecialchars($row_ap['titulo']) ?>"
     class="w-full h-full object-cover"
     loading="<?= $es_lcp_ap ? 'eager' : 'lazy' ?>"
     decoding="async"
     <?= $es_lcp_ap ? 'fetchpriority="high"' : '' ?>
     width="170" height="128"
     sizes="(max-width: 640px) 150px, 170px"
     onerror="this.onerror=null;this.src='https://nubira.cl/upload/servicios/default_clases.webp';">
                        <div class="absolute top-2.5 left-2.5 z-10">
                            <?php if ($es_promo_activa): ?>
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-semibold bg-amber-100 text-amber-900 border border-amber-200">
                                    Quedan <?= $descargas_restantes ?>
                                </span>
                            <?php endif; ?>
                        </div>

                    </div>

                    <div class="pt-2.5 flex flex-col flex-1 text-left">
                        <h3 class="font-medium text-[14px] leading-snug tracking-[-0.01em] text-[#222222] line-clamp-2 mb-1 min-h-[40px]"><?= htmlspecialchars($row_ap['titulo']) ?></h3>

                        <div class="text-[14px] <?= $precio_class_ap ?> mt-auto mb-1.5 leading-none"><?= $precio_ap ?></div>

                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-1.5 text-[10px] font-normal tracking-[0.01em] text-gray-500 uppercase truncate max-w-[65%]">
                                <?php if(!empty($inst_text_ap)): ?><span class="truncate"><?= $inst_text_ap ?></span><?php endif; ?>
                            </div>
                            <?php if ($ventas_totales > 0): ?>
                            <div class="shrink-0 flex items-center">
                                <span class="text-[10px] font-light tracking-[0.01em] text-gray-500 leading-none"><?= $ventas_txt ?> descargas</span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </a>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="p-4 text-sm text-gray-400 font-medium">No hay apuntes disponibles en este momento.</div>
            <?php endif; ?>
        </div>

        <button onclick="scrollCarrusel('sec-apuntes', 1)" class="hidden md:flex absolute right-2 opacity-0 group-hover:opacity-100 transition-opacity duration-200 top-[40%] -translate-y-1/2 w-10 h-10 bg-white rounded-full shadow-[0_1px_3px_rgba(0,0,0,0.04)] hover:shadow-[0_2px_8px_rgba(0,0,0,0.06)] items-center justify-center z-10 text-gray-400 hover:text-[#54A6D8] border border-[#f0f0f0] focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2 transition-[transform,color,box-shadow] duration-150 ease-out hover:scale-110"><i class="fa-solid fa-chevron-right text-xs"></i></button>
    </div>
</section>

<?php if ($res_clases_paes && $res_clases_paes->num_rows > 0): ?>
<section class="mb-3 md:mb-5 relative animate-fade-in-up">
 <div class="flex items-center justify-between mb-3 px-4 md:px-10 md:pl-11">
    <h2 class="text-lg md:text-xl font-medium text-[#222222] tracking-[-0.01em]">PAES</h2>
    <a href="/clases/paes" class="text-xs font-medium text-[#54A6D8] hover:underline hover:bg-[#eef6fb] transition-colors duration-150 ease-out bg-gray-50 px-3 py-1.5 rounded-2xl border border-[#f0f0f0] flex items-center gap-1 shrink-0 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2">Ver todo <?= icon('arrow-right', 'w-3 h-3') ?></a>
</div>
    <div class="relative group">
        <button onclick="scrollCarrusel('sec-clases-paes', -1)" class="hidden md:flex absolute left-2 opacity-0 group-hover:opacity-100 transition-opacity duration-200 top-[40%] -translate-y-1/2 w-10 h-10 bg-white rounded-full shadow-[0_1px_3px_rgba(0,0,0,0.04)] hover:shadow-[0_2px_8px_rgba(0,0,0,0.06)] items-center justify-center z-10 text-gray-400 hover:text-[#54A6D8] border border-[#f0f0f0] focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2 transition-[transform,color,box-shadow] duration-150 ease-out hover:scale-110"><i class="fa-solid fa-chevron-left text-xs"></i></button>
<div id="sec-clases-paes" class="flex gap-4 md:gap-5 overflow-x-auto snap-x snap-mandatory pb-3 no-scrollbar scroll-smooth compact-root pl-4 pr-4 md:pl-10 md:pr-10">
                <?php $idx_paes = 0; while ($row_paes = $res_clases_paes->fetch_assoc()):
                    $idx_paes++;
                    $es_lcp_paes = ($idx_paes <= 2);
                    $link_hash_paes = url_servicio((int)$row_paes['id'], $row_paes['slug'] ?? null);
                    $portada_set_paes = srcset_portada($row_paes);
                    $portada_url_paes = $portada_set_paes['card'];
                    $rating_val_paes = isset($row_paes['rating_promedio']) ? (float)$row_paes['rating_promedio'] : 0;
                    $total_v_paes = isset($row_paes['total_votos']) ? (int)$row_paes['total_votos'] : 0;
                    $nombre_completo_paes = !empty($row_paes['nombre_tutor']) ? $row_paes['nombre_tutor'] : 'Profesor';
                    $partes_paes = array_values(array_filter(explode(' ', trim((string)$nombre_completo_paes))));
                    $tutor_nombre_paes = "Profesor";
                    if (!empty($partes_paes[0])) {
                        $tutor_nombre_paes = ucwords(strtolower($partes_paes[0]));
                        if (count($partes_paes) >= 2) {
                            $tutor_nombre_paes .= ' ' . strtoupper(substr($partes_paes[count($partes_paes)-1], 0, 1)) . '.';
                        }
                    }
                    $foto_tutor_paes = !empty($row_paes['foto_perfil']) ? '/app/perfil/fotos/' . $row_paes['foto_perfil'] : "https://ui-avatars.com/api/?name=".urlencode($tutor_nombre_paes)."&background=f1f5f9&color=64748b&size=128";
                    $categoria_overlay = $row_paes['categoria'] ?? 'Otros';
                    $prefijo_overlay = in_array($categoria_overlay, ['Otros','Asesoría']) ? '' : 'Clase de';
                    $nombre_categoria_overlay = ($categoria_overlay === 'Otros') ? 'Clase' : $categoria_overlay;
                    $es_oferta_paes = oferta_vigente($row_paes);
                    $pct_paes = ($es_oferta_paes && (int)($row_paes['precio'] ?? 0) > 0) ? round(((int)$row_paes['precio'] - (int)$row_paes['precio_oferta']) / (int)$row_paes['precio'] * 100) : 0;
                    $precio_normal_paes = $row_paes['precio'] ?? 0;
                    if ($es_oferta_paes) {
                        $precio_html_paes = "<span class='line-through text-gray-400 text-[10px] md:text-xs font-medium mr-1'>$" . number_format($precio_normal_paes, 0, ',', '.') . "</span><span class='text-gray-600 font-normal tracking-tight'>$" . number_format($row_paes['precio_oferta'], 0, ',', '.') . "</span>" . ($pct_paes > 0 ? "<span class='bg-green-600 text-white text-[9px] font-semibold px-1 py-px rounded ml-1.5 leading-none relative -top-0.5'>-{$pct_paes}%</span>" : "");
                        $precio_class_paes = "text-[#222222] font-normal tracking-[-0.01em]";
                    } else if (is_numeric($precio_normal_paes) && $precio_normal_paes > 0) {
                        $precio_html_paes = "$" . number_format($precio_normal_paes, 0, ',', '.');
                        $precio_class_paes = "text-[#222222] font-normal tracking-[-0.01em]";
                    } else {
                        $precio_html_paes = "Gratis";
                        $precio_class_paes = "text-[#222222] font-normal tracking-[-0.01em]";
                    }
                    $html_stars_paes = render_rating_html($rating_val_paes, $total_v_paes);
                    $inst_text_paes = institucion_tutor($row_paes['institucion_maestra'] ?? ($row_paes['institucion'] ?? ''));
                ?>
                <a href="<?= $link_hash_paes ?>" onclick="registrarClick(<?= (int)$row_paes['id'] ?>, 'servicio')"
                   class="block flex flex-col cursor-pointer group snap-center w-[220px] md:w-[240px] flex-shrink-0 bg-transparent h-full">
                    <div class="relative w-full aspect-[4/3] bg-gray-100 overflow-hidden rounded-2xl border border-[#f0f0f0] shadow-[0_1px_3px_rgba(0,0,0,0.04)] transition-all">
                        <img src="<?= htmlspecialchars($portada_url_paes) ?>"
     srcset="<?= htmlspecialchars($portada_set_paes['thumb']) ?> 240w,
             <?= htmlspecialchars($portada_set_paes['card']) ?> 480w,
             <?= htmlspecialchars($portada_set_paes['main']) ?> 1200w"
     sizes="(max-width: 640px) 220px, 240px"
     alt="<?= htmlspecialchars($row_paes['titulo']) ?>"
     class="w-full h-full object-cover transition-transform duration-500 ease-out group-hover:scale-105"
     loading="<?= $es_lcp_paes ? 'eager' : 'lazy' ?>"
     decoding="async"
     <?= $es_lcp_paes ? 'fetchpriority="high"' : '' ?>
     width="240" height="180"
     onerror="this.onerror=null;this.src='https://nubira.cl/upload/servicios/default_clases.webp';">
                       <?php
                       $ov_prefijo   = $prefijo_overlay;
                       $ov_categoria = $nombre_categoria_overlay;
                       $ov_foto      = $foto_tutor_paes;
                       $ov_nombre    = $tutor_nombre_paes;
                       $ov_size      = 'lg';
                       $ov_liviano   = true;
                       include __DIR__ . '/componentes/overlay_card_servicio.php';
                       ?>
                       <?php if ($es_oferta_paes): ?>
                       <div class="absolute top-1 right-1 z-10">
                           <span class="inline-flex items-center px-1.5 py-0 md:px-2 md:py-0.5 rounded-full text-[9px] md:text-[10px] font-medium bg-amber-100 text-amber-900 border border-amber-200">
                               <?= (int)$row_paes['cupos_oferta'] ?> <?= (int)$row_paes['cupos_oferta'] === 1 ? 'cupo' : 'cupos' ?>
                           </span>
                       </div>
                       <?php endif; ?>
                    </div>
                    <div class="pt-2.5 flex flex-col flex-1 text-left">
                        <h3 class="font-medium text-[14px] leading-snug tracking-[-0.01em] text-[#222222] line-clamp-2 mb-1 min-h-[40px]"><?= htmlspecialchars($row_paes['titulo']) ?></h3>
                    <div class="text-[14px] <?= $precio_class_paes ?> mt-auto mb-1.5 leading-none"><?= $precio_html_paes ?></div>
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-1.5 text-[10px] font-normal tracking-[0.01em] text-gray-500 uppercase truncate max-w-[65%]">
                                <?php if(!empty($inst_text_paes)): ?><span class="truncate"><?= $inst_text_paes ?></span><?php endif; ?>
                            </div>
                            <div class="shrink-0 flex items-center gap-1"><?= $html_stars_paes ?></div>
                        </div>
                    </div>
                </a>
                <?php endwhile; ?>
        </div>
        <button onclick="scrollCarrusel('sec-clases-paes', 1)" class="hidden md:flex absolute right-2 opacity-0 group-hover:opacity-100 transition-opacity duration-200 top-[40%] -translate-y-1/2 w-10 h-10 bg-white rounded-full shadow-[0_1px_3px_rgba(0,0,0,0.04)] hover:shadow-[0_2px_8px_rgba(0,0,0,0.06)] items-center justify-center z-10 text-gray-400 hover:text-[#54A6D8] border border-[#f0f0f0] focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2 transition-[transform,color,box-shadow] duration-150 ease-out hover:scale-110"><i class="fa-solid fa-chevron-right text-xs"></i></button>
    </div>
</section>
<?php endif; ?>

 <?php if ($tiene_ofertas_activas): ?>
     <section class="mb-3 md:mb-5 relative">
           <div class="flex items-end justify-between mb-3 px-4 md:px-10 md:pl-11">
                <div class="flex items-center gap-2">
                    <div>
                        <h2 class="text-lg md:text-xl font-medium text-[#222222] tracking-[-0.01em] leading-none">Precios de última hora</h2>
                    </div>
                </div>
            </div>
            
            <div class="relative group">
               <button onclick="scrollCarrusel('sec-ofertas', -1)" class="hidden md:flex absolute left-2 opacity-0 group-hover:opacity-100 transition-opacity duration-200 top-[40%] -translate-y-1/2 w-10 h-10 bg-white rounded-full shadow-[0_1px_3px_rgba(0,0,0,0.04)] hover:shadow-[0_2px_8px_rgba(0,0,0,0.06)] items-center justify-center z-20 text-gray-400 hover:text-[#54A6D8] border border-[#f0f0f0] focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2 transition-[transform,color,box-shadow] duration-150 ease-out hover:scale-110"><i class="fa-solid fa-chevron-left text-xs"></i></button>
                
               <div id="sec-ofertas" class="flex gap-4 overflow-x-auto snap-x snap-mandatory pb-3 pt-1 no-scrollbar scroll-smooth pl-4 pr-4 md:pl-10 md:pr-10 min-h-[108px] md:min-h-[124px] items-center">
                    
                   <?php 
                   $idx_of = 0; // [NUBIRA 2.0] Contador LCP para ofertas
                   foreach ($lista_ofertas as $row_of): 
                       $idx_of++;
                       $es_lcp_of = ($idx_of <= 2);
                       $link_hash_of = url_servicio((int)$row_of['id'], $row_of['slug'] ?? null);
                      $portada_set_of = srcset_portada($row_of);
$portada_url_of = $portada_set_of['thumb']; // miniatura 90x90 → thumb es suficiente
                        $es_activa = ((int)($row_of['cupos_oferta'] ?? 0) > 0 && !isset($row_of['es_falsa_oferta']));
                        $pct_of = ($es_activa && (int)$row_of['precio'] > 0) ? round(((int)$row_of['precio'] - (int)$row_of['precio_oferta']) / (int)$row_of['precio'] * 100) : 0;
                        // Lógica Nubira 2.0: Estrellas y Tag
                       $rating_val_of = isset($row_of['rating_promedio']) ? (float)$row_of['rating_promedio'] : 0;
                       $total_v_of = isset($row_of['total_votos']) ? (int)$row_of['total_votos'] : 0;
                       $html_stars_of = render_rating_html($rating_val_of, $total_v_of);
                       $tag_of = "CLASES";
                       $tag_color_of = "text-[#54A6D8]";
                       // [OVERLAY NUBIRA] avatar tutor + categoría sobre la portada
                       $nombre_completo_ov = !empty($row_of['nombre_tutor']) ? $row_of['nombre_tutor'] : 'Profesor';
                       $partes_ov = array_values(array_filter(explode(' ', trim((string)$nombre_completo_ov))));
                       $tutor_nombre_ov = "Profesor";
                       if (!empty($partes_ov[0])) {
                           $tutor_nombre_ov = ucwords(strtolower($partes_ov[0]));
                           if (count($partes_ov) >= 2) {
                               $tutor_nombre_ov .= ' ' . strtoupper(substr($partes_ov[count($partes_ov)-1], 0, 1)) . '.';
                           }
                       }
                       $foto_field_ov   = $row_of['foto_perfil'] ?? '';
                       $foto_tutor_ov   = !empty($foto_field_ov) ? '/app/perfil/fotos/' . $foto_field_ov : 'https://ui-avatars.com/api/?name=' . urlencode($tutor_nombre_ov) . '&background=54A6D8&color=fff&size=128&bold=true';
                       $categoria_overlay = $row_of['categoria'] ?? 'Otros';
                       $prefijo_overlay = in_array($categoria_overlay, ['Otros','Asesoría']) ? '' : 'Clase de';
                       $nombre_categoria_overlay = ($categoria_overlay === 'Otros') ? 'Clase' : $categoria_overlay;
                    ?>
                        <?php if($es_activa): ?>
                        <a href="<?= $link_hash_of ?>"
                           onclick="registrarClick(<?= (int)$row_of['id'] ?>, 'servicio')"
                           class="block flex flex-col cursor-pointer group snap-start w-[150px] md:w-[170px] flex-shrink-0 bg-transparent h-full">

                            <div class="relative w-full aspect-[4/3] bg-gray-100 overflow-hidden rounded-2xl border border-[#f0f0f0] shadow-[0_1px_3px_rgba(0,0,0,0.04)] transition-all">
                                <img src="<?= htmlspecialchars($portada_url_of) ?>"
                                     srcset="<?= htmlspecialchars($portada_set_of['thumb']) ?> 240w, <?= htmlspecialchars($portada_set_of['card']) ?> 480w"
                                     sizes="170px"
                                     alt="<?= htmlspecialchars($row_of['titulo']) ?>"
                                     class="w-full h-full object-cover transition-transform duration-500 ease-out group-hover:scale-105"
                                     loading="<?= $es_lcp_of ? 'eager' : 'lazy' ?>" decoding="async"
                                     <?= $es_lcp_of ? 'fetchpriority="high"' : '' ?>
                                     width="170" height="128">

                                <?php
                                $ov_prefijo   = $prefijo_overlay;
                                $ov_categoria = $nombre_categoria_overlay;
                                $ov_foto      = $foto_tutor_ov;
                                $ov_nombre    = $tutor_nombre_ov;
                                $ov_size      = 'lg';
                                $ov_liviano   = true;
                                include __DIR__ . '/componentes/overlay_card_servicio.php';
                                ?>

                                <div class="absolute top-1 right-1 z-10">
                                    <span class="inline-flex items-center px-1.5 py-0 md:px-2 md:py-0.5 rounded-full text-[9px] md:text-[10px] font-medium bg-amber-100 text-amber-900 border border-amber-200">
                                        <?= (int)$row_of['cupos_oferta'] ?> <?= (int)$row_of['cupos_oferta'] === 1 ? 'cupo' : 'cupos' ?>
                                    </span>
                                </div>
                            </div>

                            <div class="pt-2.5 flex flex-col flex-1 text-left">
                                <h3 class="font-medium text-[14px] leading-snug tracking-[-0.01em] text-[#222222] line-clamp-2 mb-1 min-h-[40px]"><?= htmlspecialchars($row_of['titulo']) ?></h3>
                                <div class="text-[14px] mt-auto mb-1.5 leading-none whitespace-nowrap">
                                    <span class="text-[11px] text-gray-400 line-through font-medium mr-1">$<?= number_format($row_of['precio'], 0, ',', '.') ?></span>
                                    <span class="text-gray-600 font-normal tracking-tight">$<?= number_format($row_of['precio_oferta'], 0, ',', '.') ?></span>
                                    <?php if ($pct_of > 0): ?><span class="bg-green-600 text-white text-[9px] font-semibold px-1 py-px rounded ml-1.5 leading-none relative -top-0.5">-<?= $pct_of ?>%</span><?php endif; ?>
                                </div>
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-1.5 text-[10px] font-light text-gray-400 uppercase tracking-wide truncate max-w-[65%]">
                                        <span class="truncate"><?= $tag_of ?></span>
                                    </div>
                                    <div class="shrink-0 flex items-center gap-1"><?= $html_stars_of ?></div>
                                </div>
                            </div>
                        </a>
                        <?php else: ?>
                        <a href="<?= $link_hash_of ?>"
                           onclick="registrarClick(<?= (int)$row_of['id'] ?>, 'servicio')"
                           class="block flex flex-col cursor-pointer group snap-start w-[150px] md:w-[170px] flex-shrink-0 bg-transparent h-full">

                            <div class="relative w-full aspect-[4/3] bg-gray-100 overflow-hidden rounded-2xl border border-[#f0f0f0] shadow-[0_1px_3px_rgba(0,0,0,0.04)] transition-all">
                                <img src="<?= htmlspecialchars($portada_url_of) ?>"
                                     alt="<?= htmlspecialchars($row_of['titulo']) ?>"
                                     class="w-full h-full object-cover"
                                     loading="<?= $es_lcp_of ? 'eager' : 'lazy' ?>" decoding="async"
                                     width="170" height="128">

                                <?php
                                $ov_prefijo   = $prefijo_overlay;
                                $ov_categoria = $nombre_categoria_overlay;
                                $ov_foto      = $foto_tutor_ov;
                                $ov_nombre    = $tutor_nombre_ov;
                                $ov_size      = 'lg';
                                $ov_liviano   = true;
                                include __DIR__ . '/componentes/overlay_card_servicio.php';
                                ?>
                            </div>

                            <div class="pt-2.5 flex flex-col flex-1 text-left">
                                <h3 class="font-medium text-[14px] leading-snug tracking-[-0.01em] text-[#222222] line-clamp-2 mb-1 min-h-[40px]"><?= htmlspecialchars($row_of['titulo']) ?></h3>
                                <div class="text-[13px] mt-auto mb-1.5 leading-none">
                                    <span class="text-gray-600 font-normal tracking-tight">$<?= number_format($row_of['precio'], 0, ',', '.') ?></span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-1.5 text-[10px] font-light text-gray-400 uppercase tracking-wide truncate max-w-[65%]">
                                        <span class="truncate"><?= $tag_of ?></span>
                                    </div>
                                </div>
                            </div>
                        </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
              <button onclick="scrollCarrusel('sec-ofertas', 1)" class="hidden md:flex absolute right-2 opacity-0 group-hover:opacity-100 transition-opacity duration-200 top-[40%] -translate-y-1/2 w-10 h-10 bg-white rounded-full shadow-[0_1px_3px_rgba(0,0,0,0.04)] hover:shadow-[0_2px_8px_rgba(0,0,0,0.06)] items-center justify-center z-20 text-gray-400 hover:text-[#54A6D8] border border-[#f0f0f0] focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2 transition-[transform,color,box-shadow] duration-150 ease-out hover:scale-110"><i class="fa-solid fa-chevron-right text-xs"></i></button>
            </div>
        </section>
        <?php endif; ?>

<?php if (false): // OCULTO: seccion IA removida de la home ?>
   <section id="zona-ia" class="mb-3 md:mb-5 relative animate-fade-in-up transition-all duration-500">
    
    <?php require_once __DIR__ . '/componentes/seccion_recomendaciones.php'; ?>
    
    <div class="relative group">
      <button onclick="scrollCarrusel('carrusel-ia', -1)" class="hidden md:flex absolute left-2 opacity-0 group-hover:opacity-100 transition-opacity duration-200 top-[40%] -translate-y-1/2 w-10 h-10 bg-white rounded-full shadow-[0_1px_3px_rgba(0,0,0,0.04)] hover:shadow-[0_2px_8px_rgba(0,0,0,0.06)] items-center justify-center z-20 text-gray-400 hover:text-[#54A6D8] border border-[#f0f0f0] focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2 transition-[transform,color,box-shadow] duration-150 ease-out hover:scale-110"><i class="fa-solid fa-chevron-left text-xs"></i></button>
        
 <div id="carrusel-ia" class="flex gap-4 overflow-x-auto snap-x snap-mandatory pb-3 no-scrollbar scroll-smooth compact-root pl-4 pr-4 md:pl-10 md:pr-10 min-h-[100px] md:min-h-[130px]">
            <?php for($i=0; $i<6; $i++): ?>
            <div class="flex-shrink-0 w-[220px] md:w-[300px] h-[96px] md:h-[112px] bg-gray-50 rounded-2xl border border-gray-200 p-2 md:p-2.5 flex gap-3 snap-start overflow-hidden">
                <div class="w-20 h-20 md:w-[90px] md:h-[90px] rounded-xl bg-gray-200 flex-shrink-0 animate-pulse self-center"></div>
                <div class="flex flex-col justify-center w-full gap-2 py-1">
                    <div class="h-2 bg-gray-200 rounded-full w-1/4 animate-pulse"></div>
                    <div class="h-3 bg-gray-200 rounded w-full animate-pulse"></div>
                    <div class="h-3 bg-gray-200 rounded w-3/4 animate-pulse"></div>
                    <div class="h-3 bg-gray-200 rounded w-1/3 animate-pulse mt-1"></div>
                </div>
            </div>
            <?php endfor; ?>
        </div>
        
        <button onclick="scrollCarrusel('carrusel-ia', 1)" class="hidden md:flex absolute right-2 opacity-0 group-hover:opacity-100 transition-opacity duration-200 top-[40%] -translate-y-1/2 w-10 h-10 bg-white rounded-full shadow-[0_1px_3px_rgba(0,0,0,0.04)] hover:shadow-[0_2px_8px_rgba(0,0,0,0.06)] items-center justify-center z-20 text-gray-400 hover:text-[#54A6D8] border border-[#f0f0f0] focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2 transition-[transform,color,box-shadow] duration-150 ease-out hover:scale-110"><i class="fa-solid fa-chevron-right text-xs"></i></button>
    </div>
</section>
<?php endif; ?>

<?php if (false): // OCULTO: seccion Apuntes nuevos removida de la home ?>
<!-- [NUBIRA 2.0] APUNTES RECIÉN PUBLICADOS -->
<section class="mb-3 md:mb-5 relative animate-fade-in-up">
    <div class="flex items-end justify-between mb-3 px-4 md:px-10 max-w-[1600px] mx-auto gap-3">
        <h2 class="text-lg md:text-xl font-medium text-[#222222] tracking-[-0.01em]">Apuntes nuevos</h2>
        <a href="/apuntes?orden=nuevos" class="text-xs font-medium text-[#54A6D8] hover:underline hover:bg-[#eef6fb] transition-colors duration-150 ease-out bg-gray-50 px-3 py-1.5 rounded-2xl border border-[#f0f0f0] flex items-center gap-1 shrink-0 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2">Ver todo <?= icon('arrow-right', 'w-3 h-3') ?></a>
    </div>
    <div class="relative group">
        <button onclick="scrollCarrusel('sec-apuntes-nuevos', -1)" class="hidden md:flex absolute left-2 opacity-0 group-hover:opacity-100 transition-opacity duration-200 top-[40%] -translate-y-1/2 w-10 h-10 bg-white rounded-full shadow-[0_1px_3px_rgba(0,0,0,0.04)] hover:shadow-[0_2px_8px_rgba(0,0,0,0.06)] items-center justify-center z-10 text-gray-400 hover:text-[#54A6D8] border border-[#f0f0f0] focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2 transition-[transform,color,box-shadow] duration-150 ease-out hover:scale-110"><i class="fa-solid fa-chevron-left text-xs"></i></button>
        
        <div id="sec-apuntes-nuevos" class="flex gap-4 md:gap-5 overflow-x-auto snap-x snap-mandatory pb-3 no-scrollbar scroll-smooth compact-root pl-4 pr-4 md:pl-10 md:pr-10">
            <?php if (isset($res_apuntes_nuevos) && $res_apuntes_nuevos && $res_apuntes_nuevos->num_rows > 0): 
                $idx_apn = 0;
            ?>
                <?php while ($row_apn = $res_apuntes_nuevos->fetch_assoc()): 
                    $idx_apn++;
                    $es_lcp_apn = ($idx_apn <= 2);
                    $link_hash_apn = function_exists('nubira_encriptar_id') ? nubira_encriptar_id($row_apn['id']) : (int)$row_apn['id'];
                    $portada_url_apn = resolver_portada_apunte($row_apn);
                    $promo_gratis_apn = (int)($row_apn['promo_gratis'] ?? 0);
                    $promo_limite_apn = (int)($row_apn['promo_limite'] ?? 0);
                    $promo_contador_apn = (int)($row_apn['promo_contador'] ?? 0);
                    $es_promo_activa_apn = ($promo_gratis_apn === 1 && $promo_contador_apn < $promo_limite_apn);
                    $descargas_restantes_apn = $promo_limite_apn - $promo_contador_apn;
                    $ventas_totales_apn = (int)($row_apn['ventas_totales'] ?? 0);
                    $ventas_txt_apn = abreviar_conteo($ventas_totales_apn);
                    
                    // --- Avatar y nombre tutor ---
                    $nombre_completo_apn = !empty($row_apn['nombre_tutor']) ? $row_apn['nombre_tutor'] : 'Estudiante';
                    $partes_nombre_apn = array_values(array_filter(explode(' ', trim((string)$nombre_completo_apn))));
                    $tutor_nombre_apn = "Estudiante";
                    if (!empty($partes_nombre_apn[0])) {
                        $tutor_nombre_apn = ucwords(strtolower($partes_nombre_apn[0]));
                        if (count($partes_nombre_apn) >= 2) {
                            $tutor_nombre_apn .= ' ' . strtoupper(substr($partes_nombre_apn[count($partes_nombre_apn)-1], 0, 1)) . '.';
                        }
                    }
                    $foto_tutor_apn = !empty($row_apn['foto_perfil']) ? '/app/perfil/fotos/' . $row_apn['foto_perfil'] : "https://ui-avatars.com/api/?name=".urlencode($tutor_nombre_apn)."&background=f1f5f9&color=64748b";
                    
                    // --- Badge "Nuevo" si tiene 7 días o menos ---
                    $es_nuevo_apn = false;
                    if (!empty($row_apn['fecha_subida']) && $row_apn['fecha_subida'] !== '0000-00-00 00:00:00' && $row_apn['fecha_subida'] !== '0000-00-00') {
                        try { $es_nuevo_apn = (new DateTime())->diff(new DateTime($row_apn['fecha_subida']))->days <= 7; } catch (Throwable $e) {}
                    }
                    
                    // --- Precio ---
                    $precio_val_apn = $row_apn['precio'] ?? 0;
                    if ($es_promo_activa_apn) {
                        $precio_apn = "<span class='line-through text-gray-400 text-[10px] md:text-xs font-medium mr-1'>$" . number_format($precio_val_apn, 0, ',', '.') . "</span><span class='text-gray-600 font-normal tracking-tight'>¡Gratis!</span>";
                        $precio_class_apn = "text-[#222222] font-normal tracking-[-0.01em] flex items-center";
                    } else if (is_numeric($precio_val_apn) && $precio_val_apn > 0) {
                        $precio_apn = "$" . number_format($precio_val_apn, 0, ',', '.'); 
                        $precio_class_apn = "text-[#222222] font-normal tracking-[-0.01em]";
                    } else { 
                        $precio_apn = "Gratis"; 
                        $precio_class_apn = "text-[#222222] font-normal tracking-[-0.01em]";
                    }
                    $inst_text_apn = abreviar_institucion($row_apn['institucion_maestra'] ?? ($row_apn['institucion'] ?? ''));
                ?>
                
                <a href="/apunte/<?= $link_hash_apn ?>" onclick="registrarClick(<?= (int)$row_apn['id'] ?>, 'apunte')" 
                   class="block flex flex-col cursor-pointer group snap-center w-[220px] md:w-[240px] flex-shrink-0 bg-transparent h-full">
                    
                    <div class="relative w-full aspect-[4/3] bg-gray-100 overflow-hidden rounded-2xl border border-[#f0f0f0] shadow-[0_1px_3px_rgba(0,0,0,0.04)] transition-all">
                        <img src="<?= htmlspecialchars($portada_url_apn) ?>"
                             alt="<?= htmlspecialchars($row_apn['titulo']) ?>" 
                             class="w-full h-full object-cover transition-transform duration-500 ease-out group-hover:scale-105" 
                             loading="<?= $es_lcp_apn ? 'eager' : 'lazy' ?>" 
                             decoding="async" 
                             <?= $es_lcp_apn ? 'fetchpriority="high"' : '' ?> 
                             width="240" height="180"
                             sizes="(max-width: 640px) 220px, 240px"
                             onerror="this.onerror=null;this.src='https://nubira.cl/upload/servicios/default_clases.webp';">
                        
                        <div class="absolute top-2.5 left-2.5 z-10">
                            <?php if ($es_promo_activa_apn): ?>
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-semibold bg-amber-100 text-amber-900 border border-amber-200">
                                    Quedan <?= $descargas_restantes_apn ?>
                                </span>
                            <?php endif; ?>
                        </div>

                    </div>
                    
                    <div class="pt-2.5 flex flex-col flex-1 text-left">
                        <h3 class="font-medium text-[14px] leading-snug tracking-[-0.01em] text-[#222222] line-clamp-2 mb-1 min-h-[40px]"><?= htmlspecialchars($row_apn['titulo']) ?></h3>
                        
                        <div class="text-[14px] <?= $precio_class_apn ?> mt-auto mb-1.5 leading-none"><?= $precio_apn ?></div>
                        
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-1.5 text-[10px] font-normal tracking-[0.01em] text-gray-500 uppercase truncate max-w-[65%]">
                                <?php if(!empty($inst_text_apn)): ?><span class="truncate"><?= $inst_text_apn ?></span><?php endif; ?>
                            </div>
                            <?php if ($ventas_totales_apn > 0): ?>
                            <div class="shrink-0 flex items-center">
                                <span class="text-[10px] font-light tracking-[0.01em] text-gray-500 leading-none"><?= $ventas_txt_apn ?> descargas</span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </a>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="p-4 text-sm text-gray-400 font-medium w-full text-center border border-dashed border-gray-200 rounded-2xl bg-gray-50 flex items-center justify-center min-h-[150px]">
                    Aún no hay apuntes nuevos. ¡Sé el primero en subir uno!
                </div>
            <?php endif; ?>
        </div>
        
        <button onclick="scrollCarrusel('sec-apuntes-nuevos', 1)" class="hidden md:flex absolute right-2 opacity-0 group-hover:opacity-100 transition-opacity duration-200 top-[40%] -translate-y-1/2 w-10 h-10 bg-white rounded-full shadow-[0_1px_3px_rgba(0,0,0,0.04)] hover:shadow-[0_2px_8px_rgba(0,0,0,0.06)] items-center justify-center z-10 text-gray-400 hover:text-[#54A6D8] border border-[#f0f0f0] focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2 transition-[transform,color,box-shadow] duration-150 ease-out hover:scale-110"><i class="fa-solid fa-chevron-right text-xs"></i></button>
    </div>
</section>
<?php endif; ?>

<?php if ($mostrar_seccion_paes): ?>
<!-- [NUBIRA 2.0] APUNTES PAES -->
<section class="mb-3 md:mb-5 relative animate-fade-in-up">
   <div class="flex items-end justify-between mb-3 px-4 md:px-10 max-w-[1600px] mx-auto gap-3">
        <h2 class="text-lg md:text-xl font-medium text-[#222222] tracking-[-0.01em]">Apuntes PAES</h2>
        <a href="/apuntes?nivel=paes" class="text-xs font-medium text-[#54A6D8] hover:underline hover:bg-[#eef6fb] transition-colors duration-150 ease-out bg-gray-50 px-3 py-1.5 rounded-2xl border border-[#f0f0f0] flex items-center gap-1 shrink-0 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2">Ver todo <?= icon('arrow-right', 'w-3 h-3') ?></a>
    </div>
    
    <div class="relative group">
        <button onclick="scrollCarrusel('sec-apuntes-paes', -1)" class="hidden md:flex absolute left-2 opacity-0 group-hover:opacity-100 transition-opacity duration-200 top-[40%] -translate-y-1/2 w-10 h-10 bg-white rounded-full shadow-[0_1px_3px_rgba(0,0,0,0.04)] hover:shadow-[0_2px_8px_rgba(0,0,0,0.06)] items-center justify-center z-10 text-gray-400 hover:text-[#54A6D8] border border-[#f0f0f0] focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2 transition-[transform,color,box-shadow] duration-150 ease-out hover:scale-110"><i class="fa-solid fa-chevron-left text-xs"></i></button>
        
        <div id="sec-apuntes-paes" class="flex gap-4 md:gap-5 overflow-x-auto snap-x snap-mandatory pb-3 no-scrollbar scroll-smooth compact-root pl-4 pr-4 md:pl-10 md:pr-10">
            <?php 
            $idx_paes = 0;
            while ($row_paes = $res_apuntes_paes->fetch_assoc()): 
                $idx_paes++;
                $es_lcp_paes = ($idx_paes <= 2);
                $link_hash_paes = function_exists('nubira_encriptar_id') ? nubira_encriptar_id($row_paes['id']) : (int)$row_paes['id'];
                $portada_url_paes = resolver_portada_apunte($row_paes);
                $promo_gratis_p = (int)($row_paes['promo_gratis'] ?? 0);
                $promo_limite_p = (int)($row_paes['promo_limite'] ?? 0);
                $promo_contador_p = (int)($row_paes['promo_contador'] ?? 0);
                $es_promo_p = ($promo_gratis_p === 1 && $promo_contador_p < $promo_limite_p);
                $ventas_p = (int)($row_paes['ventas_totales'] ?? 0);
                $ventas_txt_p = abreviar_conteo($ventas_p);
                
                $nombre_completo_p = !empty($row_paes['nombre_tutor']) ? $row_paes['nombre_tutor'] : 'Estudiante';
                $partes_p = array_values(array_filter(explode(' ', trim((string)$nombre_completo_p))));
                $tutor_p = "Estudiante";
                if (!empty($partes_p[0])) {
                    $tutor_p = ucwords(strtolower($partes_p[0]));
                    if (count($partes_p) >= 2) {
                        $tutor_p .= ' ' . strtoupper(substr($partes_p[count($partes_p)-1], 0, 1)) . '.';
                    }
                }
                $foto_p = !empty($row_paes['foto_perfil']) ? '/app/perfil/fotos/' . $row_paes['foto_perfil'] : "https://ui-avatars.com/api/?name=".urlencode($tutor_p)."&background=fff7ed&color=ea580c";
                
                $precio_val_p = $row_paes['precio'] ?? 0;
                if ($es_promo_p) {
                    $precio_p = "<span class='line-through text-gray-400 text-[10px] md:text-xs font-medium mr-1'>$" . number_format($precio_val_p, 0, ',', '.') . "</span><span class='text-orange-500 tracking-tight'>¡Gratis!</span>";
                    $precio_class_p = "text-[#222222] font-normal tracking-[-0.01em] flex items-center";
                } else if (is_numeric($precio_val_p) && $precio_val_p > 0) {
                    $precio_p = "$" . number_format($precio_val_p, 0, ',', '.'); 
                    $precio_class_p = "text-gray-900 font-bold";
                } else { 
                    $precio_p = "Gratis"; 
                    $precio_class_p = "text-green-600 font-bold"; 
                }
                $inst_p = abreviar_institucion($row_paes['institucion_maestra'] ?? ($row_paes['institucion'] ?? ''));
            ?>
            
            <a href="/apunte/<?= $link_hash_paes ?>" onclick="registrarClick(<?= (int)$row_paes['id'] ?>, 'apunte')" 
               class="block flex flex-col cursor-pointer group snap-center w-[220px] md:w-[240px] flex-shrink-0 bg-transparent h-full">
                
                <div class="relative w-full aspect-[4/3] bg-gray-100 overflow-hidden rounded-2xl transition-all">
                    <img src="<?= htmlspecialchars($portada_url_paes) ?>" 
                         alt="<?= htmlspecialchars($row_paes['titulo']) ?>" 
                         class="w-full h-full object-cover" 
                         loading="<?= $es_lcp_paes ? 'eager' : 'lazy' ?>" 
                         decoding="async" 
                         <?= $es_lcp_paes ? 'fetchpriority="high"' : '' ?> 
                         width="240" height="180"
                         sizes="(max-width: 640px) 220px, 240px"
                         onerror="this.onerror=null;this.src='https://nubira.cl/upload/servicios/default_clases.webp';">
                    
                    <div class="absolute top-2.5 left-2.5 flex flex-wrap gap-1 z-10">
                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[9px] font-extrabold uppercase tracking-wider bg-gradient-to-tr from-orange-400 to-orange-500 text-white shadow-sm border border-orange-300/50">
                            📘 PAES
                        </span>
                    </div>

                    <div class="absolute top-2 right-2 z-10 shrink-0">
                        <img src="<?= htmlspecialchars($foto_p, ENT_QUOTES, 'UTF-8') ?>" 
                             class="w-8 h-8 rounded-full object-cover shadow-md border-[1.5px] border-white/95 bg-gray-50 transform-gpu"
                             width="32" height="32" decoding="async" loading="lazy"
                             alt="Tutor">
                    </div>
                </div>
                
                <div class="pt-2.5 flex flex-col flex-1 text-left">
                    <h3 class="font-medium text-[14px] leading-snug tracking-[-0.01em] text-[#222222] line-clamp-2 mb-1 min-h-[40px]"><?= htmlspecialchars($row_paes['titulo']) ?></h3>
                    
                    <div class="text-[14px] <?= $precio_class_p ?> mt-auto mb-1.5 leading-none"><?= $precio_p ?></div>
                    
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-1.5 text-[10px] font-normal tracking-[0.01em] text-gray-500 uppercase truncate max-w-[65%]">
                            <?php if(!empty($inst_p)): ?><?= icon('building', 'w-3 h-3 text-gray-300 flex-shrink-0') ?><span class="truncate"><?= $inst_p ?></span><?php endif; ?>
                        </div>
                        <?php if ($ventas_p > 0): ?>
                        <div class="shrink-0 flex items-center bg-gray-50 px-2 py-0.5 rounded border border-gray-100">
                            <span class="text-[10px] font-light tracking-[0.01em] text-gray-800 leading-none">
                                <?= $ventas_txt_p ?> <span class="font-medium text-gray-500">descargas</span>
                            </span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </a>
            <?php endwhile; ?>
        </div>
        
        <button onclick="scrollCarrusel('sec-apuntes-paes', 1)" class="hidden md:flex absolute right-2 opacity-0 group-hover:opacity-100 transition-opacity duration-200 top-[40%] -translate-y-1/2 w-10 h-10 bg-white rounded-full shadow-[0_1px_3px_rgba(0,0,0,0.04)] hover:shadow-[0_2px_8px_rgba(0,0,0,0.06)] items-center justify-center z-10 text-gray-400 hover:text-[#54A6D8] border border-[#f0f0f0] focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2 transition-[transform,color,box-shadow] duration-150 ease-out hover:scale-110"><i class="fa-solid fa-chevron-right text-xs"></i></button>
    </div>
</section>
<?php endif; ?>
<div class="hidden md:block w-full border-t border-gray-100 mt-5 pt-4 pb-0 px-2 overflow-hidden">
    <?php 
        $rutas_footer = [__DIR__ . '/componentes/footer_minimal.php', __DIR__ . '/app/componentes/footer_minimal.php'];
        foreach ($rutas_footer as $rf) {
            if (file_exists($rf)) { require_once $rf; break; }
        }
    ?>
</div>

</main>




<div id="modal-captacion" class="fixed inset-0 z-[100] flex items-center justify-center hidden opacity-0 transition-opacity duration-300 bg-gray-900/40 backdrop-blur-sm px-4 md:p-0">
    <div id="card-captacion" class="bg-white w-full max-w-[850px] rounded-2xl md:rounded-3xl shadow-xl transform translate-y-full scale-95 transition-all duration-300 overflow-hidden relative flex flex-col max-h-[85vh] md:max-h-[90vh]">
        <div class="px-5 py-4 md:px-6 md:py-5 border-b border-gray-100 flex justify-between items-center bg-white shrink-0">
            <div>
                <h3 class="text-lg md:text-2xl font-bold text-gray-900 tracking-tight leading-tight">¿Haces clases o vendes apuntes?</h3>
                <p class="text-xs md:text-sm text-gray-500 mt-0.5">Descubre por qué los mejores tutores usan Nubira.</p>
            </div>
            <button onclick="cerrarModalCaptacion()" class="p-2 bg-gray-50 hover:bg-gray-100 rounded-full transition-all hover:scale-[1.05] shrink-0 ml-4"><i class="fa-solid fa-xmark text-gray-500"></i></button>
        </div>
        <div class="flex-1 overflow-y-auto overflow-x-hidden p-0 bg-white">
            <div class="grid grid-cols-1 md:grid-cols-2 h-full">
                <div class="bg-gray-50 p-5 md:p-8 border-b md:border-b-0 md:border-r border-gray-100">
                    <div class="flex items-center gap-3 mb-4 md:mb-6">
                        <div class="w-8 h-8 md:w-10 md:h-10 rounded-xl bg-red-100 text-red-500 flex items-center justify-center shrink-0"><i class="fa-solid fa-chalkboard-user text-lg md:text-xl"></i></div>
                        <h4 class="text-base md:text-lg font-bold text-gray-800 tracking-tight">Dar clases por RRSS</h4>
                    </div>
                    <ul class="space-y-4 md:space-y-5 text-xs md:text-sm text-gray-600">
                        <li class="flex items-start gap-2.5"><span class="text-red-400 mt-0.5 shrink-0"><i class="fa-solid fa-xmark"></i></span><div><strong class="text-gray-800 block mb-0.5">Te dejan en "visto"</strong>Pierdes tiempo respondiendo mensajes a personas que preguntan precios y luego desaparecen.</div></li>
                        <li class="flex items-start gap-2.5"><span class="text-red-400 mt-0.5 shrink-0"><i class="fa-solid fa-xmark"></i></span><div><strong class="text-gray-800 block mb-0.5">Cobros incómodos</strong>Tienes que perseguir a los alumnos para que transfieran o arriesgarte a dar la clase sin garantías.</div></li>
                        <li class="flex items-start gap-2.5"><span class="text-red-400 mt-0.5 shrink-0"><i class="fa-solid fa-xmark"></i></span><div><strong class="text-gray-800 block mb-0.5">Agenda desordenada</strong>Agendar por WhatsApp mezcla tu vida personal con tus alumnos, perdiendo links y horarios.</div></li>
                    </ul>
                </div>
                <div class="bg-white p-5 md:p-8 flex flex-col">
                    <div class="flex items-center gap-3 mb-4 md:mb-6">
                        <div class="w-8 h-8 md:w-10 md:h-10 rounded-xl bg-sky-100 text-[#54A6D8] flex items-center justify-center shrink-0"><i class="fa-solid fa-rocket text-lg md:text-xl"></i></div>
                        <h4 class="text-base md:text-lg font-bold text-gray-800 tracking-tight">Enseñar en Nubira</h4>
                    </div>
                    <ul class="space-y-4 md:space-y-5 text-xs md:text-sm text-gray-600">
                        <li class="flex items-start gap-2.5"><span class="text-[#54A6D8] mt-0.5 shrink-0"><i class="fa-solid fa-check-circle text-base md:text-lg"></i></span><div><strong class="text-gray-800 block mb-0.5">Pagos 100% garantizados</strong>El alumno paga por adelantado a través de la plataforma. Tu dinero está seguro siempre.</div></li>
                        <li class="flex items-start gap-2.5"><span class="text-[#54A6D8] mt-0.5 shrink-0"><i class="fa-solid fa-check-circle text-base md:text-lg"></i></span><div><strong class="text-gray-800 block mb-0.5">Ventas en automático</strong>Sube tus apuntes una vez y genera ingresos 24/7 sin tener que responder ningún DM.</div></li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="p-4 md:p-6 bg-white border-t border-gray-100 shrink-0">
            <?php if ($is_guest): ?>
                <a href="/registro?rol=tutor" class="block w-full text-center py-3 md:py-4 rounded-2xl font-medium text-white bg-gradient-to-r from-sky-400 to-[#54A6D8] shadow-[0_1px_3px_rgba(0,0,0,0.08)] hover:shadow-[0_4px_14px_rgba(84,166,216,0.35)] hover:scale-[1.01] transition-[transform,box-shadow] duration-150 ease-out text-sm md:text-base focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2">Crea tu cuenta gratis</a>
            <?php elseif (($_SESSION['intencion_uso'] ?? '') !== 'comprar'): ?>
                <button onclick="cerrarModalCaptacion(); document.getElementById('btn-publicar')?.click();" class="block w-full text-center py-3 md:py-4 rounded-2xl font-medium text-white bg-gradient-to-r from-sky-400 to-[#54A6D8] shadow-[0_1px_3px_rgba(0,0,0,0.08)] hover:shadow-[0_4px_14px_rgba(84,166,216,0.35)] hover:scale-[1.01] transition-[transform,box-shadow] duration-150 ease-out text-sm md:text-base focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2">Comenzar a enseñar ahora</button>
            <?php else: ?>
                <button onclick="cerrarModalCaptacion();" class="block w-full text-center py-3 md:py-4 rounded-2xl font-medium text-gray-500 bg-gray-100 hover:bg-gray-200 transition-colors text-sm md:text-base focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-300 focus-visible:ring-offset-2">Entendido</button>
            <?php endif; ?>
        </div>
    </div>
</div>

<div id="modal-beneficios-alumno" class="fixed inset-0 z-[100] flex items-center justify-center hidden opacity-0 transition-opacity duration-300 bg-gray-900/40 backdrop-blur-sm px-4 md:p-0">
    <div id="card-beneficios-alumno" class="bg-white w-full max-w-[850px] rounded-2xl md:rounded-3xl shadow-xl transform translate-y-full scale-95 transition-all duration-300 overflow-hidden relative flex flex-col max-h-[85vh] md:max-h-[90vh]">
        <div class="px-5 py-4 md:px-6 md:py-5 border-b border-gray-100 flex justify-between items-center bg-white shrink-0">
            <div>
                <h3 class="text-lg md:text-2xl font-bold text-gray-900 tracking-tight leading-tight">¿Buscas apuntes o tutorías?</h3>
                <p class="text-xs md:text-sm text-gray-500 mt-0.5">La forma inteligente de salvar el semestre.</p>
            </div>
            <button onclick="cerrarModalAlumno()" class="p-2 bg-gray-50 hover:bg-gray-100 rounded-full transition-all hover:scale-[1.05] shrink-0 ml-4"><i class="fa-solid fa-xmark text-gray-500"></i></button>
        </div>
        <div class="flex-1 overflow-y-auto overflow-x-hidden p-0 bg-white">
            <div class="grid grid-cols-1 md:grid-cols-2 h-full">
                <div class="bg-gray-50 p-5 md:p-8 border-b md:border-b-0 md:border-r border-gray-100">
                    <div class="flex items-center gap-3 mb-4 md:mb-6">
                        <div class="w-8 h-8 md:w-10 md:h-10 rounded-xl bg-red-100 text-red-500 flex items-center justify-center shrink-0"><i class="fa-brands fa-whatsapp text-lg md:text-xl"></i></div>
                        <h4 class="text-base md:text-lg font-bold text-gray-800 tracking-tight">Buscar en RRSS</h4>
                    </div>
                    <ul class="space-y-4 md:space-y-5 text-xs md:text-sm text-gray-600">
                        <li class="flex items-start gap-2.5"><span class="text-red-400 mt-0.5 shrink-0"><i class="fa-solid fa-xmark"></i></span><div><strong class="text-gray-800 block mb-0.5">Apuntes basura o virus</strong>Descargas PDFs dudosos que no tienen la materia de tu prueba.</div></li>
                        <li class="flex items-start gap-2.5"><span class="text-red-400 mt-0.5 shrink-0"><i class="fa-solid fa-xmark"></i></span><div><strong class="text-gray-800 block mb-0.5">Estafas con tutores</strong>Transfieres por adelantado en Instagram y desaparecen.</div></li>
                        <li class="flex items-start gap-2.5"><span class="text-red-400 mt-0.5 shrink-0"><i class="fa-solid fa-xmark"></i></span><div><strong class="text-gray-800 block mb-0.5">Tiempo perdido</strong>Pierdes horas rogando por accesos a Drive en grupos muertos.</div></li>
                    </ul>
                </div>
                <div class="bg-white p-5 md:p-8 flex flex-col">
                    <div class="flex items-center gap-3 mb-4 md:mb-6">
                        <div class="w-8 h-8 md:w-10 md:h-10 rounded-xl bg-sky-100 text-[#54A6D8] flex items-center justify-center shrink-0"><i class="fa-solid fa-graduation-cap text-lg md:text-xl"></i></div>
                        <h4 class="text-base md:text-lg font-bold text-gray-800 tracking-tight">Estudiar con Nubira</h4>
                    </div>
                    <ul class="space-y-4 md:space-y-5 text-xs md:text-sm text-gray-600">
                        <li class="flex items-start gap-2.5"><span class="text-[#54A6D8] mt-0.5 shrink-0"><i class="fa-solid fa-check-circle text-base md:text-lg"></i></span><div><strong class="text-gray-800 block mb-0.5">Material verificado</strong>Resúmenes filtrados por estudiantes reales de tu universidad.</div></li>
                        <li class="flex items-start gap-2.5"><span class="text-[#54A6D8] mt-0.5 shrink-0"><i class="fa-solid fa-check-circle text-base md:text-lg"></i></span><div><strong class="text-gray-800 block mb-0.5">Dinero 100% protegido</strong>Si el apunte o clase no cumple, tienes respaldo total.</div></li>
                        <li class="flex items-start gap-2.5"><span class="text-[#54A6D8] mt-0.5 shrink-0"><i class="fa-solid fa-check-circle text-base md:text-lg"></i></span><div><strong class="text-gray-800 block mb-0.5">Descarga instantánea</strong>Haces clic y el PDF es tuyo de inmediato. Sin esperas.</div></li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="p-4 md:p-6 bg-white border-t border-gray-100 shrink-0">
            <?php if ($is_guest): ?>
                <a href="/registro?rol=alumno" class="block w-full text-center py-3 md:py-4 rounded-2xl font-medium text-white bg-gradient-to-r from-sky-400 to-[#54A6D8] shadow-[0_1px_3px_rgba(0,0,0,0.08)] hover:shadow-[0_4px_14px_rgba(84,166,216,0.35)] hover:scale-[1.01] transition-[transform,box-shadow] duration-150 ease-out text-sm md:text-base focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2">Crea tu cuenta gratis</a>
            <?php else: ?>
                <button onclick="cerrarModalAlumno(); document.getElementById('btn-explora')?.click();" class="block w-full text-center py-3 md:py-4 rounded-2xl font-medium text-white bg-gradient-to-r from-sky-400 to-[#54A6D8] shadow-[0_1px_3px_rgba(0,0,0,0.08)] hover:shadow-[0_4px_14px_rgba(84,166,216,0.35)] hover:scale-[1.01] transition-[transform,box-shadow] duration-150 ease-out text-sm md:text-base focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2">Explorar apuntes ahora</button>
            <?php endif; ?>
        </div>
    </div>
</div>


<?php 
require_once __DIR__ . '/componentes/nav_bottom.php';
require_once __DIR__ . '/componentes/modal_publicar.php';
require_once __DIR__ . '/componentes/modal_explora.php';
?>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const esVisitante = <?= $is_guest ? 'true' : 'false' ?>;
    const debeVer     = <?= !empty($debe_ver_onboarding) ? 'true' : 'false' ?>;
    const mostrar = esVisitante
        ? (localStorage.getItem('nubira_onboarding_visto') !== '1')
        : debeVer;
    if (mostrar && typeof window.abrirOnboarding === 'function') {
        // Auto-show deshabilitado el 16-jun por bajísima conversión post-TikTok. Mantener solo apertura manual via botón Tutorial.
        // setTimeout(() => window.abrirOnboarding(), 1500);
    }
});
</script>

<script>
    // =============================================
    // JS NUBIRA 2.0 - PROTECCIÓN 3G (FAILSAFES)
    // =============================================

  const NubiraUI = {
        init() {
            const dismissLoader = () => {
                const l = document.getElementById('loader-nativo');
                const b = document.body;
                sessionStorage.setItem('nubira_loader_visto', '1');

                // 1. Quitamos el candado visual
                if (b) b.classList.remove('fouc-lock');
                
                // 2. Desvanecemos el loader
                if(l && l.style.display !== 'none') { 
                    l.style.opacity = '0'; 
                    setTimeout(() => l.style.display = 'none', 300); 
                } 
            };
            
            // [NUBIRA 2.0] Loader inteligente:
            // - Ocultamos en cuanto el DOM esté listo (contenido visible).
            // - 'load' sigue siendo un seguro extra si el DOM ya está listo.
            // - Failsafe de 1500ms (antes 2500ms) por si algún recurso cuelga.
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', dismissLoader, { once: true });
            } else {
                // DOM ya está listo: ocultar en el próximo frame
                requestAnimationFrame(dismissLoader);
            }
            window.addEventListener('load', dismissLoader, { once: true });
            setTimeout(dismissLoader, 1500); 
        },
        scrollCarrusel(id, dir) { 
            const c = document.getElementById(id); 
            if(c) c.scrollBy({ left: dir * 300, behavior: 'smooth' }); 
        }
    };
    window.scrollCarrusel = NubiraUI.scrollCarrusel;

    const NubiraModales = {
        setup(triggerId, modalId, cardId, closeId) {
            const btn = document.getElementById(triggerId);
            const modal = document.getElementById(modalId);
            const card = document.getElementById(cardId);
            const close = document.getElementById(closeId);
            if(!btn || !modal) return;
            const open = () => { 
                modal.classList.remove('hidden'); 
                requestAnimationFrame(() => { card.classList.remove('translate-y-full', 'opacity-0'); });
                document.body.style.overflow = 'hidden'; 
            };
            const shut = () => { 
                card.classList.add('translate-y-full', 'opacity-0'); 
                setTimeout(() => { modal.classList.add('hidden'); document.body.style.overflow = ''; }, 300); 
            };
            btn.onclick = (e) => { 
                e.preventDefault(); 
                <?php if ($is_guest): ?>
                    if (triggerId === 'btn-publicar') {
                        window.location.href = '/login?redir=' + encodeURIComponent(window.location.pathname + window.location.search);
                        return;
                    }
                <?php endif; ?>
                open(); 
            }; 
            if(close) close.onclick = shut; 
            modal.onclick = (e) => { if(e.target === modal) shut(); };
        },
        abrirCaptacion() {
            const modal = document.getElementById('modal-captacion');
            const card = document.getElementById('card-captacion');
            if(!modal || !card) return;
            modal.classList.remove('hidden');
            requestAnimationFrame(() => { modal.classList.remove('opacity-0'); card.classList.remove('translate-y-full', 'scale-95'); });
            document.body.style.overflow = 'hidden';
        },
        cerrarCaptacion() {
            const modal = document.getElementById('modal-captacion');
            const card = document.getElementById('card-captacion');
            card.classList.add('translate-y-full', 'scale-95');
            modal.classList.add('opacity-0');
            setTimeout(() => { modal.classList.add('hidden'); document.body.style.overflow = ''; }, 300);
        },
        abrirAlumno() {
            const modal = document.getElementById('modal-beneficios-alumno');
            const card = document.getElementById('card-beneficios-alumno');
            if(!modal || !card) return;
            modal.classList.remove('hidden');
            requestAnimationFrame(() => { modal.classList.remove('opacity-0'); card.classList.remove('translate-y-full', 'scale-95'); });
            document.body.style.overflow = 'hidden';
        },
        cerrarAlumno() {
            const modal = document.getElementById('modal-beneficios-alumno');
            const card = document.getElementById('card-beneficios-alumno');
            card.classList.add('translate-y-full', 'scale-95');
            modal.classList.add('opacity-0');
            setTimeout(() => { modal.classList.add('hidden'); document.body.style.overflow = ''; }, 300);
        }
    };
    window.abrirModalCaptacion = NubiraModales.abrirCaptacion;
    window.cerrarModalCaptacion = NubiraModales.cerrarCaptacion;
    window.abrirModalAlumno = NubiraModales.abrirAlumno;
    window.cerrarModalAlumno = NubiraModales.cerrarAlumno;

    const NubiraHydrate = {
        async loadHTML(container) {
            const src = container.getAttribute('data-src');
            if(!src) return;
            try {
                const r = await fetch(src + (src.includes('?')?'&':'?') + 't=' + Date.now());
                if(!r.ok) throw new Error();
                const html = await r.text();
                if(html.trim()) {
                    container.innerHTML = html;
                } else {
                    const hideTarget = container.getAttribute('data-hide-target');
                    const elToHide = hideTarget ? document.querySelector(hideTarget) : container;
                    (elToHide || container).style.display = 'none';
                }
            } catch(e) {
                // UX: Si falla el internet, damos opción de reintentar (Airbnb vibe)
                container.innerHTML = `
                <div class="flex-shrink-0 w-full p-4 flex flex-col items-center justify-center border border-dashed border-gray-200 bg-gray-50 rounded-2xl">
                    <p class="text-xs text-gray-500 mb-2">Conexión inestable</p>
                    <button onclick="NubiraHydrate.loadHTML(this.closest('[data-src]'))" class="px-3 py-1 bg-white border border-gray-200 text-[#54A6D8] font-bold text-[11px] rounded-lg shadow-sm hover:shadow active:scale-95 transition-all">Reintentar</button>
                </div>`;
            }
        },
        async actualizarBadgeChats() {
            <?php if ($is_guest) echo 'return;'; ?>
            try {
                const res = await fetch('/app/contar_mensajes_nuevos.php');
                const data = await res.json();
                const total = parseInt(data.total || 0);
                ['badge-chats-sidebar', 'badge-chats-bottom'].forEach(id => {
                    const el = document.getElementById(id);
                    if(el) { 
                        if(id.includes('sidebar')) el.innerText = total;
                        total > 0 ? el.classList.remove('hidden') : el.classList.add('hidden'); 
                    }
                });
            } catch(e) {}
        }
    };

  const NubiraCerebro = {
    // [NUBIRA 2.0] Renderiza las cards ya resueltas dentro del carrusel
    _renderCards(containerAI, htmlArray) {
        containerAI.innerHTML = ''; // Limpiamos Skeletons
        let agregadas = 0;
        htmlArray.forEach((html, index) => {
            if (!html) return;
            const tempDiv = document.createElement('div');
            tempDiv.innerHTML = html;
            const card = tempDiv.firstElementChild;
            if (card) {
                card.style.animationDelay = `${index * 80}ms`;
                card.classList.add('animate-fade-in-up');
                containerAI.appendChild(card);
                agregadas++;
            }
        });
        return agregadas;
    },

    // [NUBIRA 2.0] Fallback: el método original (motor_ia.php + N x render_card.php)
    // [NUBIRA 2.0] Renderizado progresivo: cada card aparece apenas llega.
    // Antes: Promise.all esperaba a las 6 y pintaba juntas (~800ms).
    // Ahora: insertamos cada card en su slot en cuanto responde el fetch.
    _cargarLegacy(containerAI) {
        return fetch('/app/api/motor_ia.php')
            .then(r => r.json())
            .then(data => {
                if (!data || !Array.isArray(data.items) || data.items.length === 0) {
                    document.getElementById('zona-ia').style.display = 'none';
                    return;
                }

                // [NUBIRA 2.0] Reutilizamos los skeletons YA pintados por PHP.
                // Antes: borrábamos todo el contenedor y creábamos slots nuevos → flash visual.
                // Ahora: usamos los skeletons existentes como placeholders ordenados,
                //        y los reemplazamos uno a uno sin limpiar el contenedor.
                const skeletonsPHP = Array.from(containerAI.children);
                
                // Si hay menos skeletons que items IA, completamos con clones
                while (skeletonsPHP.length < data.items.length) {
                    const clone = skeletonsPHP[0].cloneNode(true);
                    containerAI.appendChild(clone);
                    skeletonsPHP.push(clone);
                }
                
                // Si hay más skeletons que items IA, eliminamos los sobrantes
                while (skeletonsPHP.length > data.items.length) {
                    skeletonsPHP.pop().remove();
                }
                
                const slots = skeletonsPHP;

                // 2. Lanzamos los 6 fetch en paralelo, pero cada uno se pinta
                //    de forma INDEPENDIENTE apenas responde (no esperamos a los demás).
                let agregadas = 0;
                let fallidas = 0;
                const total = data.items.length;

                data.items.forEach((item, index) => {
                    fetch(`/app/componentes/render_card.php?id=${item.id}&tipo=${item.tipo}`)
                        .then(res => res.text())
                        .then(html => {
                            if (!html || !html.trim()) {
                                slots[index].remove();
                                fallidas++;
                                return;
                            }
                            const tempDiv = document.createElement('div');
                            tempDiv.innerHTML = html;
                            const card = tempDiv.firstElementChild;
                            if (card) {
                                card.style.animationDelay = `${index * 50}ms`;
                                card.classList.add('animate-fade-in-up');
                                // Reemplazamos el skeleton por la card real
                                slots[index].replaceWith(card);
                                agregadas++;
                            } else {
                                slots[index].remove();
                                fallidas++;
                            }
                        })
                        .catch(() => {
                            slots[index].remove();
                            fallidas++;
                        })
                        .finally(() => {
                            // Si todas fallaron, ocultamos la sección completa
                            if ((agregadas + fallidas) === total && agregadas === 0) {
                                document.getElementById('zona-ia').style.display = 'none';
                            }
                        });
                });
            })
            .catch(() => {
                document.getElementById('zona-ia').style.display = 'none';
            });
    },

   cargar() {
        const containerAI = document.getElementById('carrusel-ia');
        if (!containerAI) return;

        // Llamada directa al flujo estable (Legacy)
        // Esto evita el error de "full-not-available" y renderiza las cards con tu backend actual
        this._cargarLegacy(containerAI);
    }
};

    
    // ── INICIALIZADOR EN CASCADA ──
    NubiraUI.init();

    document.addEventListener('DOMContentLoaded', () => {

        NubiraModales.setup('btn-publicar', 'modal-quick', 'quick-card', 'quick-close');
        NubiraModales.setup('btn-explora', 'modal-explora', 'explora-card', 'explora-close');

       // [NUBIRA 2.0] Fase 1: Arranque paralelo
        // La IA y "Visto recientemente" son independientes → lanzamos ambos a la vez.
        // Antes: sec-recientes esperaba 100ms + competía con 6 fetches de render_card.
        // Ahora: ambos pelean por la red desde el mismo instante.
        NubiraCerebro.cargar();
        
        const secRecientes = document.getElementById('sec-recientes');
        const secInteligente = document.getElementById('sec-inteligente-wrapper');
        if (secRecientes) NubiraHydrate.loadHTML(secRecientes);
        if (secInteligente) NubiraHydrate.loadHTML(secInteligente);

     // Fase 3: Procesos en 2do Plano (Ahorrando Batería y Red)
        const scheduleIdle = window.requestIdleCallback || ((cb) => setTimeout(cb, 300));
        scheduleIdle(() => {
          
            
            NubiraHydrate.actualizarBadgeChats();
            
            // Revisa chats cada 45 segundos, SOLO si la pestaña está activa
            setInterval(() => {
                if(!document.hidden) {
                    NubiraHydrate.actualizarBadgeChats();
                }
            }, 45000);
        });
      
    });
</script>
</body>
</html>