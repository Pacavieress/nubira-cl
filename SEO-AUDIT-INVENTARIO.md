# Auditoría SEO Nubira — Pasada 1: Inventario factual

Fecha de extracción: 2026-07-27. Fuente: código real del repo (`C:\nubira`) + BD local (`u516405553_u516405553_Nub`, dataset de desarrollo, mucho más chico que producción — se marca explícitamente cada vez que un número viene de acá).

Este documento **no contiene recomendaciones**. Solo hechos extraídos leyendo código y schema real. Donde no se encontró algo, se escribe `NO EXISTE` de forma explícita.

---

## A. Estructura y rutas

### A.1 — Árbol de archivos PHP públicos

- `app/*.php` (nivel raíz de `app/`): **312 archivos PHP totales**.
  - De esos, **65 son `admin_*.php`** (panel de administración, no se detallan uno por uno).
  - Los **247 restantes** son páginas públicas/autenticadas, endpoints AJAX, crons y helpers de nivel superior. Los públicos con landing directa (no AJAX/cron) más relevantes para SEO: `vitrina.php`, `vitrina_apuntes.php`, `detalle_servicio.php`, `ver_apunte.php`, `perfil.php`, `landing_categoria.php`, `clases_servicios.php`, `descubre.php`, `busqueda.php`.
- Subdirectorios de `app/`: `admin/`, `ajax/`, `api/`, `app/` (anidado, sin auditar contenido), `assets/`, `cache_ia/`, `chat_archivos/`, `componentes/`, `cron/`, `css/`, `datos/`, `helpers/`, `includes/`, `logs/`, `middleware/`, `perfil/`, `public_html/`, `uploads_entregas/`.
- Raíz del repo (`C:\nubira\*.php`, fuera de `app/`): `register.php`, `login.php`, `recuperar.php`, `restablecer.php`, `sobre-nosotros.php`, `privacidad.php`, `terminos.php`, `pago_error.php`, `index.php`, `error_general.php` (entre otros — ver `.htaccess` para la lista completa referenciada).

### A.2 — Cómo se resuelven las rutas

Mecanismo: **`.htaccess` con `mod_rewrite`**, sin router propio. Archivo: `C:\nubira\.htaccess` (290 líneas).

Reglas relevantes (citadas literal):

```apache
RewriteEngine On
RewriteBase /

# Evita reescribir si archivo o directorio existe
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d

# Enlaces cortos y seguros (token)
RewriteRule ^s/([a-zA-Z0-9_-]+)$ app/detalle_servicio.php?token_seguro=$1 [L,QSA]
RewriteRule ^a/([a-zA-Z0-9\-\_=]+)$ app/ver_apunte.php?token_seguro=$1 [L,QSA]
RewriteRule ^r/([a-zA-Z0-9]+)$ app/redirigir_corto.php?codigo=$1 [L,QSA]

# Raíz → /explorar (301)
RewriteRule ^$                        /explorar                         [R=301,L]
RewriteRule ^inicio$                  /explorar                         [R=301,L]
RewriteRule ^sitemap\.xml$            app/sitemap.php                   [L]

# Vitrinas
RewriteRule ^explorar/?$               app/vitrina.php              [L,QSA]
RewriteRule ^apuntes/?$               app/vitrina_apuntes.php           [L,QSA]

# Servicios — URL canónica SEO (Fase 1)
RewriteRule ^servicios/(.+)-(\d+)/?$ app/detalle_servicio.php?servicio_id=$2&slug_captured=$1 [L,QSA]
RewriteRule ^servicios/(\d+)/?$ app/detalle_servicio.php?servicio_id=$1 [L,QSA]
RewriteRule ^detalle-servicio/([a-zA-Z0-9_-]+)/?$ app/detalle_servicio.php?id=$1 [L,QSA]
RewriteRule ^servicios/?$                  app/clases_servicios.php          [L,QSA]

# Apuntes — regla "estilo Airbnb"
RewriteRule ^apunte/([a-zA-Z0-9_-]+)/?$   app/ver_apunte.php?id=$1       [L,QSA]

# Landings SEO por categoría (pSEO Fase 1)
RewriteRule ^clases/([a-z]+)/?$            app/landing_categoria.php?tipo=clases&cat=$1   [L,QSA]
RewriteRule ^apuntes/([a-z]+)/?$           app/landing_categoria.php?tipo=apuntes&cat=$1  [L,QSA]

# Errores personalizados
ErrorDocument 404 /error_general.php?code=404
ErrorDocument 403 /error_general.php?code=403
ErrorDocument 401 /error_general.php?code=401
ErrorDocument 500 /error_general.php?code=500

# Captura final (404 real)
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^.*$ /error_general.php?code=404 [L]
```

Nota técnica: las 2 `RewriteCond` de arriba (líneas 37-38 del archivo) solo se aplican a la regla `RewriteRule` inmediatamente siguiente (`^s/...`), no a todas las reglas de abajo — es sintaxis estándar de Apache, no un bug, pero vale aclararlo porque a simple vista parece que protegen todo el bloque.

También existe **redirect 301 de rutas viejas a nuevas** en múltiples puntos (patrón repetido ~10 veces): `RewriteRule ^mis_ventas/?$ /mis-ventas [R=301,L]` → luego `RewriteRule ^mis-ventas/?$ app/mis_ventas.php [L,QSA]`.

**Inconsistencia detectada (hecho, no juicio)**: `C:\nubira\index.php` (el archivo físico que Apache serviría por `DirectoryIndex` para `/`) contiene su propio redirect:
```php
// index.php
header("Location: /explorar", true, 302);
exit;
```
Es un **302** (temporal), mientras que la regla de `.htaccess` para el mismo caso (`^$` → `/explorar`) es **301** (permanente). En la práctica, la regla de `mod_rewrite` intercepta la petición a `/` antes de que Apache llegue a evaluar `DirectoryIndex`, así que el 301 de `.htaccess` gana siempre — el 302 de `index.php` existe en el repo pero, en el flujo normal de request, no se ejecuta.

### A.3 — Patrones de URL pública existentes, con ejemplo real

| Patrón | Archivo destino | Ejemplo real |
|---|---|---|
| `/` | redirect 301 → `/explorar` | `https://nubira.cl/` |
| `/explorar` | `app/vitrina.php` | `https://nubira.cl/explorar` |
| `/apuntes` | `app/vitrina_apuntes.php` | `https://nubira.cl/apuntes` |
| `/servicios` | `app/clases_servicios.php` | `https://nubira.cl/servicios` |
| `/servicios/{slug}-{id}` | `app/detalle_servicio.php` | `https://nubira.cl/servicios/clases-de-calculo-i-8930` |
| `/servicios/{id}` (sin slug) | `app/detalle_servicio.php` | `https://nubira.cl/servicios/8930` |
| `/detalle-servicio/{id ofuscado}` | `app/detalle_servicio.php` | `https://nubira.cl/detalle-servicio/MTg0LW51YmlyYV9zZWNyZXRv` |
| `/apunte/{hash}` | `app/ver_apunte.php` | `https://nubira.cl/apunte/MTg0LW51YmlyYV9zZWNyZXRv` |
| `/clases/{categoria}` (pSEO) | `app/landing_categoria.php?tipo=clases` | `https://nubira.cl/clases/matematicas` |
| `/apuntes/{categoria}` (pSEO) | `app/landing_categoria.php?tipo=apuntes` | `https://nubira.cl/apuntes/matematicas` |
| `/perfil/{id ofuscado}` | `app/perfil.php` | `https://nubira.cl/perfil/MTY3LW51YmlyYV9zZWNyZXRv` |
| `/s/{token}` (link corto servicio) | `app/detalle_servicio.php?token_seguro=` | `https://nubira.cl/s/Ab3xK9` |
| `/a/{token}` (link corto apunte) | `app/ver_apunte.php?token_seguro=` | `https://nubira.cl/a/Xy2mQ1` |
| `/r/{codigo}` (link corto genérico) | `app/redirigir_corto.php` | `https://nubira.cl/r/K7pQ2` |
| `/archivo-chat/{id numérico}` | `app/ver_archivo_chat.php` | `https://nubira.cl/archivo-chat/646` |
| `/sitemap.xml` | `app/sitemap.php` (dinámico) | `https://nubira.cl/sitemap.xml` |
| `/admin/*` (65 rutas) | varios `app/admin_*.php` | `https://nubira.cl/admin/panel` |

### A.4 — Construcción de la URL de un apunte (respuesta puntual)

**Función que genera el hash**: `nubira_encriptar_id($id)`, en `C:\nubira\app\seguridad_url.php:11-17`:
```php
define('NUBIRA_SALT', 'nubira_secreto');

function nubira_encriptar_id($id) {
    if (!$id || !is_numeric($id)) return '';
    $string = $id . '-' . NUBIRA_SALT;
    return rtrim(strtr(base64_encode($string), '+/', '-_'), '=');
}
```

**¿Es reversible?** Sí, trivialmente. Es **Base64 puro** (URL-safe: `+/` → `-_`, sin padding `=`), no cifrado. Cualquiera puede decodificarlo con una línea de código (`base64_decode(strtr($hash, '-_', '+/'))` reconstruye `"{id}-nubira_secreto"`). La función de reversa vive en el mismo archivo:
```php
function nubira_desencriptar_id($hash) {
    $decoded = base64_decode(strtr($hash, '-_', '+/'));
    if ($decoded && strpos($decoded, '-' . NUBIRA_SALT) !== false) {
        $partes = explode('-', $decoded);
        return (int)$partes[0];
    }
    return 0;
}
```
El nombre ("Shield"/"enmascaramiento") sugiere una intención de seguridad, pero el mecanismo real es **ofuscación de bajo esfuerzo**, no cifrado — no protege contra enumeración deliberada, solo contra un vistazo casual a la URL.

**¿Hay ruta alternativa por ID plano?** Sí — `app/ver_apunte.php:51-59`:
```php
if (isset($_GET['id'])) {
    $param_id = $_GET['id'];
    if (is_numeric($param_id)) {
        if (function_exists('nubira_encriptar_id')) {
            $hash_seguro = nubira_encriptar_id($param_id);
            header("Location: /apunte/" . $hash_seguro, true, 301);
            exit;
        }
    }
    ...
```
Si llega un `id` numérico plano, el propio archivo lo **redirige 301** al hash — no sirve el contenido directo por ID numérico. Si llega un hash inválido/no numérico, intenta desencriptarlo para resolver el archivo.

**¿Hay ruta alternativa por slug?** `NO EXISTE` para apuntes — a diferencia de servicios (que sí tienen `/servicios/{slug}-{id}`), los apuntes no tienen columna de slug en su tabla (`apuntes` no tiene `slug`, confirmado en el schema de B.5) ni ninguna URL con nombre legible; solo `/apunte/{hash}`.

---

## B. Base de datos

### B.5 — Schema real (vía `DESCRIBE`, BD local)

**Nota importante de nomenclatura**: la tabla de usuarios real y activa es `alumnos`, **no** `usuarios`. Sí existe una tabla llamada `usuarios` en el schema (ver hallazgo abierto más abajo), pero `alumnos` es la que usa el 100% del código de negocio (auth, perfil, servicios, apuntes, contratos, etc.).

**`alumnos`** (38 columnas):
```
id, nombre, bio, foto_perfil, carrera, correo, password, confirmado, rol, token,
ultimo_reenvio, tipo, publicaciones_gratis_utilizadas, institucion, token_recuperacion,
expiracion_token, dominio, cambiar_password, debe_cambiar_password, bloqueado, visible,
ultima_sesion, recibir_emails, remember_token, calificacion_promedio, cantidad_votos,
fecha_registro, visto_admin, vistas_perfil, notif_sugerencia_vista, tiempo_respuesta_promedio,
verificacion_estado, onboarding_visto, universidad, anio_egreso, anios_experiencia,
suspendido_hasta, motivo_suspension, cuenta_express
```

**`servicios`** (49 columnas — se listan las relevantes a SEO/categorización):
```
id, alumno_id, institucion, titulo, slug, preview, descripcion, horarios_json,
nombre_oferente, categoria (default 'Otro'), materia, area, asignatura,
nivel (enum basico/intermedio/avanzado), modalidad, ubicacion, precio, estado
(enum pendiente/aprobado/rechazado), score_nubira, fecha_publicacion, visible,
total_votos, rating_promedio, video_path, video_estado, es_paes, ...
```

**`apuntes`** (36 columnas — relevantes):
```
id, id_alumno, titulo, sigla, nombre_curso, profesor, semestre, anio, asignatura,
materia, subtema, nivel_academico (enum universitario/paes/escolar), descripcion,
archivo, portada, tipo_archivo, fecha_subida, publico, precio, institucion, estado,
destacado, bloqueado, categoria (default 'general'), visible, descargas
```
**`apuntes` NO tiene columna `slug`.**

**`materias`** (12 filas en BD local):
```
id, slug, nombre, grupo, activa, orden, fecha_creacion
```
Usada solo en `app/admin_categorizar_apuntes.php` y `app/api/asignar_materia_apunte.php` — es un sistema de categorización específico para **apuntes**, separado del campo `servicios.categoria`.

**`instituciones`** (17 filas en BD local): `id, dominio, nombre`. **`NO EXISTE` ninguna referencia a esta tabla en `app/*.php`** (0 coincidencias de `FROM instituciones` / `JOIN instituciones` en todo el código) — parece una tabla huérfana.

**`dominios_permitidos`** (83 filas en BD local): la tabla que **sí** usa el sistema real de verificación institucional (confirmado también en `CLAUDE.md`, sección "Implementado: sello Verificado"). Columnas de ejemplo: `dominio`, `institucion`.

### B.6 — ¿Existe campo de "carrera"? ¿"universidad" normalizada?

Sí a ambas preguntas, pero **como texto libre, no como relación normalizada (FK) a ninguna tabla**:

- `alumnos.carrera` — `varchar(100)`, nullable. Texto libre.
- `alumnos.universidad` — `varchar(100)`, nullable. Texto libre.
- `alumnos.institucion` — `varchar(50)`, nullable. Texto libre — **coexiste con `universidad`** en la misma tabla (2 columnas de propósito aparentemente similar).
- `servicios.institucion` — `varchar(50)`, `NOT NULL` (pero permite string vacío). Texto libre, no FK.
- `alumnos.dominio` — `varchar(100)`, se cruza contra `dominios_permitidos.dominio` para determinar `verificacion_estado`, pero es un match de texto (`dominio = dominio`), no una FK declarada en el schema.

**No se infiere puramente del dominio del correo** — hay columnas explícitas (`institucion`, `universidad`) que se llenan aparte del dominio (probablemente en el registro o por el helper `institucion.php`), pero no hay tabla normalizada de "universidades" con ID al que estas columnas apunten.

### B.7 — Matriz materia (categoría) × universidad (institución) — conteo real de servicios activos

**Dato de PRODUCCIÓN** (a diferencia del resto de este documento, esta cifra **no la obtuve yo directamente** — la ejecución de la query en la BD de producción no está a mi alcance desde este entorno local; el usuario corrió la consulta equivalente en producción y entregó el resultado, que se transcribe aquí tal como fue reportado, reemplazando la estimación de BD local que aparecía antes en esta sección).

Servicios activos por categoría, con `institucion` **vacía en casi todos los casos**:

| categoria | n | institucion |
|---|---|---|
| Matemáticas | 30 | vacía |
| Idiomas | 7 | vacía |
| Química | 7 | vacía |
| Biología | 7 | vacía |
| Otros | 5 | vacía |
| Lenguaje | 5 | vacía |
| Asesoría | 4 | vacía |
| Otro | 4 | vacía |
| Historia | 3 | vacía |

Con `institucion` **llena** (1 servicio cada una — dato reportado como "sucio/sin normalizar", es decir texto libre no homogeneizado): **USM, USACH, PUCV, IACC (x2), UCSC, UAI** — 7 servicios en total. El usuario no especificó a qué categoría pertenece cada uno de estos 7.

Además: 1 servicio de categoría **Física**, con `institucion` vacía (reportado aparte por el usuario, no incluido en la tabla de categorías de arriba).

**Hechos que se desprenden de este dato**:
- **`Otros` y `Otro` aparecen como 2 valores de categoría distintos** (5 + 4 = 9 servicios) — confirma a nivel de producción lo que el schema ya insinuaba (`servicios.categoria` es `varchar(40)` sin `ENUM` ni tabla de referencia, así que nada impide esta duplicación).
- De un total aproximado de 72 (suma de la tabla de categorías) + 7 (institución llena) + 1 (Física) = **80 servicios activos en producción**, solo **7 (≈9%) tienen `institucion` con algún valor**, y esos 7 valores no están normalizados (siglas sueltas: "USM", "USACH", etc., no nombres completos consistentes).
- Ninguna combinación categoría×universidad reportada tiene más de 1 servicio — **no hay ninguna celda de la matriz materia×universidad con volumen real hoy**, en producción, tal como se sospechaba (y peor de lo que sugería el dataset local, que al menos tenía 2 celdas con 1 servicio cada una en Lenguaje).

---

## Notas del usuario para la Pasada 2 (decisiones ya tomadas, no recomendaciones mías)

Lo siguiente **no es análisis ni propuesta generada por mí** — son instrucciones/decisiones explícitas que el usuario dio junto con el dato de producción de B.7, y quedan registradas acá tal cual para que la Pasada 2 parta de estas restricciones ya resueltas, sin tener que re-derivarlas:

1. **Landings por universidad y la matriz materia×universidad quedan fuera del roadmap inicial.** Condición de desbloqueo fijada por el usuario: ≥5 servicios activos con `institucion` normalizada en al menos 3 universidades distintas. Hoy (B.7) hay 7 servicios con institución llena, repartidos en 6 valores distintos, ninguno con más de 1 servicio — no cumple la condición.
2. **El plan debe incluir una fase previa de "higiene de datos"**, con 3 tareas específicas ya definidas por el usuario:
   - Diagnosticar por qué `institucion` llega vacía en la gran mayoría de los servicios — revisar el flujo de creación (`publicar-servicio` / `app/publicar_servicio.php`).
   - Normalizar los valores de `institucion` ya existentes, usando el helper `app/helpers/institucion.php` (ya existe en el repo, mencionado en el contexto inicial de esta auditoría).
   - Unificar las categorías duplicadas `"Otros"` / `"Otro"` (confirmado como 2 valores distintos en B.7, 5 y 4 servicios respectivamente).
3. **Landing de materia (categoría) viable hoy, según el usuario**: solo **Matemáticas** (30 servicios). **Química, Biología e Idiomas** (7 cada una) quedan como segunda ola. El resto de las categorías **no debe generar landing** por ahora.
4. **La Pasada 2 debe partir auditando la infraestructura de landings/pSEO que YA existe, no proponer reconstruirla.** Esta infraestructura ya está documentada en este mismo inventario:
   - `app/landing_categoria.php` — el template de landing (ver C.10/C.11/C.12/C.13: usa `nubira_seo_meta()`, `nubira_canonical_tag()`, tiene JSON-LD, exactamente 1 H1 — es el template mejor implementado a nivel SEO de todo el proyecto).
   - `app/helpers/seo.php`, función `nubira_categorias_seo()` (líneas 38-57) — mapa fijo de **16 categorías** (`matematicas, quimica, fisica, biologia, programacion, idiomas, historia, lenguaje, economia, diseno, derecho, asesoria, calculo, ingles, tesis, paes`).
   - `app/sitemap.php` (Sección E, líneas 78-95) — solo incluye una landing en el sitemap si esa categoría tiene **≥3 servicios públicos** (`COUNT(*) >= 3`).
   - La Pasada 2 debe evaluar, con el dato real de B.7, si esas 16 categorías y ese umbral de 3 siguen siendo correctos — por ejemplo, el umbral técnico de `sitemap.php` (≥3) ya lo cumplen hoy Matemáticas (30), Idiomas (7), Química (7), Biología (7), Otros (5), Lenguaje (5), Asesoría (4), Otro (4) e Historia (3) — es decir, técnicamente **8 de 9 categorías con datos** ya pasarían el filtro del sitemap, un criterio más laxo que la decisión de producto del punto 3 de arriba (que solo habilita Matemáticas ahora + 3 en segunda ola). Esta discrepancia entre "lo que el código ya permite generar" y "lo que el usuario decidió lanzar" es un hecho a resolver explícitamente en la Pasada 2, no algo que yo esté resolviendo acá.

---

## C. Estado SEO actual

### C.8 — `robots.txt`

Existe en la raíz (`C:\nubira\robots.txt`), contenido completo:
```
User-agent: *
Allow: /
Disallow: /admin
Disallow: /bandeja-entrada
Disallow: /mini-aula
Sitemap: https://nubira.cl/sitemap.xml
```

### C.9 — `sitemap.xml`

Existe, es **dinámico** (no un archivo estático): `app/sitemap.php`, servido en `/sitemap.xml` vía `.htaccess` (`RewriteRule ^sitemap\.xml$ app/sitemap.php [L]`).

Genera:
- **Sección A** — 8 páginas estáticas (`/`, `/explorar`, `/apuntes`, `/servicios`, `/descubre`, `/sobre-nosotros`, `/terminos`, `/privacidad`), con `priority`/`changefreq` fijos.
- **Sección B** — todos los servicios con `estado IN ('aprobado','publicado','activo') AND visible=1` (mismo filtro que `cargar_servicios.php`), usando `url_servicio()`.
- **Sección C** — todos los apuntes con `publico=1 AND visible=1`, usando `/apunte/' . nubira_encriptar_id($id)`.
- **Sección E** — landings de categoría (`/clases/{slug}`) **solo si tienen ≥3 servicios públicos** en esa categoría (chequeado con `COUNT(*)` real por categoría).

El archivo tiene un `TODO` explícito en el propio código: dividir en sitemap-index si supera 45.000 URLs, y cachear 6h si la BD crece — ninguno de los dos implementado todavía (son comentarios `TODO`, no código).

### C.10 — Mecanismo de `<title>` / `<meta description>`

**Hay un helper centralizado, pero se usa de forma parcial** (7 de ~120 archivos con `<title>`/`<meta description>` lo usan). Helper: `app/helpers/seo.php`, función `nubira_seo_meta(string $title, string $description)`:
```php
function nubira_seo_meta(string $title, string $description): string {
    $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $d = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');
    return '<title>' . $t . '</title>' . "\n  "
         . '<meta name="description" content="' . $d . '" />' . "\n  "
         . '<meta property="og:title" content="' . $t . '" />' . "\n  "
         . '<meta property="og:description" content="' . $d . '" />';
}
```
Archivos que **sí** llaman a `nubira_seo_meta()`/`nubira_canonical_tag()`: `perfil.php`, `descubre.php`, `vitrina.php`, `landing_categoria.php`, `clases_servicios.php`, `vitrina_apuntes.php` (+ el propio `seo.php`).

Los **2 templates de mayor tráfico esperado** (`detalle_servicio.php`, `ver_apunte.php`) **no usan el helper** — construyen su propio `<title>`/`<meta description>` con lógica inline y truncado manual:
- `detalle_servicio.php:276-293`: título = `"{titulo}{sufijo PAES} en {institución} | Nubira"`, truncado a 65 caracteres con `mb_substr(...) . '...'`; descripción = plantilla con modalidad + categoría + nombre del tutor + primeros 100 caracteres de la descripción, truncada a 155 caracteres.
- `ver_apunte.php:361-362`: título = `"{titulo} | Nubira"` (sin truncado de longitud); descripción = primeros 155 caracteres de la descripción del apunte (`mb_strimwidth`).

El resto de los ~110 archivos restantes con `<title>` hardcodeado son en su mayoría páginas admin, de cuenta, de pago o utilitarias (no páginas de indexación pública relevante) — no se auditó cada uno individualmente por volumen, pero el patrón dominante es texto fijo tipo `<title>Mis Ventas | Nubira Admin</title>`.

### C.11 — JSON-LD / schema.org

**Sí existe**, en 4 archivos:

1. **`app/componentes/head_common.php:33-47`** — `@type: Organization`, se inyecta en **toda página que incluya este componente** (sitewide):
```php
echo json_encode([
    '@context' => 'https://schema.org', '@type' => 'Organization',
    'name' => 'Nubira', 'alternateName' => 'Nubira.cl', 'url' => 'https://nubira.cl',
    'logo' => 'https://nubira.cl/img/logo.webp', 'sameAs' => ['https://instagram.com/nubira.cl'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
```
2. **`detalle_servicio.php:365-398`** — `@type: Service`, con `provider` (`Person`), `offers` (`Offer` con precio/moneda CLP), y `aggregateRating` **condicional** (solo si `$tot_votos > 0`).
3. **`ver_apunte.php:414-...`** — `@type: LearningResource`, con `datePublished`, `inLanguage: es`, `educationalLevel`, `about` (asignatura/materia).
4. **`landing_categoria.php:172`** — tiene bloque `application/ld+json` (no se transcribió el contenido completo, pero se confirma su existencia).

`NO EXISTE` JSON-LD en `vitrina.php`, `vitrina_apuntes.php`, `clases_servicios.php`, `perfil.php`, `descubre.php`.

### C.12 — Canonical

**Sí existe**, centralizado en `app/helpers/seo.php`:
```php
function nubira_canonical(?string $path_forzado = null): string {
    $dominio = 'https://nubira.cl';
    $path = $path_forzado ?? parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    ...
    return $dominio . $path;
}
function nubira_canonical_tag(?string $path_forzado = null): string {
    return '<link rel="canonical" href="' . htmlspecialchars(nubira_canonical($path_forzado), ...) . '" />';
}
```
Se calcula por `REQUEST_URI` real (sin query string), no-www, sin trailing slash (salvo raíz), o se puede forzar un path explícito (usado por `landing_categoria.php` para fijar `/{tipo}/{slug}` como canónica).

Confirmado presente en: `vitrina.php` (fijo a `/explorar`), `landing_categoria.php` (dinámico por tipo/slug), `detalle_servicio.php` (vía `url_servicio()`, variable propia `$url_canonical`, no llama directamente a `nubira_canonical_tag()` sino que arma el `<link>` a mano con la misma lógica), `ver_apunte.php` (ídem, variable `$url_canonical`).

### C.13 — Conteo de H1 por template principal

| Template | H1 encontrados | Detalle |
|---|---|---|
| `vitrina.php` (`/explorar`, página de mayor tráfico esperado) | **0** ⚠️ | Ningún `<h1>` en todo el archivo. |
| `descubre.php` | **0** ⚠️ | Ningún `<h1>` en todo el archivo. |
| `ver_apunte.php` | **2** ⚠️ | Dos `<h1>` idénticos en el DOM simultáneamente: uno en el bloque `class="block lg:hidden"` (línea 511, versión móvil) y otro en `class="hidden lg:block"` (línea 671, versión desktop). Ambos con el mismo `<?= $titulo ?>`. Solo uno es visible según el viewport (CSS), pero **los 2 existen en el HTML servido** — un crawler ve 2 H1 en la misma página. |
| `detalle_servicio.php` | 1 | Correcto — línea 512. |
| `vitrina_apuntes.php` | 1 | Correcto. |
| `landing_categoria.php` | 1 | Correcto — línea 202, contenido dinámico (`<?= htmlspecialchars($h1) ?>`). |
| `perfil.php` | 1 | Correcto. |
| `clases_servicios.php` | 2, pero **condicionales** (no simultáneos) | Líneas 231/234: `if/else` según `$qs_orden === 'nuevos'` — solo se renderiza una de las dos ramas por request. No es un duplicado real. |
| `index.php` (raíz) | 0 | El archivo entero es un `header("Location: ...")`, no genera HTML. |

### C.14 — Atributo `alt` en imágenes de servicios/apuntes

**Inconsistente entre templates** — depende de qué renderizador de tarjeta se use:

- **`app/cargar_servicios.php:274-278`** (listado principal AJAX de `/explorar`) — **sí tiene** `alt`:
  ```php
  <img src="<?= htmlspecialchars($portada_url) ?>"
       alt="<?= htmlspecialchars($row['titulo']) ?>"
       class="..." loading="lazy" onerror="...">
  ```
  Valor del `alt`: el título del servicio, escapado.

- **`app/componentes/render_card.php:134-136`** (usado por el fallback de recomendaciones IA en `vitrina.php`, ver C.16) — **`NO EXISTE` atributo `alt`**:
  ```php
  <img src="<?= $img ?>"
       class="w-full h-full object-cover ..." group-hover:scale-105"
       loading="lazy" decoding="async" width="240" height="180">
  ```
- **`app/componentes/overlay_card_servicio.php:38-42`** (avatar del tutor, incluido dentro de ambos renderizadores anteriores) — **sí tiene** `alt` con el nombre del tutor.

### C.15 — Lazy loading en listados

**Sí existe**, con `loading="lazy"` en:
- `cargar_servicios.php:277` (portada principal del listado).
- `render_card.php:136` (portada del fallback IA).
- `overlay_card_servicio.php:41` (avatar del tutor).

`decoding="async"` solo aparece en `render_card.php`, no en `cargar_servicios.php`.

---

## D. Rendimiento

### D.16 — Queries N+1 en listados principales

**Los endpoints de listado principal auditados (`cargar_servicios.php`, `cargar_apuntes.php`, `cargar_vistos.php`, `cargar_descubre.php`) NO tienen N+1 de base de datos** — cada uno resuelve su listado con **una sola query por página** (usando subqueries correlacionadas embebidas en el `SELECT` para votos/rating, o `WHERE id IN (...)` para batchear por lote de IDs). Ejemplo del patrón usado (`cargar_servicios.php:100-110`):
```sql
SELECT s.*, ...,
      (SELECT COUNT(*) FROM valoraciones v WHERE v.servicio_id = s.id AND ...) as total_votos,
      (SELECT AVG(v.calificacion) FROM valoraciones v WHERE v.servicio_id = s.id AND ...) as rating_promedio,
      a.foto_perfil, a.nombre as nombre_tutor, bi.archivo as banco_archivo
FROM servicios s
LEFT JOIN alumnos a ON s.alumno_id = a.id
LEFT JOIN dominios_permitidos dp ON a.dominio = dp.dominio
LEFT JOIN banco_imagenes bi ON bi.id = s.imagen_banco_id
```

**Sí existe un patrón N+1 real, pero a nivel de HTTP, no de SQL** — confirmado por un comentario explícito en el propio código, `app/vitrina.php:2092`:
```js
// [NUBIRA 2.0] Fallback: el método original (motor_ia.php + N x render_card.php)
```
Y su implementación, `app/vitrina.php:2131-2133`:
```js
data.items.forEach((item, index) => {
    fetch(`/app/componentes/render_card.php?id=${item.id}&tipo=${item.tipo}`)
        .then(res => res.text())
        .then(html => { ... });
});
```
Este es el camino de **fallback** de las recomendaciones IA (cuando el método principal falla): dispara **N peticiones HTTP separadas**, cada una ejecutando su propia query en `render_card.php` (líneas 30-43 y 102-112 de ese archivo) — 1 query por tarjeta, N tarjetas = N queries, cada una en su propio round-trip de red. Es el propio código el que lo llama "el método original" en contraste con el método optimizado (batch) que existe como camino primario.

### D.17 — Caché y compresión

- **Compresión**: `.htaccess` tiene `mod_deflate` (gzip), **no** brotli:
  ```apache
  <IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/css application/javascript application/json image/svg+xml
  </IfModule>
  ```
- **Cache-Control de assets estáticos** (imágenes, CSS, JS, fuentes): `max-age=2592000` (30 días), vía `.htaccess`:
  ```apache
  <FilesMatch "\.(webp|jpg|jpeg|png)$">
    Header set Cache-Control "public, no-transform, max-age=2592000"
  </FilesMatch>
  <FilesMatch "\.(css|js|woff|woff2|svg|ico)$">
    Header set Cache-Control "public, max-age=2592000"
  </FilesMatch>
  ```
- **No hay caché de página HTML** (ni de servidor tipo Varnish/Redis, ni de aplicación tipo output-cache) — cada request PHP se ejecuta completo.
- **Sí existe caché de aplicación puntual, basada en archivos**: `app/cache_ia/*.json` — resultados de IA (recomendaciones/generación) cacheados como archivos JSON individuales, con nombre por hash MD5 (confirmado por archivos reales presentes: `bienvenida_09da85a2d9e7c971cf094eadc2d7e5c3.json`, etc.). Documentado también en `CLAUDE.md` ("AI recommendations: file-based, 30-min TTL").
- **OPcache**: mencionado en `CLAUDE.md` como advertencia operativa ("OPCache warning: can serve stale PHP after edits"), no como código de configuración explícito en el repo — es una nota de infraestructura de Hostinger, no algo controlado desde el código.

### D.18 — Tailwind: ¿compilado/purgado, o CDN completo?

**CDN completo, sin purgar**, en **119 ocurrencias sobre 114 archivos** de `<script src="https://cdn.tailwindcss.com">` (JIT-en-navegador, recompila clases en cada carga de página en el cliente).

**Sí existen archivos CSS precompilados en el repo** (`css/tailwind.min.css`, `css/nubira.min.css`, `app/css/nubira.min.css`), pero **`NO EXISTE` ningún `tailwind.config.js`** en el repo, y de los 3 archivos `.css` mencionados, **solo 1 referencia (`app/admin_empleos.php`)** los usa en el HTML — el resto del proyecto (114 archivos) sigue cargando el CDN sin purgar. Los `.css` precompilados parecen ser un remanente de un intento de build anterior, no el mecanismo activo.

---

## Preguntas abiertas (no resueltas leyendo el código)

1. **Tabla `usuarios` vs `alumnos`**: existe una tabla `usuarios` completa en el schema (80 filas en BD local, columnas `nombre/apellido/email/foto/especialidad/rol enum('admin','usuario','tutor')/estado`), estructuralmente distinta y más simple que `alumnos`. Se confirmó que **sí tiene una referencia real en código**: `app/recibir_mensajes_chat_mini_aula.php:31` — `JOIN usuarios u ON m.usuario_id = u.id` (para resolver quién envió un mensaje en `mensajes_mini_aula`). Esto es llamativo porque **todo el resto del sistema de mini-aula** (auditado en sesiones previas de este mismo proyecto: `mini_aula.php`, `chat_mini_aula.php`, `cargar_mensajes_chat_mini_aula.php`) opera sobre `alumnos`, no `usuarios`. No se pudo determinar desde el código si esto es: (a) un bug real (JOIN contra la tabla equivocada, que rompería el nombre/foto mostrado en el chat de mini-aula), (b) una migración a medio hacer, o (c) intencional por alguna razón no documentada. Requiere decisión/investigación aparte, fuera del alcance de esta pasada de solo inventario.

2. **Tabla `instituciones` (17 filas)**: cero referencias encontradas en `app/*.php`. ¿Es una tabla completamente huérfana (candidata a limpieza), o la usa algún script fuera de `app/` (cron externo, admin, `sql/` scripts) que no se buscó?

3. **`alumnos.institucion` vs `alumnos.universidad`**: ambas columnas existen (`varchar(50)` y `varchar(100)` respectivamente) en la misma tabla, con nombres que sugieren el mismo propósito. No se determinó desde el schema solo cuál es la fuente de verdad actual, ni si ambas se mantienen sincronizadas, ni en qué flujo se llena cada una (requeriría rastrear cada punto de escritura, fuera del alcance de esta pasada).

4. ~~Representatividad de los números de la Sección B.7~~ — **RESUELTO**: el usuario aportó el dato real de producción (80 servicios activos totales, 7 con institución llena y sin normalizar), que ya reemplazó la estimación de BD local en B.7. Queda abierto un sub-punto menor: no se especificó a qué categoría pertenece cada uno de los 7 servicios con institución llena (USM/USACH/PUCV/IACC×2/UCSC/UAI) — no es crítico dado que ninguna combinación individual supera 1 servicio de todos modos.

5. **Alcance real de "landings de categoría" (`landing_categoria.php`)**: el sitemap solo las incluye si tienen ≥3 servicios públicos en esa categoría (`sitemap.php:78-95`). No se determinó cuántas de las 16 categorías de `nubira_categorias_seo()` (`helpers/seo.php:38-57`) cumplen ese umbral hoy en producción — en BD local, con el conteo de la sección B.7, ninguna categoría individual llega a 3 servicios con institución no vacía, pero el umbral de sitemap no filtra por institución, solo por categoría total, así que este número específico no se calculó (quedó fuera del alcance puntual pedido).

6. **`app/app/` (subdirectorio anidado dentro de `app/`)**: aparece en el listado de subdirectorios pero no se abrió su contenido — no se sabe si es una duplicación real, un mount point, o vacío.
