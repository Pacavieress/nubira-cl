
## Resuelto (18/08/2026): robots.txt desincronizado entre repo y producción + bloqueo de /login, /registro

Detectado al investigar por qué Google seguía rastreando `/app/iniciar_chat.php` pese a que el repo local ya tenía `Disallow: /app/*.php`. La causa real: **producción tenía un `robots.txt` desactualizado**, sin esa línea ni `Disallow: /reportar-servicio` — nunca se habían desplegado, no era un bug de la regla (confirmado con `curl https://nubira.cl/robots.txt` en vivo vs. el archivo del repo). Se agregaron además `Disallow: /login` y `Disallow: /registro` (Google los rastreaba, incluidas las variantes `?redir=...`, sin necesidad de comodines — el matching de robots.txt es por prefijo cuando no hay `$` al final). Verificado con simulación del algoritmo de matching de Google que los 6 patrones objetivo quedan bloqueados y que páginas públicas reales (`/explorar`) no se ven afectadas.

**Pendiente Fase 2 (proyecto aparte, NO implementado)**: auditar en el HTML público qué enlaces internos generan URLs de `/login?redir=...` y `/registro?redir=...` que Google puede descubrir directamente (probablemente botones tipo "Contactar" en `<a href="/login?redir=...">` sobre perfiles/servicios cuando el visitante no tiene sesión). El bloqueo de robots.txt ya aplicado es un parche a nivel de rastreo — la causa raíz (por qué esas URLs son *linkeables* y no solo un resultado de un redirect server-side) sigue sin auditar. Candidato a revisar: si esos botones deberían requerir un click/JS antes de exponer la URL completa de login en el HTML, en vez de un `<a href>` directo.

## Resuelto (18/08/2026) + Pendiente Fase 2: auditoría SEO de Google Search Console (noindex/redirects/canónicas)

Auditoría por inferencia de código (sin exportación de URLs de GSC) sobre los 3 problemas de mayor volumen reportados: 254 "Excluida por noindex", 188 "Con redirección", 67 "Duplicada sin canónica". Conclusión: los primeros 2 son comportamiento esperado (noindex condicional anti-thin-content en `landing_categoria.php`/`guias.php`; redirects de gate de login en páginas privadas rastreadas sin sesión) — no requieren fix. El tercero (67, sin canónica) sí era un bug real y ya se corrigió: `app/busqueda.php` no tenía `<link rel="canonical">` — se agregó `nubira_canonical_tag('/busqueda')` (helpers/seo.php), forzado a la URL base sin importar `q`/`orden`/`pagina`/filtros. Verificado con curl que la canónica es siempre `https://nubira.cl/busqueda` bajo cualquier combinación de query params. El resto de páginas de listado (`vitrina.php`, `clases_servicios.php`, `vitrina_apuntes.php`, `descubre.php`, `landing_categoria.php`, `guia_post.php`, `guias.php`) ya tenían canónica correcta desde antes — no se tocaron.

**Fase 2 pendiente (proyecto aparte, NO iniciado)**: landings SEO por materia con canónica propia — evaluar si `landing_categoria.php` amerita expansión/mejora (más contenido único por materia, evitar que el umbral anti-thin-content `< 3` esté noindexando de más si el catálogo crece). No confundir con el fix ya aplicado arriba: esto es una mejora de contenido/estrategia SEO, no un bug de canónica.

## Pendiente: verificacion_estado='pendiente' es un estado huérfano sin camino a 'aprobado'

Detectado el 10/08/2026 al corregir el mensaje de éxito de `completar_perfil.php`. `verificacion_estado='pendiente'` en `alumnos` es un estado huérfano — cuando un usuario se registra sin correo institucional/VIP y completa el formulario de "vender" en `completar_perfil.php`, queda en `'pendiente'` sin ningún camino real en el código actual hacia `'aprobado'`. El mecanismo de aprobación manual (pestaña admin con botones aprobar/rechazar, correos de notificación) fue eliminado el 10/07/2026, pero nada lo reemplazó. Efecto real: el usuario nunca obtiene el ✓ de "Verificado" en su perfil, aunque esto no bloquea publicar ni comprar. Pendiente decidir en sesión dedicada: reactivar algún mecanismo real de aprobación, o simplificar/eliminar el sistema de verificación por ahora.

## Pendiente: busqueda.php sin ningún `<h1>` en ninguno de sus 2 estados

Detectado el 10/08/2026 al compactar el espacio vertical entre el header y las cards de resultados. `busqueda.php` no tiene ningún `<h1>` en ninguno de sus dos estados: el estado "resultados" se quedó sin `<h1>` tras eliminar hoy el que decía `Resultados para "xxx"` (redundante — el buscador del header, fijo y con el término precargado, ya cumplía esa función), y el estado vacío "¿Qué quieres aprender hoy?" ya usaba `<h2>` en vez de `<h1>` desde antes de esta sesión. Impacto bajo (no es error duro de accesibilidad ni penalización directa de SEO), pendiente para retomar con un `<h1 class="sr-only">` visualmente oculto que mantenga la jerarquía semántica sin ocupar espacio visual.

## Pendiente: cifras de la plataforma en sobre-nosotros.php (decisión deliberada, no descuido)

Detectado el 04/08/2026 durante la auditoría de `sobre-nosotros.php`. La página hoy no muestra ningún número de tracción (tutores activos, apuntes publicados, universidades cubiertas) — decisión deliberada por ahora, no un olvido: la plataforma es joven y mostrar cifras bajas podría restar confianza en vez de sumarla, dado el tono de la página ("chico y honesto, todavía construyendo"). Retomar cuando existan cifras que valga la pena mostrar (ej. "500 estudiantes ya encontraron tutor").

**Cabo suelto menor, misma página**: tras el fix de jerarquía de encabezados de esta sesión (H2/H3 invertidos corregidos), los `<h4>` bajo "Los principios" (3 ítems) y "El equipo" ("Pablo") quedan en salto de nivel — cuelgan directo de un `<h2>` sin `<h3>` intermedio. Bajo impacto (no es error de accesibilidad duro, solo no ideal), no se tocó por estar fuera del alcance pedido en esa pasada.

## Pendiente: datos_bancarios.php no muestra el resultado de solicitar_retiro.php al usuario

Detectado el 04/08/2026 al revisar la protección CSRF de `solicitar_retiro.php`. Ese archivo redirige con `?error=<código>` (monto_invalido, monto_minimo, saldo_insuficiente, sin_datos_bancarios, csrf_invalido, db) o `?retiro=ok` según el resultado (líneas 74-76, 107, 111), pero `datos_bancarios.php` no lee `$_GET['error']` ni `$_GET['retiro']` en ningún lugar — los parámetros llegan mudos, sin banner de éxito ni de error. Un usuario que solicita un retiro no tiene forma de saber si funcionó salvo mirando el historial más abajo en la misma página. Gap de UX en un flujo de dinero real, pendiente de resolver en sesión dedicada (mapear cada código a un mensaje visible, banner o toast).

## Pendiente: residuo de "Mis Ventas" en panel_gestion.php:120 tras la consolidación a /clases-vendidas

Detectado el 04/08/2026 al confirmar el alcance del commit 58b62f7 (redirect 301 de `/mis-ventas` a `/clases-vendidas`, tile eliminado, `app/mis_ventas.php` sin acceso real). El array `$herramientas_tutor` en `panel_gestion.php:120` todavía incluye `'Mis Ventas'`:

```php
$herramientas_tutor = ['Mis Publicaciones', 'Clases Vendidas', 'Apuntes Vendidos', 'Mis Ventas', 'Mis Contratos', 'Mi Billetera', 'Métricas'];
```

Confirmado que **no** reabre acceso: este array solo filtra por título contra `$accesos_user` (línea 112-126) para decidir qué tiles mostrar a un tutor — no es la lista de tiles en sí. Como el tile "Mis Ventas" ya no existe en `$accesos_user`, la entrada en `$herramientas_tutor` es inerte (no hace match con nada). Candidato a limpieza cosmética en una sesión dedicada — quitar `'Mis Ventas'` de esa lista para que no quede como referencia muerta a una ruta consolidada.

## Pendiente: auditoría UX de mis_servicios.php (/mis-publicaciones) — P2 a P6 sin aplicar

Auditoría completa hecha el 04/08/2026 (P1, acordeones cerrados por defecto, ya resuelto — ver commit correspondiente). Quedan 5 hallazgos priorizados por impacto real, documentados acá para retomar en sesión dedicada:

- **P2 — Viewport con `user-scalable=0`**: el `<meta viewport>` de `mis_servicios.php:129` trae `maximum-scale=1, user-scalable=0`, desactivando el pinch-to-zoom en toda la página sin ninguna razón técnica aparente (no es mapa/canvas/juego). Problema real de accesibilidad para usuarios con baja visión. Ninguna otra página del sitio hace esto. Fix: quitar `maximum-scale=1, user-scalable=0` del meta tag.
- **P3 — Fallas silenciosas en eliminar/reactivar**: las 3 acciones POST (`eliminar_servicio`, `reactivar_servicio`, `eliminar_apunte`, líneas 24-75) están envueltas en `try/catch` que silencian cualquier error ("Silenciamos el error para no dar 500"). Si un UPDATE falla, el usuario es redirigido igual sin ningún mensaje — no hay forma de saber si la acción realmente funcionó.
- **P4 — "Reactivar" sin "Pausar"**: confirmado con grep en todo el codebase que el estado `'pausado'` de un servicio solo se LEE en `mis_servicios.php` (badge + botón "Reactivar") pero no existe ninguna acción `pausar_servicio` en ningún archivo del sitio. Un usuario no tiene forma de pausar su propio servicio — el flujo de reactivación existe para un estado que hoy es inalcanzable desde la UI.
- **P5 — Cero estadísticas por publicación**: la lista no muestra vistas, ventas/contratos ni calificación por servicio/apunte. Mejora de valor para tutores activos, no bloquea nada.
- **P6 — Tipografía/paleta vieja**: la página no recibió la ronda de refinamiento visual aplicada esta semana a perfil.php/guias.php/guia_post.php/vitrina.php. Usa `font-extrabold`/`font-black` y la familia de color `slate` (`text-slate-800`, `border-slate-100`, etc.) en vez de `font-medium`/`tracking-[-0.01em]` y `gray`/`#f0f0f0` que documenta el sistema de diseño. Puramente cosmético, sin impacto funcional — por eso quedó último en la priorización.

## Idea en evaluación: íconos de redes sociales (Instagram/Facebook) clickeables en perfil.php

Incentivar a los usuarios a seguir la cuenta de Nubira. Descartado `footer.php` como ubicación principal (oculto en móvil desde ayer). Considerado también un bloque al final de `vitrina.php`. Decisión pendiente sobre el comportamiento al hacer clic: (A) simple `target="_blank"` a pestaña nueva sin salir de Nubira, o (B) modal con vista previa antes de salir — dentro de B, evaluados 3 niveles: Nivel 1 (modal estático, foto+nombre+botón, sin API), Nivel 2 (datos reales vía Instagram/Facebook API, requiere mantenimiento de tokens que expiran), Nivel 3 (widget embebido oficial de Meta). Nubira ya tiene cuentas activas de Instagram y Facebook con contenido real. Pausado el 03/08/2026 para decidir con más calma en otra sesión — evaluar esfuerzo de mantenimiento a largo plazo antes de elegir nivel.

## Resuelto (31/07/2026): margen lateral de las cards de marketing ampliado a 110px

Superó al intento anterior de mover `colX=450`/140px (ver nota vieja de esta misma sección, ahora obsoleta): esta vez se movió el margen compartido (`$M = 110`) de forma uniforme a TODOS los elementos horizontales de `nb_generar_imagen_post()` a la vez (avLeft, colMaxW de nombre/institución, ancho de wrap del título, padX de features, x del botón, ancla de "Nubira.cl") en vez de solo `colX`. Verificado con `imagettfbbox()` real que las 4 frases fijas de features siguen entrando en una sola línea con margen (mínimo 11px en la más larga, "Chat anónimo antes de contratar"). `NB_IMG_VERSION` v20→v21.

## Pendiente: badge "Disponible" en las cards de marketing puede colisionar con nombres largos del tutor

Ej. "Sofía Valentina C.", 459px, se trunca si se reserva espacio fijo para el badge. Recomendación evaluada: reposicionar el badge a su propia línea separada (implica mover en cascada las coordenadas Y de categoría/rating/título) — no es solo un ajuste de margen horizontal. Evaluado el 31/07/2026, sin aplicar por requerir más tiempo de diseño cuidadoso.

## Pendiente: descubre.php tiene una arquitectura de página distinta al resto del sitio

Sidebar como drawer JS propio (oculto en todas las resoluciones hasta clic en hamburguesa), sin `header.php` ni `nav_bottom.php` incluidos. Para alinearlo al `sidebar.php` estándar (detectado el 30/07/2026 al intentar extender el refinamiento visual del sidebar) hace falta: (1) agregar `header.php`, (2) reemplazar el `<aside>` hardcodeado por include real de `sidebar.php`, (3) agregar `nav_bottom.php` para recuperar navegación móvil, (4) eliminar JS muerto de `openSidebar`/`closeSidebar`/`overlay`, (5) ajustar el layout de `<main>` al patrón estándar (`lg:ml-64` + padding, no `flex`). Es una reestructuración de página completa, no un simple cambio de include — requiere sesión dedicada.

## Pendiente: vitrina.php y cargar_apuntes.php calculan "ventas_totales" con fórmulas DISTINTAS

vitrina.php (3 carruseles de apuntes) y cargar_apuntes.php calculan `ventas_totales` con fórmulas DISTINTAS para el mismo apunte — vitrina.php usa solo `ap.descargas`, cargar_apuntes.php suma `ventas_apuntes` (precio>0) + `ap.descargas`. Esto significa que el mismo apunte podría mostrar números diferentes según en qué página se vea. Detectado el 29/07/2026 al unificar la palabra a "descargas" en ambos. Evaluar si conviene unificar también las fórmulas para consistencia total.

## Pendiente sin resolver completamente: salto/zoom visual al cargar perfil.php en móvil (red lenta)

Investigado el 29/07/2026 con 3 intentos: (1) Google Fonts preload+onload swap — insuficiente. (2) defer en Tailwind CDN + candado esperando DOMContentLoaded — empeoró el problema (defer no garantiza que Tailwind terminó de inyectar CSS, solo que el script se ejecutó). (3) Candado con sonda real (requestAnimationFrame verificando display computado de un elemento con clase .hidden) — mejoró parcialmente, pero la sonda solo verifica UNA clase representativa, no todas las clases realmente usadas en la página, así que el salto persiste en algunos elementos (confirmado con íconos SVG). (4) Se agregó tamaño intrínseco (width/height) a los SVG de icon() en iconos.php, beneficiando a 48 archivos del sitio — resuelve el caso específico de íconos, pero el usuario reporta que "sigue el tema" en general. Pendiente: investigar si hace falta ampliar la sonda para verificar múltiples clases representativas, o un enfoque más robusto de raíz. Log temporal en loader_nativo.php pendiente de remover cuando se cierre este tema.

## Pendiente: panel_gestion.php se renderiza DOS VECES en perfil.php (candidato a investigación de arquitectura)

Detectado el 29/07/2026 al investigar un reporte de "zoom"/salto visual al cargar perfil.php en móvil. `app/componentes/panel_gestion.php` (34 tiles de accesos) se incluye dos veces en la misma carga de `perfil.php`: una copia dentro de la sección `xl:hidden` para móvil (`perfil.php` ~línea 732) y otra dentro de `<aside class="hidden xl:block">` para escritorio (~línea 1041), cada una oculta por CSS según el breakpoint. No parece un descuido — el propio `panel_gestion.php` ya tiene un workaround documentado en su JS para el problema que esto genera (IDs de badge duplicados): comentario `// NUBIRA 2.0 BUGFIX: Usamos selector de atributo para atrapar IDs duplicados (Móvil + Escritorio)` (`panel_gestion.php:232-233`), que usa `document.querySelectorAll('[id="..."]')` en vez de `getElementById` para no perderse ninguna de las dos copias.

Relevancia para el bug de "zoom" investigado: duplicar el panel infla el volumen de DOM y de clases Tailwind que el CDN de Tailwind (carga JIT en el navegador, ver `perfil.php:451`) tiene que escanear e inyectar antes de que el candado anti-FOUC (`fouc-lock`, `componentes/loader_nativo.php`) se libere — factor agravante identificado, no la causa raíz confirmada (esa sigue siendo el propio CDN de Tailwind bloqueante corriendo carrera contra el timing de revelado de la página).

Pendiente de decidir: si vale la pena rediseñar esto (renderizar una sola vez y reposicionar vía CSS/JS según breakpoint, o cargar la versión de escritorio de forma diferida) o dejarlo como está dado que ya tiene su propio workaround funcionando. Requiere sesión dedicada — no se tocó el 29/07/2026 para no arriesgar el workaround de IDs duplicados ya existente.

## Pendiente: admin como observador en clase en progreso (mini_aula.php)

Detectado el 28/07/2026 al investigar si el admin puede "espiar" una clase en curso. El acceso YA existe hoy y es más abierto de lo ideal — no falta un camino de entrada, falta que ese camino sea de observador real en vez de participante indistinguible:

- `mini_aula.php:34-36` exime explícitamente al admin de la restricción de comprador/vendedor (`if (!$es_admin) { $sql .= " AND (c.comprador_id = ? OR c.vendedor_id = ?)"; }`) — cualquier admin puede cargar `/app/mini_aula.php?id=X` para cualquier contrato, sin ser parte de él.
- `mini_aula.php:128-133` fuerza `$video_habilitado = true` para admin siempre, saltándose la sala de espera y el cierre por horario.
- La función de videollamada (`iniciarClase()`, `mini_aula.php:654-698`) no tiene ninguna rama para `$es_admin` — se une a la sala real de Daily.co con su nombre de sesión, cámara y mic con comportamiento default, indistinguible de un participante real. Los otros dos participantes además reciben la notificación de "reunión activa" (badge polling, `mini_aula.php:802-816`) apenas el admin entra — no es sigiloso.
- Existe una variable `$rol_en_contrato = 'espectador_admin'` (`mini_aula.php:168`) que sugiere que se pensó un modo observador, pero nunca se usa en ningún otro lugar del archivo — código muerto, no una función real.
- Contraste: `chat_mini_aula.php:68-69` sí implementa un observador real y funcional (`$bloqueado = $es_admin || ...` — el admin ve el chat pero no puede escribir). Ese mismo criterio nunca se replicó al video.

Decisión de diseño pendiente: modo **VISIBLE** (admin se une como participante identificado, ej. "Nubira - Soporte" — más simple técnicamente, ya que solo requiere cambiar el `userName` que se le pasa a Daily.co en vez del nombre de sesión real, y es más transparente/ético hacia los participantes) vs. modo **INVISIBLE** (admin oculto de la videollamada real para los otros participantes — mucho más complejo técnicamente, implica trabajar contra las capacidades de la librería/API de Daily.co, y tiene implicaciones legales de privacidad a evaluar antes de construirlo). Recomendación previa: ir con modo visible. Sin resolver por decisión pendiente del usuario.

## Aclaración importante: hay 3 archivos "hub" de admin — cuál es cuál (evitar confusión futura)

Detectado el 27/07/2026 al intentar agregar el link de Guías al "menú admin" y descubrir, con evidencia real (grep + render con sesión simulada + revisión de `.htaccess`), que había editado el archivo equivocado primero. Documentado acá para no repetir la confusión:

- **`app/panel_gestion.php` — el hub REAL que los admins usan día a día.** Es un componente incluido dentro de `perfil.php` (Mi Perfil): cuando `$es_admin === true`, renderiza el grid tipo Bento con el array `$accesos_admin` (título, link, ícono SVG, color, bg, badge). **Este es el que hay que editar** cuando se agrega una sección admin nueva — confirmado que es el que el usuario ve y usa.
- **`app/admin_panel.php` (ruta `/admin/panel`) — un hub secundario con accordion de grupos** ("Gestión de Plataforma", "Contenidos y Publicaciones", etc.), con su propio `<aside>` propio (no usa el sidebar público). Sigue vigente y actualizado (tiene el link a Guías agregado el 27/07/2026), pero **no es el que el usuario mira habitualmente** — nadie enlaza a `/admin/panel` desde la navegación normal del sitio. Además está desactualizado: le faltan `/admin/marketing-cards`, `/admin/ia`, `/admin/banco-imagenes`, `/admin/avisos` — drift acumulado de sesiones anteriores, no introducido ahora.
- **`app/admin_dashboard.php` (ruta `/admin/dashboard`) — archivo legacy/abandonado.** Usa Bulma CSS, redirige a `../public/login.html` (ruta que ya ni existe en la estructura actual), y solo tiene 2 botones hardcodeados (Ver Usuarios, Ver Apuntes). No tiene ningún accordion ni lista de secciones. Candidato a eliminar en una sesión de limpieza (verificar primero que nada más lo referencie), pero no se tocó ahora por estar fuera de alcance.

**Regla práctica para el futuro**: si se agrega una sección `/admin/*` nueva, agregarla en **`panel_gestion.php`** (`$accesos_admin`) primero — es el que realmente importa. `admin_panel.php` es secundario/opcional de mantener. `admin_dashboard.php` no se debe tocar ni usar como referencia.

## Pendiente: reactivar app/datos/ia_nubira.php (endpoint de IA para descripción de apuntes)

`app/datos/ia_nubira.php:23-25` corta con un `exit` temprano ("Servicio de IA temporalmente no disponible... mientras se migra a nuevo proveedor de IA") antes de llegar al código real que arma el prompt y llama a Gemini. Detectado el 27/07/2026 durante el diseño del módulo de IA del Centro de Recursos. Confirmado con el usuario: la migración de proveedor mencionada en el comentario **se descartó** — Gemini sigue siendo el proveedor real, hay que reactivar este endpoint (quitar el corte temprano y confirmar que `GEMINI_API_KEY` sigue siendo válida), no migrar a otro proveedor. Tarea separada, no forma parte del Centro de Recursos — pendiente para una sesión dedicada.

## Tech-debt pendiente: lógica de $categoria_overlay triplicada (7 copias) en vitrina.php/cargar_servicios.php/busqueda.php

Detectado durante Fase 1 de la auditoría SEO (22/07/2026), al investigar el bug de `servicios.categoria = 'Otro'` (singular) renderizando "Clase de Otro" en el overlay de las cards de producción (ids 60, 61, 105, 109 — ver `sql/pendientes/fase1_unificacion_categoria_otro.sql`). El bug existió justamente porque el bloque de 3 líneas que decide el label del overlay está copy-pasteado 7 veces en vez de vivir en un solo lugar:

```php
$categoria_overlay = $row['categoria'] ?? 'Otros';
$prefijo_overlay = in_array($categoria_overlay, ['Otros','Asesoría']) ? '' : 'Clase de';
$nombre_categoria_overlay = ($categoria_overlay === 'Otros') ? 'Clase' : $categoria_overlay;
```

Ubicaciones: `vitrina.php` (5 copias, ~líneas 882, 1013, 1118, 1334, 1445), `cargar_servicios.php` (~línea 191), `busqueda.php` (~línea 466). Fuera de alcance de la auditoría SEO (que es sobre higiene de datos, no refactor de código) — candidato para extraer a una función helper compartida (ej. `nb_categoria_overlay($categoria)`) en una sesión de limpieza técnica dedicada, para que un futuro caso similar se arregle una sola vez y no 7.

## Pendiente: badge de nombre del tutor puede desbordar el lienzo en cards de marketing

Pendiente: el badge de nombre del tutor en las cards de marketing (nb_generar_imagen_post()) puede desbordar el lienzo con nombres muy largos — colMaxW=590px hoy, sin manejo explícito de overflow/truncado. Riesgo detectado en varias rondas de rediseño del 21/07/2026, sin resolver por estar fuera de alcance de cada sesión. Evaluar truncado o reducción de tamaño de fuente dinámico para nombres largos.

## Pendiente: aplicar el rediseño de card de marketing a nb_generar_imagen_history()

Aplicar el mismo rediseño de card (foto grande, badge disponible, categoría+rating separados, título genérico, bio condicional, features fijas) a `nb_generar_imagen_history()` — hoy solo se rediseñó POST 1:1, HISTORY 9:16 sigue con el diseño viejo, generando inconsistencia visual entre ambos formatos hasta que se aplique el mismo trabajo ahí.

## Pendiente: definir relación entre notificar_alternativas_chat.php (cron) y enviar_cupon_alternativas.php (panel manual)

Definir la relación entre `notificar_alternativas_chat.php` (cron automático, 20 min sin respuesta, sin cupón) y `enviar_cupon_alternativas.php` (panel manual, con cupón). Duda pendiente: ¿el panel manual debería ampliar su criterio de "nunca respondió" a "no respondió el mismo día calendario" para casos como Maura (conversación 156, escribió 18/07 20:48, vendedor respondió 20/07 13:56 — casi 2 días después, pero técnicamente "sí respondió" así que hoy no calificaría)? Riesgo identificado: si se amplía el criterio, se generaría superposición con el cron automático (mismo estudiante recibiría 2 correos: el automático a los 20 min sin cupón, y después el manual con cupón si el admin decide enviarlo). Recomendación pendiente de decidir: mantener el panel manual enfocado en casos que el cron nunca cubrió (conversaciones de más de 30 días, o períodos donde el cron estuvo inactivo), no como refuerzo de casos ya atendidos por el automático. Pendiente desde el 20/07/2026.

## Mejora futura: restringir el checkbox "Prepara para la PAES" a categorías relevantes

Evaluar si el checkbox "Prepara para la PAES" debería estar restringido a ciertas categorías relevantes (Matemáticas, Lenguaje, Ciencias, Historia), en vez de estar disponible para cualquier categoría sin restricción. Detectado el 10/07/2026 al trabajar el SEO de PAES en detalle_servicio.php.

## Pendiente: subir imagen/ícono real en cards de novedades (admin_marketing_cards.php, tab Novedades)

`nb_generar_imagen_novedad_post()` y `nb_generar_imagen_novedad_history()` (`app/helpers/imagen_compartir.php`) ya reservan el espacio arriba del título con un círculo placeholder liso color acento (`$diamIcono=90`, sin lógica de carga). Falta la funcionalidad real: que el admin pueda subir una imagen/ícono al crear la novedad (nuevo input file en el formulario de `admin_marketing_cards.php?tab=novedades`, guardarla en disco/BD, y que el generador dibuje esa imagen recortada en círculo en vez del placeholder liso — mismo patrón que `nb_dibujar_avatar()` usa para la foto del tutor en servicios). Diseñado el 09/07/2026, sin implementar — mejora para otra sesión.

## Pendiente: bug de z-index en admin_leads_gmail.php (barra de selección tapada por nav_bottom en móvil)

Mismo bug que encontramos y arreglamos hoy en admin_marketing_cards.php: `#action-bar` (admin_leads_gmail.php:373-374, z-50) queda por debajo de `nav_bottom.php:152` (z-[60]) — ambos son `fixed bottom-0` de ancho completo en móvil, así que nav_bottom tapa la barra de selección "Ver como carrusel"/acción al seleccionar filas. admin_marketing_cards.php copió este patrón de admin_leads_gmail.php y heredó el problema (ahí lo arreglamos ocultando nav_bottom vía JS solo mientras la barra de selección esté visible, sincronizado con syncBar()). Aplicar el mismo fix acá cuando se retome este archivo — no se tocó ahora a pedido explícito del usuario, fuera de alcance de la sesión del 09/07/2026.

## Implementado: sello "Verificado" automático por dominio institucional (simplificado 10/07/2026)

Ya NO es un plan pendiente — está implementado y funcionando, y hoy se simplificó: se eliminó el paso de revisión manual del admin (confirmado que nunca discriminaba — aprobaba a todos en la cola), así que el sello depende 100% del cálculo automático en `register.php`, sin intervención humana.

CASO DETONANTE (histórico, 03/06/2026): Paulina (licenciada en matemáticas, profesora PAES) se registró con Gmail y su perfil mostraba "Estudiante Universitario" sin serlo. Motivó este sistema.

Flujo real, con archivo:línea:
- `register.php:58-72` — al registrarse, 3 ramas deciden `tipo`/`verificacion_estado` en el INSERT mismo: dominio institucional → `aprobado`; excepción VIP (`excepciones_email`) → `aprobado`; cualquier otro caso (default) → `particular` + `pendiente`. No depende de ninguna acción posterior del usuario.
- `login.php:92,211-213` — si `verificacion_estado='pendiente'` y no hay `bio` cargada, redirige una vez a `/completar_perfil`. Con `bio` ya cargada, entra normal a `/vitrina` (con `?aviso=verificacion_pendiente`, ver nota de limpieza abajo).
- `app/completar_perfil.php` — formulario real (tipo + carrera/universidad/año egreso/años experiencia + bio ≥100 caracteres), existe y funciona.
- `app/admin_usuarios.php` — **ya NO tiene** pestaña de revisión manual (eliminada 10/07/2026: tab "Pendientes", query, botones aprobar/rechazar, badge de alerta en `contar_alertas_sistema.php`, y las funciones `enviarCorreoVerificacionAprobada`/`Rechazada` en `correo.php`). En su lugar, la tabla "Todos" tiene un filtro de inspección "Verificado: Sí/No" (`?verificado=si|no`), sin ninguna acción de aprobar/rechazar — solo lectura.
- `perfil.php:186-187,524-526`, `ver_apunte.php:242-243,763`, `detalle_servicio.php:523` — badge ✓ "Verificado" condicionado a `verificacion_estado='aprobado'`.

**Diferencia importante respecto al plan original**: el punto "Restricción: si pendiente/rechazado, NO puede publicar servicios ni apuntes" **no se implementó así**. Confirmado por grep: `publicar_servicio.php` y `formulario_subir_apunte.php` no tienen ninguna referencia a `verificacion_estado` — un usuario pendiente puede publicar y comprar/vender sin restricción. El único efecto real de quedar "pendiente" sin revisar es que no aparece el badge ✓. Si en algún momento se quiere bloquear publicación de verdad, es trabajo nuevo, no algo que ya esté y haya que destrabar.

DOMINIOS INSTITUCIONALES: se gestionan en tabla `dominios_permitidos`. Lista actual via:
`SELECT dominio, institucion FROM dominios_permitidos ORDER BY institucion ASC;`

### Limpieza menor pendiente (candidato para sesión de limpieza futura)
`login.php:212` genera la URL `/vitrina?aviso=verificacion_pendiente` para mostrar un banner de aviso — pero ningún archivo en `app/` lee ese parámetro `aviso`. Código muerto sin efecto visible; candidato a eliminar (o a implementar el banner, si se decide que vale la pena) en una sesión dedicada.

### Bug menor preexistente: `dominio`/`verificacion_estado` no se recalculan si un admin cambia el correo de un usuario
`admin_usuarios.php:146-156` (acción `editar_usuario`, modal "Editar Usuario") permite a un admin cambiar el `correo` de cualquier usuario, pero el `UPDATE` solo toca `nombre`/`correo` — no recalcula `dominio` ni `verificacion_estado` contra `dominios_permitidos`. Si un admin le pone un correo institucional a alguien ahí, no queda verificado automáticamente. Nota: **no** existe este caso vía autoservicio — `editar_datos.php` tiene el campo `correo` en `readonly` (línea 238), un usuario no puede cambiar su propio correo. Candidato a fix en otra sesión (recalcular `dominio`/`verificacion_estado` en ese mismo `UPDATE`, igual que hace `register.php`).

## Pendiente: Contratación de múltiples horas consecutivas con precio proporcional

Nueva funcionalidad: contratación de múltiples horas consecutivas con precio proporcional (precio pasa a ser precio/hora). Investigación de alcance completa hecha el 09/07/2026 — toca: contratos.duracion_horas (columna nueva), contratar_servicio.php (selector de duración), crear_contrato.php (extender lock anti-race a N bloques consecutivos, todo-o-nada), iniciar_pago_servicio.php (corregir monto fijo en preapproval de MercadoPago — usar precio dinámico), UI de precio en tarjetas/detalle (aclarar "/hora"). Requiere sesión dedicada, no es un fix rápido.

## Pendiente: Fase 2 de admin_marketing_cards.php — cards de novedades/anuncios de plataforma

Fase 2 de admin_marketing_cards.php: agregar generador de cards de NOVEDADES/anuncios de plataforma (no solo servicios existentes) — nuevo generador de imagen tipo GD con plantilla propia (título, ícono, branding), a diferencia de img_servicio.php que dibuja datos de un servicio real. Fuente del contenido: admin redacta manualmente el texto de la novedad (Claude Code no tiene memoria propia entre sesiones; puede ayudar a redactar leyendo el historial de commits recientes o este mismo CLAUDE.md si se le pide explícitamente). Mantener los mismos 2 formatos que ya existen (POST 1:1 e HISTORY 9:16) — cubren Instagram Feed/Facebook (post) e Instagram Story/TikTok (history) sin necesitar un formato por cada red social, que multiplicaría el trabajo sin agregar valor proporcional. Diseñado el 09/07/2026, tras completar la v1 de Marketing/Cards para servicios.

# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Pendiente: Funcionalidad "Soporte Nubira" (chat admin→usuario)

Plan auditado y aprobado. NO implementar hasta cerrar caso Javiera Vásquez (PUCV).

### Contexto
Admin necesita enviar mensajes directos a usuarios desde Nubira (sin correo manual). El usuario recibe aviso por correo + ve el hilo en su bandeja de mensajes con badge "Soporte Nubira".

### Ajustes confirmados
- Nombre unificado: **"Soporte Nubira"** en toda la UI (badge, chat, lista). SMTP puede seguir como "Equipo Nubira".
- Ocultar usuario fantasma en admin_usuarios.php: `WHERE id != SOPORTE_USER_ID`
- Filtro "Soporte" en admin_chats.php: opcional, dejar para después

### Pasos de implementación
**Paso 0 — phpMyAdmin (manual):**
- INSERT alumnos: `nombre='Soporte Nubira'`, `correo='soporte@nubira.cl'`, `rol='admin'`, `visible=0`, `confirmado=1` → anotar ID como SOPORTE_USER_ID
- INSERT servicios: `titulo='Soporte Nubira'`, `alumno_id=SOPORTE_USER_ID`, `estado='aprobado'`, `visible=1`, `precio=0` → anotar ID como SOPORTE_SERVICE_ID

**Paso 1 — `app/config.php` (+2 líneas):**
```php
define('SOPORTE_USER_ID', <id>);
define('SOPORTE_SERVICE_ID', <id>);
```

**Paso 2 — `app/admin_enviar_mensaje.php` (reescritura ~60 líneas):**
- Buscar/crear conversación con `servicio_id=SOPORTE_SERVICE_ID`, `comprador_id=destino_id`, `vendedor_id=SOPORTE_USER_ID`
- INSERT en `mensajes` con `remitente_id=SOPORTE_USER_ID` (bypass DLP, inserción directa)
- UPDATE `conversaciones` SET `ultima_interaccion=NOW()`, `oculto_comprador=0`
- `enviarCorreoNuevoMensaje()` con `$nombreEmisor='Soporte Nubira'` — enviar siempre (no aplicar condición offline)

**Paso 3 — `app/mis_chats.php` (~15 líneas):**
- Agregar `(c.servicio_id = SOPORTE_SERVICE_ID) AS es_soporte` al SELECT
- En render: avatar rose, etiqueta "Soporte", nombre forzado a "Soporte Nubira"

**Paso 4 — `app/chat_previo_contrato.php` (~10 líneas):**
- `$es_soporte = ((int)$chat['servicio_id'] === SOPORTE_SERVICE_ID)`
- Ocultar botones "Contratar"/"Ver Aviso" si `$es_soporte`
- Mostrar nombre "Soporte Nubira" fijo (sin truncar apellido)

**Paso 5 — `app/admin_usuarios.php`:**
- Agregar `AND id != SOPORTE_USER_ID` en el WHERE del query de listado de usuarios

### Riesgos a recordar
- Servicio fantasma NUNCA eliminar/ocultar (rompe INNER JOIN en mis_chats y chat_previo_contrato)
- `notificar_inactividad_chat.php`: agregar `AND c.vendedor_id != SOPORTE_USER_ID` para que el cron no envíe alerts de inactividad al usuario fantasma

---

## Pendientes 03/06/2026

### Crítico — Deploy a Hostinger por FileZilla
Subir a /home/u516405553/domains/nubira.cl/public_html/app/:
- app/admin_chats.php (sistema DLP completo)
- app/enviar_mensaje.php (registro de intentos)
- app/contar_alertas_sistema.php (badge admin chats)
- app/admin_usuarios.php (badge usuarios nuevos)

Verificación post-deploy:
- Abrir nubira.cl/admin/chats logueado como admin → crea tabla dlp_intentos automáticamente
- Abrir nubira.cl/admin/usuarios → crea columna visto_admin + marca todos como vistos
- Confirmar pills nuevos visibles, badge usuarios baja a 0
- Probar end-to-end DLP en producción (enviar contacto en un chat de prueba)

### Pendiente desde 01/06: deploy 8-10 archivos de OFERTAS
- app/vitrina.php
- app/cargar_servicios.php
- app/busqueda.php
- app/cargar_vistos.php
- app/componentes/render_card.php
- app/componentes/panel_gestion.php
- app/detalle_servicio.php
- app/ver_apunte.php
- app/admin_ofertas.php
- app/contratar_servicio.php

### Pendiente desde 07/07/2026: eliminar 5 archivos huérfanos en Hostinger
Ya eliminados y commiteados en local (commit 9c96d96). Hay que borrarlos también del servidor vía FileZilla en /home/u516405553/domains/nubira.cl/public_html/:
- app/panel_tutor_guia.php
- app/centro_tutores.php
- app/admin/gestionar_guia.php
- app/api/registrar_feedback_guia.php
- app/mercadopago_webhook.php

Contexto: ninguno tenía ruta activa en .htaccess ni links reales en el repo (diagnóstico completo antes de eliminar). No se tocaron las tablas guia_tutores_contenido ni guia_feedback — quedan vacías en la BD por si se necesita recuperar el contenido a futuro (mismo criterio que con Oportunidades).

No requiere verificación post-deploy especial (nada los enlazaba).

### Producto (sin urgencia)
- Conversación con Nubira Producto (Cowork) sobre cómo atraer primeros compradores reales
- Identificar segmento concreto (USACH / PUC / AIEP / PAES)
- Plan concierge para cerrar primera transacción manual

### Técnico viejo
- BUG conocido panel admin banco_imagenes: subir imagen nueva crea registro adicional en lugar de reemplazar. Workaround manual: tras subir/cambiar imagen del banco en producción, ejecutar el UPDATE general que reasigna imagen_banco_id a la imagen activa más reciente por categoría (con COLLATE utf8mb4_unicode_ci si las tablas tienen collations distintas). Refactor pendiente: agregar botón 'Reemplazar' explícito al panel admin que sobrescriba el registro existente.
- Git filter-repo 2da pasada (keys viejas en commits antiguos)
- Resetear el otro PC cuando vuelva a él
- Copia segura del config.php de producción
- Cambiar contraseñas SMTP y key Gemini que pasaron por chat
- Apelar Google
- Actualizar XAMPP a PHP 8.2 (para poder testear flujo de pago en local)

## Mejoras de UX/Growth pendientes

## Secciones de descubrimiento estilo Airbnb (idea pendiente)

Implementar carruseles contextuales en vitrina.php similar a 'Alojamientos en X' de Airbnb. La arquitectura actual ya lo permite (cada carrusel es una query SQL + render_card).

Ideas iniciales en orden de prioridad:
1. 'Tutores top esta semana' (más contratos cerrados últimos 7 días)
2. 'Empieza mañana' (servicios con próximo cupo dentro de 48h)
3. 'Bajo $10.000' (servicios económicos)
4. 'Para tu PAES' (filtro por keyword en titulo/descripcion)
5. 'Materias críticas primer año' (Cálculo, Álgebra, Química)
6. 'Tutores que responden en menos de 1 hora' (requiere métrica de respuesta)

Requisitos previos:
- Recategorización de los 55 servicios completada
- 12 imágenes IA del banco completas
- Definir métricas de comportamiento del tutor (tiempo respuesta promedio)

Implementación: cada carrusel = nueva query SQL en vitrina.php + componente render_card existente. Sin trabajo nuevo de diseño.

## Estrategia SEO Nubira (pendiente)

## Estrategia SEO para vencer competencia (Superprof, Studocu, Wuolah)

### Mes 1 - SEO técnico
- Schema.org markup en cada servicio (tipo: Service + Person + Review)
- Sitemap.xml dinámico generado desde la BD
- robots.txt: permitir /, bloquear /app/, /admin/
- Meta tags dinámicos por servicio: title, description, Open Graph, Twitter Cards
- Lighthouse 90+ móvil

### Mes 2 - Contenido (blog en /blog)
- 2 artículos/semana enfocados en long-tail Chile:
  - 'Cómo prepararse para PAES Matemática 2026'
  - 'Mejores tutores de Cálculo en USACH'
  - 'Ramos críticos primer año de Ingeniería Chile'
  - 'Cuánto cuesta una clase particular en Santiago 2026'
- Cada artículo apunta a servicios reales de Nubira (links internos)

### Mes 3 - Autoridad (backlinks)
- Contactar 10 centros de alumnos de universidades chilenas
- Inscripción en Google My Business
- Tutores comparten perfil en LinkedIn

### Diferenciador clave
Contenido chileno específico que la competencia internacional no puede hacer:
- PAES, universidades chilenas, ramos específicos
- Pesos chilenos, modalidad híbrida
- Comisión transparente
- Pago protegido en CLP

## Deploy banco de imágenes - notas críticas
- Al ejecutar CREATE TABLE banco_imagenes en producción (Hostinger phpMyAdmin), USAR COLLATE utf8mb4_unicode_ci en la columna categoria (NO utf8mb4_general_ci como quedó en local).
- Después de subir imágenes IA al banco en producción, ejecutar el UPDATE general que asigna imagen_banco_id a todos los servicios por categoría.
- El UPDATE necesita COLLATE explícito si las tablas tienen collations distintas.

## Onboarding 'Cómo funciona Nubira' (decisión técnica)

Slides hardcodeados en componente PHP. Cuando se valide que el onboarding funciona y se necesite iterar copy/imágenes sin deploy, migrar a tabla onboarding_slides editable desde nuevo panel admin (patrón similar a avisos_campanas pero sin fan-out a usuarios).

Validación clave: ¿cuántos usuarios COMPLETAN los 5 slides vs cuántos saltan en slide 1? Medir con la columna onboarding_visto + opcionalmente un onboarding_slide_max si se quiere granularidad.

## Project Overview

**Nubira** (nubira.cl) is a Chilean educational marketplace where university students buy and sell tutoring services (servicios) and study notes (apuntes). Currency is CLP. Solo founder operates all roles. Future: native iOS/Android app via Flutter (~March 2027), PWA as intermediate step. All code must be API-first ready.

## Development Setup

### Local (XAMPP)
- **URL:** http://nubira.local/ (VirtualHost configured)
- **Apache:** port 80, MySQL: port 3306
- **DocumentRoot:** `C:/nubira` (symlinked from `C:\xampp\htdocs\nubira`)
- **phpMyAdmin:** http://localhost/phpmyadmin/
- **Database:** `u516405553_u516405553_Nub`

### Production (Hostinger)
- Deploy via FTP to Hostinger/hPanel
- DB access via phpMyAdmin (no SSH, no local Node.js)
- Database: `u516405553_Nub` on host

### Credentials
`app/conexion.php` has hardcoded credentials. The `.env` file is NOT auto-loaded (no dotenv library). Update `conexion.php` for local dev.

## Tech Stack (STRICT — no deviations)

- **Backend:** PHP 8.x, procedural mysqli, prepared statements MANDATORY everywhere
- **Frontend:** HTML5 semantic, Tailwind CSS (CDN, migrando página por página a build compilado — ver "Build de CSS" abajo), NO custom CSS except minimal `<style>` adjustments
- **JS:** Vanilla ES6+ only, NO jQuery. Animations via `classList`, `transform`, `opacity`
- **Icons:** Custom SVG system via `icon('name')` in `app/iconos.php`. FontAwesome only if already present in file. Migrating to Heroicons-style outline SVGs (stroke-width 1.5)
- **Libraries:** MercadoPago SDK, PHPMailer, pdf.js, viewerjs (only if already in file)

## Build de CSS (Tailwind CLI)

Migración en curso desde Tailwind Play CDN a un build compilado con Tailwind CLI v3
(misma versión mayor que el CDN, `tailwindcss@3.4.19`, para minimizar diferencias de
comportamiento). Página por página, no de una sola vez — ver estado abajo.

- **Config fuente:** `tailwind.config.js` (content, color `nubira`, safelist, 3 plugins:
  forms/typography/aspect-ratio), `src/input.css` (entrada `@tailwind base/components/utilities`).
- **Salida:** `css/tailwind.min.css` — servida como `<link>` estático, reemplaza el
  `<script src="cdn.tailwindcss.com">` página por página.
- **Regenerar tras CUALQUIER cambio de clases Tailwind:** `npm run build:css`
  (usa `npm run watch:css` para iterar en vivo). Subir `css/tailwind.min.css` junto
  con los `.php` que cambiaste en el mismo deploy — nunca por separado.
- **Rollback por página:** cada página mantiene su propio bloque `<head>`
  autocontenido (deliberado — NO centralizar el `<link>` en `head_common.php`
  hasta que las 132 páginas estén migradas y confirmadas estables). Revertir una
  página específica es de un solo archivo (`git checkout <commit> -- archivo.php`),
  sin afectar a las demás.
- **Estado de migración:** 0/132 páginas migradas (pipeline listo, piloto sin iniciar).

## Design System — Nubira 2.0 (Airbnb-inspired)

### Colors
- Primary accent: `#54A6D8` (ONLY accent color — no yellow, purple, green accents)
- Gradients: `from-sky-400 to-[#54A6D8]` and `from-orange-300 to-orange-500`
- Backgrounds: `bg-gray-50` or `bg-white`
- No emoji icons

### Components
- Borders: `rounded-xl`, `rounded-2xl`, `rounded-3xl`
- Shadows: `shadow-sm`, `shadow-md`, `shadow-lg`
- Hover: `transition-all hover:shadow-md hover:scale-[1.01]`
- Cards: white container, `border border-gray-100`, images with `object-cover` (NEVER deform)
- Skeletons: `animate-pulse` before content loads
- Empty states: `bg-gray-50`, `border-dashed`, soft icons

### Typography
- `font-bold`, `tracking-tight`, `leading-tight`
- Headings: `text-xl` to `text-4xl` (Airbnb style)

### Layout
- **Header:** `fixed top-0`, white + `backdrop-blur-md`. Desktop: logo, breadcrumb, publish buttons, user. Mobile: user only
- **Sidebar:** `fixed left-0 w-64` (desktop). Uses `nav_class('/route')` for active state
- **Bottom nav:** `fixed bottom-0` (mobile), 5 icons, center "publish" button with blue Nubira bubble
- **Main content:** `pt-20` mobile, `md:ml-64` desktop. Max widths: `max-w-[1600px]` lists, `max-w-[1100px]` detail/forms
- **Components:** `app/componentes/` — header, sidebar, nav_bottom, modal

## Architecture

### Routing
Apache RewriteRules in `.htaccess`. Clean URLs: `/explorar` → `app/vitrina.php`. `RewriteBase /`. No framework router.

### Page Pattern
```php
session_start();
require_once __DIR__ . '/conexion.php';
if (!isset($_SESSION['usuario_id'])) { header("Location: /login"); exit; }
```
Admin pages check `$_SESSION['rol'] === 'admin'`.

### Database Schema
- DB: `u516405553_Nub` (~87 tables)
- `alumnos` key columns: `id`, `nombre`, `bio`, `carrera`, `correo`, `rol`, `tipo`, `institucion`, `calificacion_promedio`, `cantidad_votos`, `vistas_perfil`, `tiempo_respuesta_promedio`, `bloqueado`, `visible`, `confirmado`, `foto_perfil` (NOT `foto`)
- Soft delete: `visible = 1` on `alumnos`
- Active services: `estado = 'aprobado'` + `COALESCE(visible, 1) = 1`
- `alumno_id` in `servicios` (NOT `usuario_id`)
- Profile URL: `/perfil/[base64_encode($id . '-nubira_secreto')]` with padding stripped via `rtrim(..., '=')`
- Ratings: `valoraciones` table with `rol_evaluado = 'vendedor'` (NOT `contratos.calificacion_comprador`)
- Service tiers: 5 levels — leyenda / élite / pro / top / nuevo (inline SVG stars)

### Session Variables
| Key | Value |
|-----|-------|
| `$_SESSION['usuario_id']` | User PK |
| `$_SESSION['usuario_nombre']` | Display name |
| `$_SESSION['rol']` | `'alumno'` or `'admin'` |
| `$_SESSION['institucion']` | University name |
| `$_SESSION['dominio']` | Email domain |

### Key Shared Files
| File | Purpose |
|------|---------|
| `app/conexion.php` | mysqli connection + timezone Chile |
| `app/config.php` | Constants (BASE_URL, MP tokens, email) |
| `app/correo.php` | PHPMailer wrapper (SMTP Hostinger) |
| `app/iconos.php` | `icon($name, $classes)` → inline SVG |
| `app/seguridad_url.php` | `nubira_encriptar_id()` / `nubira_desencriptar_id()` |
| `app/logger.php` | Activity logging with bot detection (`es_bot`, `user_agent`) |
| `app/init_sesion.php` | Session initialization |
| `app/env_loader.php` | Env file reader |

### Modals
Standard animation: `translate-y-full → translate-y-0`, `opacity-0 → opacity-100`. Blurred backdrop (Airbnb style). Use `setupModal(trigger, modal, card, close)`.

### Chat System
Badge via `contar_mensajes_nuevos.php`. Sidebar and bottom nav must show it. Polling with `document.hidden` guard + `visibilitychange` refresh. Intervals: ~30-45s.

### Caching
- Nav alerts: session-based, 5-min TTL (`$_SESSION['nav_cache_invalidar']`)
- AI recommendations: file-based, 30-min TTL (`app/cache_ia/`)
- OPCache warning: can serve stale PHP after edits — use `opcache_reset()` or sonda technique

## Payments
MercadoPago SDK. Two tokens: `MP_ACCESS_TOKEN` (apuntes/servicios), `MP_ACCESS_TOKEN_OPORTUNIDADES`. Webhook: `app/notificaciones_mp.php` → responds 200 immediately, then processes.

## AI
Google Gemini 2.0 Flash via `app/datos/ia_nubira.php`. Cached in `app/cache_ia/` as JSON (MD5 key). Admin panel: `/admin/ia`.

## Email
`app/correo.php` wraps PHPMailer. SMTP: `no-reply@nubira.cl`, `contacto@nubira.cl`. Logs: `app/log_correos.txt`.

## Module Map
| Module | Key files |
|--------|-----------|
| Services vitrina | `app/vitrina.php`, `app/cargar_servicios.php`, `app/detalle_servicio.php` |
| Notes vitrina | `app/vitrina_apuntes.php`, `app/cargar_apuntes.php`, `app/ver_apunte.php` |
| Search | `app/busqueda.php` (multi-word Spanish tokenizer with plural stripping) |
| Publish | `app/publicar_servicio.php`, `app/formulario_subir_apunte.php` |
| Contracts/Chat | `app/chat_previo_contrato.php`, `app/chat_mini_aula.php`, `app/contratar_servicio.php` |
| User finances | `app/mis_ventas.php`, `app/mis_compras.php`, `app/datos_bancarios.php` |
| Support | `app/reclamos_sugerencias.php` (7-category slug system), `app/admin_reclamos.php` |
| Admin | `app/admin_panel.php`, `app/admin_*.php` |
| Auth | `login.php`, `register.php`, `app/logout.php` |
| Profile | `app/perfil.php`, `app/editar_datos.php`, `app/actualizar_foto.php` |

## Known Technical Debt (explicitly deferred)
- Hardcoded Gemini API key in source
- `CURLOPT_SSL_VERIFYPEER = false` in production
- `$es_admin = true` hardcoded in `contar_alertas_sistema.php`
- Auto-migrations on every page load in `admin_usuarios.php`
- `app/config.php` has 2 blank lines BEFORE the `<?php` tag (lines 1-2) → emits output before PHP starts; latent "headers already sent" risk. Fix in a later iteration (strip the leading blank lines).

## Key Patterns
- PRG (Post-Redirect-Get) enforced
- Spanish plural-stripping for search: remove trailing `s` or `es` when word length permits
- Bot isolation at INSERT time (not query-time filtering)
- Ticket state: admin response → `en_proceso` (not `resuelto`) so notifications fire
- Always use `require_once __DIR__ . '/file.php'` for includes
- Image pipeline: 3-size WebP (thumb 240px/q78, card 480px/q80, main 1200px/q82)