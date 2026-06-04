<?php
/**
 * VISTA: ADMIN USUARIOS (NUBIRA 2.0)
 * ROL: Full Stack Senior & Lead UX/UI
 * ESTADO: BLINDADO - Prepared Statements en TODOS los queries (incluida cascada).
 */
ini_set('display_errors', 0);
error_reporting(E_ALL);
session_start();

// 1. CONEXIÓN Y SEGURIDAD
$app_dir = dirname(__DIR__) . '/app'; 
if (!file_exists($app_dir . '/conexion.php')) $app_dir = __DIR__;
require_once $app_dir . '/conexion.php';

// AUTO-MIGRACIÓN SILENCIOSA: Columnas necesarias para el flujo Nubira 2.0
$auto_migraciones = [
    "alumnos"   => [
        "ultimo_reenvio" => "ALTER TABLE alumnos ADD COLUMN ultimo_reenvio DATETIME NULL DEFAULT NULL AFTER token",
        "visible"        => "ALTER TABLE alumnos ADD COLUMN visible TINYINT(1) NOT NULL DEFAULT 1 AFTER bloqueado"
    ],
    "servicios" => [
        "visible" => "ALTER TABLE servicios ADD COLUMN visible TINYINT(1) NOT NULL DEFAULT 1"
    ],
    "apuntes" => [
        "visible" => "ALTER TABLE apuntes ADD COLUMN visible TINYINT(1) NOT NULL DEFAULT 1"
    ]
];
foreach ($auto_migraciones as $tabla => $columnas) {
    foreach ($columnas as $col => $sql_alter) {
        $check = $conn->query("SHOW COLUMNS FROM `$tabla` LIKE '$col'");
        if ($check && $check->num_rows === 0) $conn->query($sql_alter);
    }
}

// Migración especial: visto_admin con UPDATE inicial (solo al crear la columna)
$check_visto = $conn->query("SHOW COLUMNS FROM `alumnos` LIKE 'visto_admin'");
if ($check_visto && $check_visto->num_rows === 0) {
    $conn->query("ALTER TABLE alumnos ADD COLUMN visto_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER fecha_registro");
    $conn->query("UPDATE alumnos SET visto_admin = 1");
}

// Fallback Iconos
if (file_exists($app_dir . '/iconos.php')) {
    require_once $app_dir . '/iconos.php';
} elseif (!function_exists('icon')) {
    function icon($name, $classes='') { return "<i class='fa-solid fa-$name $classes'></i>"; }
}

// Verificación Rol (esta es la verificación REAL de admin)
if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] ?? '') !== 'admin') {
    header("Location: /vitrina"); exit;
}

// Generar Token CSRF
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf_token = $_SESSION['csrf_token'];

// Helper Nav
$ruta_actual = $_SERVER['REQUEST_URI'] ?? '/';
if (!function_exists('nav_class')) {
    function nav_class($path) {
        global $ruta_actual;
        return (strpos($ruta_actual, $path) !== false) 
            ? 'group flex items-center gap-3 px-3 py-2 text-sm font-medium rounded-lg transition-all border border-transparent bg-blue-50 text-[#54A6D8] border-blue-100' 
            : 'group flex items-center gap-3 px-3 py-2 text-sm font-medium rounded-lg transition-all border border-transparent text-slate-500 hover:bg-slate-50';
    }
}

// -------------------------------------------------------------------------
// 2. CONTROLADOR (POST SEGURO)
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Error de seguridad: Token CSRF inválido.");
    }

    try {
        $accion = $_POST['accion'] ?? '';
        $id = (int)($_POST['id'] ?? 0);

        // A) BANEAR / DESBANEAR
        if ($accion === 'toggle_ban') {
            $estado = (int)$_POST['nuevo_estado']; 
            $stmt = $conn->prepare("UPDATE alumnos SET bloqueado = ? WHERE id = ?");
            $stmt->bind_param("ii", $estado, $id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['toast'] = $estado ? '⛔ Usuario Bloqueado' : '✅ Usuario Desbloqueado';
        }

        // B) CAMBIAR ROL
        if ($accion === 'cambiar_rol') {
            $actual = $_POST['rol_actual'];
            $nuevo = ($actual === 'admin') ? 'alumno' : 'admin';
            $stmt = $conn->prepare("UPDATE alumnos SET rol = ? WHERE id = ?");
            $stmt->bind_param("si", $nuevo, $id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['toast'] = "Rol cambiado a " . strtoupper($nuevo);
        }

        // C) EDITAR DATOS
        if ($accion === 'editar_usuario') {
            $nombre = trim($_POST['nombre']);
            $correo = trim($_POST['correo']);
            $stmt = $conn->prepare("UPDATE alumnos SET nombre = ?, correo = ? WHERE id = ?");
            $stmt->bind_param("ssi", $nombre, $correo, $id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['toast'] = "Datos actualizados correctamente";
        }

        // D) ELIMINAR USUARIO (SOFT DELETE EN CASCADA - NUBIRA 2.0)
        // Migrado a Prepared Statements + transacción atómica
        if ($accion === 'eliminar_usuario') {
            if ($id === (int)$_SESSION['usuario_id']) {
                $_SESSION['toast'] = "❌ No puedes eliminar tu propia cuenta.";
            } else {
                $conn->begin_transaction();
                try {
                    // 1. Ocultar Usuario
                    $stmt1 = $conn->prepare("UPDATE alumnos SET visible = 0, bloqueado = 1 WHERE id = ?");
                    $stmt1->bind_param("i", $id);
                    $stmt1->execute();
                    $stmt1->close();
                    
                    // 2. Ocultar sus Servicios
                    $stmt2 = $conn->prepare("UPDATE servicios SET visible = 0 WHERE alumno_id = ?");
                    $stmt2->bind_param("i", $id);
                    $stmt2->execute();
                    $stmt2->close();
                    
                    // 3. Ocultar sus Apuntes
                    $stmt3 = $conn->prepare("UPDATE apuntes SET visible = 0 WHERE id_alumno = ?");
                    $stmt3->bind_param("i", $id);
                    $stmt3->execute();
                    $stmt3->close();

                    $conn->commit();
                    $_SESSION['toast'] = "🗑️ Usuario y contenidos ocultados con éxito.";
                } catch (Exception $e) {
                    $conn->rollback();
                    $_SESSION['toast'] = "❌ Error en cascada: " . $e->getMessage();
                }
            }
        }

        // E) REENVIAR CORREO CONFIRMACIÓN
        if ($accion === 'reenviar_confirmacion') {
            require_once $app_dir . '/correo.php'; 
            
            $stmt = $conn->prepare("SELECT nombre, correo, token, confirmado FROM alumnos WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $res_user = $stmt->get_result();
            
            if ($res_user->num_rows > 0) {
                $user_data = $res_user->fetch_assoc();
                
                if (empty($user_data['confirmado'])) {
                    $token = $user_data['token'];
                    
                    if (empty($token)) {
                        $token = bin2hex(random_bytes(32));
                        $stmt_tok = $conn->prepare("UPDATE alumnos SET token = ? WHERE id = ?");
                        $stmt_tok->bind_param("si", $token, $id);
                        $stmt_tok->execute();
                        $stmt_tok->close();
                    }
                    
                    if (enviarCorreoConfirmacion($user_data['correo'], $user_data['nombre'], $token)) {
                        $stmt_upd = $conn->prepare("UPDATE alumnos SET ultimo_reenvio = NOW() WHERE id = ?");
                        $stmt_upd->bind_param("i", $id);
                        $stmt_upd->execute();
                        $stmt_upd->close();
                        
                        $_SESSION['toast'] = "✉️ Correo reenviado a " . htmlspecialchars($user_data['nombre']);
                    } else {
                        $_SESSION['toast'] = "❌ Error técnico al enviar el correo.";
                    }
                } else {
                    $_SESSION['toast'] = "⚠️ Este usuario ya está confirmado.";
                }
            }
            $stmt->close();
        }

        // F) APROBAR VERIFICACIÓN
        if ($accion === 'aprobar_verificacion') {
            $stmt_u = $conn->prepare("SELECT nombre, correo FROM alumnos WHERE id = ?");
            $stmt_u->bind_param("i", $id);
            $stmt_u->execute();
            $datos_u = $stmt_u->get_result()->fetch_assoc();
            $stmt_u->close();

            $stmt_v = $conn->prepare("UPDATE alumnos SET verificacion_estado = 'aprobado' WHERE id = ?");
            $stmt_v->bind_param("i", $id);
            $stmt_v->execute();
            $stmt_v->close();

            if ($datos_u) {
                require_once $app_dir . '/correo.php';
                enviarCorreoVerificacionAprobada($datos_u['correo'], $datos_u['nombre']);
            }
            $_SESSION['toast'] = "Cuenta aprobada: " . htmlspecialchars($datos_u['nombre'] ?? '');
        }

        // G) RECHAZAR VERIFICACIÓN
        if ($accion === 'rechazar_verificacion') {
            $stmt_u = $conn->prepare("SELECT nombre, correo FROM alumnos WHERE id = ?");
            $stmt_u->bind_param("i", $id);
            $stmt_u->execute();
            $datos_u = $stmt_u->get_result()->fetch_assoc();
            $stmt_u->close();

            $stmt_v = $conn->prepare("UPDATE alumnos SET verificacion_estado = 'rechazado' WHERE id = ?");
            $stmt_v->bind_param("i", $id);
            $stmt_v->execute();
            $stmt_v->close();

            if ($datos_u) {
                require_once $app_dir . '/correo.php';
                enviarCorreoVerificacionRechazada($datos_u['correo'], $datos_u['nombre']);
            }
            $_SESSION['toast'] = "Cuenta rechazada: " . htmlspecialchars($datos_u['nombre'] ?? '');
        }

    } catch (Exception $e) {
        $_SESSION['toast'] = "Error: " . $e->getMessage();
    }

    // Mantener filtros en la redirección (PRG Pattern)
    $query_string = $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : '';
    header("Location: " . $_SERVER['PHP_SELF'] . $query_string);
    exit;
}

// -------------------------------------------------------------------------
// 3. CONSULTA DE USUARIOS
// -------------------------------------------------------------------------

// Aplanar badge: marcar como vistos al entrar al panel
$conn->query("UPDATE alumnos SET visto_admin = 1 WHERE visto_admin = 0");

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$busqueda = trim($_GET['q'] ?? '');
$filtro_rol = $_GET['rol'] ?? '';

// Implementación estricta de Soft Delete en la lectura
$sql_base = "FROM alumnos u WHERE u.visible = 1";
$params = [];
$types = "";

if ($busqueda) {
    $sql_base .= " AND (u.nombre LIKE ? OR u.correo LIKE ? OR u.dominio LIKE ?)";
    $likeParam = "%" . $busqueda . "%";
    $params[] = $likeParam; $params[] = $likeParam; $params[] = $likeParam;
    $types .= "sss";
}

if ($filtro_rol) {
    $sql_base .= " AND u.rol = ?";
    $params[] = $filtro_rol;
    $types .= "s";
}

// Paginación
$stmtCount = $conn->prepare("SELECT COUNT(*) as c $sql_base");
if (!empty($params)) {
    $stmtCount->bind_param($types, ...$params);
}
$stmtCount->execute();
$total_users = $stmtCount->get_result()->fetch_assoc()['c'];
$total_pages = ceil($total_users / $limit);
$stmtCount->close();

// Consulta Final 
$sql_final = "SELECT u.*,
              (SELECT COUNT(*) FROM servicios WHERE alumno_id = u.id) as total_servicios,
              (SELECT COUNT(*) FROM apuntes WHERE id_alumno = u.id) as total_apuntes,
              (SELECT COUNT(*) FROM reclamos_sugerencias WHERE usuario_id = u.id) as total_reclamos
              $sql_base
              ORDER BY u.id DESC
              LIMIT ? OFFSET ?";

$params[] = $limit;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($sql_final);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

// Global counter ignorando eliminados lógicamente
$total_users_global = $conn->query("SELECT COUNT(id) FROM alumnos WHERE visible = 1")->fetch_row()[0];

// Tab activo
$tab = $_GET['tab'] ?? 'todos';

// Pendientes de verificación (siempre se consultan para el badge del tab)
$stmt_pend = $conn->prepare(
    "SELECT id, nombre, correo, tipo, carrera, bio, fecha_registro
     FROM alumnos
     WHERE verificacion_estado = 'pendiente' AND visible = 1
     ORDER BY fecha_registro ASC"
);
$stmt_pend->execute();
$res_pendientes = $stmt_pend->get_result();
$count_pendientes = $res_pendientes->num_rows;
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <title>Admin Usuarios | Nubira Admin</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="theme-color" content="#ffffff" />
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="icon" type="image/webp" href="/img/logo2.webp">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
    body { font-family: 'Inter', sans-serif; background-color: #ffffff; -webkit-tap-highlight-color: transparent;}
    .scrollbar-hide::-webkit-scrollbar { height: 6px; }
    .scrollbar-hide::-webkit-scrollbar-thumb { background-color: #e2e8f0; border-radius: 10px; }
    .force-no-shadow * { text-shadow: none !important; }
    select { -webkit-appearance: none; -moz-appearance: none; appearance: none; }
  </style>
</head>

<body class="text-slate-800 antialiased overflow-x-hidden force-no-shadow">

<?php 
$institucion = "Panel Admin";
$nombre_usuario = $_SESSION['usuario_nombre'] ?? 'Admin';
$foto_perfil = $_SESSION['foto'] ?? 'default.png';
$rol = 'admin';
$es_admin = true;
$page_title = "Gestión de Usuarios";

require_once $app_dir . '/componentes/header.php'; 
require_once $app_dir . '/componentes/sidebar.php'; 
?>

<main class="pt-16 pb-32 md:pb-16 lg:ml-64 px-4 md:px-6 w-full md:w-[calc(100%-16rem)]">
  <div class="max-w-7xl mx-auto space-y-6">

   <div class="sticky top-16 bg-white/95 backdrop-blur-sm z-30 border-b border-slate-100 py-4 mb-6 flex flex-col gap-3">

        <!-- Fila 1: título + stats + filtros -->
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div class="flex items-center gap-6">
                <div>
                    <h1 class="text-xl md:text-2xl font-extrabold text-slate-900 tracking-tight">Usuarios</h1>
                    <p class="text-slate-400 text-xs font-medium mt-0.5">Gestión, bloqueo y auditoría de cuentas.</p>
                </div>

                <div class="hidden md:flex items-center gap-3 bg-slate-50 border border-slate-100 px-4 py-2 rounded-xl">
                    <div class="bg-blue-50 p-2 rounded-lg text-[#54A6D8]">
                        <i class="fa-solid fa-users text-lg"></i>
                    </div>
                    <div>
                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest leading-none mb-0.5">Total</p>
                        <p class="text-xl font-black text-slate-900 leading-none"><?= number_format($total_users_global) ?></p>
                    </div>
                    <div class="h-8 w-px bg-slate-200 mx-2"></div>
                    <a href="/app/live_counter.php" target="_blank" class="text-xs font-bold text-[#54A6D8] active:text-blue-600 transition-colors flex items-center gap-1 uppercase tracking-wide">
                        <i class="fa-solid fa-expand"></i> Live
                    </a>
                </div>
            </div>

            <form class="flex flex-col md:flex-row gap-2 w-full md:w-auto" method="GET">
                <?php if ($tab === 'pendientes'): ?>
                    <input type="hidden" name="tab" value="pendientes">
                <?php endif; ?>
                <div class="relative w-full md:w-auto">
                    <select name="rol" onchange="this.form.submit()" class="w-full md:w-auto bg-slate-50 border border-slate-200 rounded-xl px-4 pr-10 py-2.5 text-sm focus:ring-0 focus:border-[#54A6D8] focus:bg-white transition-colors cursor-pointer outline-none font-medium">
                        <option value="">Todos los Roles</option>
                        <option value="admin" <?= $filtro_rol=='admin'?'selected':'' ?>>Administradores</option>
                        <option value="alumno" <?= $filtro_rol=='alumno'?'selected':'' ?>>Alumnos</option>
                    </select>
                    <i class="fa-solid fa-chevron-down absolute right-4 top-1/2 transform -translate-y-1/2 text-slate-400 text-xs pointer-events-none"></i>
                </div>

                <div class="relative w-full md:w-64">
                    <input type="text" name="q" value="<?= htmlspecialchars($busqueda) ?>" placeholder="Buscar usuario..."
                           class="w-full bg-slate-50 border border-slate-200 rounded-xl pl-4 pr-10 py-2.5 text-sm focus:ring-0 focus:border-[#54A6D8] focus:bg-white transition-colors outline-none font-medium placeholder-slate-400">
                    <button type="submit" class="absolute right-3 top-2.5 text-slate-400 active:text-[#54A6D8]">
                        <i class="fa-solid fa-search"></i>
                    </button>
                </div>
            </form>
        </div>

        <!-- Fila 2: tabs -->
        <div class="flex items-center gap-2 pt-1">
            <?php
            $q_str = $busqueda ? '&q=' . urlencode($busqueda) : '';
            $rol_str = $filtro_rol ? '&rol=' . urlencode($filtro_rol) : '';
            ?>
            <a href="?tab=todos<?= $q_str . $rol_str ?>"
               class="<?= $tab === 'todos' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-500 hover:bg-slate-200' ?> px-3.5 py-1.5 rounded-lg text-xs font-bold transition-colors">
                Todos
            </a>
            <a href="?tab=pendientes<?= $q_str . $rol_str ?>"
               class="<?= $tab === 'pendientes' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-500 hover:bg-slate-200' ?> px-3.5 py-1.5 rounded-lg text-xs font-bold transition-colors flex items-center gap-2">
                Pendientes de verificación
                <?php if ($count_pendientes > 0): ?>
                    <span class="<?= $tab === 'pendientes' ? 'bg-white text-slate-900' : 'bg-red-500 text-white' ?> text-[10px] font-black h-4 min-w-[16px] px-1 rounded-full flex items-center justify-center">
                        <?= $count_pendientes ?>
                    </span>
                <?php endif; ?>
            </a>
        </div>

    </div>

    <?php if (isset($_SESSION['toast'])): ?>
        <div id="toast" class="fixed bottom-6 md:top-24 right-1/2 translate-x-1/2 md:translate-x-0 md:right-5 z-50 bg-slate-800 text-white px-5 py-3 rounded-xl flex items-center gap-3 animate-bounce">
            <i class="fa-solid <?= strpos($_SESSION['toast'], '❌') !== false || strpos($_SESSION['toast'], '⛔') !== false || strpos($_SESSION['toast'], '🗑️') !== false ? 'fa-circle-exclamation text-red-400' : 'fa-check-circle text-emerald-400' ?>"></i>
            <span class="text-sm font-bold tracking-wide"><?= htmlspecialchars($_SESSION['toast']) ?></span>
        </div>
        <?php unset($_SESSION['toast']); ?>
        <script>setTimeout(()=>document.getElementById('toast').remove(), 3500);</script>
    <?php endif; ?>

    <?php if ($tab === 'todos'): ?>
    <div class="bg-white border border-slate-100 rounded-3xl overflow-hidden min-h-[400px]">
       <div class="overflow-x-auto scrollbar-hide">
         <table class="w-full min-w-[1000px] text-sm text-left">
           <thead class="text-[11px] text-slate-400 uppercase tracking-widest bg-slate-50 border-b border-slate-100">
             <tr>
               <th class="px-4 py-4 font-bold text-center w-16">ID</th>
               <th class="px-4 py-4 font-bold">Usuario</th>
               <th class="px-4 py-4 font-bold w-24">Registrado</th>
               <th class="px-4 py-4 font-bold w-32">Estado</th>
               <th class="px-4 py-4 font-bold text-center w-36">Estadísticas</th>
               <th class="px-4 py-4 font-bold text-center w-24">Rol</th>
               <th class="px-4 py-4 font-bold text-right w-44">Acciones</th>
             </tr>
           </thead>
           <tbody class="divide-y divide-slate-50">
           <?php if ($res && $res->num_rows > 0): ?>
               <?php while ($u = $res->fetch_assoc()): 
                   $bloqueado = $u['bloqueado'] ?? 0;
                   $es_admin_row = $u['rol'] === 'admin';
                   $bg_row = $bloqueado ? 'bg-red-50/50' : 'hover:bg-slate-50';
                   $link_perfil = "/perfil/" . $u['id'];
                   $es_reciente = (strtotime($u['fecha_registro']) > strtotime('-48 hours'));
               ?>
                 <tr class="<?= $bg_row ?> transition-colors align-middle group">
                   
                   <td class="px-4 py-4 text-center text-slate-400 font-mono text-xs">#<?= $u['id'] ?></td>
                   
                   <td class="px-4 py-4">
                       <div class="flex items-center gap-3">
                           <?php if (!empty($u['foto_perfil'])): ?>
                               <img src="/app/perfil/fotos/<?= htmlspecialchars($u['foto_perfil']) ?>" 
                                    loading="lazy" decoding="async" alt="Foto"
                                    class="w-10 h-10 rounded-xl object-cover border border-slate-200">
                           <?php else: ?>
                               <div class="w-10 h-10 rounded-xl bg-slate-100 flex items-center justify-center font-bold text-slate-400 text-sm">
                                   <?= strtoupper(substr($u['nombre'] ?? 'U',0,1)) ?>
                               </div>
                           <?php endif; ?>
                           
                           <div class="min-w-0">
                               <div class="flex items-center gap-2">
                                   <a href="<?= $link_perfil ?>" target="_blank" class="font-bold text-slate-900 text-sm active:text-[#54A6D8] transition-colors truncate max-w-[150px] inline-block" title="Ver perfil">
                                       <?= htmlspecialchars($u['nombre'] ?? 'Sin Nombre') ?>
                                   </a>
                                   <i class="fa-solid fa-arrow-up-right-from-square text-[10px] text-slate-300 opacity-0 group-hover:opacity-100 transition-opacity"></i>
                                   <?php if($es_reciente): ?>
                                       <span class="bg-emerald-50 text-emerald-600 text-[9px] px-1.5 py-0.5 rounded font-bold uppercase">Nuevo</span>
                                   <?php endif; ?>
                               </div>
                               
                               <div class="flex items-center gap-2 mt-0.5">
                                   <p class="text-xs text-slate-500 font-medium truncate max-w-[150px]" title="<?= htmlspecialchars($u['correo'] ?? '') ?>"><?= htmlspecialchars($u['correo'] ?? '') ?></p>
                                   <button onclick="copiarTexto('<?= htmlspecialchars($u['correo']) ?>')" class="text-slate-300 hover:text-[#54A6D8] transition-colors" title="Copiar Correo">
                                       <i class="fa-regular fa-copy text-[10px]"></i>
                                   </button>
                               </div>
                           </div>
                       </div>
                   </td>
                   
                   <td class="px-4 py-4 text-xs text-slate-500 font-medium whitespace-nowrap">
                       <?php if(!empty($u['fecha_registro'])): ?>
                           <?= date('d/m/Y', strtotime($u['fecha_registro'])) ?>
                           <br>
                           <span class="text-[10px] text-slate-400"><?= date('H:i', strtotime($u['fecha_registro'])) ?></span>
                       <?php else: ?>
                           <span class="text-slate-300">-</span>
                       <?php endif; ?>
                   </td>

                   <td class="px-4 py-4">
                       <?php if($bloqueado): ?>
                           <span class="inline-flex items-center gap-1 bg-red-50 text-red-600 px-2.5 py-1 rounded-md text-[9px] font-bold uppercase tracking-widest"><i class="fa-solid fa-ban"></i> Banned</span>
                       <?php elseif(!empty($u['confirmado'])): ?>
                           <span class="inline-flex items-center gap-1 bg-emerald-50 text-emerald-600 px-2.5 py-1 rounded-md text-[9px] font-bold uppercase tracking-widest"><span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Activo</span>
                       <?php else: ?>
                           <div class="flex flex-col items-start gap-1">
                               <span class="inline-flex items-center gap-1 bg-amber-50 text-amber-600 px-2.5 py-1 rounded-md text-[9px] font-bold uppercase tracking-widest">Pendiente</span>
                               <?php if(!empty($u['ultimo_reenvio'])): ?>
                                   <span class="text-[9px] font-bold text-[#54A6D8] flex items-center gap-1 uppercase tracking-widest" title="Último reenvío manual">
                                       <i class="fa-solid fa-check-double"></i> Enviado <?= date('d/m', strtotime($u['ultimo_reenvio'])) ?>
                                   </span>
                               <?php endif; ?>
                           </div>
                       <?php endif; ?>
                   </td>

                   <td class="px-4 py-4 text-center">
                       <div class="flex justify-center gap-3 text-xs">
                           <div class="text-center" title="Servicios Publicados">
                               <span class="block font-black text-blue-600"><?= $u['total_servicios'] ?></span>
                               <span class="text-slate-400 text-[9px] font-bold uppercase tracking-widest">Pubs</span>
                           </div>
                           <div class="text-center" title="Apuntes Subidos">
                               <span class="block font-black text-emerald-600"><?= $u['total_apuntes'] ?></span>
                               <span class="text-slate-400 text-[9px] font-bold uppercase tracking-widest">Apus</span>
                           </div>
                           <div class="text-center" title="Reclamos Realizados">
                               <span class="block font-black <?= $u['total_reclamos'] > 0 ? 'text-red-500' : 'text-slate-400' ?>"><?= $u['total_reclamos'] ?></span>
                               <span class="text-slate-400 text-[9px] font-bold uppercase tracking-widest">Rep</span>
                           </div>
                       </div>
                   </td>

                   <td class="px-4 py-4 text-center">
                       <span class="px-2.5 py-1 rounded-md text-[9px] font-black uppercase tracking-widest <?= $es_admin_row ? 'bg-purple-50 text-purple-600' : 'bg-slate-100 text-slate-500' ?>">
                           <?= htmlspecialchars($u['rol'] ?? 'alumno') ?>
                       </span>
                   </td>

                   <td class="px-4 py-4 text-right">
                     <div class="flex items-center justify-end gap-1.5 opacity-100 md:opacity-60 md:group-hover:opacity-100 transition-opacity">
                         
                         <?php if (!$bloqueado && empty($u['confirmado'])): 
                             $ya_enviado = !empty($u['ultimo_reenvio']);
                             $btn_class = $ya_enviado ? 'bg-slate-100 text-slate-400 active:bg-slate-200' : 'bg-blue-50 text-[#54A6D8] active:bg-blue-100';
                         ?>
                             <form method="POST" onsubmit="return confirm('¿Reenviar el correo de activación a <?= htmlspecialchars($u['nombre']) ?>?');" class="inline">
                                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                <input type="hidden" name="accion" value="reenviar_confirmacion">
                                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                <button class="<?= $btn_class ?> p-2 rounded-xl transition-colors text-xs" title="<?= $ya_enviado ? 'Volver a Enviar Correo' : 'Enviar Correo de Confirmación' ?>">
                                    <i class="fa-solid fa-envelope<?= $ya_enviado ? '-circle-check' : '' ?>"></i>
                                </button>
                             </form>
                         <?php endif; ?>

                         <button onclick='abrirModalEditar(<?= json_encode([
                             "id" => $u['id'],
                             "nombre" => $u['nombre'] ?? "",
                             "correo" => $u['correo'] ?? ""
                         ]) ?>)' class="bg-slate-50 active:bg-slate-100 text-slate-500 p-2 rounded-xl transition-colors text-xs" title="Editar Datos">
                             <i class="fa-solid fa-pen"></i>
                         </button>

                         <form method="POST" onsubmit="return confirm('⚠️ ¿Cambiar rol a <?= htmlspecialchars($u['nombre']) ?>?\n\nSi lo haces Admin, tendrá acceso total a este panel.');" class="inline">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                            <input type="hidden" name="accion" value="cambiar_rol">
                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                            <input type="hidden" name="rol_actual" value="<?= $u['rol'] ?>">
                            <button class="bg-slate-50 active:bg-purple-50 text-purple-600 p-2 rounded-xl transition-colors text-xs" title="Cambiar Rol">
                                <i class="fa-solid fa-user-shield"></i>
                            </button>
                         </form>

                         <form method="POST" onsubmit="return confirm('🚨 ¿ESTÁS SEGURO?\n\nEstás a punto de <?= $bloqueado ? 'DESBLOQUEAR' : 'BLOQUEAR' ?> a <?= htmlspecialchars($u['nombre']) ?>.\n\nEsto afectará su acceso a la plataforma.');" class="inline">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                            <input type="hidden" name="accion" value="toggle_ban">
                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                            <input type="hidden" name="nuevo_estado" value="<?= $bloqueado ? 0 : 1 ?>">
                            <?php if($bloqueado): ?>
                                <button class="bg-emerald-50 active:bg-emerald-100 text-emerald-600 p-2 rounded-xl transition-colors text-xs" title="Desbloquear">
                                    <i class="fa-solid fa-unlock"></i>
                                </button>
                            <?php else: ?>
                                <button class="bg-amber-50 active:bg-amber-100 text-amber-600 p-2 rounded-xl transition-colors text-xs" title="Bloquear Temporalmente">
                                    <i class="fa-solid fa-ban"></i>
                                </button>
                            <?php endif; ?>
                         </form>

                         <form method="POST" onsubmit="return confirm('☢️ ¿Confirmas el Borrado Lógico de <?= htmlspecialchars($u['nombre']) ?>?\n\nEl usuario será ocultado del sistema (visible = 0) para mantener la integridad de los datos financieros/históricos asociados.');" class="inline">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                            <input type="hidden" name="accion" value="eliminar_usuario">
                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                            <button class="bg-red-50 active:bg-red-100 text-red-500 p-2 rounded-xl transition-colors text-xs" title="Eliminar (Soft Delete)">
                                <i class="fa-solid fa-trash-can"></i>
                            </button>
                         </form>

                     </div>
                   </td>
                 </tr>
               <?php endwhile; ?>
           <?php else: ?>
             <tr><td colspan="7" class="text-center py-16 text-slate-400 flex flex-col items-center justify-center">
                <i class="fa-solid fa-user-slash text-4xl mb-3 text-slate-200"></i>
                <span class="font-medium text-sm">No se encontraron usuarios activos.</span>
             </td></tr>
           <?php endif; ?>
           </tbody>
         </table>
       </div>

       <?php if ($total_pages > 1): ?>
        <div class="flex justify-center p-4 gap-2 border-t border-slate-100 bg-white">
            <?php for($i=1; $i<=$total_pages; $i++): ?>
                <a href="?page=<?= $i ?>&q=<?= urlencode($busqueda) ?>&rol=<?= urlencode($filtro_rol) ?>" 
                   class="w-8 h-8 flex items-center justify-center rounded-xl text-xs font-bold transition-all <?= $i==$page ? 'bg-[#54A6D8] text-white' : 'bg-slate-50 text-slate-600 active:bg-slate-100' ?>">
                   <?= $i ?>
                </a>
            <?php endfor; ?>
        </div>
       <?php endif; ?>
    </div>
    <?php else: ?>

    <!-- TABLA: PENDIENTES DE VERIFICACIÓN -->
    <div class="bg-white border border-slate-100 rounded-3xl overflow-hidden min-h-[400px]">
      <?php if ($count_pendientes === 0): ?>
        <div class="flex flex-col items-center justify-center py-20 text-slate-400">
          <i class="fa-solid fa-user-check text-4xl mb-3 text-slate-200"></i>
          <p class="font-medium text-sm">Sin solicitudes pendientes de verificación.</p>
        </div>
      <?php else: ?>
      <div class="overflow-x-auto scrollbar-hide">
        <table class="w-full min-w-[900px] text-sm text-left">
          <thead class="text-[11px] text-slate-400 uppercase tracking-widest bg-slate-50 border-b border-slate-100">
            <tr>
              <th class="px-4 py-4 font-bold text-center w-16">ID</th>
              <th class="px-4 py-4 font-bold">Usuario</th>
              <th class="px-4 py-4 font-bold w-28">Tipo declarado</th>
              <th class="px-4 py-4 font-bold">Carrera / Bio</th>
              <th class="px-4 py-4 font-bold w-28">Registrado</th>
              <th class="px-4 py-4 font-bold text-right w-44">Acciones</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-50">
          <?php while ($p = $res_pendientes->fetch_assoc()): ?>
            <tr class="hover:bg-slate-50 transition-colors align-middle group">

              <td class="px-4 py-4 text-center text-slate-400 font-mono text-xs">#<?= $p['id'] ?></td>

              <td class="px-4 py-4">
                <div class="min-w-0">
                  <p class="font-bold text-slate-900 text-sm truncate max-w-[180px]"><?= htmlspecialchars($p['nombre'] ?? '') ?></p>
                  <div class="flex items-center gap-1.5 mt-0.5">
                    <p class="text-xs text-slate-500 font-medium truncate max-w-[160px]"><?= htmlspecialchars($p['correo'] ?? '') ?></p>
                    <button onclick="copiarTexto('<?= htmlspecialchars($p['correo'] ?? '') ?>')" class="text-slate-300 hover:text-[#54A6D8] transition-colors" title="Copiar correo">
                      <i class="fa-regular fa-copy text-[10px]"></i>
                    </button>
                  </div>
                </div>
              </td>

              <td class="px-4 py-4">
                <?php
                $tipos = ['estudiante' => 'Estudiante', 'egresado' => 'Egresado', 'profesor' => 'Profesor', 'particular' => 'Particular'];
                $tipo_label = $tipos[$p['tipo'] ?? ''] ?? ($p['tipo'] ? ucfirst($p['tipo']) : '—');
                ?>
                <span class="bg-slate-100 text-slate-600 px-2.5 py-1 rounded-md text-[10px] font-bold uppercase tracking-widest">
                  <?= htmlspecialchars($tipo_label) ?>
                </span>
              </td>

              <td class="px-4 py-4">
                <p class="text-xs text-slate-700 font-medium truncate max-w-[200px]">
                  <?= !empty($p['carrera']) ? htmlspecialchars($p['carrera']) : '<span class="text-slate-300">Sin carrera</span>' ?>
                </p>
                <?php if (!empty(trim($p['bio'] ?? ''))): ?>
                  <p class="text-[11px] text-slate-400 mt-0.5 truncate max-w-[200px]"><?= htmlspecialchars($p['bio']) ?></p>
                <?php endif; ?>
              </td>

              <td class="px-4 py-4 text-xs text-slate-500 font-medium whitespace-nowrap">
                <?= !empty($p['fecha_registro']) ? date('d/m/Y', strtotime($p['fecha_registro'])) : '—' ?>
                <?php if (!empty($p['fecha_registro'])): ?>
                  <br><span class="text-[10px] text-slate-400"><?= date('H:i', strtotime($p['fecha_registro'])) ?></span>
                <?php endif; ?>
              </td>

              <td class="px-4 py-4 text-right">
                <div class="flex items-center justify-end gap-2">
                  <form method="POST" onsubmit="return confirm('¿Aprobar la cuenta de <?= htmlspecialchars(addslashes($p['nombre'] ?? '')) ?>?\n\nSe le enviará un correo de bienvenida.');" class="inline">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="accion" value="aprobar_verificacion">
                    <input type="hidden" name="id" value="<?= $p['id'] ?>">
                    <button class="bg-emerald-50 active:bg-emerald-100 text-emerald-700 px-3 py-1.5 rounded-xl text-xs font-bold transition-colors flex items-center gap-1.5">
                      <i class="fa-solid fa-check"></i> Aprobar
                    </button>
                  </form>
                  <form method="POST" onsubmit="return confirm('¿Rechazar la cuenta de <?= htmlspecialchars(addslashes($p['nombre'] ?? '')) ?>?\n\nSe le enviará un correo de aviso.');" class="inline">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="accion" value="rechazar_verificacion">
                    <input type="hidden" name="id" value="<?= $p['id'] ?>">
                    <button class="bg-red-50 active:bg-red-100 text-red-600 px-3 py-1.5 rounded-xl text-xs font-bold transition-colors flex items-center gap-1.5">
                      <i class="fa-solid fa-xmark"></i> Rechazar
                    </button>
                  </form>
                </div>
              </td>

            </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <?php endif; ?>

  </div>
</main>

<?php 
require_once $app_dir . '/componentes/nav_bottom.php'; 
require_once $app_dir . '/componentes/modal_publicar.php'; 
require_once $app_dir . '/componentes/modal_explora.php'; 
?>

<div id="modal-editar" class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-[70] hidden flex items-center justify-center transition-opacity p-4">
  <form method="POST" class="bg-white p-6 md:p-8 rounded-3xl w-full max-w-sm relative">
    <button type="button" onclick="document.getElementById('modal-editar').classList.add('hidden')" class="absolute top-5 right-5 text-slate-400 active:text-slate-600 w-8 h-8 bg-slate-50 rounded-full flex items-center justify-center transition-colors">
        <i class="fa-solid fa-xmark text-sm"></i>
    </button>

    <h3 class="text-lg font-bold text-slate-900 mb-6 flex items-center gap-2">
        <i class="fa-solid fa-user-pen text-[#54A6D8]"></i> Editar Usuario
    </h3>
    
    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
    <input type="hidden" name="accion" value="editar_usuario">
    <input type="hidden" name="id" id="edit_id">
    
    <div class="space-y-4">
        <div class="space-y-1.5">
            <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-widest pl-1">Nombre Completo</label>
            <input type="text" name="nombre" id="edit_nombre" required class="w-full border border-slate-200 bg-slate-50 px-4 py-3.5 rounded-2xl text-sm focus:ring-0 focus:border-[#54A6D8] focus:bg-white outline-none font-medium text-slate-800 transition-colors">
        </div>
        <div class="space-y-1.5">
            <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-widest pl-1">Correo Institucional</label>
            <input type="email" name="correo" id="edit_correo" required class="w-full border border-slate-200 bg-slate-50 px-4 py-3.5 rounded-2xl text-sm focus:ring-0 focus:border-[#54A6D8] focus:bg-white outline-none font-medium text-slate-800 transition-colors">
        </div>
    </div>

    <div class="flex justify-end gap-3 mt-8 pt-6 border-t border-slate-100">
      <button type="button" onclick="document.getElementById('modal-editar').classList.add('hidden')" class="px-5 py-3 rounded-2xl text-xs font-bold text-slate-500 active:bg-slate-50 transition-colors">Cancelar</button>
      <button type="submit" class="bg-[#54A6D8] active:bg-blue-600 text-white px-6 py-3 rounded-2xl text-xs font-bold transition-colors border border-transparent">Guardar Cambios</button>
    </div>
  </form>
</div>

<script>
    function abrirModalEditar(user) {
        document.getElementById('edit_id').value = user.id;
        document.getElementById('edit_nombre').value = user.nombre;
        document.getElementById('edit_correo').value = user.correo;
        document.getElementById('modal-editar').classList.remove('hidden');
    }

    function copiarTexto(texto) {
        navigator.clipboard.writeText(texto).then(() => {
            const t = document.createElement('div');
            t.className = 'fixed bottom-5 left-1/2 -translate-x-1/2 bg-slate-800 text-white font-bold text-xs px-4 py-2 rounded-full z-50 animate-fade-in-up';
            t.innerHTML = '<i class="fa-solid fa-check mr-1 text-emerald-400"></i> Copiado';
            document.body.appendChild(t);
            setTimeout(() => t.remove(), 2000);
        });
    }
    
    // SISTEMA DE MODALES NUBIRA 2.0 (Para el Nav Bottom)
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
                open(); 
            }; 
            if(close) close.onclick = shut; 
            modal.onclick = (e) => { if(e.target === modal) shut(); };
        }
    };

    document.addEventListener('DOMContentLoaded', () => {
        NubiraModales.setup('btn-publicar', 'modal-quick', 'quick-card', 'quick-close');
        NubiraModales.setup('btn-explora', 'modal-explora', 'explora-card', 'explora-close');
    });
</script>

</body>
</html>