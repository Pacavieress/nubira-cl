<?php
/**
 * VISTA: GESTOR DE ARCHIVOS
 * ESTADO: RUTA PERSONALIZADA 'upload_mini_aula'
 */
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

// =========================================================================
// 1. CONFIGURACIÓN DE LA CARPETA (CRÍTICO)
// =========================================================================
// Nombre exacto de la carpeta que creaste en public_html
$NOMBRE_CARPETA = 'upload_mini_aula'; 

$root = rtrim($_SERVER['DOCUMENT_ROOT'], '/'); 
$ruta_fisica = $root . '/' . $NOMBRE_CARPETA;

// Diagnóstico de carpeta
if (!is_dir($ruta_fisica)) {
    die("<div class='bg-red-100 text-red-800 p-4 font-bold border-l-4 border-red-500'>
        Error: El sistema no encuentra la carpeta: <br><code>$ruta_fisica</code><br>
        Por favor créala manualmente en tu hosting o revisa el nombre.
    </div>");
}
if (!is_writable($ruta_fisica)) {
    // Intentar arreglar permisos al vuelo
    @chmod($ruta_fisica, 0777);
    if (!is_writable($ruta_fisica)) {
        die("<div class='bg-yellow-100 text-yellow-800 p-4 font-bold border-l-4 border-yellow-500'>
            Permisos insuficientes: La carpeta <code>$NOMBRE_CARPETA</code> existe pero no puedo guardar archivos en ella.<br>
            Entra a tu FTP/cPanel y dale permisos 777.
        </div>");
    }
}

// =========================================================================
// 2. CONFIGURACIÓN APP
// =========================================================================
$app_dir = __DIR__;
require_once $app_dir . '/conexion.php';
require_once $app_dir . '/iconos.php';

if (!isset($_SESSION['usuario_id'])) exit("Acceso denegado.");

$usuario_id = (int)$_SESSION['usuario_id'];
$contrato_id = (int)($_GET['id'] ?? 0);
$es_admin = (($_SESSION['rol'] ?? '') === 'admin');

// Verificar Contrato
$sql = "SELECT id, comprador_id, vendedor_id, estado FROM contratos WHERE id = ? LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $contrato_id);
$stmt->execute();
$contrato = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$contrato || (!$es_admin && $contrato['comprador_id'] != $usuario_id && $contrato['vendedor_id'] != $usuario_id)) {
    die("<div class='flex flex-col items-center justify-center h-screen text-gray-400 text-sm gap-2'><i class='fa-solid fa-lock text-2xl'></i><span>Sin acceso al contrato #$contrato_id.</span></div>");
}

$puede_subir = !in_array($contrato['estado'], ['cancelado', 'finalizado']);
$max_size_mb = 50;

// =========================================================================
// 3. PROCESAR SUBIDA
// =========================================================================
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['archivo']) && $puede_subir) {
    $file = $_FILES['archivo'];
    
    if ($file['error'] === UPLOAD_ERR_OK) {
        $nombre_original = basename($file['name']);
        $ext = strtolower(pathinfo($nombre_original, PATHINFO_EXTENSION));
        $permitidos = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'zip', 'rar', '7z', 'txt', 'mp4', 'mov'];
        
        if ($file['size'] > $max_size_mb * 1024 * 1024) {
            $error = "El archivo supera el límite de {$max_size_mb}MB.";
        } elseif (in_array($ext, $permitidos)) {
            
            // Usamos la ruta física configurada arriba
            $nombre_final = $contrato_id . '_' . time() . '_' . uniqid() . '.' . $ext;
            $destino_final = $ruta_fisica . '/' . $nombre_final;
            
            if (move_uploaded_file($file['tmp_name'], $destino_final)) {
                $peso = round($file['size'] / 1024);
                $tipo = $file['type'];
                
                // Guardamos solo el nombre del archivo en la BD
                $stmtIns = $conn->prepare("INSERT INTO contrato_archivos (contrato_id, usuario_id, nombre_original, ruta_archivo, tipo_mime, peso_kb, fecha) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                
                if ($stmtIns) {
                    $stmtIns->bind_param("iisssi", $contrato_id, $usuario_id, $nombre_original, $nombre_final, $tipo, $peso);
                    if ($stmtIns->execute()) {
                        header("Location: ?id=$contrato_id&ok=1");
                        exit;
                    } else {
                        $error = "Error BD: " . $stmtIns->error;
                    }
                    $stmtIns->close();
                } else {
                    $error = "Error SQL Prepare: " . $conn->error;
                }
            } else {
                $sys_err = error_get_last();
                $error = "No se pudo escribir en: $destino_final. " . ($sys_err['message'] ?? '');
            }
        } else {
            $error = "Formato .$ext no permitido.";
        }
    } else {
        $error = "Error PHP Upload: Código " . $file['error'];
    }
}

// 4. LISTAR
$sqlFiles = "SELECT f.*, u.nombre as subido_por_nombre 
             FROM contrato_archivos f 
             LEFT JOIN alumnos u ON f.usuario_id = u.id 
             WHERE f.contrato_id = ? 
             ORDER BY f.fecha DESC";
$stmtF = $conn->prepare($sqlFiles);
$stmtF->bind_param("i", $contrato_id);
$stmtF->execute();
$archivos = $stmtF->get_result();

function getIconoArchivo($ext) {
    switch($ext) {
        case 'pdf': return '<i class="fa-solid fa-file-pdf text-red-500 text-3xl"></i>';
        case 'doc': case 'docx': return '<i class="fa-solid fa-file-word text-blue-600 text-3xl"></i>';
        case 'xls': case 'xlsx': return '<i class="fa-solid fa-file-excel text-green-600 text-3xl"></i>';
        case 'zip': case 'rar': return '<i class="fa-solid fa-file-zipper text-yellow-500 text-3xl"></i>';
        case 'jpg': case 'jpeg': case 'png': return '<i class="fa-solid fa-file-image text-purple-500 text-3xl"></i>';
        default: return '<i class="fa-solid fa-file-lines text-gray-400 text-3xl"></i>';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #ffffff; }
        ::-webkit-scrollbar { width: 4px; }
        ::-webkit-scrollbar-thumb { background: #e5e7eb; border-radius: 4px; }
        .drag-active { border-color: #54A6D8 !important; background-color: #eff6ff !important; transform: scale(0.99); }
    </style>
</head>
<body class="h-screen flex flex-col overflow-hidden relative">

    <?php if (isset($_GET['ok'])): ?>
        <div id="toast" class="fixed top-4 left-1/2 -translate-x-1/2 bg-green-100 border border-green-200 text-green-700 px-4 py-2 rounded-lg shadow-lg z-50 text-sm font-bold flex items-center gap-2">
            <i class="fa-solid fa-circle-check"></i> Archivo guardado correctamente
        </div>
        <script>setTimeout(()=>document.getElementById('toast').remove(), 3000);</script>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="fixed top-4 left-1/2 -translate-x-1/2 bg-red-100 border border-red-200 text-red-700 px-6 py-4 rounded-xl shadow-xl z-50 max-w-sm text-center">
            <p class="font-bold text-sm mb-1">Error al subir</p>
            <p class="text-xs"><?= $error ?></p>
            <button onclick="this.parentElement.remove()" class="mt-2 text-xs underline">Cerrar</button>
        </div>
    <?php endif; ?>

    <?php if ($puede_subir): ?>
    <div class="p-4 bg-white z-10 shrink-0">
        <form action="" method="POST" enctype="multipart/form-data" id="upload-form">
            <label id="drop-zone" class="block border-2 border-dashed border-gray-300 rounded-xl bg-gray-50/50 p-6 flex flex-col items-center justify-center cursor-pointer transition-all hover:border-blue-400 hover:bg-blue-50/20 group relative">
                <div class="w-12 h-12 bg-white rounded-full shadow-sm border border-gray-100 flex items-center justify-center mb-2 text-blue-400 group-hover:scale-110 transition-transform">
                    <i class="fa-solid fa-cloud-arrow-up text-xl"></i>
                </div>
                <span class="text-sm font-bold text-gray-700">Subir archivo</span>
                <span class="text-xs text-gray-400">Clic o arrastrar a "<?= $NOMBRE_CARPETA ?>"</span>
                <input type="file" name="archivo" id="file-input" class="hidden" onchange="subirArchivo()">
                <div id="spinner" class="absolute inset-0 bg-white/90 hidden flex-col items-center justify-center backdrop-blur-sm z-20">
                    <div class="animate-spin h-6 w-6 border-2 border-blue-500 border-t-transparent rounded-full mb-1"></div>
                    <span class="text-xs font-bold text-blue-600">Subiendo...</span>
                </div>
            </label>
        </form>
    </div>
    <?php endif; ?>

    <div class="flex-1 overflow-y-auto p-4 pt-0 space-y-2">
        <?php if ($archivos->num_rows > 0): ?>
            <?php while ($f = $archivos->fetch_assoc()): 
                $ext = strtolower(pathinfo($f['nombre_original'], PATHINFO_EXTENSION));
                $es_mio = ($f['usuario_id'] == $usuario_id);
                // FIX CRÍTICO: RUTA WEB DE DESCARGA APUNTANDO A LA CARPETA CORRECTA
                $rutaWeb = '/' . $NOMBRE_CARPETA . '/' . htmlspecialchars($f['ruta_archivo']);
                $autor = $f['subido_por_nombre'] ?? 'Usuario';
            ?>
            <div class="flex items-center p-3 border rounded-lg hover:shadow-sm transition bg-white <?= $es_mio ? 'border-blue-100' : 'border-gray-100' ?>">
                <div class="w-10 h-10 flex items-center justify-center bg-gray-50 rounded border border-gray-100 shrink-0">
                    <?= getIconoArchivo($ext) ?>
                </div>
                <div class="ml-3 flex-1 min-w-0">
                    <p class="text-sm font-bold text-gray-800 truncate"><?= htmlspecialchars($f['nombre_original']) ?></p>
                    <p class="text-[10px] text-gray-500">
                        <span class="<?= $es_mio ? 'text-blue-500 font-bold' : '' ?>"><?= $es_mio ? 'Tú' : htmlspecialchars(strtok($autor, ' ')) ?></span> • 
                        <?= round($f['peso_kb']) ?> KB • <?= date('d/m H:i', strtotime($f['fecha'])) ?>
                    </p>
                </div>
                <a href="<?= $rutaWeb ?>" download class="w-8 h-8 flex items-center justify-center text-gray-400 hover:text-blue-500 hover:bg-blue-50 rounded-full transition">
                    <i class="fa-solid fa-download"></i>
                </a>
            </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="flex flex-col items-center justify-center h-full text-gray-300 py-10">
                <i class="fa-regular fa-folder-open text-3xl mb-2"></i>
                <p class="text-xs">Sin archivos</p>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function subirArchivo() {
            const input = document.getElementById('file-input');
            if (input.files.length > 0) {
                document.getElementById('spinner').classList.remove('hidden');
                document.getElementById('upload-form').submit();
            }
        }
        const dz = document.getElementById('drop-zone');
        if(dz){
            ['dragenter', 'dragover'].forEach(e => dz.addEventListener(e, (ev)=>{ ev.preventDefault(); dz.classList.add('drag-active'); }));
            ['dragleave', 'drop'].forEach(e => dz.addEventListener(e, (ev)=>{ ev.preventDefault(); dz.classList.remove('drag-active'); }));
            dz.addEventListener('drop', (e) => {
                const files = e.dataTransfer.files;
                if(files.length > 0) {
                    document.getElementById('file-input').files = files;
                    subirArchivo();
                }
            });
        }
    </script>
</body>
</html>