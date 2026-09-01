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

// 3. VERIFICAR MONTO MÍNIMO (config global, no depende del saldo del usuario)
$stmtConf = $conn->prepare("SELECT valor FROM configuracion WHERE clave = 'monto_minimo_retiro'");
$stmtConf->execute();
$stmtConf->bind_result($minimo_retiro);
$stmtConf->fetch();
$stmtConf->close();
$minimo_retiro = $minimo_retiro ?: 10000;

// 4. VALIDACIONES DE FORMA — no dependen del saldo, se filtran antes de tomar
// ningún lock para no gastar uno en una solicitud ya inválida de entrada.
if ($monto_solicitado <= 0) { header("Location: /app/datos_bancarios.php?error=monto_invalido"); exit; }
if ($monto_solicitado < $minimo_retiro) { header("Location: /app/datos_bancarios.php?error=monto_minimo"); exit; }

// 5. LOCK POR USUARIO + CÁLCULO DE SALDO + VALIDACIÓN + REGISTRO — todo dentro de
// la misma transacción, para cerrar la carrera de doble submit.
//
// [NUBIRA 2.0] Por qué un lock sobre `alumnos` y no sobre `solicitudes_retiro`:
// el saldo depende de leer 3 tablas (ventas_apuntes, contratos, solicitudes_retiro),
// no de una sola fila que ya exista. Un `SELECT ... FOR UPDATE` sobre las propias
// solicitudes_retiro del usuario no protege nada en su PRIMER retiro (no hay fila
// previa que bloquear, así que dos requests concurrentes del primer retiro no
// quedarían serializados). La fila de `alumnos` para este usuario SIEMPRE existe
// (la sesión ya lo garantiza), así que sirve como ancla de bloqueo confiable:
// el primer request que llega toma el lock, calcula el saldo y hace su INSERT;
// cualquier segundo request del MISMO usuario queda bloqueado en el FOR UPDATE
// hasta que el primero haga commit/rollback, y solo entonces lee el saldo YA
// actualizado. Usuarios distintos bloquean filas distintas de `alumnos`, así que
// no hay contención entre ellos. Al ser un lock de fila dentro de una transacción
// (no un GET_LOCK con release manual), se libera solo al hacer commit/rollback o
// si la conexión se cierra — y esta conexión no es persistente (ver conexion.php),
// así que no hay forma de que un lock quede colgado entre requests.
// Sin riesgo de deadlock: cada transacción toma como máximo un lock (su propia
// fila de alumnos) y nunca en combinación con ningún otro recurso bloqueado.
$conn->begin_transaction();
try {
    $stmtLock = $conn->prepare("SELECT id FROM alumnos WHERE id = ? FOR UPDATE");
    $stmtLock->bind_param("i", $usuario_id);
    $stmtLock->execute();
    $stmtLock->close();

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

    if ($monto_solicitado > $saldo_disponible) {
        $conn->rollback();
        header("Location: /app/datos_bancarios.php?error=saldo_insuficiente");
        exit;
    }

    // 6. REGISTRAR SOLICITUD Y VINCULAR CONTRATOS (TRAZABILIDAD NUBIRA 2.0)
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