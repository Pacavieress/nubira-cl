# Informe de Performance — Nubira

**Fecha:** 22/07/2026
**Alcance:** Páginas críticas de negocio (`/explorar`, `/clases-servicios`, `/apuntes`, `/detalle-servicio/*`, `/perfil/*`, chat pre-contrato, checkout) con profundidad completa. Páginas `admin_*` (~46 archivos) revisadas solo a nivel de queries pesadas, prioridad baja.
**Restricción de infraestructura:** Hostinger compartido — sin root, sin Redis/Memcached, sin workers, sin control de Apache/MySQL. Solo `.htaccess`, PHP 8.x, MariaDB, y un cron cada 15 min. Todo hallazgo de este informe es accionable dentro de ese stack; se descartó cualquier recomendación que requiriera infraestructura no disponible.
**Método:** 4 auditorías paralelas (explorar/búsqueda/cards, detalle-servicio/apuntes, chat/checkout/perfil, infraestructura+admin) sobre el código real del repo, con lectura de archivo:línea. El hallazgo #1 se verificó manualmente contra el código fuente.

---

## Resumen ejecutivo

El problema más grave del sitio **no está en una página de alto tráfico específica, sino en un endpoint que corre en casi todas ellas — y no es solo de performance**: `app/contar_alertas_sistema.php` tenía `$es_admin = true` hardcodeado (línea 29), por lo que **cualquier usuario logueado normal** — no solo administradores — disparaba ~20 queries pesadas (varias con JOIN + GROUP BY + HAVING) en cada carga de página y cada ~45s de polling, en prácticamente todo el sitio (se incluye desde `head_common.php` en ~99 archivos). Peor aún: como el `json_encode($alertas)` final serializaba un único array plano sin filtrar, **cualquier usuario logueado recibía en la respuesta JSON los 17 contadores operacionales administrativos** (retiros y pagos escrow pendientes, contratos en progreso, intentos DLP sin revisar, archivos de chat pendientes de moderación, usuarios nuevos sin revisar, estado de campañas internas, etc.) — una fuga de información interna, no solo carga de CPU/DB de más. **Corregido el 22/07/2026**: se reemplazó el flag por el chequeo real de rol (`$_SESSION['rol'] === 'admin'`) y se agregó una limpieza defensiva (`unset` de toda clave `admin_*`) antes del `json_encode` para que una regresión futura del flag no vuelva a exponer estos datos.

En segundo lugar, hay un patrón recurrente de **cálculos que deberían estar cacheados y no lo están**: rating/votos de servicios (recalculado con subqueries correlacionadas en 5 archivos distintos de listado), tiempo de respuesta del tutor (recalculado on-demand en `detalle_servicio.php` y `perfil.php` pese a que ya existe un cron que lo precalculaba y quedó desactivado), y el badge de header (una caché de sesión de 5 min ya implementada en `nav_bottom.php` que nunca se activa porque `header.php` no la alimenta).

Tercero, hay ganancias "gratis" de infraestructura sin tocar una sola query: falta compresión gzip/brotli en `.htaccess`, falta `Cache-Control`/`max-age` para CSS/JS/fuentes, y hay un `SET time_zone` como query separado en cada request vía `conexion.php`.

El resto son hallazgos puntuales (búsqueda con `LIKE '%...%'` sin poder usar índice, polling sin guard de pestaña oculta, contador de visitas sin throttling, chat que re-trae la conversación completa en cada poll) que se listan abajo por prioridad.

---

## Top 20 hallazgos (ordenados por impacto de negocio)

| # | Hallazgo | Archivo:Línea | Impacto | Quick win |
|---|----------|----------------|---------|-----------|
| 1 | `$es_admin = true` hardcodeado — ~20 queries admin corren para cualquier usuario logueado en casi toda página del sitio, **y además expone 17 contadores operacionales admin en el JSON de respuesta** | `app/contar_alertas_sistema.php:29` | **Crítico — Seguridad + Performance, site-wide** | **Sí — 1 línea (corregido 22/07/2026)** |
| 2 | Caché de sesión de 5 min ya implementada nunca se activa: `header.php` no llena `$_SESSION['nav_cache_...']`, así que el guard de `nav_bottom.php`/`sidebar.php` nunca aplica y se re-consulta `alumnos` en cada request de cada usuario logueado | `app/componentes/header.php:55-71` | Alto, site-wide | Sí |
| 3 | Sin compresión gzip/brotli en respuestas (HTML/CSS/JS/JSON) | `.htaccess` (falta bloque `mod_deflate`) | Alto, site-wide | Sí |
| 4 | `Cache-Control` solo cubre imágenes y sin `max-age`; CSS/JS/fuentes se re-descargan en cada visita | `.htaccess:8-12` | Medio-alto, site-wide | Sí |
| 5 | Tiempo de respuesta del tutor calculado on-demand (SELECT sin LIMIT + mediana en PHP) en vez de usar el cron ya existente (`recalcular_tiempos_tutores.php`, desactivado) — se ejecuta en cada vista de perfil y de detalle de servicio | `app/helpers/tiempo_respuesta.php:24-52`, llamado desde `app/detalle_servicio.php:185-187` y `app/perfil.php:308-319` | Alto (2 páginas críticas) | Sí (reactivar cron) |
| 6 | Rating/votos de servicio recalculado con subqueries correlacionadas idénticas, repetidas en 5 archivos de listado, en cada carga | `app/vitrina.php` (6 lugares), `app/cargar_servicios.php:102-103`, `app/busqueda.php:236-237`, `app/cargar_vistos.php:71-72`, `app/componentes/render_card.php:33-34` | Alto (páginas de mayor tráfico) | Sí (columna materializada + cron) |
| 7 | Chat: cada poll (3-20s) re-trae **todos** los mensajes de la conversación desde el inicio (sin LIMIT) y re-renderiza el HTML completo | `app/cargar_mensajes.php:88-107`, invocado desde `app/chat_previo_contrato.php:1052-1084` | Alto, crece con la antigüedad de cada chat | Parcial (LIMIT) / mayor (fetch por delta) |
| 8 | Home: chequeo `EXISTS`+`AVG` por cada alumno de la tabla para decidir mostrar una sección, cuando ya existe cron para esto (mismo cron desactivado del punto 5) | `app/vitrina.php:284-335` | Alto (página de mayor tráfico) | Sí (reactivar cron) |
| 9 | Búsqueda usa `LIKE '%término%'` (wildcard inicial) sobre hasta 6 columnas × N palabras — imposible usar índice, full scan en cada búsqueda | `app/busqueda.php:197-220`, también `app/vitrina.php:354`, `app/cargar_servicios.php:75` | Alto (página de búsqueda) | Medio esfuerzo (índice FULLTEXT en MariaDB, sin infra nueva) |
| 10 | `ORDER BY SHA2(...)` calculado por fila para desempate de orden en scroll infinito — no usa índice, costo crece con el offset | `app/cargar_servicios.php:134-138` | Medio-alto | No (rediseño del shuffle) |
| 11 | Índices candidatos probablemente faltantes en columnas de filtro de altísima frecuencia (`servicios.estado/visible/alumno_id`, `alumnos.bloqueado`, `valoraciones.servicio_id`, `respuestas_tutor.tutor_id/creado_en`, `reservas_slots.tutor_id/fecha_clase/estado`, `alumnos.remember_token`) | Ver detalle sección 3 | Alto (a confirmar con `EXPLAIN`) | Sí (solo DDL) |
| 12 | Polling del badge de mensajes en `perfil.php` cada 10s **sin** guard de `document.hidden`, duplicado con otro poll en `sidebar.php` que consulta lo mismo | `app/perfil.php:1401` | Medio-alto (página crítica) | Sí |
| 13 | Contador de visitas del servicio (`UPDATE servicios SET visitas=visitas+1`) sin throttling, a diferencia del patrón correcto ya usado en `header.php` para `ultima_sesion` | `app/detalle_servicio.php:157-164` | Medio (contención de escritura en servicios populares) | Sí |
| 14 | Transacción de checkout mantiene el lock `FOR UPDATE` sobre `servicios` más tiempo del necesario: 2 SELECTs adicionales a la misma fila podrían fusionarse en el SELECT inicial | `app/crear_contrato.php:94-241` | Medio (concurrencia en checkout de servicios populares) | Sí |
| 15 | Polling de `panel_gestion.php` cada 5s **sin** guard de `document.hidden` (a diferencia del polling de chats en el mismo archivo, que sí lo tiene) | `app/componentes/panel_gestion.php:279-299` | Medio-alto (se agrava por el punto 1) | Sí |
| 16 | `reservas_slots` — no se pudo confirmar índice compuesto `(tutor_id, fecha_clase, estado)`; sin él, el `FOR UPDATE` puede tomar gap-locks amplios y serializar reservas no solapadas del mismo tutor | `app/crear_contrato.php:225-241` | Medio (a verificar) | Sí, si falta |
| 17 | `SET time_zone` ejecutado como query mysqli separada en cada request, en un valor que solo cambia 2 veces al año | `app/conexion.php:27` | Medio, site-wide | Sí |
| 18 | `ORDER BY RAND($seed)` en 9 queries de la home y en `cargar_apuntes.php` — filesort sin índice, mitigado parcialmente por el seed de 30 min pero no cacheado | `app/vitrina.php` (9 queries), `app/cargar_apuntes.php:160-172` | Medio | Parcial (cachear resultado del carrusel en `app/cache_ia/`) |
| 19 | Reseñas de un servicio: se traen todas (sin LIMIT, con JOIN a `alumnos`) y el promedio se calcula sumando en PHP en vez de `AVG()`/`COUNT()` en SQL | `app/detalle_servicio.php:200-204` | Medio-alto (servicios con muchas reseñas) | Sí |
| 20 | Las `RewriteCond !-f`/`!-d` de `.htaccess` solo protegen la primera `RewriteRule`; las ~78 reglas restantes se evalúan en cada request incluso para assets estáticos existentes | `.htaccess:30-31` (guard) vs. resto del archivo | Bajo-medio, site-wide | Sí |

---

## Quick wins — lista de trabajo (bajo riesgo, alto o buen impacto)

Ordenados por impacto/esfuerzo, para ejecutar en una sesión dedicada:

1. **`app/contar_alertas_sistema.php:29`** — cambiar `$es_admin = true;` por `$es_admin = (($_SESSION['rol'] ?? '') === 'admin');`
2. **`app/componentes/header.php:55-71`** — reusar el patrón `$_SESSION['nav_cache_' . $uid]` (TTL 300s) que ya existe en `nav_bottom.php`, en vez de consultar `alumnos` sin caché en cada request.
3. **`.htaccess`** — agregar bloque `mod_deflate` para HTML/CSS/JS/JSON.
4. **`.htaccess:8-12`** — extender `Cache-Control` a `.css|.js|.woff2?|.svg|.ico` con `max-age=2592000`.
5. **`.htaccess:30-31`** — mover el guard `!-f`/`!-d` (con `[L]`) justo después de `RewriteBase /` para cortar temprano en assets reales.
6. **`app/helpers/tiempo_respuesta.php`** — reactivar `app/cron/recalcular_tiempos_tutores.php` (cada 15 min) y volver a leer `alumnos.tiempo_respuesta_promedio` en `detalle_servicio.php` y `perfil.php` en vez de recalcular on-demand.
7. **`app/vitrina.php:284-335`** — misma solución del punto 6: leer el valor cacheado por el cron en vez del `EXISTS`+`AVG` por alumno.
8. **Rating/votos** — agregar columnas materializadas (`servicios.total_votos_cache`, `servicios.rating_promedio_cache`) actualizadas por el cron de 15 min con un `UPDATE...JOIN` batch; reemplazar las subqueries correlacionadas en los 5 archivos de listado por lectura directa de columna.
9. **`app/detalle_servicio.php:200-204`** — separar en `SELECT AVG(calificacion), COUNT(*) ...` (sin traer filas) + `SELECT ... ORDER BY v.id DESC LIMIT 20` solo para el listado visible.
10. **`app/detalle_servicio.php:157-164`** — aplicar el mismo throttling de sesión que ya usa `header.php` para `ultima_sesion` (máx. 1 UPDATE cada N minutos por servicio/sesión).
11. **`app/perfil.php:1401`** — agregar guard `if (!document.hidden)` y subir el intervalo de 10s a 45s, igual que en `vitrina.php:2213-2218`.
12. **`app/componentes/panel_gestion.php:279-299`** — mismo guard `document.hidden` que ya usa el polling de chats en el mismo archivo; subir de 5s a 30-60s.
13. **`app/conexion.php:27`** — cachear el offset de zona horaria (cambia 2 veces al año) y solo ejecutar `SET time_zone` cuando cambie respecto al valor cacheado.
14. **`app/crear_contrato.php:94-241`** — ampliar el `SELECT ... FOR UPDATE` inicial para incluir `duracion_minutos` y `horarios_json`, eliminando las 2 consultas adicionales a la misma fila dentro de la transacción; mover la consulta de `comision_plataforma` fuera del lock (cachear con TTL, invalidada por el cron de 15 min).
15. **Índices a verificar/crear vía `EXPLAIN` + DDL** (sin tocar código): `servicios(estado, visible, alumno_id)`, `alumnos(bloqueado)`, `valoraciones(servicio_id, rol_evaluado, calificacion)`, `respuestas_tutor(tutor_id, creado_en, minutos_respuesta)`, `reservas_slots(tutor_id, fecha_clase, estado)`, `login_fallos(fecha)`.
16. **`app/cargar_mensajes.php:88-107`** — como mitigación inmediata, agregar `ORDER BY fecha DESC LIMIT 200` (invertir en PHP) mientras se diseña el fetch por delta real.
17. **`app/cargar_mensajes.php:61-65`** — agregar `AND (estado_visto = 0 OR estado_visto IS NULL)` al `UPDATE` para que MySQL descarte rápido cuando no hay nada que marcar como leído.

---

## Detalle por área

### A. Explorar / búsqueda / cards (`vitrina.php`, `cargar_servicios.php`, `busqueda.php`, `cargar_vistos.php`, `render_card.php`, `panel_gestion.php`)

**Rating/votos duplicado (hallazgo #6).** La misma subquery correlacionada:
```sql
(SELECT COUNT(*) FROM valoraciones v WHERE v.servicio_id = s.id AND v.calificacion > 0 AND v.rol_evaluado = 'vendedor') as total_votos,
(SELECT AVG(v.calificacion) FROM valoraciones v WHERE v.servicio_id = s.id AND v.calificacion > 0 AND v.rol_evaluado = 'vendedor') as rating_promedio
```
aparece en `vitrina.php:212-213,253-254,302-303,344-345,461-462,492-493`, `cargar_servicios.php:102-103`, `busqueda.php:236-237`, `cargar_vistos.php:71-72` y `render_card.php:33-34`. Se recalcula para cada fila en cada carga, en las páginas de mayor tráfico del sitio.

**Home: cron de tiempo de respuesta apagado (hallazgo #8).** `vitrina.php:284-335` calcula on-demand, con un `EXISTS`+subquery `AVG` por cada alumno, si mostrar la sección "tutores que responden rápido" — el propio comentario del código dice que reemplaza a un cron que quedó parado.

**`ORDER BY RAND($seed)` (hallazgo #18).** 9 queries en `vitrina.php` (líneas 227, 233, 264, 319, 356, 401, 422, 472, 501) usan un seed fijo por 30 min, lo que mitiga la re-ejecución pero no evita el filesort sin índice en cada request dentro de esa ventana.

**`ORDER BY SHA2(...)` en scroll infinito (hallazgo #10).** `cargar_servicios.php:134-138` calcula un hash SHA-256 por fila candidata para desempatar el orden, sin poder usar índice; el costo crece con el offset a medida que el usuario scrollea.

**Búsqueda con `LIKE '%...%'` (hallazgo #9).** `busqueda.php:197-220` arma bloques `(s.titulo LIKE ? OR s.descripcion LIKE ? OR ...)` con wildcard inicial sobre hasta 6 columnas × N palabras — full scan garantizado. Candidato directo a índice `FULLTEXT` (soportado en MariaDB/InnoDB sin infraestructura adicional).

**`SELECT s.*`/`ap.*` (hallazgo parcial de #6/#19).** La mayoría de queries de listado en `vitrina.php`, `cargar_servicios.php` y `busqueda.php` usan wildcard en vez de columnas explícitas; `cargar_vistos.php:68-76` y `render_card.php:30` ya lo hacen bien y sirven de plantilla.

**Índices candidatos (hallazgo #11):** `servicios(estado, visible, alumno_id)`, `alumnos(bloqueado)`, `valoraciones(servicio_id, rol_evaluado, calificacion)`, `contratos(servicio_id, calificacion_comprador)`, `respuestas_tutor(tutor_id, creado_en, minutos_respuesta)`, `tracker_intereses(usuario_id, categoria)` / `(huella_visitante, categoria)`, `servicios(categoria)`/`(modalidad)`/`(institucion)`, `visitantes_anonimos(device_id)`, `alumnos(remember_token)` (usado en auto-login de cada request de visitante no logueado).

**Polling de `panel_gestion.php` (hallazgo #15).** Cada 5s, sin guard `document.hidden`, dispara 2 fetches (`contar_alertas_sistema.php` + `contar_mensajes_nuevos.php`), a diferencia del polling de chats en el mismo archivo que sí respeta la pestaña oculta.

**N+1 a nivel HTTP en carrusel IA (mención, impacto real bajo hoy).** `vitrina.php:2097-2174` hace 1 fetch por card a `render_card.php` en vez de un batch por IDs. La sección está actualmente detrás de `if (false)` en `vitrina.php:1540` (deshabilitada), así que el impacto real hoy es bajo — pero corregir el patrón antes de reactivarla (aceptar `?ids=1,2,3` con una sola query `WHERE id IN (...)`).

**Pool de IDs en `NOT IN` creciente (prioridad baja).** `vitrina.php:359,504` — deduplicación intencional entre carruseles, pool acotado (~40-50 IDs), no prioritario.

### B. Detalle de servicio / apuntes (`detalle_servicio.php`, `ver_apunte.php`, `vitrina_apuntes.php`, `cargar_apuntes.php`)

**Tiempo de respuesta on-demand (hallazgo #5).** `helpers/tiempo_respuesta.php:24-52`, llamado desde `detalle_servicio.php:185-187`: `SELECT minutos_respuesta ... ORDER BY minutos_respuesta ASC` sin `LIMIT`, trae todas las filas de 30 días a PHP para calcular una mediana a mano. Mismo patrón en `perfil.php:308-319`.

**Reseñas sin LIMIT + promedio en PHP (hallazgo #19).** `detalle_servicio.php:200-204` trae todas las valoraciones con JOIN a `alumnos` y suma en PHP en vez de usar `AVG()`/`COUNT()`.

**Afinidad por categoría sin caché.** `detalle_servicio.php:231-245` — `GROUP BY`/`SUM` sobre `tracker_intereses` en cada visita, para usuarios logueados y visitantes. Candidato a cachear en `app/cache_ia/` (TTL 15-30 min, mismo mecanismo ya usado para IA).

**Contador de visitas sin throttling (hallazgo #13).** `detalle_servicio.php:157-164` — `UPDATE servicios SET visitas=visitas+1` en cada carga, sin el mismo throttling de sesión que `header.php` ya usa para `ultima_sesion`.

**`getimagesize()` síncrono para meta OG.** `detalle_servicio.php:314` y `ver_apunte.php:345` — decodifica la imagen de portada en cada request solo para leer ancho/alto, pese a que las variantes WebP son estáticas. Guardar dimensiones en BD/JSON al momento de generar la variante `main`.

**Heartbeat de tracking cada 5s.** `track_vista.php`, invocado desde `detalle_servicio.php:1655-1657` y `ver_apunte.php:1194-1196` — un UPSERT en `vistas_detalle` cada 5s mientras la pestaña esté visible (12-18 escrituras por visita de 60-90s). Subir a 20-30s y/o depender del `sendBeacon` final en `visibilitychange`/`pagehide`.

**Geolocalización externa sin caché.** `helpers/geoip.php:11-25` — llamada síncrona a `ip-api.com` (tier gratuito, 45 req/min) sin caché por IP, hasta 2s de espera consumiendo un worker PHP-FPM. Cachear por IP con TTL 24h.

**`ORDER BY RAND(seed)` en apuntes (hallazgo #18).** `cargar_apuntes.php:160-172` — mismo patrón que en vitrina, impacto bajo hoy, crece con el tamaño de `apuntes`.

**Ya bien resuelto (no tocar):** `cargar_apuntes.php` ya tiene paginación real, ya resolvió un N+1 de banners (comentado explícitamente en el código) y calcula ventas totales con subquery agregada en SQL. `ver_apunte.php` usa columnas explícitas y no tiene contador de vistas síncrono. `helpers/imagen_servicio.php` ya cachea el lookup de `banco_imagenes` con `static $cache`. La generación de imágenes WebP (thumb/card/main) ya ocurre al momento de subida, no en el request de lectura.

### C. Chat / checkout / perfil / header / sidebar / nav

**`$es_admin = true` (hallazgo #1, verificado y corregido).** `contar_alertas_sistema.php:29` — confirmado en el código real. El bloque de 15 queries admin (líneas 143-276, con JOIN+GROUP BY+HAVING y anti-joins `NOT EXISTS`/`NOT IN`) corría para cualquier usuario logueado. Se llama desde `head_common.php` (~99 archivos) en cada carga de página y cada ~45s de polling.

Verificación adicional del `echo json_encode($alertas)` final (línea ~280 antes del fix): `$alertas` es un único array plano que mezcla claves de usuario (`reclamos`, `soporte`, `falta_banco`...) con las 17 claves `admin_*`, sin ningún filtro antes de serializar. El `if ($es_admin)` solo controlaba si esas queries se ejecutaban, no si sus resultados se exponían — con el flag en `true`, cualquier usuario logueado recibía en el JSON: retiros/pagos escrow pendientes, contratos en progreso, reclamos sin revisar, servicios/apuntes pendientes de aprobación, login fallidos, usuarios nuevos sin revisar, **intentos DLP sin revisar** (violaciones de contacto en chat), archivos de chat pendientes de moderación, tutores con perfil incompleto y estado de campañas internas de correo.

**Fix aplicado (22/07/2026):** (1) `$es_admin = (($_SESSION['rol'] ?? '') === 'admin');` en la línea 29; (2) defensa en profundidad antes del `json_encode`: `foreach` sobre `$alertas` que hace `unset()` de toda clave con prefijo `admin_` cuando `!$es_admin`, para que una regresión futura del flag no vuelva a exponer estos datos aunque las queries se ejecuten por error.

**Caché de header muerta (hallazgo #2, verificado).** `header.php:55-71` consulta `alumnos` sin condición en cada request; el guard de caché de sesión de `nav_bottom.php:44`/`sidebar.php:15` nunca se activa porque `header.php` inicializa `$alerta_encendida_php = false` siempre y no llena `$_SESSION['nav_cache_...']`.

**Chat: full refetch sin LIMIT (hallazgo #7).** `cargar_mensajes.php:88-107`, pese a que el comentario dice "el polling solo trae deltas" — en realidad trae TODOS los mensajes desde el inicio de la conversación, cada 3-20s (polling adaptativo de `chat_previo_contrato.php:1052-1084`, con guard `document.hidden` correcto). Para conversaciones largas, esto es O(total_mensajes) por poll. El polling de 3s en sí es correcto e intencional (documentado); el problema es el costo del fetch que multiplica.

**`UPDATE` incondicional de "visto" en cada poll.** `cargar_mensajes.php:61-65` — ejecuta el `UPDATE` de marcado de leído sin verificar antes si hay filas por actualizar.

**Polling de `perfil.php` sin guard (hallazgo #12).** `perfil.php:1401` — `setInterval(actualizarBadgeChats, 10000)` sin `document.hidden`, contradice el estándar del proyecto (`~30-45s` + guard) y duplica el trabajo de `sidebar.php:236-239` (30s, sí con guard) que calcula básicamente el mismo dato contra un endpoint distinto.

**Lock extendido en checkout (hallazgo #14).** `crear_contrato.php:94-241` — `FOR UPDATE` sobre `servicios` se mantiene mientras se ejecutan 2 SELECTs adicionales a la misma fila (`duracion_minutos` línea 194-199, `horarios_json` línea 208-213) y una consulta a `configuracion` (línea 113-117), todo dentro de la transacción, alargando la ventana de contención en servicios populares.

**Índice de `reservas_slots` (hallazgo #16).** No se pudo confirmar en el repo (no hay migraciones/esquema) si existe `(tutor_id, fecha_clase, estado)`. Sin él, el `FOR UPDATE` de `crear_contrato.php:225-241` puede tomar gap-locks amplios.

**Query casi duplicada en `contar_alertas_sistema.php`.** Líneas 70-84 y 97-101 consultan `reclamos_sugerencias` para el mismo usuario dos veces con filtros casi idénticos — se resuelve solo al arreglar el hallazgo #1, pero conviene fusionar en un único resultset.

**`iniciar_pago_servicio.php` — sin hallazgos.** Una sola creación de preferencia de MercadoPago por checkout, sin cálculos redundantes. El único costo fijo (carga del SDK en cada request) es inherente a hosting compartido sin proceso persistente, no accionable.

### D. Infraestructura (`.htaccess`, `conexion.php`, `config.php`, `logger.php`, `middleware/antibot.php`)

**Sin compresión (hallazgo #3).** `.htaccess` no tiene bloque `mod_deflate`. Afecta el peso de cada respuesta HTML/CSS/JS/JSON en cada visita.

**Cache-Control incompleto (hallazgo #4).** `.htaccess:8-12` solo cubre `webp|jpg|jpeg|png` y sin `max-age`; falta CSS/JS/fuentes.

**Orden de RewriteRules (hallazgo #20).** El guard `!-f`/`!-d` en `.htaccess:30-31` solo protege la primera regla; las ~78 reglas restantes se evalúan siempre, incluso para assets estáticos existentes, antes de llegar al bloque de captura final (líneas 281-283).

**`SET time_zone` por request (hallazgo #17).** `conexion.php:27` ejecuta un query separado para fijar la zona horaria en cada carga, cuando el offset de Chile cambia como máximo 2 veces al año.

**Conexión no persistente — correcto, no es hallazgo.** `conexion.php:7` usa `new mysqli(...)` sin `p:`, lo cual es lo correcto en Hostinger con PHP-FPM compartido (conexiones persistentes suelen agotar el pool). `set_charset('utf8mb4')` también evita el `SET NAMES` extra. Se documenta para que no se "corrija" innecesariamente en el futuro.

**`env_loader.php` — múltiples `file_exists()` por request (menor).** Hasta 4 rutas candidatas probadas en cada carga antes de encontrar `.env`. Impacto de microsegundos pero en el 100% de las páginas.

**`shield_rate_limit` sin limpieza.** `middleware/antibot.php:72-121` — la tabla de rate-limiting crece indefinidamente, sin proceso de purga de IPs/ventanas vencidas. No bloqueante hoy, pero conviene una purga probabilística de baja frecuencia.

**`logger.php` — aceptable, no es hallazgo.** El INSERT sin batching por vista es el patrón correcto disponible en este stack (sin colas/Redis); los índices de `historial_actividad` para las lecturas del admin están bien puestos.

### E. Páginas `admin_*` (prioridad baja — un solo usuario administrador)

Revisadas solo a nivel de queries pesadas, por instrucción explícita de alcance:

- **`admin_login_fallos.php:133,153`** — `login_fallos` sin índice en `fecha`, filesort completo en cada carga del panel. Quick win: `CREATE INDEX idx_fecha ON login_fallos(fecha)`.
- **`admin_chats.php:617-635`** — 6 `COUNT(*)` separadas sobre `conversaciones`/`mensajes` que podrían fusionarse en una sola query con `SUM(CASE WHEN...)`.
- **`admin_chats.php:519`** — historial completo de mensajes de una conversación sin `LIMIT`.
- **`admin_dominios.php:96`** — subquery correlacionada con `LIKE '%@dominio'` (wildcard inicial) por cada fila; si `alumnos` ya tiene columna `dominio` indexada (usada en `admin_resumen_dominios.php`), usarla en el JOIN en vez de `LIKE` sobre `correo`.
- **`admin_solicitudes.php:103`** — `SELECT *` sin `LIMIT`, bajo volumen hoy pero sin techo.
- **`admin_banners.php:195`, `admin_popup.php:34`, `admin_excepciones.php:43`** — `SELECT *` sin `LIMIT`, pero son tablas de configuración pequeñas por naturaleza del negocio — se descartan como no-issue, documentado para no re-auditar.

---

## Nota metodológica

Los hallazgos de índices (sección A e ítem 16 de quick wins) son **candidatos**, no confirmaciones — no hay acceso directo a `SHOW INDEX`/esquema en este repo. Verificar con `EXPLAIN` antes de crear cada índice. El resto de hallazgos se basa en lectura directa del código fuente citado, con archivo:línea verificable.
