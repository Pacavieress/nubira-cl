<?php
/**
 * BACKEND: CARGAR MENSAJES (CORREGIDO Y ESTILIZADO)
 * UBICACIÓN: public_html/app/cargar_mensajes_chat_mini_aula.php
 * CONECTA CON: chat_mensajes (La tabla nueva que creamos)
 */

// Evitar caché para que los mensajes aparezcan al instante
header("Cache-Control: no-cache, must-revalidate");
ini_set('display_errors', 0);
if (session_status() === PHP_SESSION_NONE && !headers_sent()) session_start();

// 1. CONEXIÓN INTELIGENTE
$rutas = [__DIR__ . '/conexion.php', dirname(__DIR__) . '/conexion.php', __DIR__ . '/../conexion.php'];
$found = false;
foreach ($rutas as $ruta) {
    if (file_exists($ruta)) { require_once $ruta; $found = true; break; }
}
if (!$found) exit('<div class="text-red-500 text-xs p-2">Error: BD no encontrada</div>');

// 2. SEGURIDAD
if (!isset($_SESSION['usuario_id'])) exit;

$usuario_id = (int)$_SESSION['usuario_id'];
// Aceptamos ID por GET (AJAX) o variable global (Include)
$id_contrato = (int)($_GET['id'] ?? $GLOBALS['id_contrato'] ?? 0);

if ($id_contrato <= 0) exit;

// 3. ACTUALIZAR VISTO (Marcar mensajes del otro como leídos)
$conn->query("UPDATE chat_aula SET visto = 1 WHERE contrato_id = $id_contrato AND remitente_id != $usuario_id AND visto = 0");

// 4. CONSULTA
$sql = "SELECT * FROM chat_aula WHERE contrato_id = $id_contrato ORDER BY fecha ASC";
$res = $conn->query($sql);

if ($res->num_rows === 0): ?>
    <div class="flex flex-col items-center justify-center h-48 opacity-50 select-none animate-fade-in">
        <div class="w-12 h-12 bg-gray-100 rounded-full flex items-center justify-center mb-2">
            <i class="fa-regular fa-comments text-gray-400 text-xl"></i>
        </div>
        <p class="text-xs text-gray-400">Aún no hay mensajes.</p>
    </div>
<?php return; endif;

// 5. RENDERIZADO
$prev_user = 0;
$fecha_actual = '';

while($msg = $res->fetch_assoc()): 
   $soyYo = ($msg['remitente_id'] == $usuario_id);
    
    // --- LÓGICA DE FECHAS (Mejora UX) ---
  $fecha_msg = date('Y-m-d', strtotime($msg['fecha']));
$hora  = date('H:i', strtotime($msg['fecha']));
    
    if ($fecha_msg !== $fecha_actual) {
      $fecha_texto = ($fecha_msg === date('Y-m-d')) ? 'Hoy' : (($fecha_msg === date('Y-m-d', strtotime('-1 day'))) ? 'Ayer' : date('d/m/Y', strtotime($msg['fecha'])));
        echo '<div class="flex justify-center my-4"><span class="bg-gray-100 text-gray-500 text-[10px] font-bold px-3 py-1 rounded-full uppercase tracking-wider shadow-sm border border-gray-200/50">' . $fecha_texto . '</span></div>';
        $fecha_actual = $fecha_msg;
        // Si cambia el día, reseteamos el prev_user para forzar la burbuja con colita
        $prev_user = 0; 
    }
    // -----------------------------------

   $consecutivo = ($prev_user == $msg['remitente_id']);
    
    // Estilos Nubira 2.0
    if ($soyYo) {
        // Mis mensajes: Si es consecutivo no tiene la "colita"
        $claseBurbuja = $consecutivo 
            ? 'bg-[#54A6D8] text-white rounded-2xl rounded-tr-xl shadow-sm ml-auto' 
            : 'bg-[#54A6D8] text-white rounded-2xl rounded-tr-sm shadow-sm ml-auto';
    } else {
        // Mensajes del otro
        $claseBurbuja = $consecutivo 
            ? 'bg-white text-gray-800 border border-gray-100 rounded-2xl rounded-tl-xl shadow-sm mr-auto'
            : 'bg-white text-gray-800 border border-gray-100 rounded-2xl rounded-tl-sm shadow-sm mr-auto';
    }
    
    // Ajuste de margen
    $margin = $consecutivo ? 'mt-1' : 'mt-3';
?>
    
    <div class="flex w-full <?= $soyYo ? 'justify-end' : 'justify-start' ?> <?= $margin ?> animate-fade-in px-1 group">
        <div class="relative max-w-[85%] md:max-w-[75%] px-3 py-2 text-[14px] leading-relaxed break-words <?= $claseBurbuja ?>">
            
            <?= nl2br(htmlspecialchars($msg['mensaje'])) ?>
            
            <div class="text-[9px] text-right mt-1 font-medium min-w-[40px] flex justify-end gap-1 items-center select-none <?= $soyYo ? 'text-blue-100' : 'text-gray-400' ?>">
                <?= $hora ?>
                <?php if($soyYo): ?>
                    <span class="<?= $msg['visto'] ? 'text-white' : 'opacity-70' ?>">
                        <i class="fa-solid fa-check-double text-[9px]"></i>
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </div>

<?php 
  $prev_user = $msg['remitente_id'];
endwhile; 
?>