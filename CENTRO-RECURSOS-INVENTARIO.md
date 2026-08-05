# Centro de Recursos — Inventario (Pasada 1, solo lectura)

Fecha: 2026-07-27. Alcance: inventario factual de infraestructura existente reutilizable para el futuro "Centro de Recursos" (contenido editorial tipo Superprof). Sin propuestas de diseño ni código nuevo — ver restricción explícita del pedido.

---

## A. Reusabilidad de la infraestructura SEO existente (pSEO de categorías)

### A1. ¿`seo_categorias_contenido` + `landing_categoria.php` sirven tal cual para páginas de categoría del Centro de Recursos?

**No tal cual — son conceptualmente distintos, aunque el patrón (tabla de contenido + template + interruptor) es reusable como molde.**

- `seo_categorias_contenido` (columnas reales: `id, categoria, tipo ENUM('apuntes','clases','ambos'), titulo_h1, parrafo_intro, meta_description, updated_at, filtro_titulo, indexable`) describe **metadata de una landing que AGREGA servicios/apuntes existentes por categoría** — no tiene columnas para contenido editorial propio (cuerpo de artículo, autor, fecha de publicación editorial, imagen de portada de artículo).
- `app/landing_categoria.php` (`app/landing_categoria.php:49-113`) construye `$filas` consultando la tabla `servicios` o `apuntes` (según `$tipo`), filtrando por `categoria = ?` o `filtro_titulo LIKE ?`. Es un **template de listado**, no un template de contenido de lectura larga.
- El Centro de Recursos necesita 2 tipos de página que no calzan en ese modelo: (a) un artículo individual (contenido propio, no un listado de otra tabla) y (b) posiblemente un hub/índice de artículos por tema — parecido en espíritu a la landing de categoría, pero listando filas de una tabla de artículos, no de `servicios`/`apuntes`.

**Conclusión de A1**: el *patrón* (tabla de config + interruptor `indexable` + guarda anti-thin-content + template) es un molde válido a replicar para el hub de artículos por tema, pero la tabla y el template de artículo individual necesitan su propio modelo — no son extensiones directas de `seo_categorias_contenido`/`landing_categoria.php`.

### A2. ¿`nubira_seo_meta()`, `nubira_canonical_tag()` y el patrón JSON-LD son genéricos y reusables, o están acoplados a servicios/categorías?

Archivo: `app/helpers/seo.php` (94 líneas).

- **`nubira_canonical(?string $path_forzado)`** (línea 10) y **`nubira_canonical_tag(?string $path_forzado)`** (línea 21): **100% genéricas**. Reciben un path string y devuelven la URL canónica / el tag `<link rel="canonical">`. No tienen ninguna referencia a servicios, categorías, ni ningún concepto de dominio. **Reusables tal cual para `/guias/{slug}`.**
- **`nubira_seo_meta(string $title, string $description)`** (línea 28): también **100% genérica** — recibe título y descripción como strings planos, devuelve `<title>` + meta description + OG tags, todo escapado. **Reusable tal cual.**
- **`nubira_categorias_seo()`** (línea 38) y **`generar_slug()`** (línea 61): `nubira_categorias_seo()` SÍ está acoplada — es un mapa hardcodeado de 16 categorías de servicios/apuntes, no aplica a artículos. `generar_slug(string $titulo)` es genérica (normaliza cualquier string a slug), útil para generar el slug de un artículo a partir de su título.
- **JSON-LD (FAQPage, BreadcrumbList)**: **NO están en `helpers/seo.php`** — están construidos **inline dentro de `landing_categoria.php`** (líneas 164-189), como arrays PHP armados ad-hoc en el propio archivo, no como funciones reusables. Un artículo nuevo necesitaría su propio bloque JSON-LD escrito desde cero (o el helper tendría que extraerse primero) — no hay una función `nubira_faq_ld()` ni `nubira_breadcrumb_ld()` que un artículo pueda simplemente llamar.

### A3. ¿`sitemap.php` permite agregar una sección nueva de forma aditiva?

**Sí, es aditivo por diseño** — `app/sitemap.php` está estructurado como bloques de código independientes con comentarios de sección (`// A. Páginas estáticas`, `// B. Servicios públicos`, `// C. Apuntes públicos`, `// E. Landings de categoría`), cada uno un `foreach`/`while` que hace `echo url_xml(...)` sobre su propia query. Agregar una sección `F. Artículos del Centro de Recursos` es literalmente copiar el patrón de la Sección C (bloque `while ($row = $r->fetch_assoc())` con su propio `SELECT`) — no toca ninguna sección existente. El propio archivo ya tiene precedente de "más secciones después" en un comentario (`// TODO: si supera ~45.000 URLs, dividir en sitemaps por tipo + sitemap index`), y el volumen actual (~90 URLs indexables) está lejísimos de ese límite — no se justifica un sitemap-index separado solo para artículos todavía.

---

## B. Patrones y componentes reutilizables del resto del proyecto

### B4. Templates/layout — ¿hay un include común?

Sí. El patrón repetido en páginas públicas (`landing_categoria.php`, `detalle_servicio.php`, `admin_avisos.php`, etc.) es:

```php
<?php require_once __DIR__ . '/componentes/head_common.php'; ?>   <!-- dentro de <head>, después de viewport -->
...
<?php require_once __DIR__ . '/componentes/header.php'; ?>         <!-- topbar fixed -->
<?php require_once __DIR__ . '/componentes/sidebar.php'; ?>        <!-- fixed left, desktop -->
<main class="pt-20 pb-28 md:pb-10 lg:ml-64 px-4 max-w-[1600px] mx-auto md:px-8">
  ...contenido...
</main>
<?php require_once __DIR__ . '/componentes/nav_bottom.php'; ?>     <!-- bottom nav, mobile -->
```

- `app/componentes/head_common.php`: tags PWA (manifest, iconos, splash screens iOS), JSON-LD `Organization` (schema.org), registro de service worker, poller de alertas (`contar_alertas_sistema.php`). Explícitamente **NO incluye** `<title>`, meta description, canonical ni OG — cada página los define aparte (vía `nubira_seo_meta()`/`nubira_canonical_tag()`). Esto es exactamente lo que un artículo necesitaría incluir.
- `app/componentes/header.php`: maneja sesión, captura de término de búsqueda (`$_GET['q']`), estado visitante vs. logueado.
- `app/componentes/footer_minimal.php`: footer simple (copyright, links legales, redes) — usado en páginas públicas tipo landing. Candidato directo para el pie de un artículo.
- Ancho de contenido: `max-w-[1600px]` para listados (`landing_categoria.php`), `max-w-[1100px]` para detalle/formularios (`admin_avisos.php`, `detalle_servicio.php` usa grid de 12 columnas dentro de un contenedor propio) — un artículo de lectura larga probablemente calza mejor con el patrón `max-w-[1100px]` (o incluso más angosto, tipo `max-w-[720px]` para texto, no documentado hoy porque no existe un caso de uso de lectura larga en el proyecto todavía).

### B5. Manejo de imágenes — ¿hay un patrón de subida/almacenamiento reusable?

Sí, patrón claro y ya probado en 2 variantes:

1. **Pipeline de 3 tamaños WebP** (documentado en `CLAUDE.md`: thumb 240px/q78, card 480px/q80, main 1200px/q82). Implementación de referencia: `app/admin_banco_imagenes.php:38-51`, función `banco_generar_tamano($src, $w0, $h0, $max_w, $dest, $q)` — usa `imagecreatetruecolor` + `imagecopyresampled` + `imagewebp` de GD, con `getimagesize()` (línea 129) para validar que el archivo subido sea una imagen real antes de procesar, restringido a `image/jpeg`, `image/png`, `image/webp`.
2. **Resolver unificado de portada**: `app/helpers/imagen_servicio.php` — funciones `url_portada($row)`, `srcset_portada($row)`, `path_portada($row)`. Reciben una fila de BD con el contrato `imagen_banco_id` / `banco_archivo` / `imagen` (legacy) y devuelven la URL correcta según prioridad (banco de imágenes → legacy → placeholder), con fallback automático a la variante `main` si el tamaño pedido no existe físicamente, y cache-busting vía `filemtime()`. Este resolver está escrito de forma genérica sobre un "contrato de fila" (no importa de qué tabla venga, mientras tenga esas 3 columnas) — **el patrón es directamente replicable para una columna `imagen_portada` en una futura tabla de artículos**, aunque el helper actual está nombrado/acoplado a "servicio" y tendría que copiarse o generalizarse, no llamarse tal cual.
3. Archivos físicos de imágenes hoy viven en `/upload/banco/` y `/upload/servicios/` (rutas relativas a `DOCUMENT_ROOT`, resueltas vía `nb_doc_root()`) — un directorio nuevo tipo `/upload/guias/` seguiría el mismo patrón sin fricción.

### B6. ¿Existe algún sistema de tags/etiquetas reusable?

**No existe un sistema de tags real (multi-valor) en ninguna tabla actual.** Lo que existe es categorización **single-value**:

| Tabla | Columna | Tipo | Notas |
|---|---|---|---|
| `servicios` | `categoria` | `varchar(40) NOT NULL DEFAULT 'Otros'` | 1 sola categoría por servicio, sin normalizar a tabla/ENUM (ver hallazgo de la auditoría SEO Fase 1 — 2 valores casi duplicados `'Otro'`/`'Otros'` coexistieron hasta esta sesión). |
| `servicios` | `materia` | `varchar(80) DEFAULT NULL`, con `KEY idx_materia` | Campo libre adicional, más granular que `categoria`. |
| `servicios` | `area` | `varchar(60) DEFAULT NULL` | Sin índice. |
| `servicios` | `asignatura` | `varchar(120) DEFAULT NULL` | Sin índice. |
| `apuntes` | `categoria` | `varchar(50) DEFAULT 'general'`, comentario `'Categoría detectada por IA (ej: medicina)'` | Asignada automáticamente por IA, no por el usuario. |
| `apuntes` | `asignatura` | `varchar(100) DEFAULT NULL` | |
| `apuntes` | `materia` | `varchar(50) DEFAULT NULL`, con `KEY idx_apunte_materia` y `KEY idx_apunte_nivel_materia (nivel_academico, materia)` | |

Ninguna de estas es una relación muchos-a-muchos (no hay tabla `tags` + tabla pivote `servicio_tags`). Un sistema de tags real para artículos (si se necesita más de 1 etiqueta por artículo) sería infraestructura nueva, no una reutilización.

### B7. Patrón del panel admin (CRUD, autenticación, permisos)

Leído `app/admin_avisos.php` como representativo (además de los ya vistos esta sesión: `admin_banco_imagenes.php`, `admin_marketing_cards.php`). Patrón consistente:

```php
require_once __DIR__ . '/init_sesion.php';   // sesión + $conn
require_once __DIR__ . '/iconos.php';
// ... helpers específicos del panel ...
if (($_SESSION['rol'] ?? '') !== 'admin') { header("Location: /"); exit; }   // RBAC: 1 sola línea, sin roles intermedios
```

- **Autenticación/permisos**: binario — `admin` o no. No hay niveles de permiso (ej. "editor" vs "admin"), confirmado también en `CLAUDE.md` ("Admin pages check `$_SESSION['rol'] === 'admin'`"). Un futuro admin de artículos seguiría exactamente este mismo check, sin necesidad de nueva infraestructura de permisos.
- **CRUD**: patrón PRG (Post-Redirect-Get) — un mismo archivo maneja `if ($_SERVER['REQUEST_METHOD'] === 'POST')` con un `switch`/`if` sobre una acción (`$_POST['accion']` o similar) para crear/editar/borrar, seguido de `header("Location: ...")` + `exit`, y el `GET` normal renderiza el listado + formulario. Confirmado en `admin_banco_imagenes.php:53` (`/* ----------------- ACCIONES (POST + PRG) ----------------- */`) y en el flujo de `admin_avisos.php` (formulario de nueva campaña + tabla de historial en la misma página).
- **Layout admin**: mismo `head_common.php` + `header.php` + `sidebar.php` que el resto del sitio (no hay un layout admin separado), con Tailwind vía CDN + Google Fonts Inter cargados inline en cada archivo admin (no en un layout compartido — esto se repite archivo por archivo, tal como se documentó ya en el hallazgo de tech-debt de `$categoria_overlay` triplicado; mismo patrón de duplicación, distinto lugar).

---

## C. Enlazado interno — mecanismos ya existentes

### C8. Link "Ver más clases de X" en `detalle_servicio.php` — funcionamiento exacto

Ubicación: cómputo en `app/detalle_servicio.php` (bloque agregado en SEO Fase 2, ~línea 297-315), render ~línea 605-612.

**Condición de aparición** (todas deben cumplirse):
1. `$servicio['categoria']` no está vacío.
2. Existe una fila en `seo_categorias_contenido` con `categoria = $servicio['categoria']` AND `tipo IN ('clases', 'ambos')` (ORDER BY para preferir `tipo` exacto sobre `'ambos'`, LIMIT 1).
3. Esa fila tiene `indexable = 1`.
4. Esa `categoria` tiene una entrada en el mapa `nubira_categorias_seo()` (se resuelve el slug vía `array_flip()` de ese mapa) — si la categoría no está en el mapa (ej. `'Otros'`, explícitamente excluido del mapa), no hay link posible aunque `indexable` fuera 1.

**Si todo se cumple**: `$link_categoria_slug` queda con el slug real, y se renderiza:
```php
<a href="/clases/<?= htmlspecialchars($link_categoria_slug) ?>">
    Ver más clases de <?= htmlspecialchars($servicio['categoria']) ?>
    ...
</a>
```
Si cualquier condición falla, `$link_categoria_slug` queda `null` y el bloque `<?php if ($link_categoria_slug): ?>` no imprime nada.

**Es el único mecanismo de "enlazado inteligente condicionado a estado de indexabilidad" que existe en el proyecto hoy** — cualquier enlace nuevo desde un futuro artículo hacia una landing de servicio/categoría debería replicar esta misma lógica de 4 condiciones, no asumir que la categoría del servicio siempre tiene landing.

### C9. ¿Otro cálculo de "contenido relacionado/similar" en el código?

Sí, 2 mecanismos adicionales, ambos en `detalle_servicio.php`, ninguno relacionado con SEO (son de UX/conversión, no pensados para linking indexable):

1. **"Servicios recomendados"** (`app/detalle_servicio.php:229-271`): calcula la "categoría favorita" del visitante consultando la tabla `tracker_intereses` (`SELECT categoria, SUM(peso_score) ... GROUP BY categoria ORDER BY total_puntos DESC LIMIT 1`, excluyendo `categoria = 'General'`), con fallback a la categoría del servicio actual si no hay historial. Luego trae 4 servicios distintos al actual, ordenados por `CASE WHEN categoria = ? THEN 1 ELSE 2` (primero la categoría favorita, luego la del servicio actual, luego más recientes). No usa `indexable` ni ningún criterio SEO — es puro engagement.
2. **`app/componentes/seccion_recomendaciones.php`**: genera copys dinámicos server-side según si el visitante es tutor (`servicios` con `estado='aprobado' AND visible=1`) o creador de apuntes (`apuntes` con `estado='aprobado' AND visible=1 AND bloqueado=0`) — es personalización de copy, no un cálculo de "artículos/servicios similares" en el sentido de contenido relacionado.

### D10. Columnas disponibles para relacionar un futuro artículo con tutores/apuntes/servicios

Puntos de cruce reales (mismos ya listados en B6, repetidos aquí en su rol de "clave de relación"):

| Para relacionar con... | Columna(s) | Tabla |
|---|---|---|
| Servicios de una materia/categoría | `categoria` (single-value, `varchar(40)`), `materia` (`varchar(80)`, indexada), `area` (`varchar(60)`), `asignatura` (`varchar(120)`) | `servicios` |
| Apuntes de una materia/categoría | `categoria` (asignada por IA, `varchar(50)`), `materia` (`varchar(50)`, indexada), `asignatura` (`varchar(100)`) | `apuntes` |
| Tutores de una universidad | `COALESCE(dp.institucion, a.institucion)` — el mismo patrón usado en `landing_categoria.php`/`detalle_servicio.php`, NO `alumnos.universidad` (ver auditoría Fase 1: `universidad` es el campo libre que el usuario llena, `institucion` vía `dominios_permitidos` es lo que realmente se muestra) | `alumnos` + `dominios_permitidos` |
| Interés/afinidad de un visitante | `categoria` (`varchar(100)`, comentario explícito "Para sugerir similares"), `peso_score`, `tipo_interaccion` | `tracker_intereses` |
| Slug canónico de categoría (para construir la URL de la landing relacionada) | mapa hardcodeado `nubira_categorias_seo()` (16 entradas) | `app/helpers/seo.php` (no es una tabla) |

No existe hoy ninguna columna de "tema" o "tag" normalizada que un artículo pueda usar directamente sin mapear manualmente a estos valores de `categoria`/`materia`/`asignatura` ya existentes (ver B6 — son campos libres, no una taxonomía cerrada).

---

## E. Restricciones de infraestructura (reconfirmadas)

Confirmado de nuevo, no solo heredado del inventario anterior:

- **Hosting**: Hostinger compartido. Confirmado explícitamente en `INFORME-PERFORMANCE.md:5`: *"sin root, sin Redis/Memcached, sin workers, sin control de Apache/MySQL. Solo `.htaccess`, PHP 8.x, MariaDB, y un cron cada 15 min."*
- **Deploy**: manual vía FileZilla (confirmado en `CLAUDE.md`, sección "Production (Hostinger)" — sin SSH, sin Node.js local en el servidor, sin CI/CD).
- **Cron existente**: 1 solo cron cada 15 minutos, ya en uso por múltiples scripts en `app/cron/` (`limpiar_typing.php`, `recalcular_tiempos_tutores.php` — hoy desactivado según `INFORME-PERFORMANCE.md:30`, `recordatorios_clases.php`, `verificar_modalidad_hibrida.php`, `verificar_horario_faltante.php`). Cualquier tarea periódica que el Centro de Recursos necesite (ej. recalcular contenido relacionado, expirar cache) tiene que sumarse a ese mismo cron de 15 min, no asumir un cron propio adicional.

Cualquier propuesta de Centro de Recursos en la próxima pasada debe funcionar sin Redis/colas/workers — solo PHP síncrono por request + el cron de 15 min compartido.

---

## Conclusión preliminar

El patrón de `seo_categorias_contenido` + interruptor `indexable` + guarda anti-thin-content es un molde de diseño valioso y directamente replicable para el hub/índice de artículos por tema, pero **no sirve para el artículo individual**: no tiene columnas de contenido editorial (cuerpo, portada, autor, fecha), y `landing_categoria.php` es un template de listado que agrega otra tabla (`servicios`/`apuntes`), no un template de lectura. El Centro de Recursos necesita su propio modelo de datos (tabla de artículos + su propia landing de listado), reutilizando como piezas sueltas: `nubira_seo_meta()`/`nubira_canonical_tag()` (genéricas, sin cambios), el patrón de 3-tamaños WebP de `admin_banco_imagenes.php`, el layout `head_common.php`+`header.php`+`sidebar.php`+`footer_minimal.php`, y el criterio de 4 condiciones del link "Ver más clases de X" como referencia para cualquier enlazado condicionado a `indexable` que el artículo quiera hacer hacia servicios/categorías.
