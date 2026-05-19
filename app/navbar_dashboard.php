<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$rol = $_SESSION['rol'] ?? 'alumno';
$es_admin = $rol === 'admin';
?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container">
  <div class="max-w-6xl mx-auto px-4 mb-4">
  <a href="dashboard.php" class="inline-block bg-blue-600 hover:bg-blue-700 text-white text-sm px-4 py-2 rounded">
    ← Volver 
  </a>
</div>
      <ul class="navbar-nav">
        <?php if ($es_admin): ?>
          <li class="nav-item">
            <a class="nav-link text-white" href="admin_usuarios.php">Usuarios</a>
          </li>
          <li class="nav-item">
            <a class="nav-link text-white" href="admin_apuntes.php">Apuntes</a>
          </li>
        <?php else: ?>
          <li class="nav-item">
            <a class="nav-link text-white" href="vitrina.php"> Vitrina Pública</a>
          </li>
        <?php endif; ?>
        <li class="nav-item">
          <a class="nav-link text-white" href="logout.php">Cerrar sesión</a>
        </li>
      </ul>
    </div>
  </div>
</nav>
