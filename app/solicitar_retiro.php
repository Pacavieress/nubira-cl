<?php
/**
 * PROCESADOR: SOLICITAR RETIRO
 * UBICACIÓN: public_html/app/solicitar_retiro.php
 * ESTADO: Nubira 2.0 - Seguridad Financiera Reforzada y Trazabilidad.
 */
session_start();
require_once __DIR__ . '/conexion.php';

// 1. SEGURIDAD
if (!isset($_SESSION['usuario_id'])) {
    header("Location: /login");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /app/datos_bancarios.php");
    exit;
}

if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    header("Location: /app/datos_bancarios.php?error=csrf_invalido");
    exit;
}

$usuario_id = (int)$_SESSION['usuario_id'];
$monto_solicitado = intval($_POST['monto'] ?? 0);

// 2. VERIFICAR DATOS BANCARIOS
$stmtDatos = $conn->prepare("SELECT banco FROM datos_pago_usuario WHERE usuario_id = ?");
$stmtDatos->bind_param("i", $usuario_id);
$stmtDatos->execute();
$resultDatos = $stmtDatos->get_result();

if ($resultDatos->num_rows === 0) {
    $stmtDatos->close();
    header("Location: /app/datos_bancarios.php?error=sin_datos_bancarios");
    exit;
}

$rowDatos = $resultDatos->fetch_assoc();
$institucion = $rowDatos['banco']; 
$stmtDatos->close();

// 3. CÁLCULO DE SALDO REAL
// A. Ganancias por Apuntes
$stmtA = $conn->prepare("SELECT SUM(precio) AS total FROM ventas_apuntes WHERE vendedor_id = ? AND pagado_al_vendedor = 1");
$stmtA->bind_param("i", $usuario_id);
$stmtA->execute();
$ganancia_apuntes = $stmtA->get_result()->fetch_assoc()['total'] ?? 0;
$stmtA->close();

// B. Ganancias por Servicios (Contratos Liberados) [NUBIRA 2.0 - TYPO FIX Aplicado]
$stmtS = $conn->prepare("SELECT SUM(monto + COALESCE(monto_subsidio, 0) - COALESCE(monto_comision, 0)) AS total FROM contratos WHERE vendedor_id = ? AND estado IN ('liberado', 'finalizado', 'completado')");
$stmtS->bind_param("i", $usuario_id);
$stmtS->execute();
$ganancia_servicios = $stmtS->get_result()->fetch_assoc()['total'] ?? 0;
$stmtS->close();

// C. Total ya retirado Y PENDIENTE
$stmtR = $conn->prepare("SELECT SUM(monto) AS total FROM solicitudes_retiro WHERE usuario_id = ? AND estado IN ('aprobado', 'pendiente', 'pagado')");
$stmtR->bind_param("i", $usuario_id);
$stmtR->execute();
$total_retirado_y_retenido = $stmtR->get_result()->fetch_assoc()['total'] ?? 0;
$stmtR->close();

// SALDO DISPONIBLE FINAL REAL
$saldo_disponible = ($ganancia_apuntes + $ganancia_servicios) - $total_retirado_y_retenido;

// 4. VERIFICAR MONTO MÍNIMO
$stmtConf = $conn->prepare("SELECT valor FROM configuracion WHERE clave = 'monto_minimo_retiro'");
$stmtConf->execute();
$stmtConf->bind_result($minimo_retiro);
$stmtConf->fetch();
$stmtConf->close();
$minimo_retiro = $minimo_retiro ?: 10000;

// 5. VALIDACIONES ESTRICTAS
if ($monto_solicitado <= 0) { header("Location: /app/datos_bancarios.php?error=monto_invalido"); exit; }
if ($monto_solicitado < $minimo_retiro) { header("Location: /app/datos_bancarios.php?error=monto_minimo"); exit; }
if ($monto_solicitado > $saldo_disponible) { header("Location: /app/datos_bancarios.php?error=saldo_insuficiente"); exit; }

// 6. REGISTRAR SOLICITUD Y VINCULAR CONTRATOS (TRAZABILIDAD NUBIRA 2.0)
$conn->begin_transaction();
try {
    $estado_inicial = 'pendiente';
    $stmtInsert = $conn->prepare("INSERT INTO solicitudes_retiro (usuario_id, monto, institucion, estado, fecha_solicitud) VALUES (?, ?, ?, ?, NOW())");
    $stmtInsert->bind_param("iiss", $usuario_id, $monto_solicitado, $institucion, $estado_inicial);
    
    if (!$stmtInsert->execute()) throw new Exception("Error al insertar solicitud");
    
    $id_solicitud = $conn->insert_id; // <-- Capturamos el ID
    $stmtInsert->close();

    // Marcamos los contratos con el ID de esta solicitud para la Trazabilidad del Admin
    $stmtUpdateContratos = $conn->prepare("
        UPDATE contratos 
        SET solicitud_retiro_id = ? 
        WHERE vendedor_id = ? 
        AND estado IN ('liberado', 'finalizado', 'completado') 
        AND solicitud_retiro_id IS NULL
    ");
    $stmtUpdateContratos->bind_param("ii", $id_solicitud, $usuario_id);
    $stmtUpdateContratos->execute();
    $stmtUpdateContratos->close();

    $conn->commit();
    require_once __DIR__ . '/enviar_push_nubira.php';
    $solicitante_p = explode(' ', trim($_SESSION['usuario_nombre'] ?? 'Alguien'))[0];
    $monto_p = '$' . number_format($monto_solicitado, 0, ',', '.');
    enviar_push_nubira(1, '💸 Retiro solicitado', $solicitante_p . ' pidió retiro de ' . $monto_p . '. Procesar.', '/admin/retiros');
    header("Location: /app/datos_bancarios.php?retiro=ok");

} catch (Exception $e) {
    $conn->rollback();
    header("Location: /app/datos_bancarios.php?error=db");
}
exit;
?>