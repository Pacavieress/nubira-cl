<?php
// app/helpers/comprador_invitado.php
// Checkout de invitado para apuntes (sin registro, sin login, cero campos) — diseño
// aprobado en sesión del 24/08/2026, revisado para cero fricción (sin email) el mismo día.
// Un comprador invitado es una fila REAL en `alumnos` (genérica, sin email real) para no
// tocar los INNER JOIN existentes de ventas_apuntes.php, admin_compras_apuntes.php,
// mis_ventas.php y exportar_ventas.php. Se distingue de una cuenta normal con
// `es_comprador_invitado=1` — nombre elegido a propósito para no chocar con "invitado" del
// sistema de referidos (invitar_interesado.php y afines, feature distinta).
//
// Capas que bloquean el login de una fila invitada aunque alguien adivinara la password:
// confirmado=0 (login.php ya rechaza esto), visible=0 (oculta de admin_usuarios.php, mismo
// patrón que "Soporte Nubira"), password = hash de 32 bytes aleatorios (password_hash, nunca
// se muestra ni se comunica a nadie).
//
// Sin email que capturar, cada compra de invitado es una fila NUEVA (no hay identidad que
// buscar/reutilizar) — quien crea la fila es quien primero procese el payment_id
// (pago_exitoso.php o notificaciones_mp.php, lo que llegue primero); el otro camino reutiliza
// ese mismo id leyéndolo de `compras.usuario_id` en vez de crear una fila propia. Ver la nota
// de coordinación en pago_exitoso.php/notificaciones_mp.php.

require_once __DIR__ . '/../conexion.php';

// Auto-migración — mismo patrón que login.php:7-11. Se ejecuta una vez por request que
// cargue este helper (checkout invitado, retorno de pago, webhook), no en cada carga de
// página del sitio.
try { $conn->query("ALTER TABLE alumnos ADD COLUMN es_comprador_invitado TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}

if (!function_exists('crearCompradorInvitado')) {
    /**
     * Crea una fila-fantasma nueva en `alumnos` para una compra de invitado. Sin email: el
     * correo es un placeholder sintético no entregable (solo para satisfacer la columna
     * NOT NULL + UNIQUE), único por fila vía uniqid+random así que nunca colisiona.
     */
    function crearCompradorInvitado(mysqli $conn): int {
        $correo_sintetico = sprintf('invitado_%s_%s@invitado.nubira.cl', time(), bin2hex(random_bytes(4)));
        $password_aleatoria = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);

        $stmt = $conn->prepare(
            "INSERT INTO alumnos (nombre, correo, password, confirmado, visible, rol, tipo, es_comprador_invitado)
             VALUES ('Comprador Invitado', ?, ?, 0, 0, 'alumno', 'invitado', 1)"
        );
        $stmt->bind_param("ss", $correo_sintetico, $password_aleatoria);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();
        return $id;
    }
}

if (!function_exists('enlaceDescargaApunte')) {
    /**
     * Genera el link firmado de descarga (HMAC), reutilizable tanto para el comprador
     * logueado (ver_apunte.php) como para el comprador invitado (pago_exitoso.php,
     * notificaciones_mp.php) — misma fórmula que ya existía en ver_apunte.php::build_file_url(),
     * extraída acá para no duplicarla. $compradorId es usuario_id de sesión en el caso
     * logueado, o el id de la fila alumno-invitado en el caso invitado — descargar_apunte.php
     * ya no asume que viene de sesión.
     */
    function enlaceDescargaApunte(int $idApunte, string $archivo, int $compradorId, int $ttlSegundos = 30 * 24 * 3600): string {
        $secret = getenv('NUBIRA_HMAC_SECRET') ?: ($_ENV['NUBIRA_HMAC_SECRET'] ?? 'NUBIRA_SECRET_TEMP_CAMBIAR');
        $exp = time() + $ttlSegundos;
        $sig = hash_hmac('sha256', "$idApunte|$compradorId|$archivo|$exp", $secret);
        return "/app/descargar_apunte.php?id=$idApunte&archivo=" . urlencode($archivo) . "&comprador_id=$compradorId&exp=$exp&sig=$sig";
    }
}
