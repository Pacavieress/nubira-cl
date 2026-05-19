<?php
// [NUBIRA 2.0] Lógica de Copys Dinámicos (SSR - Zero Latency)
// Archivo: componentes/seccion_recomendaciones.php

global $conn; // Conexión procedural mysqli

$is_guest = !isset($_SESSION['usuario_id']);
$titulo_ia = "";
$subtitulo_ia = "";

if (!$is_guest && !empty($_SESSION['usuario_nombre'])) {
    $usuario_id = (int)$_SESSION['usuario_id'];
    $primer_nombre = htmlspecialchars(explode(' ', trim($_SESSION['usuario_nombre']))[0]);
    
    // Banderas de perfil
    $es_tutor = false;
    $es_creador = false;

    // 1. Verificamos si es TUTOR (Tiene servicios aprobados y visibles)
    // Usamos alumno_id y verificamos estado y visibilidad según la BD real
    $query_tutor = "SELECT COUNT(id) FROM servicios WHERE alumno_id = ? AND estado = 'aprobado' AND visible = 1";
    if ($stmt_tutor = $conn->prepare($query_tutor)) {
        $stmt_tutor->bind_param("i", $usuario_id);
        $stmt_tutor->execute();
        $stmt_tutor->bind_result($count_servicios);
        $stmt_tutor->fetch();
        $stmt_tutor->close();
        
        if ($count_servicios > 0) {
            $es_tutor = true;
        }
    }

    // 2. Si no es tutor, verificamos si es CREADOR (Tiene apuntes aprobados, visibles y no bloqueados)
    // Usamos id_alumno y verificamos estado, visibilidad y bloqueos según la BD real
    if (!$es_tutor) {
        $query_apuntes = "SELECT COUNT(id) FROM apuntes WHERE id_alumno = ? AND estado = 'aprobado' AND visible = 1 AND bloqueado = 0";
        if ($stmt_apuntes = $conn->prepare($query_apuntes)) {
            $stmt_apuntes->bind_param("i", $usuario_id);
            $stmt_apuntes->execute();
            $stmt_apuntes->bind_result($count_apuntes);
            $stmt_apuntes->fetch();
            $stmt_apuntes->close();
            
            if ($count_apuntes > 0) {
                $es_creador = true;
            }
        }
    }

   // 3. Asignación de Hooks según Jerarquía
    if ($es_tutor) {
        // A. TUTORES: Foco en conversión, optimización de perfil y calidad (100% On-Platform)
        $saludos = [
            // Foco en Reseñas (La idea que propusiste, clave para la confianza)
            ['t' => "La confianza lo es todo", 's' => "Baja el precio de tu primera asesoría o da una intro gratis para ganar tus primeras 5 estrellas."],
            
            // Foco en Disponibilidad y Algoritmo
            ['t' => "¿Listo para enseñar esta semana?", 's' => "Los tutores que actualizan sus horarios tienen un 70% más de visibilidad en el buscador."],
            
            // Foco en Tiempo de Respuesta (Vital en un marketplace)
            ['t' => "Tus alumnos tienen urgencia", 's' => "Responder rápido a las solicitudes en la plataforma te posiciona más arriba en Nubira."],
            
            // Foco en Expansión de Servicios
            ['t' => "¿Dominas otro ramo complejo?", 's' => "Publica un nuevo servicio. Mientras más opciones ofrezcas, más ingresos generas."],
            
            // Foco en Calidad Visual (Estilo Airbnb)
            ['t' => "Una buena portada vende sola", 's' => "Asegúrate de que la imagen de tus servicios sea clara y profesional. Destaca del resto."],
            
            // NUEVO: Foco en SEO Interno y Temporada (Reemplaza al contacto por fuera)
            ['t' => "Aprovecha la época de pruebas", 's' => "Ajusta el título de tus servicios incluyendo las palabras clave que más buscan en tu facultad."]
        ];
    } elseif ($es_creador) {
        // B. CREADORES: Foco en subir más material e ingresos pasivos
        $saludos = [
            ['t' => "Tus apuntes salvan semestres", 's' => "¿Tienes material nuevo? Súbelo y sigue generando ingresos pasivos."],
            ['t' => "Convierte tus cuadernos en saldo", 's' => "La época de pruebas se acerca. Es el mejor momento para publicar."],
            ['t' => "Tu biblioteca es un éxito", 's' => "Revisa tus métricas y descubre qué ramos están buscando más tus compañeros."],
            ['t' => "Sigue aportando a tu facultad", 's' => "Los apuntes bien formateados se venden el triple de rápido."]
        ];
    } else {
        // C. CONSUMIDORES LOGUEADOS: Foco en estudio y descubrimiento
        $saludos = [
            ['t' => "Especial para ti", 's' => "Material recomendado según tu actividad reciente."],
            ['t' => "¿Qué estudiaremos hoy?", 's' => "Encuentra exactamente lo que necesitas para tu próxima evaluación."],
            ['t' => "Asegura tus notas esta semana", 's' => "Apuntes y tutorías destacadas de tu facultad."],
            ['t' => "Optimiza tu tiempo de estudio", 's' => "Selección inteligente basada en tus búsquedas anteriores."]
        ];
    }
    
    $hook_actual = $saludos[array_rand($saludos)];
    $titulo_ia = $hook_actual['t'];
    $subtitulo_ia = $hook_actual['s'];
    
} else {
    // D. INVITADOS: Embudo de Dolor Progresivo (Tracking con Cookies)
    
    // 1. Leer o inicializar la cookie de visitas (Dura 30 días)
    $visitas_invitado = isset($_COOKIE['nubira_visitas']) ? (int)$_COOKIE['nubira_visitas'] : 0;
    $visitas_invitado++; // Sumamos la visita actual
    
    // Si los headers no se han enviado, actualizamos la cookie silenciosamente
    if (!headers_sent()) {
        setcookie('nubira_visitas', $visitas_invitado, time() + (86400 * 30), "/");
    }

   // 2. Definir el nivel de dolor según la cantidad de veces que ha entrado
if ($visitas_invitado == 1) {
    // NIVEL 1: Descubrimiento (Agilidad, empatía y solución rápida)
    $hooks_dolor = [
        ['t' => '¿Prueba esta semana?', 's' => 'Encuentra un tutor de tu U disponible para hoy.'],
        ['t' => 'No te estanques', 's' => 'Agenda una clase 1 a 1 y resuelve tus dudas ahora.'],
        ['t' => 'Aprende de los mejores', 's' => 'Nuestros tutores ya aprobaron tu ramo.']
    ];
} elseif ($visitas_invitado == 2 || $visitas_invitado == 3) {
    // NIVEL 2: Consideración (Urgencia, cupos y frustración con otras alternativas)
    $hooks_dolor = [
        ['t' => 'Se acaban los cupos', 's' => 'Reserva a tu tutor antes de que se llene su agenda.'],
        ['t' => '¿Un video de 2 hrs no ayuda?', 's' => 'Aclara tus dudas en 30 minutos con un experto.'],
        ['t' => '¿Te dejaron en visto?', 's' => 'Aquí ves disponibilidad real y agendas al instante.']
    ];
} else {
    // NIVEL 3: Decisión (Push final, costo/beneficio de reprobar)
    $hooks_dolor = [
        ['t' => 'Un rojo tiene solución', 's' => 'Aún estás a tiempo. Un buen tutor te salva el ramo.'],
        ['t' => 'Echarse el ramo es peor', 's' => 'Invierte en una clase, asegura tu nota y respira.'],
        ['t' => 'Salva tu semestre', 's' => 'Regístrate gratis, elige tu tutor y empieza hoy.']
    ];
}
    
    $hook_actual = $hooks_dolor[array_rand($hooks_dolor)];
    $titulo_ia = $hook_actual['t'];
    $subtitulo_ia = $hook_actual['s'];
}
?>

<div class="mb-3 px-4 md:px-10 max-w-[1600px] mx-auto">
    <h2 class="text-lg md:text-xl font-bold text-gray-900 tracking-tight"><?= $titulo_ia ?></h2>
</div>