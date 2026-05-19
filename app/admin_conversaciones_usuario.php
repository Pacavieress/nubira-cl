<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once __DIR__ . '/../app/conexion.php';

// Seguridad básica admin
if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] ?? '') !== 'admin') {
    header('Location: /login');
    exit;
}

$usuario_id = (int)($_GET['usuario'] ?? 0);
if ($usuario_id <= 0) {
    http_response_code(400);
    echo "Falta parámetro ?usuario=";
    exit;
}

// Traer datos del usuario
$usr = null;
$stmtU = $conn->prepare("SELECT id, nombre, correo, institucion FROM alumnos WHERE id = ? LIMIT 1");
$stmtU->bind_param("i", $usuario_id);
$stmtU->execute();
$resU = $stmtU->get_result();
if ($resU && $resU->num_rows === 1) {
    $usr = $resU->fetch_assoc();
} else {
    echo "Usuario no encontrado.";
    exit;
}

// Traer conversaciones donde participa (como comprador o vendedor)
$sql = "
    SELECT
        c.id,
        c.servicio_id,
        c.comprador_id,
        c.vendedor_id,
        c.creado_en,
        s.titulo AS servicio_titulo,
        ac.nombre AS comprador_nombre,
        ac.correo AS comprador_correo,
        av.nombre AS vendedor_nombre,
        av.correo AS vendedor_correo
    FROM conversaciones c
    LEFT JOIN servicios s ON s.id = c.servicio_id
    LEFT JOIN alumnos ac ON ac.id = c.comprador_id
    LEFT JOIN alumnos av ON av.id = c.vendedor_id
    WHERE c.comprador_id = ? OR c.vendedor_id = ?
    ORDER BY c.creado_en DESC, c.id DESC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $usuario_id, $usuario_id);
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Conversaciones de Usuario | Nubira</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 text-gray-800">
  <header class="bg-white shadow p-4 sticky top-0 z-50">
    <div class="flex items-center justify-between">
      <h1 class="text-xl font-bold">
        Conversaciones de <span class="text-[#54A6D8]"><?= htmlspecialchars($usr['nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
      </h1>
      <a href="admin_autores_servicios.php"
         class="text-sm text-[#54A6D8] hover:underline">← Volver</a>
    </div>
    <p class="text-sm text-gray-600 mt-1">
      Correo: <?= htmlspecialchars($usr['correo'] ?? '', ENT_QUOTES, 'UTF-8') ?> ·
      Institución: <?= $usr['institucion'] ? htmlspecialchars($usr['institucion'], ENT_QUOTES, 'UTF-8') : '—' ?>
    </p>
  </header>

  <main class="p-6">
    <div class="bg-white rounded-xl shadow overflow-hidden">
      <table class="min-w-full text-sm text-left">
        <thead class="bg-[#54A6D8] text-white">
          <tr>
            <th class="px-4 py-3">#</th>
            <th class="px-4 py-3">Fecha</th>
            <th class="px-4 py-3">Rol</th>
            <th class="px-4 py-3">Contraparte</th>
            <th class="px-4 py-3">Servicio</th>
            <th class="px-4 py-3">Acciones</th>
          </tr>
        </thead>
        <tbody>
        <?php
        $i = 1;
        if ($result && $result->num_rows > 0):
          while ($row = $result->fetch_assoc()):
              $esComprador = ((int)$row['comprador_id'] === $usuario_id);
              $rol = $esComprador ? 'comprador' : 'vendedor';

              // datos contraparte
              $contraNombre = $esComprador ? ($row['vendedor_nombre'] ?? '') : ($row['comprador_nombre'] ?? '');
              $contraCorreo = $esComprador ? ($row['vendedor_correo'] ?? '') : ($row['comprador_correo'] ?? '');

              $fecha = $row['creado_en'] ? date('d/m/Y H:i', strtotime($row['creado_en'])) : '-';
        ?>
          <tr class="border-b hover:bg-gray-50">
            <td class="px-4 py-3"><?= $i++ ?></td>
            <td class="px-4 py-3 text-gray-600"><?= $fecha ?></td>
            <td class="px-4 py-3">
              <span class="px-2 py-1 rounded-full text-xs <?= $esComprador ? 'bg-blue-100 text-blue-700' : 'bg-amber-100 text-amber-700' ?>">
                <?= $rol ?>
              </span>
            </td>
            <td class="px-4 py-3">
              <div class="font-medium"><?= htmlspecialchars($contraNombre ?? '', ENT_QUOTES, 'UTF-8') ?></div>
              <div class="text-xs text-gray-500"><?= htmlspecialchars($contraCorreo ?? '', ENT_QUOTES, 'UTF-8') ?></div>
            </td>
            <td class="px-4 py-3">
              <?= $row['servicio_titulo']
                    ? htmlspecialchars($row['servicio_titulo'], ENT_QUOTES, 'UTF-8')
                    : '<span class="text-gray-400 italic">sin título</span>' ?>
            </td>
            <td class="px-4 py-3">
              <!-- Placeholder para “ver detalle” si luego quieres listar mensajes -->
              <span class="text-gray-400 text-xs italic">Detalle de mensajes (próx.)</span>
            </td>
          </tr>
        <?php
          endwhile;
        else:
        ?>
          <tr>
            <td colspan="6" class="text-center py-8 text-gray-500">No hay conversaciones para este usuario.</td>
          </tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </main>
</body>
</html>
