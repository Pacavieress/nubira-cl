<?php
session_start();
require_once __DIR__ . '/conexion.php';

if (!isset($_SESSION['usuario_id'])) {
  header("Location: /login");
  exit;
}

$usuario_id = (int)$_SESSION['usuario_id'];

$sql = "
SELECT 
  s.id AS id_servicio,
  s.titulo AS titulo_servicio,
  u.id AS id_preguntador,
  u.nombre AS nombre_preguntador,
  p.id AS id_pregunta,
  p.pregunta,
  p.respuesta,
  p.fecha_pregunta
FROM preguntas_servicios p
JOIN servicios s ON s.id = p.id_servicio
JOIN alumnos u ON u.id = p.id_preguntador
WHERE s.alumno_id = ? AND p.archivado = 0
ORDER BY s.id, u.id, p.fecha_pregunta ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $usuario_id);
$stmt->execute();
$result = $stmt->get_result();

$conversaciones = [];
while ($fila = $result->fetch_assoc()) {
  $key = $fila['id_servicio'] . '-' . $fila['id_preguntador'];
  if (!isset($conversaciones[$key])) {
    $conversaciones[$key] = [
      'servicio' => [
        'id' => $fila['id_servicio'],
        'titulo' => $fila['titulo_servicio']
      ],
      'usuario' => [
        'id' => $fila['id_preguntador'],
        'nombre' => $fila['nombre_preguntador']
      ],
      'mensajes' => [],
      'pendientes' => 0
    ];
  }

  $conversaciones[$key]['mensajes'][] = [
    'id_pregunta' => $fila['id_pregunta'],
    'pregunta' => $fila['pregunta'],
    'respuesta' => $fila['respuesta'],
    'fecha' => $fila['fecha_pregunta']
  ];

  if (empty($fila['respuesta'])) {
    $conversaciones[$key]['pendientes']++;
  }
}

$stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Preguntas recibidas - Nubira</title>
<script src="https://cdn.tailwindcss.com"></script>
<style>
  :root { --nubira: #54A6D8; }
  .text-nubira { color: var(--nubira); }
  .bg-nubira { background-color: var(--nubira); }
</style>
</head>

<body class="bg-white min-h-screen text-gray-800 overflow-x-hidden">

<!-- SIDEBAR -->
<aside class="hidden md:flex md:flex-col fixed top-16 left-0 h-[calc(100%-4rem)] w-64 
             bg-white border-r border-gray-200 shadow-lg z-30">
  <div class="p-6">
    <nav class="flex flex-col space-y-3">
      <a href="/vitrina" class="text-gray-700 hover:text-[#54A6D8]">🏠 Inicio</a>
      <a href="/dashboard" class="text-gray-700 hover:text-[#54A6D8]">⚙️ Perfil</a>
      <a href="/vitrina-apuntes" class="text-gray-700 hover:text-[#54A6D8]">📘 Explorar Apuntes</a>
      <a href="/clases-servicios" class="text-gray-700 hover:text-[#54A6D8]">🧑‍🏫 Explorar Servicios</a>
      <a href="/oportunidades" class="text-gray-700 hover:text-[#54A6D8]">🎯 Explorar Oportunidades</a>
    </nav>
  </div>
</aside>

<!-- HEADER -->
<header class="fixed top-0 left-0 right-0 z-40 bg-gradient-to-b from-[#C8E8F8]/80 to-white border-b border-white shadow-sm">
  <div class="max-w-6xl mx-auto flex items-center justify-between h-16 px-4">
    <span class="text-[#54A6D8] font-bold text-lg">💬 Preguntas recibidas</span>
    <a href="/mis-preguntas-archivadas" 
       class="flex items-center gap-1 text-sm text-gray-700 hover:text-[#54A6D8] font-medium">
       📁 Ver archivadas
    </a>
  </div>
</header>

<!-- CONTENIDO -->
<main class="pt-20 pb-28 md:ml-72 px-4 sm:px-6 flex justify-center overflow-x-hidden">
  <div id="contenedor-chats" class="w-full max-w-3xl space-y-6">

    <?php if (empty($conversaciones)): ?>
      <div class="bg-white border border-gray-200 shadow rounded-xl p-6 text-gray-600 text-center">
        Aún no has recibido preguntas en tus servicios.
      </div>
    <?php else: ?>
      <?php foreach ($conversaciones as $key => $conv): ?>
        <div class="chat bg-white border border-gray-200 rounded-2xl shadow-sm p-6 hover:shadow-md transition overflow-hidden"
             data-key="<?= $key ?>" data-servicio="<?= $conv['servicio']['id'] ?>" data-user="<?= $conv['usuario']['id'] ?>">

          <!-- CABECERA -->
          <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-3 p-2 bg-[#F0FAFF] rounded-md border border-[#54A6D8]/30">
            <div class="min-w-0">
              <h2 class="text-base font-semibold text-blue-900 flex flex-wrap items-center gap-2">
                <?= htmlspecialchars($conv['servicio']['titulo']) ?>
                <?php if ($conv['pendientes'] > 0): ?>
                  <span class="bg-red-100 text-red-700 text-xs font-semibold px-2 py-0.5 rounded-full"><?= $conv['pendientes'] ?> sin responder</span>
                <?php endif; ?>
              </h2>
              <p class="text-sm text-gray-600 truncate">👤 <?= htmlspecialchars($conv['usuario']['nombre']) ?></p>
            </div>
            <div class="flex gap-2 mt-2 sm:mt-0 shrink-0">
              <a href="/detalle-servicio/<?= (int)$conv['servicio']['id'] ?>"
                 class="px-3 py-1.5 text-sm font-medium text-white bg-[#54A6D8] rounded-lg hover:bg-blue-600 transition">
                 🔎 Ver servicio
              </a>
              <form action="/app/archivar_conversacion.php" method="POST"
                    onsubmit="return confirm('¿Seguro que deseas archivar esta conversación?')">
                <input type="hidden" name="id_servicio" value="<?= (int)$conv['servicio']['id'] ?>">
                <input type="hidden" name="id_preguntador" value="<?= (int)$conv['usuario']['id'] ?>">
                <button type="submit"
                        class="px-3 py-1.5 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg border border-gray-300 hover:text-[#54A6D8]">
                  🧹 Limpiar
                </button>
              </form>
            </div>
          </div>

          <!-- MENSAJES -->
          <div class="mensajes bg-gray-50 rounded-xl p-4 space-y-3 max-h-[280px] overflow-y-auto">
            <?php foreach ($conv['mensajes'] as $m): ?>
              <div class="msg" data-id="<?= $m['id_pregunta'] ?>">
                <p class="text-gray-800 text-sm"><b><?= htmlspecialchars($conv['usuario']['nombre']) ?>:</b> <?= htmlspecialchars($m['pregunta']) ?></p>
                <?php if (!empty($m['respuesta'])): ?>
                  <p class="ml-3 mt-1 text-green-700 text-sm"><b>Respuesta:</b> <?= htmlspecialchars($m['respuesta']) ?></p>
                <?php else: ?>
                  <form method="post" action="/app/responder_pregunta.php" class="ml-3 mt-2">
                    <input type="hidden" name="id_pregunta" value="<?= (int)$m['id_pregunta'] ?>">
                    <textarea name="respuesta" rows="2"
                              class="w-full border border-gray-300 rounded-lg p-2 text-sm focus:ring-2 focus:ring-[#54A6D8] resize-none"
                              placeholder="Responde aquí..." required></textarea>
                    <button type="submit"
                            class="mt-1 bg-[#54A6D8] hover:bg-blue-600 text-white px-4 py-1.5 rounded-lg text-sm font-semibold transition">
                      Enviar respuesta
                    </button>
                  </form>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', () => {
  // Al cargar, deja todos los scroll al fondo
  document.querySelectorAll('.mensajes').forEach(div => div.scrollTop = div.scrollHeight);
});

// Evita llamadas simultáneas
let actualizando = false;

// 🧠 Actualiza sin mover scroll y también refresca respuestas editadas
async function actualizarMensajes() {
  if (actualizando) return;
  actualizando = true;

  try {
    const res = await fetch('/app/cargar_conversaciones.php?_=' + Date.now(), {
      credentials: 'same-origin',
      cache: 'no-store'
    });
    if (!res.ok) return;

    const data = await res.json();

    for (const [key, conv] of Object.entries(data)) {
      const chat = document.querySelector(`.chat[data-key="${key}"]`);
      if (!chat) continue;

      const cont = chat.querySelector('.mensajes');
      if (!cont) continue;

      // Detecta si el usuario está al final del scroll
      const estabaAbajo = cont.scrollTop + cont.clientHeight >= cont.scrollHeight - 30;
      const scrollPos = cont.scrollTop;
      const idsExistentes = Array.from(cont.querySelectorAll('.msg')).map(m => m.dataset.id);

      conv.mensajes.forEach(m => {
        const existente = cont.querySelector(`.msg[data-id="${m.id_pregunta}"]`);

        if (!existente) {
          // ➕ Agregar mensaje nuevo
          const nuevo = document.createElement('div');
          nuevo.className = 'msg animate-fadein';
          nuevo.dataset.id = m.id_pregunta;
          nuevo.innerHTML = `
            <p class="text-gray-800 text-sm"><b>${conv.usuario.nombre}:</b> ${m.pregunta}</p>
            ${
              m.respuesta
                ? `<p class="ml-3 mt-1 text-green-700 text-sm respuesta"><b>Respuesta:</b> ${m.respuesta}</p>`
                : `<form method="post" action="/app/responder_pregunta.php" class="ml-3 mt-2 form-respuesta">
                     <input type="hidden" name="id_pregunta" value="${m.id_pregunta}">
                     <textarea name="respuesta" rows="2"
                       class="w-full border border-gray-300 rounded-lg p-2 text-sm focus:ring-2 focus:ring-[#54A6D8] resize-none"
                       placeholder="Responde aquí..." required></textarea>
                     <button type="submit"
                       class="mt-1 bg-[#54A6D8] hover:bg-blue-600 text-white px-4 py-1.5 rounded-lg text-sm font-semibold transition">
                       Enviar respuesta
                     </button>
                   </form>`
            }
          `;
          cont.appendChild(nuevo);

        } else {
          // 🔄 Si existe y la respuesta cambió, actualiza sin mover nada
          const pRespuesta = existente.querySelector('.respuesta');
          if (m.respuesta && (!pRespuesta || pRespuesta.textContent.trim() !== m.respuesta.trim())) {
            if (pRespuesta) {
              pRespuesta.innerHTML = `<b>Respuesta:</b> ${m.respuesta}`;
            } else {
              const nueva = document.createElement('p');
              nueva.className = 'ml-3 mt-1 text-green-700 text-sm respuesta animate-fadein';
              nueva.innerHTML = `<b>Respuesta:</b> ${m.respuesta}`;
              existente.appendChild(nueva);
              // Si había un formulario, lo eliminamos
              const formAntiguo = existente.querySelector('form');
              if (formAntiguo) formAntiguo.remove();
            }
          }
        }
      });

      // Solo hace scroll si el usuario estaba al final
      if (estabaAbajo) {
        cont.scrollTo({ top: cont.scrollHeight, behavior: "smooth" });
      } else {
        cont.scrollTop = scrollPos;
      }
    }
  } catch (e) {
    console.error("Error al actualizar:", e);
  } finally {
    actualizando = false;
  }
}

// 📩 Enviar respuesta sin recargar
document.addEventListener('submit', async (e) => {
  if (!e.target.classList.contains('form-respuesta')) return;
  e.preventDefault();

  const form = e.target;
  const id_pregunta = form.querySelector('[name=id_pregunta]').value;
  const respuesta = form.querySelector('[name=respuesta]').value.trim();
  if (!respuesta) return alert('Escribe una respuesta.');

  try {
    const body = new URLSearchParams({ id_pregunta, respuesta });
    const res = await fetch('/app/responder_pregunta.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body
    });

    if (res.ok) {
      form.remove();
      actualizarMensajes();
    }
  } catch (err) {
    console.error('Error al enviar respuesta:', err);
  }
});

// ⏱️ Refresca cada 10 segundos
setInterval(actualizarMensajes, 10000);
</script>


<style>
@keyframes fadein {
  from { opacity: 0; transform: translateY(5px); }
  to { opacity: 1; transform: translateY(0); }
}
.animate-fadein {
  animation: fadein 0.4s ease-out;
}
.mensajes {
  scroll-behavior: smooth;
  overscroll-behavior: contain;
}

</style>


</body>
</html>
