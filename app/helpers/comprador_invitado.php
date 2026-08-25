<?php
// app/helpers/comprador_invitado.php
// Checkout de invitado para apuntes (sin registro, sin login) — diseño aprobado 24/08/2026,
// revisado a cero fricción (sin email) el mismo día, y revisado de nuevo el 25/08/2026 para
// agregar un email OPCIONAL de respaldo (recibir el link por correo, no obligatorio).
// Un comprador invitado es una fila REAL en `alumnos` (con email real si lo dieron, sintético
// si no) para no tocar los INNER JOIN existentes de ventas_apuntes.php,
// admin_compras_apuntes.php, mis_ventas.php y exportar_ventas.php. Se distingue de una cuenta
// normal con `es_comprador_invitado=1` — nombre elegido a propósito para no chocar con
// "invitado" del sistema de referidos (invitar_interesado.php y afines, feature distinta).
//
// Capas que bloquean el login de una fila invitada aunque alguien adivinara la password:
// confirmado=0 (login.php ya rechaza esto), visible=0 (oculta de admin_usuarios.php, mismo
// patrón que "Soporte Nubira"), password = hash de 32 bytes aleatorios (password_hash, nunca
// se muestra ni se comunica a nadie).
//
// Dos caminos de creación, elegidos en pago_exitoso.php/notificaciones_mp.php según si
// `metadata.email` viene en el pago: SIN email, cada compra es una fila NUEVA (no hay
// identidad que buscar); CON email, se busca-o-crea por correo (mismo email = mismo
// comprador). En ambos casos, quien crea la fila es quien primero procese el payment_id — el
// otro camino reutiliza ese mismo id leyéndolo de `compras.usuario_id` en vez de crear el
// suyo propio. Ver la nota de coordinación en pago_exitoso.php/notificaciones_mp.php.

require_once __DIR__ . '/../conexion.php';

// Auto-migración — mismo patrón que login.php:7-11. Se ejecuta una vez por request que
// cargue este helper (checkout invitado, retorno de pago, webhook), no en cada carga de
// página del sitio.
try { $conn->query("ALTER TABLE alumnos ADD COLUMN es_comprador_invitado TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
try { $conn->query("ALTER TABLE ventas_apuntes ADD COLUMN correo_enviado TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}

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

if (!function_exists('obtenerOCrearCompradorInvitado')) {
    /**
     * Busca o crea el alumno-invitado correspondiente a un email real (dado voluntariamente
     * como respaldo, no obligatorio). Mismo email = misma fila en compras futuras — no rompe
     * el UNIQUE(apunte_id, comprador_id) porque ese constraint es por apunte, no por
     * comprador. Si el email ya pertenece a una cuenta real (no invitada), se rechaza
     * explícitamente en vez de mezclar compras con la cuenta de otra persona.
     *
     * @return array{ok: bool, id: ?int, error: ?string}
     */
    function obtenerOCrearCompradorInvitado(mysqli $conn, string $email): array {
        $email = strtolower(trim($email));

        $buscar = function () use ($conn, $email): ?array {
            $stmt = $conn->prepare("SELECT id, es_comprador_invitado FROM alumnos WHERE correo = ? LIMIT 1");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $fila = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return $fila ?: null;
        };

        $existente = $buscar();
        if ($existente) {
            if ((int)$existente['es_comprador_invitado'] === 1) {
                return ['ok' => true, 'id' => (int)$existente['id'], 'error' => null];
            }
            return ['ok' => false, 'id' => null, 'error' => 'cuenta_existente'];
        }

        $nombre = explode('@', $email)[0];
        $password_aleatoria = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        $dominio = substr(strrchr($email, '@'), 1) ?: '';

        // Carrera: otra request (browser vs. webhook, o doble intento) puede crear la fila
        // entre nuestro SELECT y el INSERT — el UNIQUE(correo) la frena. Según el entorno,
        // mysqli reporta ese choque lanzando mysqli_sql_exception (default desde PHP 8.1) o
        // devolviendo execute()=false con errno 1062 sin lanzar nada — se cubren ambos casos
        // en vez de asumir uno solo.
        $insertOk = false;
        $errno = 0;
        $id = null;
        try {
            $stmt = $conn->prepare(
                "INSERT INTO alumnos (nombre, correo, password, dominio, confirmado, visible, rol, tipo, es_comprador_invitado)
                 VALUES (?, ?, ?, ?, 0, 0, 'alumno', 'invitado', 1)"
            );
            $stmt->bind_param("ssss", $nombre, $email, $password_aleatoria, $dominio);
            $insertOk = $stmt->execute();
            $errno = $stmt->errno;
            if ($insertOk) $id = (int)$stmt->insert_id;
            $stmt->close();
        } catch (mysqli_sql_exception $e) {
            $errno = $e->getCode();
        }

        if ($insertOk && $id) {
            return ['ok' => true, 'id' => $id, 'error' => null];
        }

        if ($errno === 1062) {
            // LOCK IN SHARE MODE, no un SELECT normal — mismo motivo que la recuperación de
            // compras.payment_id en pago_exitoso.php/notificaciones_mp.php: bajo REPEATABLE
            // READ, esta transacción no vería la fila que la otra ruta (browser vs. webhook)
            // acaba de confirmar si usara el snapshot fijado en el primer SELECT de $buscar().
            $stmtGanador = $conn->prepare("SELECT id, es_comprador_invitado FROM alumnos WHERE correo = ? LOCK IN SHARE MODE");
            $stmtGanador->bind_param("s", $email);
            $stmtGanador->execute();
            $ganador = $stmtGanador->get_result()->fetch_assoc();
            $stmtGanador->close();

            if ($ganador && (int)$ganador['es_comprador_invitado'] === 1) {
                return ['ok' => true, 'id' => (int)$ganador['id'], 'error' => null];
            }
            return ['ok' => false, 'id' => null, 'error' => 'cuenta_existente'];
        }

        return ['ok' => false, 'id' => null, 'error' => 'error_bd'];
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
