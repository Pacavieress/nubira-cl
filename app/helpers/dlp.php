<?php
/**
 * Motor centralizado de DLP anti-contacto para chats.
 * Único lugar donde viven los patrones — usado por enviar_mensaje.php (chat previo/aula)
 * y enviar_mensajes_chat_mini_aula.php (aula en vivo). Antes estaban duplicados en ambos
 * archivos; la duplicación fue justo lo que dejó pasar el bug de links a nubira.cl sin
 * que se corrigiera en los dos lugares a la vez.
 */

if (!function_exists('nb_dlp_nucleo_digitos_tel')) {
    // Núcleo reutilizado por los callers para sus checks propios (celular con contexto,
    // teléfono fraccionado en varios mensajes): 7+ dígitos consecutivos con separadores opcionales.
    function nb_dlp_nucleo_digitos_tel(): string {
        return '(?:\d[\s\-\.]*){7,}';
    }
}

if (!function_exists('nb_dlp_patrones')) {
    function nb_dlp_patrones(): array {
        $nucleo_digitos_tel = nb_dlp_nucleo_digitos_tel();
        return [
            // 1. CORREOS ELECTRÓNICOS (Normales y Ofuscados)
            'email'              => '/[a-z0-9._%+-]+(?:@|\s+arroba\s+|\[arroba\]|\(arroba\))[a-z0-9.-]+(?:\.|\s+punto\s+|\[punto\])[a-z]{2,}/i',

            // 2. TELÉFONOS (Atrapa +569, 9, espacios, guiones y puntos)
            'telefono'           => '/(?:\+?56\s*9|9)?[\s\-\.]*' . $nucleo_digitos_tel . '/',

            // 3. REDES SOCIALES (Nombres, siglas y variaciones fonéticas)
            'redes'              => '/\b(wh?a[ts]+s?[aá]pp?|wasap|watsap|whsatap|guatsap|wsp|wa\.me|instagram|insta|ig|face|fb|tiktok|tk|telegram|tg|t\.me|discord|dc|linktree|x\.com|twitter|tw|linkedin|in)\b/i',

            // 4. MÉTODOS DE PAGO Y BANCOS (Evita transferencias directas)
            'banco'              => '/\b(transferencia|transferir|cuenta rut|cta rut|banco|santander|bci|estado|scotiabank|itau|tenpo|mach|mercadopago|mp|pago rut|datos de mi cuenta|mi rut|rut:)\b/i',

            // 5. INTENCIÓN DE CONTACTO Y UBICACIÓN
            'intencion_contacto' => '/\b(contacto|fono|tel[eé]fono|ll[aá]mame|llamada|mi n[uú]mero|direcci[oó]n|calle|pasaje|vives en|vivo en|mi casa|zoom|meet|teams|skype)\b/i',

            // 6. IDENTIDAD Y BÚSQUEDA (Evita que se busquen por fuera)
            'identidad'          => '/\b(mi nombre es|me llamo|mi apellido|me dicen|puedes decirme|b[úu]scame|encontrarme|encontrame|soy el de|mi perfil|mi cuenta)\b/i',

            // 7. ENLACES EXTERNOS
            'urls'               => '/(http|https|www\.)/i',
        ];
    }
}

if (!function_exists('nb_dlp_quitar_links_propios')) {
    // Quita links a nubira.cl/www.nubira.cl (con o sin ruta) de un texto ya en minúsculas.
    // Solo se usa para evaluar la categoría 'urls' — un link propio no debe contar como
    // "enlace externo".
    function nb_dlp_quitar_links_propios(string $texto_lower): string {
        return preg_replace('#\b(?:https?://)?(?:www\.)?nubira\.cl(/\S*)?#i', '', $texto_lower);
    }
}

if (!function_exists('nb_dlp_evaluar_patrones')) {
    // Evalúa $mensaje_lower (ya en minúsculas) contra nb_dlp_patrones(), en orden. La
    // categoría 'urls' se evalúa sobre una copia sin links propios (nubira.cl); el resto
    // de categorías ve el mensaje intacto — un teléfono real junto a un link propio sigue
    // bloqueando por 'telefono'. Devuelve la clave de la primera categoría que matchea,
    // o null si el mensaje pasa limpio. No llama a ningún nb_dlp_bloquear*() ni tiene
    // efectos secundarios — cada caller sigue siendo dueño de su propio registro/respuesta.
    function nb_dlp_evaluar_patrones(string $mensaje_lower): ?string {
        $patrones = nb_dlp_patrones();
        $mensaje_sin_links_propios = nb_dlp_quitar_links_propios($mensaje_lower);

        foreach ($patrones as $categoria => $pattern) {
            $texto_evaluar = ($categoria === 'urls') ? $mensaje_sin_links_propios : $mensaje_lower;
            if (preg_match($pattern, $texto_evaluar)) {
                return $categoria;
            }
        }
        return null;
    }
}
