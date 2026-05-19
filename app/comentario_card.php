<?php
// comentario_card.php
// Espera $comentario = ['nombre' => ..., 'texto' => ..., 'img' => ...];
// Usa una imagen por defecto si no se entrega

// Ruta de imagen por defecto
$imgDefault = '/img/testimonio_burbuja.svg'; // Cambia por tu avatar o burbuja

// Escoge la imagen: la del comentario, o la default si no existe
$img = isset($comentario['img']) && $comentario['img']
    ? $comentario['img']
    : $imgDefault;

// (Opcional) Si quieres validar que exista en disco, agrega esto:
// if ($img !== $imgDefault && !file_exists($_SERVER['DOCUMENT_ROOT'] . $img)) $img = $imgDefault;
?>

<div class="bg-white border border-gray-100 p-5 rounded-2xl shadow-lg w-full max-w-lg flex items-center gap-4 mb-3">
  <img src="<?= htmlspecialchars($img) ?>" alt="Usuario" class="w-12 h-12 rounded-full object-cover shadow" loading="lazy">
  <div>
    <p class="text-gray-800 font-semibold mb-1"><?= htmlspecialchars($comentario['nombre'] ?? 'Anónimo') ?></p>
    <p class="text-gray-600"><?= htmlspecialchars($comentario['texto'] ?? '') ?></p>
  </div>
</div>
