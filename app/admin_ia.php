<?php
/**
 * NUBIRA AI DOJO - Panel de Entrenamiento
 * Ubicación: app/admin_ia.php
 * Versión: 16.0 (Stable & Debugged)
 */

// 1. CONFIGURACIÓN Y DEPURACIÓN
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// 2. SEGURIDAD
if (!isset($_SESSION['usuario_id'])) { 
    header("Location: /login"); 
    exit;
}

// 3. RUTAS ABSOLUTAS (Para evitar Error 500)
// Detectamos la raíz del servidor para incluir archivos sin errores
$doc_root = $_SERVER['DOCUMENT_ROOT']; 
$app_path = $doc_root . '/app'; 

// Carga de dependencias
if (file_exists($app_path . '/conexion.php')) require_once $app_path . '/conexion.php';
else die("❌ Error Crítico: No encuentro 'conexion.php' en $app_path");

if (file_exists($app_path . '/iconos.php')) require_once $app_path . '/iconos.php';

// 4. GESTIÓN DEL CEREBRO (JSON)
$json_path = $app_path . '/datos/sales_brain.json';
$php_path  = $app_path . '/datos/sales_brain.php';
$datos_dir = $app_path . '/datos';

// Verificar carpeta datos
if (!file_exists($datos_dir)) @mkdir($datos_dir, 0755, true);

// Migración Automática (PHP -> JSON)
if (!file_exists($json_path) && file_exists($php_path)) {
    $data = require $php_path;
    file_put_contents($json_path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// Cargar Cerebro
$brain = [];
if (file_exists($json_path)) {
    $brain = json_decode(file_get_contents($json_path), true);
} elseif (file_exists($php_path)) {
    $brain = require $php_path;
}

// Inicializar variable de mensaje
$mensaje = "";
$tipo_mensaje = ""; // 'success' o 'error'

// 5. PROCESAR ENTRENAMIENTO (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'train') {
        $cat = $_POST['category'];
        $new_kw = mb_strtolower(trim($_POST['keyword']), 'UTF-8'); 
        $apunte_id = intval($_POST['apunte_id']);
        
        if ($new_kw && isset($brain[$cat])) {
            if (!isset($brain[$cat]['keywords'])) $brain[$cat]['keywords'] = [];

            if (!in_array($new_kw, $brain[$cat]['keywords'])) {
                $brain[$cat]['keywords'][] = $new_kw;
                
                if(file_put_contents($json_path, json_encode($brain, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))){
                    $mensaje = "🧠 ¡Aprendido! Se agregó '<b>$new_kw</b>' a la categoría <b>".strtoupper($cat)."</b>.";
                    $tipo_mensaje = "success";
                    
                    // Opcional: Marcar como revisado en BD si tienes una columna para eso
                    // $conn->query("UPDATE apuntes SET ia_accepted = 2 WHERE id = $apunte_id");
                } else {
                    $mensaje = "❌ Error: No se pudo escribir en sales_brain.json. Revisa permisos.";
                    $tipo_mensaje = "error";
                }
            } else {
                $mensaje = "⚠️ Esa palabra ya existe en el cerebro de la IA.";
                $tipo_mensaje = "warning";
            }
        }
    }
}

// 6. CONSULTA DE CASOS (SQL BLINDADO)
// Verifica si la columna existe antes de consultar para evitar Fatal Error
$check_col = $conn->query("SHOW COLUMNS FROM apuntes LIKE 'categoria'");
$col_exists = ($check_col && $check_col->num_rows > 0);

if ($col_exists) {
    $sql = "SELECT id, titulo, descripcion, ia_keywords, categoria, fecha_subida 
            FROM apuntes 
            WHERE ia_used = 1 AND ia_accepted = 0 
            ORDER BY fecha_subida DESC LIMIT 20";
    $casos = $conn->query($sql);
} else {
    // Fallback si no has ejecutado el ALTER TABLE
    $mensaje = "⚠️ <b>ALERTA DE BASE DE DATOS:</b> Falta la columna 'categoria'. <br>Ejecuta: <code>ALTER TABLE apuntes ADD COLUMN categoria VARCHAR(50) DEFAULT 'general';</code>";
    $tipo_mensaje = "error";
    $casos = false;
}

// Contar vocabulario total
$total_kws = 0;
if($brain) {
    foreach($brain as $c) {
        if(isset($c['keywords'])) $total_kws += count($c['keywords']);
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Nubira Dojo | Entrenar IA</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #F8FAFC; }
        .card-shadow { box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03); }
        .success-msg { background-color: #F0FDF4; border-color: #BBF7D0; color: #166534; }
        .error-msg { background-color: #FEF2F2; border-color: #FECACA; color: #991B1B; }
        .warning-msg { background-color: #FFFBEB; border-color: #FDE68A; color: #92400E; }
    </style>
</head>
<body class="text-slate-800">

<?php 
// Carga segura de componentes visuales
if(file_exists($app_path . '/componentes/header.php')) include $app_path . '/componentes/header.php';
if(file_exists($app_path . '/componentes/sidebar.php')) include $app_path . '/componentes/sidebar.php'; 
?>

<main class="pt-24 pb-20 lg:ml-64 px-6 max-w-[1400px] mx-auto">

    <div class="flex flex-col md:flex-row md:items-end justify-between gap-4 mb-8">
        <div>
            <div class="flex items-center gap-2 text-purple-600 font-bold text-xs uppercase tracking-wider mb-1">
                <i class="fa-solid fa-brain"></i> Panel de Inteligencia
            </div>
            <h1 class="text-3xl font-bold text-slate-900">El Dojo de Entrenamiento</h1>
            <p class="text-slate-500 text-sm mt-1">Enseña a la IA basándote en las correcciones de los usuarios.</p>
        </div>
        <div class="flex gap-4">
            <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm min-w-[140px]">
                <p class="text-xs text-slate-400 font-bold uppercase">Pendientes</p>
                <p class="text-2xl font-bold text-orange-500"><?= ($casos && $casos->num_rows > 0) ? $casos->num_rows : 0 ?></p>
            </div>
            <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm min-w-[140px]">
                <p class="text-xs text-slate-400 font-bold uppercase">Vocabulario</p>
                <p class="text-2xl font-bold text-[#54A6D8]"><?= $total_kws ?></p>
            </div>
        </div>
    </div>

    <?php if($mensaje): ?>
        <div class="border px-6 py-4 rounded-xl mb-8 flex items-center gap-3 shadow-sm <?= $tipo_mensaje == 'error' ? 'error-msg' : ($tipo_mensaje == 'warning' ? 'warning-msg' : 'success-msg') ?>">
            <i class="fa-solid <?= $tipo_mensaje == 'error' ? 'fa-triangle-exclamation' : 'fa-check-circle' ?>"></i>
            <span><?= $mensaje ?></span>
        </div>
    <?php endif; ?>

    <?php if ($casos && $casos->num_rows > 0): ?>
        <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
            <?php while($row = $casos->fetch_assoc()): ?>
                <div class="bg-white border border-slate-200 rounded-2xl card-shadow overflow-hidden group hover:border-purple-200 transition-all p-6 relative">
                    
                    <div class="flex justify-between items-start mb-4 border-b border-slate-50 pb-4">
                        <div class="flex items-center gap-3">
                            <span class="text-[10px] font-bold bg-slate-100 text-slate-500 px-2 py-1 rounded">#<?= $row['id'] ?></span>
                            <div>
                                <h3 class="font-bold text-slate-800 leading-tight"><?= htmlspecialchars($row['titulo']) ?></h3>
                                <p class="text-[10px] text-slate-400 mt-1 uppercase font-bold">
                                    Categoría Detectada: 
                                    <span class="text-[#54A6D8]">
                                        <?= strtoupper(!empty($row['categoria']) ? $row['categoria'] : 'GENERAL') ?>
                                    </span>
                                </p>
                            </div>
                        </div>
                        <span class="text-[10px] text-slate-400 font-mono bg-slate-50 px-2 py-1 rounded">
                            <?= date('d/m H:i', strtotime($row['fecha_subida'])) ?>
                        </span>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm mb-4">
                        <div class="bg-slate-50 p-3 rounded-lg border border-slate-100 h-full">
                            <p class="text-[10px] font-bold text-slate-400 uppercase mb-2 flex items-center gap-1">
                                <i class="fa-solid fa-robot"></i> IA Keywords
                            </p>
                            <p class="text-slate-600 italic text-xs leading-relaxed">
                                <?= !empty($row['ia_keywords']) ? htmlspecialchars($row['ia_keywords']) : '<span class="text-slate-300">Sin datos</span>' ?>
                            </p>
                        </div>
                        
                        <div class="bg-orange-50 p-3 rounded-lg border border-orange-100 h-full">
                            <p class="text-[10px] font-bold text-orange-600 uppercase mb-2 flex items-center gap-1">
                                <i class="fa-solid fa-user-pen"></i> Texto Humano
                            </p>
                            <div class="text-slate-700 text-xs leading-relaxed max-h-24 overflow-y-auto custom-scrollbar">
                                <?= nl2br(htmlspecialchars($row['descripcion'])) ?>
                            </div>
                        </div>
                    </div>

                    <form method="POST" class="mt-auto pt-2 border-t border-slate-50">
                        <input type="hidden" name="action" value="train">
                        <input type="hidden" name="apunte_id" value="<?= $row['id'] ?>">
                        
                        <label class="block text-[10px] font-bold text-purple-600 uppercase mb-2">Enseñar nueva palabra clave:</label>
                        
                        <div class="flex gap-2">
                            <input type="text" name="keyword" placeholder="Ej: penal, hormigón..." required 
                                   class="flex-1 bg-white border border-slate-200 rounded-lg px-3 py-2 text-sm outline-none focus:border-purple-400 focus:ring-2 focus:ring-purple-100 transition">
                            
                            <select name="category" class="bg-white border border-slate-200 rounded-lg px-2 text-xs outline-none w-32 cursor-pointer hover:border-slate-300">
                                <?php foreach($brain as $key => $val): ?>
                                    <option value="<?= $key ?>" <?= $key == ($row['categoria'] ?? 'general') ? 'selected' : '' ?>>
                                        <?= ucfirst($key) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <button type="submit" class="bg-slate-900 text-white px-4 py-2 rounded-lg hover:bg-black transition shadow-md flex items-center justify-center" title="Aprender">
                                <i class="fa-solid fa-plus"></i>
                            </button>
                        </div>
                    </form>

                </div>
            <?php endwhile; ?>
        </div>
    <?php else: ?>
        
        <div class="flex flex-col items-center justify-center py-20 text-center bg-white border border-dashed border-slate-300 rounded-3xl">
            <div class="w-20 h-20 bg-green-50 rounded-full flex items-center justify-center mb-4">
                <i class="fa-solid fa-check-circle text-4xl text-green-500"></i>
            </div>
            <h2 class="text-xl font-bold text-slate-800">¡Todo limpio, Maestro!</h2>
            <p class="text-slate-500 max-w-md mt-2 text-sm">
                No hay discrepancias pendientes. La IA está sincronizada con los usuarios.<br>
                <?= !$col_exists ? '<br><span class="text-red-500 font-bold">Nota: Revisa el mensaje de error de la BD arriba.</span>' : '' ?>
            </p>
        </div>

    <?php endif; ?>

</main>
</body>
</html>