<?php
session_start();

// 1. CARGA SEGURA DE CONEXIÓN
$app_dir = file_exists(__DIR__ . '/conexion.php') ? __DIR__ : __DIR__ . '/app';
require_once $app_dir . '/conexion.php';
require_once $app_dir . '/iconos.php';

// Verificación de Admin
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'admin') {
    header("Location: /login");
    exit;
}

$mensaje = "";
$error   = "";

/* ----------- Guardar Configuraciones Globales (Mínimo Retiro y Comisión) ----------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_configuracion'])) {
    $monto_minimo = intval($_POST['monto_minimo']);
    $comision = intval($_POST['comision']);

    if ($monto_minimo < 1) {
        $error = "❌ El monto mínimo debe ser al menos 1 peso.";
    } elseif ($comision < 0 || $comision > 100) {
        $error = "❌ La comisión debe estar entre 0% y 100%.";
    } else {
        // Guardar Monto Mínimo
        $stmt = $conn->prepare("INSERT INTO configuracion (clave, valor) VALUES ('monto_minimo_retiro', ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
        $stmt->bind_param("i", $monto_minimo);
        $stmt->execute();
        $stmt->close();

        // Guardar Comisión
        $stmt2 = $conn->prepare("INSERT INTO configuracion (clave, valor) VALUES ('comision_plataforma', ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
        $stmt2->bind_param("i", $comision);
        $stmt2->execute();
        $stmt2->close();

        $mensaje = "✅ Configuraciones financieras actualizadas con éxito.";
    }
}

// Leer configuraciones actuales
$monto_minimo_actual = 10000;
$comision_actual = 0;

$stmtC = $conn->query("SELECT clave, valor FROM configuracion WHERE clave IN ('monto_minimo_retiro', 'comision_plataforma')");
while ($row = $stmtC->fetch_assoc()) {
    if ($row['clave'] === 'monto_minimo_retiro') $monto_minimo_actual = intval($row['valor']);
    if ($row['clave'] === 'comision_plataforma') $comision_actual = intval($row['valor']);
}
/* ----------- Filtros (Parcheados Nubira 2.0) ----------- */
$filtro = $_GET['estado'] ?? 'pendiente';
$filtro_institucion = $_GET['institucion'] ?? '';

$condicion    = [];
$param_types  = "";
$param_values = [];

// [NUBIRA 2.0] Filtrar siempre por el estado activo, a menos que se pidan 'todas'
if ($filtro !== 'todas' && in_array($filtro, ['pendiente', 'aprobado', 'rechazado'])) {
    $condicion[] = "r.estado = ?";
    $param_types .= "s";
    $param_values[] = $filtro;
}

if (!empty($filtro_institucion)) {
    $condicion[] = "LOWER(r.institucion) = ?";
    $param_types .= "s";
    $param_values[] = strtolower($filtro_institucion);
}
$where = $condicion ? "WHERE " . implode(" AND ", $condicion) : "";

/* ----------- Acciones aprobar/rechazar (Con envío de Correo Nubira 2.0) ----------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'], $_POST['id']) && !isset($_POST['form_configuracion'])) {
    $id     = intval($_POST['id']);
    $accion = $_POST['accion'];
    
    // Cargar el motor de correos
    require_once $app_dir . '/correo.php';
    
    if ($accion === 'aprobar') {
        $stmt = $conn->prepare("UPDATE solicitudes_retiro SET estado = 'aprobado', fecha_pago = NOW() WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
        
        // Buscar datos para el correo de éxito
        $q = $conn->query("SELECT r.monto, a.nombre, a.correo FROM solicitudes_retiro r JOIN alumnos a ON r.usuario_id = a.id WHERE r.id = $id");
        if ($row = $q->fetch_assoc()) {
            $monto_f = '$' . number_format($row['monto'], 0, ',', '.');
            $msg = "<h3>¡Pago Enviado! 💸</h3><p>Hola <b>{$row['nombre']}</b>,</p><p>Te hemos transferido exitosamente los fondos solicitados desde tu billetera hacia tu cuenta bancaria.</p><p>Monto transferido: <b style='color:#059669; font-size:18px;'>{$monto_f}</b></p><hr><p><small>Equipo Nubira.cl</small></p>";
            if (function_exists('enviarCorreo')) enviarCorreo($row['correo'], "✅ Pago Transferido a tu Cuenta", $msg);
        }
        $mensaje = "✅ Solicitud aprobada y comprobante enviado al tutor.";
        
    } elseif ($accion === 'rechazar') {
        $stmt = $conn->prepare("UPDATE solicitudes_retiro SET estado = 'rechazado' WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
        
        // Buscar datos para el correo de rechazo
        $q = $conn->query("SELECT a.nombre, a.correo FROM solicitudes_retiro r JOIN alumnos a ON r.usuario_id = a.id WHERE r.id = $id");
        if ($row = $q->fetch_assoc()) {
            $msg = "<h3>Retiro Rechazado ❌</h3><p>Hola <b>{$row['nombre']}</b>,</p><p>Tu solicitud de retiro ha sido rechazada. Por favor, revisa que tus datos bancarios en la plataforma estén correctos y vuelve a solicitar el retiro desde tu billetera.</p><hr><p><small>Equipo Nubira.cl</small></p>";
            if (function_exists('enviarCorreo')) enviarCorreo($row['correo'], "❌ Revisa tus Datos Bancarios", $msg);
        }
        $mensaje = "❌ Solicitud rechazada y aviso enviado al tutor.";
    }
}

/* ----------- Query principal ----------- */
$sql = "SELECT r.*, a.nombre, a.correo, d.banco, d.tipo_cuenta, d.numero_cuenta, d.titular_nombre, d.rut
        FROM solicitudes_retiro r
        JOIN alumnos a ON r.usuario_id = a.id
        LEFT JOIN datos_pago_usuario d ON r.usuario_id = d.usuario_id
        $where
        ORDER BY r.fecha_solicitud DESC";
$stmt = $conn->prepare($sql);
if (!empty($param_types)) $stmt->bind_param($param_types, ...$param_values);
$stmt->execute();
$resultado = $stmt->get_result();
$stmt->close();

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <title>Admin Retiros | Nubira</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <meta name="theme-color" content="#ffffff" />
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="icon" type="image/webp" href="/img/logo2.webp">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
    body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }
    .scrollbar-hide::-webkit-scrollbar { display: none; }
    .scrollbar-hide { -ms-overflow-style: none; scrollbar-width: none; }
    .pb-safe { padding-bottom: env(safe-area-inset-bottom); }
    .animate-fade-in-up { animation: fadeInUp 0.6s ease-out forwards; }
    @keyframes fadeInUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    /* NUBIRA FIX: Evitar zoom automático en móviles en los inputs */
    @media screen and (max-width: 768px) {
      input, select, textarea { font-size: 16px !important; }
    }
  </style>
</head>

<body class="bg-[#f8fafc] text-slate-800 antialiased overflow-x-hidden">

  <?php 
  $page_title = "Gestión Financiera";
  require_once $app_dir . '/componentes/header.php'; 
  require_once $app_dir . '/componentes/sidebar.php'; 
  ?>

  <main class="pt-24 pb-28 md:pb-10 md:ml-64 px-4 max-w-[1600px] mx-auto overflow-hidden md:px-8">

    <?php if ($mensaje): ?>
      <div class="flex items-center gap-3 bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3 rounded-xl mb-6 shadow-sm animate-fade-in-up">
        <i class="fa-solid fa-circle-check text-emerald-500 text-lg"></i>
        <p class="text-sm font-medium"><?= htmlspecialchars($mensaje) ?></p>
      </div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="flex items-center gap-3 bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-xl mb-6 shadow-sm animate-fade-in-up">
        <i class="fa-solid fa-triangle-exclamation text-red-500 text-lg"></i>
        <p class="text-sm font-medium"><?= htmlspecialchars($error) ?></p>
      </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8 animate-fade-in-up">
        
      <div class="bg-white p-5 rounded-2xl shadow-sm border border-slate-200 lg:col-span-1">
          <form method="POST" class="flex flex-col gap-4">
            <input type="hidden" name="form_configuracion" value="1">
            
            <div>
                <label for="monto_minimo" class="text-[11px] font-bold text-slate-500 uppercase tracking-wider block mb-1.5">Mínimo Retiro</label>
                <div class="relative w-full">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <span class="text-slate-400 sm:text-sm">$</span>
                    </div>
                    <input type="number" id="monto_minimo" name="monto_minimo" min="1" step="1" required 
                           value="<?= htmlspecialchars($monto_minimo_actual) ?>" 
                           class="w-full pl-7 pr-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-[#54A6D8]/50 focus:border-[#54A6D8] transition-all" />
                </div>
            </div>

            <div>
                <label for="comision" class="text-[11px] font-bold text-slate-500 uppercase tracking-wider block mb-1.5">Comisión Plataforma (%)</label>
                <div class="relative w-full">
                    <input type="number" id="comision" name="comision" min="0" max="100" step="1" required 
                           value="<?= htmlspecialchars($comision_actual) ?>" 
                           class="w-full pl-3 pr-8 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-[#54A6D8]/50 focus:border-[#54A6D8] transition-all" />
                    <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                        <span class="text-slate-400 sm:text-sm">%</span>
                    </div>
                </div>
            </div>

            <button type="submit" class="bg-[#54A6D8] hover:bg-blue-600 text-white w-full py-2.5 rounded-xl text-sm font-semibold transition-colors shadow-sm mt-1">
                <i class="fa-solid fa-floppy-disk mr-1"></i> Guardar Ajustes
            </button>
          </form>
      </div>

      <div class="bg-white p-5 rounded-2xl shadow-sm border border-slate-200 lg:col-span-2 flex flex-col justify-center">
          <div class="flex flex-col md:flex-row gap-4 justify-between items-start md:items-center">
              
            <div class="flex bg-slate-100 p-1 rounded-xl overflow-x-auto scrollbar-hide w-full md:w-auto">
                <a href="?estado=pendiente" class="px-4 py-2 rounded-lg text-sm font-medium transition-all whitespace-nowrap <?= $filtro==='pendiente'?'bg-white text-amber-600 shadow-sm':'text-slate-500 hover:text-slate-700' ?>">
                    <i class="fa-solid fa-clock mr-1"></i> Pendientes
                </a>
                <a href="?estado=aprobado" class="px-4 py-2 rounded-lg text-sm font-medium transition-all whitespace-nowrap <?= $filtro==='aprobado'?'bg-white text-emerald-600 shadow-sm':'text-slate-500 hover:text-slate-700' ?>">
                    <i class="fa-solid fa-check mr-1"></i> Aprobadas
                </a>
                <a href="?estado=rechazado" class="px-4 py-2 rounded-lg text-sm font-medium transition-all whitespace-nowrap <?= $filtro==='rechazado'?'bg-white text-red-600 shadow-sm':'text-slate-500 hover:text-slate-700' ?>">
                    <i class="fa-solid fa-xmark mr-1"></i> Rechazadas
                </a>
                <a href="?estado=todas" class="px-4 py-2 rounded-lg text-sm font-medium transition-all whitespace-nowrap <?= $filtro==='todas'?'bg-white text-blue-600 shadow-sm':'text-slate-500 hover:text-slate-700' ?>">
                    <i class="fa-solid fa-list mr-1"></i> Todas
                </a>
              </div>

              <form method="GET" action="" class="flex gap-2 w-full md:w-auto">
                <?php if(isset($_GET['estado'])): ?>
                    <input type="hidden" name="estado" value="<?= htmlspecialchars($_GET['estado']) ?>">
                <?php endif; ?>
                <select name="institucion" class="w-full md:w-40 bg-slate-50 border border-slate-200 text-slate-700 text-sm rounded-xl focus:ring-[#54A6D8] focus:border-[#54A6D8] block px-3 py-2.5">
                  <option value="">🏫 Institución</option>
                  <option value="uc" <?= strtolower($filtro_institucion)==='uc'?'selected':'' ?>>UC</option>
                  <option value="aiep" <?= strtolower($filtro_institucion)==='aiep'?'selected':'' ?>>AIEP</option>
                  <option value="santotomas" <?= strtolower($filtro_institucion)==='santotomas'?'selected':'' ?>>Santo Tomás</option>
                </select>
                <button class="bg-slate-800 hover:bg-slate-700 text-white px-4 py-2.5 rounded-xl text-sm font-semibold transition-colors shadow-sm">
                    <i class="fa-solid fa-filter"></i>
                </button>
              </form>
          </div>
      </div>

    </div>

    <?php if ($resultado && $resultado->num_rows > 0): ?>

      <div class="hidden md:block bg-white shadow-sm border border-slate-200 rounded-2xl overflow-hidden animate-fade-in-up" style="animation-delay: 100ms;">
        <div class="overflow-x-auto">
          <table class="w-full text-left border-collapse">
            <thead>
              <tr class="bg-slate-50 border-b border-slate-200">
                <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Usuario</th>
                <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Datos Bancarios</th>
                <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Monto</th>
                <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Estado</th>
                <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider text-right">Acciones</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
            <?php while ($r = $resultado->fetch_assoc()): 
                $inicial = strtoupper(substr($r['nombre'], 0, 1));
            ?>
              <tr class="hover:bg-slate-50/50 transition-colors group">
                <td class="px-6 py-4 align-top">
                  <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-blue-100 text-[#54A6D8] flex items-center justify-center font-bold shadow-sm shrink-0 border border-blue-200">
                        <?= $inicial ?>
                    </div>
                    <div>
                        <p class="font-bold text-slate-800 text-sm"><?= htmlspecialchars($r['nombre']) ?></p>
                        <p class="text-xs text-slate-500"><?= htmlspecialchars($r['correo']) ?></p>
                        <p class="text-[10px] text-slate-400 mt-1"><i class="fa-regular fa-clock"></i> <?= date('d M Y, H:i', strtotime($r['fecha_solicitud'])) ?></p>
                    </div>
                  </div>
                </td>
                
                <td class="px-6 py-4 align-top">
                  <div class="bg-slate-50 p-3 rounded-lg border border-slate-100 text-[13px] text-slate-600 relative group-hover:bg-white transition-colors" id="banco-<?= $r['id'] ?>">
                    <p class="mb-1"><span class="font-semibold text-slate-800">Banco:</span> <?= htmlspecialchars($r['banco']) ?></p>
                    <p class="mb-1"><span class="font-semibold text-slate-800">Cta:</span> <?= htmlspecialchars($r['numero_cuenta']) ?> <span class="text-slate-400">(<?= htmlspecialchars($r['tipo_cuenta']) ?>)</span></p>
                    <p class="mb-1"><span class="font-semibold text-slate-800">Nombre:</span> <?= htmlspecialchars($r['titular_nombre']) ?></p>
                    <p><span class="font-semibold text-slate-800">RUT:</span> <?= htmlspecialchars($r['rut']) ?></p>
                    
                    <button onclick="copiarDatos(this, 'banco-<?= $r['id'] ?>')" class="absolute top-2 right-2 text-slate-400 hover:text-[#54A6D8] bg-white hover:bg-blue-50 border border-slate-200 p-1.5 rounded-md shadow-sm transition-all" title="Copiar datos">
                        <i class="fa-regular fa-copy"></i>
                    </button>
                  </div>
                </td>
                
                <td class="px-6 py-4 align-top">
                  <span class="text-lg font-bold text-slate-800">$<?= number_format($r['monto'],0,',','.') ?></span>
                </td>
                
                <td class="px-6 py-4 align-top">
                  <?php if ($r['estado']==='aprobado'): ?>
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs font-semibold">
                        <i class="fa-solid fa-circle text-[8px]"></i> Aprobado
                    </span>
                  <?php elseif ($r['estado']==='rechazado'): ?>
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-red-50 border border-red-200 text-red-700 text-xs font-semibold">
                        <i class="fa-solid fa-circle text-[8px]"></i> Rechazado
                    </span>
                  <?php else: ?>
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-amber-50 border border-amber-200 text-amber-700 text-xs font-semibold">
                        <i class="fa-solid fa-circle text-[8px] animate-pulse"></i> Pendiente
                    </span>
                  <?php endif; ?>
                </td>
                
                <td class="px-6 py-4 align-top text-right">
                  <div class="flex flex-col gap-2 w-28 ml-auto">
                      <button onclick="abrirAuditoria(<?= $r['id'] ?>)" class="w-full bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold px-3 py-2 rounded-lg border border-slate-200 transition-all flex justify-center items-center gap-1 shadow-sm">
                          <i class="fa-solid fa-magnifying-glass-dollar"></i> Detalle
                      </button>

                      <?php if ($r['estado']==='pendiente'): ?>
                        <form method="post" class="flex flex-col gap-2">
                          <input type="hidden" name="id" value="<?= $r['id'] ?>">
                          <button name="accion" value="aprobar" class="w-full bg-emerald-500 hover:bg-emerald-600 text-white text-xs font-bold px-3 py-2 rounded-lg shadow-sm transition-all flex justify-center items-center gap-1">
                              <i class="fa-solid fa-check"></i> Aprobar
                          </button>
                          <button name="accion" value="rechazar" class="w-full bg-white border border-red-200 text-red-600 hover:bg-red-50 text-xs font-bold px-3 py-2 rounded-lg shadow-sm transition-all flex justify-center items-center gap-1">
                              <i class="fa-solid fa-xmark"></i> Rechazar
                          </button>
                        </form>
                      <?php else: ?>
                        <span class="text-xs font-medium text-slate-400 bg-slate-100 px-3 py-1.5 rounded-lg border border-slate-200 inline-block text-center">Procesada</span>
                      <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endwhile; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="grid gap-4 md:hidden animate-fade-in-up" style="animation-delay: 100ms;">
        <?php $resultado->data_seek(0); while ($r=$resultado->fetch_assoc()): 
            $inicial = strtoupper(substr($r['nombre'], 0, 1));
        ?>
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-5 relative overflow-hidden">
          <div class="absolute top-0 left-0 w-full h-1 <?php echo ($r['estado']==='aprobado') ? 'bg-emerald-500' : (($r['estado']==='rechazado') ? 'bg-red-500' : 'bg-amber-400'); ?>"></div>

          <div class="flex justify-between items-start mb-4 pt-1">
              <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-blue-100 text-[#54A6D8] flex items-center justify-center font-bold shadow-sm shrink-0 border border-blue-200">
                    <?= $inicial ?>
                </div>
                <div>
                    <p class="font-bold text-slate-800 text-sm"><?= htmlspecialchars($r['nombre']) ?></p>
                    <p class="text-[11px] text-slate-500"><?= htmlspecialchars($r['correo']) ?></p>
                </div>
              </div>
              <div class="text-right">
                  <p class="text-lg font-extrabold text-slate-800">$<?= number_format($r['monto'],0,',','.') ?></p>
                  <p class="text-[10px] text-slate-400"><?= date('d M H:i', strtotime($r['fecha_solicitud'])) ?></p>
              </div>
          </div>

          <div class="bg-slate-50 p-4 rounded-xl border border-slate-100 text-xs text-slate-600 mb-4 relative" id="banco-mob-<?= $r['id'] ?>">
            <p class="mb-1"><span class="font-semibold text-slate-800">Banco:</span> <?= htmlspecialchars($r['banco']) ?></p>
            <p class="mb-1"><span class="font-semibold text-slate-800">Cta:</span> <?= htmlspecialchars($r['numero_cuenta']) ?></p>
            <p class="mb-1"><span class="font-semibold text-slate-800">Titular:</span> <?= htmlspecialchars($r['titular_nombre']) ?></p>
            <p><span class="font-semibold text-slate-800">RUT:</span> <?= htmlspecialchars($r['rut']) ?></p>
            
            <button onclick="copiarDatos(this, 'banco-mob-<?= $r['id'] ?>')" class="absolute bottom-3 right-3 text-slate-400 hover:text-[#54A6D8] bg-white border border-slate-200 px-2 py-1 rounded shadow-sm transition-all flex items-center gap-1">
                <i class="fa-regular fa-copy"></i> <span class="text-[10px] font-medium">Copiar</span>
            </button>
          </div>

          <div class="flex flex-col gap-3 mt-3 pt-3 border-t border-slate-100">
            <div class="flex items-center justify-between">
              <div>
                <?php if ($r['estado']==='aprobado'): ?>
                  <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md bg-emerald-50 border border-emerald-200 text-emerald-700 text-[11px] font-bold">
                      <i class="fa-solid fa-check"></i> Pagado
                  </span>
                <?php elseif ($r['estado']==='rechazado'): ?>
                  <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md bg-red-50 border border-red-200 text-red-700 text-[11px] font-bold">
                      <i class="fa-solid fa-xmark"></i> Rechazado
                  </span>
                <?php else: ?>
                  <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md bg-amber-50 border border-amber-200 text-amber-700 text-[11px] font-bold">
                      <i class="fa-solid fa-clock"></i> Pendiente
                  </span>
                <?php endif; ?>
              </div>

              <button onclick="abrirAuditoria(<?= $r['id'] ?>)" class="bg-slate-100 border border-slate-200 text-slate-700 hover:bg-slate-200 text-xs font-bold px-3 py-1.5 rounded-lg transition-all shadow-sm flex items-center gap-1">
                  <i class="fa-solid fa-magnifying-glass-dollar"></i> Detalle
              </button>
            </div>

            <?php if ($r['estado']==='pendiente'): ?>
              <form method="post" class="flex gap-2 w-full">
                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                <button name="accion" value="rechazar" class="w-1/2 bg-white border border-red-200 text-red-600 hover:bg-red-50 text-xs font-bold px-3 py-2 rounded-lg transition-all shadow-sm">
                    Rechazar
                </button>
                <button name="accion" value="aprobar" class="w-1/2 bg-emerald-500 hover:bg-emerald-600 text-white text-xs font-bold px-3 py-2 rounded-lg shadow-sm transition-all">
                    Aprobar
                </button>
              </form>
            <?php endif; ?>
          </div>

        </div>
        <?php endwhile; ?>
      </div>

    <?php else: ?>
      <div class="bg-white rounded-3xl border border-slate-200 border-dashed p-12 text-center flex flex-col items-center justify-center animate-fade-in-up">
          <div class="w-16 h-16 bg-slate-50 rounded-full flex items-center justify-center text-slate-300 mb-4">
              <i class="fa-solid fa-inbox text-3xl"></i>
          </div>
          <h3 class="text-lg font-bold text-slate-800 mb-1">Sin solicitudes</h3>
          <p class="text-slate-500 text-sm">No hay retiros en esta categoría por el momento.</p>
      </div>
    <?php endif; ?>

  </main>

  <?php require_once $app_dir . '/componentes/nav_bottom.php'; ?>

  <script>
    function copiarDatos(btnElement, idContenedor) {
      const contenedor = document.getElementById(idContenedor);
      let textoParaCopiar = "";
      const parrafos = contenedor.querySelectorAll('p');
      parrafos.forEach(p => { textoParaCopiar += p.innerText + "\n"; });

      var temp = document.createElement('textarea');
      temp.value = textoParaCopiar.trim();
      document.body.appendChild(temp);
      temp.select();
      document.execCommand('copy');
      document.body.removeChild(temp);
      
      const iconoOriginal = btnElement.innerHTML;
      btnElement.innerHTML = '<i class="fa-solid fa-check text-emerald-500"></i>';
      btnElement.classList.add('border-emerald-200', 'bg-emerald-50');
      
      setTimeout(() => {
          btnElement.innerHTML = iconoOriginal;
          btnElement.classList.remove('border-emerald-200', 'bg-emerald-50');
      }, 2000);
    }
  </script>
  <div id="modalAuditoria" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm opacity-0 transition-opacity duration-300">
      <div class="bg-white w-full max-w-2xl rounded-3xl shadow-2xl overflow-hidden transform scale-95 transition-transform duration-300" id="modalAuditoriaContent">
          <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
              <div>
                  <h3 class="text-lg font-bold text-slate-800">Desglose de Pago</h3>
                  <p class="text-xs text-slate-500">Auditoría de contratos vinculados a este retiro</p>
              </div>
              <button onclick="cerrarAuditoria()" class="text-slate-400 hover:text-slate-600 p-2 hover:bg-slate-100 rounded-full transition-all">
                  <i class="fa-solid fa-xmark text-xl"></i>
              </button>
          </div>
          
          <div id="contenidoAuditoria" class="p-6 max-h-[60vh] overflow-y-auto">
              <div class="flex justify-center py-10">
                  <div class="animate-spin h-8 w-8 border-4 border-[#54A6D8] border-t-transparent rounded-full"></div>
              </div>
          </div>

          <div class="p-6 bg-slate-50 border-t border-slate-100 flex justify-end">
              <button onclick="cerrarAuditoria()" class="px-6 py-2 bg-slate-800 text-white text-sm font-bold rounded-xl hover:bg-slate-700 transition-all">
                  Cerrar
              </button>
          </div>
      </div>
  </div>

  <script>
    function abrirAuditoria(idRetiro) {
        const modal = document.getElementById('modalAuditoria');
        const modalContent = document.getElementById('modalAuditoriaContent');
        const contenido = document.getElementById('contenidoAuditoria');
        
        // Cargar Spinner
        contenido.innerHTML = '<div class="flex justify-center py-10"><div class="animate-spin h-8 w-8 border-4 border-[#54A6D8] border-t-transparent rounded-full"></div></div>';
        
        // Mostrar Modal
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        // Efecto Fade In
        setTimeout(() => {
            modal.classList.remove('opacity-0');
            modalContent.classList.remove('scale-95');
        }, 10);
        
        // Llamada AJAX
        fetch(`/app/api_auditoria_retiro.php?id=${idRetiro}`)
            .then(response => response.text())
            .then(html => { contenido.innerHTML = html; })
            .catch(err => { contenido.innerHTML = '<p class="text-red-500 text-center py-4">Error de conexión al obtener el detalle.</p>'; });
    }

    function cerrarAuditoria() {
        const modal = document.getElementById('modalAuditoria');
        const modalContent = document.getElementById('modalAuditoriaContent');
        
        // Efecto Fade Out
        modal.classList.add('opacity-0');
        modalContent.classList.add('scale-95');
        setTimeout(() => {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }, 300);
    }

    // Cerrar con ESC o clic fuera
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') cerrarAuditoria(); });
    document.getElementById('modalAuditoria').addEventListener('click', (e) => {
        if (e.target === document.getElementById('modalAuditoria')) cerrarAuditoria();
    });
  </script>
</body>
</html>