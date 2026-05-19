<?php
/**
 * DATA: NUBIRA BRAIN - PLATFORM CENTRIC EDITION (V9.0)
 * CAMBIOS: Sin Institución, Sin Zoom/Meet. Todo es "Aula Nubira".
 */

return [

    // 1. DICCIONARIO DE DETECCIÓN (Keywords)
    'keywords' => [
        // NUEVO: Música (Sacamos instrumentos de Educación)
        'Musica'    => ['guitarra', 'piano', 'canto', 'bajo', 'bateria', 'ukelele', 'violin', 'teclado', 'solfeo', 'produccion musical', 'dj', 'flauta', 'musica'],
        
        // NUEVO: Bienestar y Deporte
        'Bienestar' => ['entren', 'personal trainer', 'gym', 'yoga', 'pilates', 'nutricion', 'dieta', 'meditacion', 'futbol', 'tenis', 'padel', 'fitness', 'crossfit', 'zumba', 'masaje', 'terapeut', 'psicolog'],

        // NUEVO: Gastronomía
        'Gastronomia' => ['comida', 'almuerzo', 'colacion', 'sushi', 'pizza', 'hamburguesa', 'bajon', 'torta', 'queque', 'galleta', 'dulce', 'reposteria', 'pan', 'catering', 'cocktail', 'vegan', 'vegetariano'],

        // LOS CLÁSICOS (Sin los instrumentos musicales)
        'Idiomas'   => ['ingles', 'inglés', 'english', 'frances', 'francés', 'aleman', 'alemán', 'chino', 'toefl', 'ielts', 'toeic', 'speaking', 'idioma', 'lengua', 'japones'],
        'Educacion' => ['clase', 'tutor', 'enseñ', 'aprend', 'curso', 'profe', 'matematica', 'calculo', 'fisica', 'quimica', 'biologia', 'historia', 'geografia', 'lenguaje', 'psu', 'paes', 'ayudantia', 'reforzamiento', 'estadistica', 'algebra', 'programacion', 'python', 'java', 'excel', 'contabilidad', 'anatomia', 'derecho', 'economia', 'finanzas'],
        'Creativo'  => ['diseñ', 'logo', 'web', 'prog', 'foto', 'video', 'redac', 'transcri', 'traduc', 'guion', 'social media', 'cm', 'post', 'instagram', 'branding', 'edicion', 'flyer', 'ppt', 'presenta', 'render', '3d', 'autocad', 'plano', 'ilustracion', 'photoshop', 'illustrator', 'indesign', 'canva'],
        'Tecnico'   => ['repara', 'tecnico', 'pc', 'compu', 'formateo', 'limpieza', 'insta', 'arreglo', 'mantencion', 'soporte', 'instalacion', 'recuperacion', 'celular', 'iphone', 'pantalla', 'notebook', 'mac', 'windows', 'virus', 'ssd', 'ram'],
        'Asesoria'  => ['tesis', 'asesor', 'gui', 'proyecto', 'consul', 'coach', 'legal', 'abogado', 'psico', 'terapia', 'ayuda', 'correccion', 'normas', 'apa', 'spss', 'encuesta', 'metodologia', 'marco teorico'],
        'Servicios' => ['paseo', 'perro', 'gato', 'mascota', 'cuida', 'limpi', 'flete', 'mudanza', 'tramite', 'chofer', 'uber', 'delivery', 'aseo', 'planchado', 'lavado', 'impresion', 'scanner', 'arriendo']
    ],

    // 2. ARQUETIPOS LIMPIOS

    'Educacion' => [
        'hooks' => [
            "👋 Hola, soy {NOMBRE}. Si {TEMA} te está (sacando canas verdes|costando), cuenta conmigo.",
            "Soy {NOMBRE}. ¿Sientes que {TEMA} se te viene encima? (Tranquilo/a|No te preocupes), lo sacamos adelante.",
            "🚀 (Asegura el azul|Salva el semestre) en {TEMA}. Soy {NOMBRE} y te explico (en fácil|con paciencia).",
            "¿La verdad? {TEMA} es difícil si no tienes buena base. Soy {NOMBRE} y te ayudo a (entender de verdad|ponerte al día).",
            // CORREGIDO: Eliminada la redundancia "preparemos... preparados"
            "¡No te eches el ramo! Soy {NOMBRE}. Preparemos tus evaluaciones de {TEMA} (con anticipación|para asegurar esa nota)."
        ],
        'problems' => [
            "El problema es que a veces pasan la materia muy rápido y uno se queda con dudas.",
            "(A veces|Muchas veces) estudiar solo no basta, te trabas en un ejercicio y pierdes horas.",
            "Llega la prueba y (los nervios te traicionan|te bloqueas) aunque hayas estudiado.",
            "No dejes que {TEMA} te baje el promedio general."
        ],
        'solutions' => [
            "✅ Como estudiante, sé exactamente qué es lo que preguntan los profes.",
            "✅ Te ofrezco un espacio seguro para preguntar esas cosas que te da vergüenza preguntar en clase.",
            "✅ Mis clases {MODALIDAD} son 100% enfocadas en tus guías y temario.",
            "✅ La idea es que hagas el 'click' con la materia y vayas (relax|seguro) a la evaluación."
        ],
        'method' => [
            "🔹 ¿Cómo trabajo? Revisamos tu materia y atacamos tus puntos débiles.",
            "👉 Metodología: Full práctica. Resolvemos ejercicios hasta que te salgan solos.",
            "👉 Todo gestionado de forma segura a través de la plataforma."
        ],
        'cta' => [
            "📅 (Me quedan pocos cupos|Agenda abierta). Escríbeme al chat de **Nubira.cl** y coordinamos.",
            "📩 ¿Te tinca? Mándame un mensaje por **Nubira.cl** y le damos.",
            "💬 Hablemos por el chat de **Nubira.cl** para armar tu plan de estudio.",
            "🚀 No esperes a tener el rojo encima. Escríbeme un mensaje interno por **Nubira.cl**."
        ]
    ],

   // ---------------------------------------------------------
    // 2. ARQUETIPO: IDIOMAS (Políglota)
    // ENFOQUE: Speaking, Cultura, Sin miedo
    // ---------------------------------------------------------
    'Idiomas' => [
        'hooks' => [
            // Todos los hooks ahora llevan {SALUDO} al inicio
            "👋 {SALUDO} Soy {NOMBRE}. (Te ayudo|Te enseño) a dominar {TEMA} de forma natural.",
            "{SALUDO} Soy {NOMBRE}. Si necesitas mejorar tu {TEMA} (para viajar|para la U), (estoy aquí|cuenta conmigo).",
            "🚀 {SALUDO} Soy {NOMBRE}. Lleva tu {TEMA} a otro nivel (sin aburrirte con pura gramática).",
            "¿{TEMA} te cuesta? {SALUDO} Soy {NOMBRE} y te ayudo a perder el miedo a hablar."
        ],
        'problems' => [
            "Uno suele entender cuando lee, pero (se congela|se bloquea) cuando tiene que hablar.",
            "Las apps sirven, pero no te corrigen la pronunciación ni te explican el 'por qué' cultural.",
            "Necesitas practicar conversación con alguien real en un ambiente de confianza."
        ],
        'solutions' => [
            "✅ **Clases Dinámicas**: Full conversación, listening y uso real del idioma.",
            "✅ **Tips Nativos**: Te enseño los trucos prácticos para sonar natural.",
            "✅ **Sin Juicios**: Aquí se viene a aprender. Equivócate todo lo que quieras, yo te corrijo.",
            "✅ **A tu ritmo**: Preparamos certificaciones o simplemente practicamos fluidez."
        ],
        'method' => [
            "🔹 Usamos temas que te gusten (música, series, cultura) para practicar.",
            "👉 Metodología inmersiva y feedback de pronunciación al instante."
        ],
        'cta' => [
            // CTAs NEUTROS (Funcionan para cualquier idioma)
            "📅 ¡Empecemos ya! Escríbeme al chat de **Nubira.cl** para coordinar.",
            "📩 ¿Te animas a aprender? Mándame un mensaje por **Nubira.cl** y agendamos.",
            "💬 Hablemos por el chat de **Nubira.cl** y evaluamos tu nivel.",
            "🚀 Sube de nivel hoy mismo. Envíame un mensaje interno por **Nubira.cl**."
        ]
    ],
    
   'Tecnico' => [
        'hooks' => [
            "🚑 (Alerta roja|Emergencia): ¿Tu {TEMA} (murió|no prende|se fue a negro)? Soy {NOMBRE} y lo revivo.",
            "🔌 Hola, soy {NOMBRE}. No botes tu {TEMA}, (yo lo arreglo|démosle una segunda vida) por una fracción del precio.",
            "¡No entres en pánico! Soy {NOMBRE}. Servicio técnico experto en {TEMA} (rápido y sin chamullo).",
            "🛠️ ¿Tu {TEMA} suena como turbina de avión o está lentísimo? Soy {NOMBRE} y lo dejo (volando|como nuevo).",
            "💾 (Salva tu info|Recupera tus archivos). Soy {NOMBRE} y me especializo en revivir {TEMA}."
        ],
        'problems' => [
            "Lo típico: llevas el equipo a un servicio externo y (te cobran un ojo de la cara|te inventan fallas|demoran semanas).",
            "Justo en la semana de pruebas el equipo decide fallar (la Ley de Murphy no perdona).",
            "Te da desconfianza pasar tu equipo porque tienes tus fotos y archivos personales ahí.",
            "Necesitas una solución AHORA, no en 15 días hábiles."
        ],
        'solutions' => [
            "✅ **Privacidad Garantizada**: Tus archivos son sagrados, no reviso nada que no corresponda.",
            "✅ **Diagnóstico Honesto**: Te digo la firme. Si no vale la pena arreglarlo, te lo diré.",
            "✅ **Rapidez Estudiantil**: Sé que necesitas el equipo para estudiar, así que priorizo la entrega.",
            "✅ **Explicación en fácil**: No te mareo con términos técnicos para cobrarte más."
        ],
        'method' => [
            "🔹 Revisión exhaustiva y presupuesto transparente antes de meter mano.",
            "👉 Si es software, podemos verlo remoto. Si es hardware, coordinamos entrega segura.",
            "👉 Garantía por el trabajo: si la falla persiste, respondo."
        ],
        'cta' => [
            "💬 (Cotiza gratis|Haz tu consulta) al chat de **Nubira.cl** antes de que el daño sea peor.",
            "📩 ¿Tienes dudas? Mándame un mensaje por **Nubira.cl** con el modelo de tu equipo.",
            "⚡ ¡Resucita tu equipo! Envíame un mensaje interno por **Nubira.cl**."
        ]
    ],

    // ---------------------------------------------------------
    // 4. ARQUETIPO: CREATIVO (Diseño, Programación, Multimedia)
    // ENFOQUE: "Se ve Pro", Rúbricas, Plazos Fatales
    // ---------------------------------------------------------
    'Creativo' => [
        'hooks' => [
            "✨ Hola, soy {NOMBRE}. Llevo tu entrega de {TEMA} al (siguiente nivel|nivel profesional).",
            "👋 Soy {NOMBRE}. ¿Necesitas que tu proyecto de {TEMA} destaque entre el resto? Yo me encargo.",
            "🎨 (No te estreses|Relájate) con la parte visual. Soy {NOMBRE} y soluciono tu {TEMA} con estilo.",
            "🚀 ¿Tu idea es buena pero la presentación falla? Soy {NOMBRE}, experto en {TEMA}.",
            "💻 Código limpio y diseño pro. Soy {NOMBRE} y te ayudo con {TEMA}."
        ],
        'problems' => [
            "Sabemos que una buena idea puede fracasar si (visualmente no acompaña|el código no corre).",
            "A veces pelear con el software (te quita horas de sueño|es frustrante) y descuidas el contenido.",
            "Los profes son exigentes con el formato y la estética, y a veces uno no tiene 'dedos para el piano'."
        ],
        'solutions' => [
            "✅ **Calidad Visual**: Entrego trabajos limpios, estéticos y listos para proyectar.",
            "✅ **Ajuste a Rúbrica**: Reviso los requisitos de tu profe para que no te bajen nota por formato.",
            "✅ **Rapidez**: Entiendo lo que significa 'para mañana', compromiso total con los plazos.",
            "✅ **Originalidad**: Nada de plantillas genéricas, trabajo a medida."
        ],
        'method' => [
            "🔹 Comunicación fluida para captar tu visión exacta.",
            "👉 Te muestro avances antes de la entrega final para que des el visto bueno.",
            "👉 Entrega en los formatos exactos que necesitas (PDF, JPG, MP4, Código)."
        ],
        'cta' => [
            "💬 ¡Cuéntame tu idea! Escríbeme al chat de **Nubira.cl** para cotizar.",
            "📩 Hablemos por **Nubira.cl**. Respondo rápido y me adapto a tu presupuesto.",
            "🚀 Que tu entrega brille. Mándame un mensaje interno por **Nubira.cl**."
        ]
    ],

    // ---------------------------------------------------------
    // 5. ARQUETIPO: ASESORÍA (Tesis, Proyectos, Legal)
    // ENFOQUE: Bloqueo mental, APA/ISO, Paz mental
    // ---------------------------------------------------------
    'Asesoria' => [
        'hooks' => [
            "🎓 Hola, soy {NOMBRE}. ¿La {TEMA} te tiene (colapsado|sin dormir)? Te ayudo a avanzar.",
            "¿Bloqueado con {TEMA}? Soy {NOMBRE} y te ayudo a destrabar el avance hoy mismo.",
            "🔎 Tu {TEMA} merece un enfoque profesional. Soy {NOMBRE} y te ofrezco una segunda opinión experta.",
            "📝 (Normas APA|Metodología|Marco Teórico)... ¿Te suena a chino? Soy {NOMBRE} y te ordeno el caos.",
            "⚖️ Asesoría integral en {TEMA}. Soy {NOMBRE}, avancemos juntos."
        ],
        'problems' => [
            "Cuando llevas mucho tiempo en un proyecto, pierdes la objetividad y no ves los errores.",
            "El formato, la redacción y las normas pueden ser un verdadero dolor de cabeza (y bajan nota).",
            "Necesitas feedback real y constructivo, no solo que te digan 'vas bien' para cumplir."
        ],
        'solutions' => [
            "✅ **Visión Crítica**: Reviso tu trabajo con ojo clínico para mejorar tu nota y redacción.",
            "✅ **Estructura**: Te ayudo a ordenar las ideas para que el proyecto tenga hilo conductor.",
            "✅ **Acompañamiento**: Preparo contigo la defensa para que te sientas seguro/a al exponer.",
            "✅ **Metodología**: Aplicamos rigor académico para que no te rechacen el avance."
        ],
        'method' => [
            "🔹 Correcciones con control de cambios y comentarios explicativos.",
            "👉 Trabajamos por metas cortas: avanza semana a semana sin agobiarte.",
            "👉 Feedback detallado y honesto en cada etapa."
        ],
        'cta' => [
            "💬 Conversemos sobre tu tema. Escríbeme al chat de **Nubira.cl**.",
            "📩 ¿Tienes dudas? Mándame un mensaje por **Nubira.cl** y evaluamos tu caso.",
            "📅 Agenda tu asesoría enviándome un mensaje por **Nubira.cl**."
        ]
    ],

    // ---------------------------------------------------------
    // 6. ARQUETIPO: SERVICIOS (Paseo, Fletes, Trámites)
    // ENFOQUE: Confianza Total, Seguridad, Comunidad
    // ---------------------------------------------------------
    'Servicios' => [
        'hooks' => [
            "⏱️ ¿Te falta tiempo? Yo, {NOMBRE}, me encargo de {TEMA} por ti.",
            "🤝 Servicio de {TEMA} (confiable y seguro|dentro de la comunidad). Soy {NOMBRE}.",
            "Delega {TEMA} y dedícate a estudiar. Soy {NOMBRE} y te ayudo con la logística.",
            "🐾/📦 Hola, soy {NOMBRE}. Apoyo responsable en {TEMA} para estudiantes y profes."
        ],
        'problems' => [
            "La vida universitaria deja poco tiempo para trámites, mascotas o quehaceres domésticos.",
            "Lo más difícil es encontrar a alguien de **confianza** (que no sea un desconocido) para esto.",
            "Buscas un servicio que cumpla a la hora, sin 'peros' y a precio justo."
        ],
        'solutions' => [
            "✅ **Confianza Nubira**: Soy parte de la comunidad, así que la seguridad está garantizada.",
            "✅ **Responsabilidad**: Entiendo la importancia de la puntualidad y el buen trato.",
            "✅ **Precios Justos**: Tarifas pensadas para el bolsillo estudiantil.",
            "✅ **Cuidado y Detalle**: Trato tus cosas (o mascotas) como si fueran mías."
        ],
        'method' => [
            "🔹 Coordinación fácil y directa por la plataforma.",
            "👉 Disponibilidad flexible (consultar horarios).",
            "👉 Servicio realizado con dedicación y buena onda."
        ],
        'cta' => [
            "💬 ¡Estoy disponible! Escríbeme al chat de **Nubira.cl** para coordinar.",
            "📩 Resuelvo tus dudas por mensaje interno en **Nubira.cl**. Respondo rápido.",
            "⚡ Facilítate la vida. Háblame por el chat de **Nubira.cl**."
        ]
    ],

    // ---------------------------------------------------------
    // 7. ARQUETIPO: GENERAL (Fallback Inteligente)
    // ---------------------------------------------------------
    'General' => [
        'hooks' => [
            "✨ Hola, soy {NOMBRE}. Ofrezco servicio profesional de {TEMA}.",
            "¿Buscas ayuda con {TEMA}? Cuenta conmigo, soy {NOMBRE}.",
            "👋 Solución práctica en {TEMA}. Soy {NOMBRE} y estoy disponible."
        ],
        'problems' => [
            "A veces es difícil encontrar a alguien comprometido y responsable para esto.",
            "Necesitas una solución rápida y confiable, sin vueltas."
        ],
        'solutions' => [
            "✅ Dedicación, responsabilidad y buen precio.",
            "✅ Me adapto a lo que necesites específicamente.",
            "✅ Trato directo y cordial."
        ],
        'method' => [
            "🔹 Coordinación segura a través de la plataforma.",
            "👉 Comunicación constante para que salga todo bien."
        ],
        'cta' => [
            "💬 Para más detalles, escríbeme al chat de **Nubira.cl**.",
            "📩 ¡Hablemos! Envíame un mensaje por **Nubira.cl** y te cuento más."
        ]
    ],
    
    // ---------------------------------------------------------
    // ARQUETIPO: MÚSICA (Instrumentos, Canto)
    // ENFOQUE: Hobby, Desestresarse, Tocar canciones rápido
    // ---------------------------------------------------------
    'Musica' => [
        'hooks' => [
            "🎸 ¿Siempre quisiste aprender {TEMA}? Soy {NOMBRE} y te enseño a tu ritmo.",
            "👋 Hola, soy {NOMBRE}. Deja la teoría aburrida y ponte a tocar {TEMA} desde la primera clase.",
            "🎶 (Libera el estrés|Desconéctate de la U) tocando {TEMA}. Soy {NOMBRE} y te ayudo a empezar.",
            "¿Tienes un {TEMA} guardando polvo? Soy {NOMBRE}. ¡Sácale provecho y aprende a tocar!",
            "🎹 Clases de {TEMA} dinámicas y entretenidas. Soy {NOMBRE}, músico y estudiante."
        ],
        'problems' => [
            "Aprender solo con YouTube es frustrante porque nadie te corrige la postura o la técnica.",
            "Las academias tradicionales son caras y te llenan de teoría antes de tocar una canción.",
            "Crees que 'no tienes dedos para el piano', pero solo te falta el profe correcto."
        ],
        'solutions' => [
            "✅ **Repertorio a tu gusto**: Aprendemos con las canciones que TÚ quieres tocar.",
            "✅ **Práctica sobre Teoría**: Vamos directo al instrumento para que te motives rápido.",
            "✅ **Paciencia y buena onda**: Avanzamos a tu velocidad, sin presiones.",
            "✅ **Técnica Correcta**: Te enseño a tocar bien para que no te lesiones ni te frustres."
        ],
        'method' => [
            "🔹 Clases personalizadas según tus gustos musicales.",
            "👉 Material de apoyo (tablaturas/partituras simplificadas).",
            "👉 Ejercicios prácticos para soltar los dedos."
        ],
        'cta' => [
            "🎵 ¡Afinemos detalles! Escríbeme al chat de **Nubira.cl**.",
            "📩 ¿Te animas? Mándame un mensaje por **Nubira.cl** y coordinamos.",
            "🚀 Empieza a tocar hoy. Mensaje interno por **Nubira.cl**."
        ]
    ],

    // ---------------------------------------------------------
    // ARQUETIPO: BIENESTAR (Deporte, Yoga, Nutrición)
    // ENFOQUE: Salud mental, Energía, "Ponerse en forma"
    // ---------------------------------------------------------
    'Bienestar' => [
        'hooks' => [
            "💪 ¿La U te tiene sedentario? Soy {NOMBRE}. Motívate y entrena {TEMA} conmigo.",
            "👋 Hola, soy {NOMBRE}. (Libera endorfinas|Bota el estrés) con sesiones de {TEMA}.",
            "🧘‍♀️ ¿Necesitas un break mental? Soy {NOMBRE} y te invito a conectar con {TEMA}.",
            "🚀 Ponte en forma sin pagar un gimnasio carísimo. Soy {NOMBRE}, tu partner de {TEMA}.",
            "🍎 Mejora tu salud y energía. Soy {NOMBRE}, asesoría en {TEMA} para estudiantes."
        ],
        'problems' => [
            "Entre clases y estudio, uno se olvida de cuidar el cuerpo y la energía baja.",
            "Los gimnasios son caros y a veces da vergüenza ir sin saber qué hacer.",
            "El estrés de los exámenes te tiene contracturado o ansioso."
        ],
        'solutions' => [
            "✅ **Adaptabilidad**: Rutinas pensadas para tus tiempos y espacio (casa/parque/gym).",
            "✅ **Salud Mental**: Enfoque no solo en lo físico, sino en liberar tensión.",
            "✅ **Motivación**: No te dejo tirar la toalla, entrenamos juntos.",
            "✅ **Economía**: Precios accesibles para presupuesto universitario."
        ],
        'method' => [
            "🔹 Evaluación inicial para ver tu nivel y objetivos.",
            "👉 Seguimiento constante para ver progresos.",
            "👉 Ambiente seguro y de respeto."
        ],
        'cta' => [
            "💪 ¡Vamos que se puede! Escríbeme al chat de **Nubira.cl**.",
            "📩 Agenda tu primera sesión por **Nubira.cl**.",
            "🚀 Cambia tu rutina hoy. Mensaje interno por **Nubira.cl**."
        ]
    ],

    // ---------------------------------------------------------
    // ARQUETIPO: GASTRONOMÍA (Venta de Comida)
    // ENFOQUE: Sabor casero, Hambre, Ahorro
    // ---------------------------------------------------------
    'Gastronomia' => [
        'hooks' => [
            "🍔 ¿Hambre y poco tiempo? Soy {NOMBRE}. Disfruta {TEMA} con (sabor casero|las 3 B).",
            "👋 Hola, soy {NOMBRE}. ¿Te bajó el hambre? Tengo {TEMA} fresco y rico para ti.",
            "🍰 Endulza tu día. Soy {NOMBRE} y preparo {TEMA} a pedido (o entrega inmediata).",
            "🥢 ¿Cansado de la comida del casino? Prueba mi {TEMA}. Soy {NOMBRE} y cocino con cariño.",
            "🚀 {TEMA} para salvar el almuerzo o el bajón. Soy {NOMBRE}."
        ],
        'problems' => [
            "La comida de la U suele ser cara, desabrida o siempre lo mismo.",
            "No tienes tiempo para cocinarte y terminas comiendo pura chatarra.",
            "A veces necesitas un 'cariñito' dulce para seguir estudiando con energía."
        ],
        'solutions' => [
            "✅ **Sabor Casero**: Hecho con ingredientes frescos y buena mano.",
            "✅ **Precio Estudiante**: Rico, contundente y barato.",
            "✅ **Higiene Total**: Preparado con todas las medidas de limpieza.",
            "✅ **Delivery**: Coordinamos entrega en el campus o punto de encuentro."
        ],
        'method' => [
            "🔹 Pedidos con anticipación o stock diario (consultar).",
            "👉 Menú variado y opciones (veg/no veg).",
            "👉 Presentación impecable."
        ],
        'cta' => [
            "😋 ¡Se agotan rápido! Reserva al chat de **Nubira.cl**.",
            "📩 Consulta el menú de hoy por **Nubira.cl**.",
            "🚀 Sacia tu hambre. Mándame un mensaje por **Nubira.cl**."
        ]
    ],
];