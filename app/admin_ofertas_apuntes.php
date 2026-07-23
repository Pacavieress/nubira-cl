<?php
/**
 * VISTA ADMIN: GESTOR DE PROMOS FLASH (APUNTES) - NUBIRA 2.0
 */
session_start();

ini_set('display_errors', 0);
error_reporting(E_ALL);

// 1. SEGURIDAD: Solo Administradores
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'admin') { 
    header('Location: /login'); 
    exit; 
}

// 2. CONEXIÓN (Búsqueda robusta de rutas)
$app_dir = __DIR__;
if (!file_exists($app_dir . '/conexion.php')) {
    $app_dir = dirname(__DIR__) . '/app';
    if (!file_exists($app_dir . '/conexion.php')) {
        $app_dir = $_SERVER['DOCUMENT_ROOT'] . '/app';
    }
}
require_once $app_dir . '/conexion.php';

// Iconos Nubira
if (file_exists($app_dir . '/iconos.php')) require_once $app_dir . '/iconos.php';
else if (!function_exists('icon')) { function icon($n, $c=''){ return "<i class='fa-solid fa-$n $c'></i>"; } }

$mensaje = '';

// 3. MOTOR CRUD: ACTIVAR / DESACTIVAR PROMO Y EDITAR PRECIO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Validar CSRF
    if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        $mensaje = '<div class="bg-red-50 text-red-600 p-4 rounded-xl mb-6 text-sm font-bold border border-red-200">Error: Token inválido.</div>';
    } else {
        $apunte_id = (int)($_POST['apunte_id'] ?? 0);

        // -- MODIFICAR PRECIO --
        if ($_POST['action'] === 'modificar_precio') {
            $nuevo_precio = (int)($_POST['nuevo_precio'] ?? 0);
            
            if ($apunte_id > 0 && $nuevo_precio >= 0) {
                $stmt = $conn->prepare("UPDATE apuntes SET precio = ? WHERE id = ?");
                $stmt->bind_param("ii", $nuevo_precio, $apunte_id);
                if ($stmt->execute()) {
                    $mensaje = '<div class="bg-blue-50 text-[#54A6D8] p-4 rounded-xl mb-6 text-sm font-bold border border-blue-200"><i class="fa-solid fa-circle-check"></i> Precio actualizado exitosamente.</div>';
                }
                $stmt->close();
            } else {
                $mensaje = '<div class="bg-red-50 text-red-600 p-4 rounded-xl mb-6 text-sm font-bold border border-red-200">Datos inválidos para el precio.</div>';
            }
        }
        // -- APLICAR PROMO --
        elseif ($_POST['action'] === 'aplicar_promo') {
            $cupos = (int)$_POST['cupos'];
            
            if ($apunte_id > 0 && $cupos > 0) {
                // Prender promo, asignar límite, reiniciar contador
                $stmt = $conn->prepare("UPDATE apuntes SET promo_gratis = 1, promo_limite = ?, promo_contador = 0 WHERE id = ?");
                $stmt->bind_param("ii", $cupos, $apunte_id);
                if ($stmt->execute()) {
                    $mensaje = '<div class="bg-green-50 text-green-700 p-4 rounded-xl mb-6 text-sm font-bold border border-green-200"><i class="fa-solid fa-circle-check"></i> Promo Flash activada. Descargas gratis habilitadas.</div>';
                }
                $stmt->close();
            } else {
                $mensaje = '<div class="bg-red-50 text-red-600 p-4 rounded-xl mb-6 text-sm font-bold border border-red-200">Datos inválidos. Ingresa los cupos.</div>';
            }
        } 
        // -- QUITAR PROMO --
        elseif ($_POST['action'] === 'quitar_promo') {
            if ($apunte_id > 0) {
                // Apagar promo, limpiar límites
                $stmt = $conn->prepare("UPDATE apuntes SET promo_gratis = 0, promo_limite = 0, promo_contador = 0 WHERE id = ?");
                $stmt->bind_param("i", $apunte_id);
                if ($stmt->execute()) {
                    $mensaje = '<div class="bg-gray-100 text-gray-700 p-4 rounded-xl mb-6 text-sm font-bold border border-gray-200"><i class="fa-solid fa-power-off"></i> Promo desactivada. Apunte devuelto a la normalidad.</div>';
                }
                $stmt->close();
            }
        }
    }
}

// Generar Token CSRF
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$CSRF = $_SESSION['csrf_token'];

// 4. TRAER LOS APUNTES DE LA BD CON FILTRO POR USUARIO
$apuntes = [];
$filtro_tutor = trim($_GET['tutor'] ?? '');

$sql = "SELECT ap.id, ap.titulo, ap.precio, ap.promo_gratis, ap.promo_limite, ap.promo_contador, a.nombre AS tutor_nombre 
        FROM apuntes ap 
        JOIN alumnos a ON ap.id_alumno = a.id 
        WHERE ap.estado = 'aprobado' ";

$params = [];
$types = "";

// Si hay búsqueda, filtramos por nombre y quitamos el límite para ver todo su catálogo
if ($filtro_tutor !== '') {
    $sql .= " AND a.nombre LIKE ? ";
    $params[] = "%" . $filtro_tutor . "%";
    $types .= "s";
    $sql .= " ORDER BY ap.promo_gratis DESC, ap.id DESC";
} else {
    // Si no hay filtro, mantenemos el límite de seguridad de 50
    $sql .= " ORDER BY ap.promo_gratis DESC, ap.id DESC LIMIT 50";
}

$stmt = $conn->prepare($sql);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $apuntes[] = $row;
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Promos Apuntes | Admin Nubira</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
    <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #f9fafb; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        input[type=number]::-webkit-inner-spin-button, 
        input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
    </style>
</head>
<body class="bg-gray-50 text-gray-900 antialiased overflow-x-hidden">

<?php 
if(file_exists($app_dir . '/componentes/header.php')) require_once $app_dir . '/componentes/header.php'; 
if(file_exists($app_dir . '/componentes/sidebar.php')) require_once $app_dir . '/componentes/sidebar.php'; 
?>

<main class="pt-24 pb-32 md:pb-16 lg:ml-64 px-4 md:px-8">
    <div class="w-full max-w-[1400px] mx-auto">
        
<div class="flex flex-col md:flex-row md:items-end justify-between gap-4 mb-8">
            <div>
                <h1 class="text-3xl font-extrabold text-gray-900 tracking-tight">Centro de Promos (Apuntes)</h1>
                <p class="text-sm text-gray-500 mt-1 font-medium">Regala descargas estratégicas limitadas sin registro.</p>
            </div>
            
            <form method="GET" class="flex items-center gap-2 w-full md:w-auto">
                <div class="relative w-full md:w-64">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <i class="fa-solid fa-search text-gray-400 text-sm"></i>
                    </div>
                    <input type="text" name="tutor" value="<?= htmlspecialchars($filtro_tutor, ENT_QUOTES, 'UTF-8') ?>" placeholder="Buscar por usuario..." 
                           class="w-full pl-9 pr-3 py-2 text-sm font-medium border border-gray-200 rounded-xl focus:ring-2 focus:ring-[#54A6D8] outline-none shadow-sm transition-all">
                </div>
                <button type="submit" class="bg-gray-900 text-white px-4 py-2 rounded-xl text-sm font-bold hover:bg-gray-800 transition-all shadow-sm">Buscar</button>
                
                <?php if($filtro_tutor !== ''): ?>
                    <a href="?" class="bg-gray-100 text-gray-500 px-3 py-2 rounded-xl text-sm font-bold hover:bg-gray-200 transition-all flex items-center justify-center" title="Limpiar filtro">
                        <i class="fa-solid fa-xmark"></i>
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <?= $mensaje ?>

        <div class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm whitespace-nowrap">
                    <thead class="bg-gray-50/80 text-gray-500 text-xs uppercase font-bold tracking-wider border-b border-gray-100">
                        <tr>
                            <th class="px-6 py-4">Apunte</th>
                            <th class="px-6 py-4">Tarifa Normal</th>
                            <th class="px-6 py-4">Estado Promo</th>
                            <th class="px-6 py-4 text-right">Acción</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        <?php foreach($apuntes as $ap): ?>
                        <tr class="hover:bg-gray-50/50 transition-colors <?= $ap['promo_gratis'] ? 'bg-orange-50/10' : '' ?>">
                            
                            <td class="px-6 py-4">
                                <p class="font-bold text-gray-900 line-clamp-1 max-w-[300px]"><?= htmlspecialchars($ap['titulo']) ?></p>
                                <p class="text-xs text-gray-500 mt-0.5">Por <?= htmlspecialchars($ap['tutor_nombre']) ?></p>
                            </td>
                            
                            <td class="px-6 py-4">
                                <form method="POST" class="flex items-center gap-2">
                                    <input type="hidden" name="csrf_token" value="<?= $CSRF ?>">
                                    <input type="hidden" name="action" value="modificar_precio">
                                    <input type="hidden" name="apunte_id" value="<?= $ap['id'] ?>">
                                    
                                    <span class="text-gray-400 font-bold">$</span>
                                    <input type="number" name="nuevo_precio" value="<?= $ap['precio'] ?>" required min="0" 
                                           class="w-24 px-2 py-1.5 text-sm font-bold text-gray-600 border border-gray-200 rounded-lg focus:ring-2 focus:ring-[#54A6D8] outline-none transition-all">
                                    <button type="submit" class="text-gray-400 hover:text-[#54A6D8] transition-colors p-1" title="Guardar Precio">
                                        <i class="fa-solid fa-floppy-disk"></i>
                                    </button>
                                </form>
                            </td>
                            
                            <td class="px-6 py-4">
                                <?php if ((int)$ap['promo_gratis'] === 1): ?>
                                    <?php if ((int)$ap['promo_contador'] < (int)$ap['promo_limite']): ?>
                                        <span class="inline-flex items-center px-2 py-1 rounded-md text-[10px] font-bold uppercase bg-orange-500 text-white shadow-sm mb-1">Activa</span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2 py-1 rounded-md text-[10px] font-bold uppercase bg-red-100 text-red-600 mb-1">Agotada</span>
                                    <?php endif; ?>
                                    <p class="text-xs font-bold text-gray-500">Usados: <?= (int)$ap['promo_contador'] ?>/<?= (int)$ap['promo_limite'] ?></p>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2 py-1 rounded-md text-[10px] font-bold uppercase bg-gray-100 text-gray-500">Normal</span>
                                <?php endif; ?>
                            </td>
                            
                            <td class="px-6 py-4 text-right">
                                <?php if($ap['promo_gratis']): ?>
                                    <form method="POST" onsubmit="return confirm('¿Apagar promo de este apunte?');">
                                        <input type="hidden" name="csrf_token" value="<?= $CSRF ?>">
                                        <input type="hidden" name="action" value="quitar_promo">
                                        <input type="hidden" name="apunte_id" value="<?= $ap['id'] ?>">
                                        <button type="submit" class="text-red-500 border border-red-200 hover:bg-red-500 hover:text-white font-bold text-xs px-4 py-2 rounded-xl transition-all">Apagar Promo</button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" class="flex items-center justify-end gap-2">
                                        <input type="hidden" name="csrf_token" value="<?= $CSRF ?>">
                                        <input type="hidden" name="action" value="aplicar_promo">
                                        <input type="hidden" name="apunte_id" value="<?= $ap['id'] ?>">
                                        
                                        <input type="number" name="cupos" placeholder="Cant. Descargas" required min="1" class="w-32 px-3 py-2 text-sm font-bold border border-gray-200 rounded-xl focus:ring-2 focus:ring-[#54A6D8] outline-none">
                                        <button type="submit" class="bg-[#54A6D8] text-white px-4 py-2 rounded-xl text-xs font-bold hover:bg-blue-600 transition-all shadow-sm">Liberar Gratis</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
    </div>
</main>

<?php if(file_exists($app_dir . '/componentes/nav_bottom.php')) require_once $app_dir . '/componentes/nav_bottom.php'; ?>
</body>
</html>
