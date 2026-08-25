<?php
// app/helpers/pago_apunte.php
// Construcción de la preferencia de MercadoPago para la compra de un apunte — extraído de
// iniciar_pago.php para que el checkout logueado (iniciar_pago.php) y el checkout de
// invitado (iniciar_pago_invitado.php) nunca puedan divergir en título/back_urls/moneda.
// Diseño aprobado en sesión del 24/08/2026 (checkout de invitado para apuntes).

require_once dirname(__DIR__) . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

use MercadoPago\MercadoPagoConfig;
use MercadoPago\Client\Preference\PreferenceClient;

if (!function_exists('crearPreferenciaApunte')) {
    /**
     * @param array{id_alumno: int, titulo: string, precio: float} $apunte
     * @param array $identidad Una de las dos formas:
     *   ['tipo' => 'usuario', 'usuario_id' => int, 'institucion' => string]
     *   ['tipo' => 'invitado']  — cero campos, no hay nada que identificar en el checkout; la
     *   fila fantasma recién se crea al confirmarse el pago (pago_exitoso.php/notificaciones_mp.php)
     * @return object La preferencia creada (usar ->init_point para el redirect)
     */
    function crearPreferenciaApunte(int $idApunte, array $apunte, array $identidad): object {
        MercadoPagoConfig::setAccessToken(MP_ACCESS_TOKEN);

        $baseUrl    = 'https://nubira.cl';
        $successUrl = $baseUrl . "/app/pago_exitoso.php";
        $failureUrl = $baseUrl . "/app/pago_error.php";

        $titulo = trim($apunte['titulo']);
        $precio = (float)$apunte['precio'];

        // es_invitado:true es solo una marca legible en los logs del webhook — no identifica
        // a nadie, no hay ningún dato personal en el checkout de invitado.
        $metadata = $identidad['tipo'] === 'invitado'
            ? ['es_invitado' => true]
            : ['usuario_id' => $identidad['usuario_id'], 'institucion' => $identidad['institucion']];

        $client = new PreferenceClient();
        return $client->create([
            "items" => [[
                "title"       => "Apunte Nubira: " . mb_substr($titulo, 0, 50),
                "description" => "Acceso permanente al documento",
                "quantity"    => 1,
                "unit_price"  => $precio,
                "currency_id" => "CLP"
            ]],
            "back_urls" => [
                "success" => $successUrl,
                "failure" => $failureUrl,
                "pending" => $successUrl
            ],
            "auto_return"        => "approved",
            "external_reference" => (string)$idApunte,
            "metadata"            => $metadata
        ]);
    }
}
