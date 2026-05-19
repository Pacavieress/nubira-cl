<?php
/**
 * SCRIPT DE CIRUGÍA DE DATOS: RESCATE DE TICKETS ESPECÍFICOS (NUBIRA 2.0)
 * Objetivo: Extraer tickets específicos de la tabla obsoleta `soporte` y 
 * adaptarlos a la nueva arquitectura relacional (`reclamos_sugerencias` + `reclamos_mensajes`).
 */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();

require_once __DIR__ . '/conexion.php';

// SEGURIDAD: Solo Admin
if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] ?? '') !== 'admin') {
    die("Acceso denegado. Se requiere nivel de administrador.");
}

$resultados = [];

// =========================================================================
// 🛑 MODIFICA ESTA LÍNEA: Pon aquí los 4 IDs de la tabla `soporte` que quieres salvar
$ids_a_salvar = [7, 8, 9, 10]; // Reemplaza con tus números (ej: [10, 24, 31, 40])
// =========================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ejecutar_cirugia'])) {
    
    foreach ($ids_a_salvar as $id_viejo) {
        // 1. Obtener los datos del ticket viejo
        $stmt_old = $conn->prepare("SELECT * FROM soporte WHERE id = ?");
        $stmt_old->bind_param('i', $id_viejo);
        $stmt_old->execute();
        $ticket = $stmt_old->get_result()->fetch_assoc();
        $stmt_old->close();

        if ($ticket) {
            $conn->begin_transaction();
            try {
                // 2. Crear el registro maestro en reclamos_sugerencias
                $texto_combinado = strtoupper($ticket['asunto']) . ":\n" . $ticket['mensaje'];
                $estado = $ticket['estado'] === 'cerrado' ? 'resuelto' : $ticket['estado'];
                
                $stmt_master = $conn->prepare("INSERT INTO reclamos_sugerencias (usuario_id, texto, fecha, estado, revisado_usuario) VALUES (?, ?, ?, ?, 0)");
                $stmt_master->bind_param('isss', $ticket['usuario_id'], $texto_combinado, $ticket['fecha_creacion'], $estado);
                $stmt_master->execute();
                $nuevo_id = $conn->insert_id; // ID del nuevo ecosistema
                $stmt_master->close();

                // 3. Insertar el mensaje original del usuario en el hilo
                $stmt_msg1 = $conn->prepare("INSERT INTO reclamos_mensajes (reclamo_id, remitente, mensaje, fecha) VALUES (?, 'usuario', ?, ?)");
                $stmt_msg1->bind_param('iss', $nuevo_id, $ticket['mensaje'], $ticket['fecha_creacion']);
                $stmt_msg1->execute();
                $stmt_msg1->close();

                // 4. Si el ticket viejo tenía respuesta del admin, insertarla en el hilo
                if (!empty($ticket['respuesta'])) {
                    // Si no hay fecha de respuesta, le sumamos 1 minuto a la creación para orden lógico
                    $stmt_msg2 = $conn->prepare("INSERT INTO reclamos_mensajes (reclamo_id, remitente, mensaje, fecha) VALUES (?, 'admin', ?, DATE_ADD(?, INTERVAL 1 MINUTE))");
                    $stmt_msg2->bind_param('iss', $nuevo_id, $ticket['respuesta'], $ticket['fecha_creacion']);
                    $stmt_msg2->execute();
                    $stmt_msg2->close();
                }

                // 5. Eliminar el registro viejo para evitar duplicados si se corre 2 veces
                $stmt_del = $conn->prepare("DELETE FROM soporte WHERE id = ?");
                $stmt_del->bind_param('i', $id_viejo);
                $stmt_del->execute();
                $stmt_del->close();

                $conn->commit();
                $resultados[] = "✅ Ticket Viejo #{$id_viejo} migrado con éxito (Nuevo ID: #{$nuevo_id}).";

            } catch (Exception $e) {
                $conn->rollback();
                $resultados[] = "❌ Error en Ticket #{$id_viejo}: " . $e->getMessage();
            }
        } else {
            $resultados[] = "⚠️ Ticket #{$id_viejo} no encontrado en la tabla 'soporte'.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cirugía de Datos | Nubira 2.0</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen flex items-center justify-center p-4">

    <div class="bg-white p-8 rounded-3xl border border-slate-200 shadow-sm max-w-lg w-full">
        <div class="w-16 h-16 bg-slate-900 text-white rounded-full flex items-center justify-center mb-6 shadow-md">
            <i class="fa-solid fa-scalpel-line-dashed text-2xl"></i>
        </div>
        
        <h1 class="text-2xl font-extrabold tracking-tight mb-2">Rescate de Tickets</h1>
        <p class="text-sm text-slate-500 font-medium mb-6">Esta herramienta extraerá los IDs <strong><?= implode(', ', $ids_a_salvar) ?></strong> de la tabla obsoleta <code>soporte</code> y los inyectará limpiamente en la arquitectura V2.</p>

        <?php if (!empty($resultados)): ?>
            <div class="bg-slate-50 p-4 rounded-2xl border border-slate-100 mb-6 space-y-2">
                <?php foreach ($resultados as $res): ?>
                    <div class="text-xs font-bold text-slate-700"><?= $res ?></div>
                <?php endforeach; ?>
            </div>
            <div class="p-4 bg-emerald-50 border border-emerald-100 rounded-2xl text-emerald-700 text-xs font-bold flex items-center gap-2">
                <i class="fa-solid fa-check-circle text-lg"></i>
                La operación ha concluido. Por favor, elimina este archivo por seguridad.
            </div>
        <?php else: ?>
            <form method="POST">
                <input type="hidden" name="ejecutar_cirugia" value="1">
                <button type="submit" onclick="return confirm('¿Confirmas que pusiste los IDs correctos en el código?')" class="w-full bg-slate-900 text-white py-3.5 rounded-2xl font-extrabold text-xs uppercase tracking-widest hover:bg-slate-800 active:scale-95 transition-all flex items-center justify-center gap-2">
                    Iniciar Extracción <i class="fa-solid fa-arrow-right"></i>
                </button>
            </form>
        <?php endif; ?>
    </div>

</body>
</html>