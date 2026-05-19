<?php
session_start();
if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'admin') {
    header('Location: /login');
    exit;
}

require_once __DIR__ . '/../app/enviar_recordatorios_inactivos.php';

$_SESSION['mensaje_admin'] = "✅ Recordatorios ejecutados manualmente.";
header('Location: /admin_recordatorios.php');
exit;
