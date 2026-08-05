# Centro de Recursos — Plan de diseño + roadmap (Pasada 2)

Basado en `CENTRO-RECURSOS-INVENTARIO.md` (Pasada 1, solo lectura). Documento de diseño únicamente — sin código de implementación, sin migraciones ejecutadas, sin commits.

**Restricciones de diseño aplicadas en todo este documento**: no se reconstruye `seo_categorias_contenido`/`landing_categoria.php`; el Centro de Recursos usa su propio modelo de datos, reutilizando como piezas sueltas `nubira_seo_meta()`, `nubira_canonical_tag()`, el layout compartido, el pipeline WebP de 3 tamaños y el patrón admin CRUD (RBAC + PRG) documentados en la Pasada 1. Todo bajo `/guias/` como espacio de nombres nuevo, sin tocar URLs de servicios/apuntes. Viable en Hostinger compartido, deploy FileZilla, 1 cron de 15 min ya en uso. Fase 1 de implementación acotada a 4 categorías piloto (Matemáticas, PAES, Métodos de estudio, Becas); el resto queda como estructura aprobada, sin filas creadas, hasta tener contenido real.

---

## Parte 1 — Modelo de datos

Todas las tablas son **aditivas** — ninguna modifica `servicios`, `apuntes` ni `alumnos`. Nomenclatura en español, consistente con el resto del schema (`servicios`, `apuntes`, `avisos_campanas`, `tracker_intereses`, `seo_categorias_contenido`). Nota: un sketch anterior (`SEO-AUDIT-PLAN.md`, Fase 5) mencionaba una tabla `guias_posts` — este plan la reemplaza por `guias_articulos`, más consistente con la convención 100% en español ya establecida en el resto del proyecto. Ninguna tabla usa `FOREIGN KEY` explícita — mismo criterio ya observado en todo el schema actual (`servicios.alumno_id`, `apuntes.id_alumno` tampoco tienen FK, la integridad se mantiene a nivel de aplicación).

### 1.1 `guias_categorias`
Taxonomía de las 15 categorías del pedido original. Se cargan las 15 filas desde el inicio (estructura aprobada), pero solo 4 quedan `habilitada = 1`.

```sql
CREATE TABLE guias_categorias (
  id                    INT NOT NULL AUTO_INCREMENT,
  nombre                VARCHAR(60) NOT NULL,
  slug                  VARCHAR(60) NOT NULL,
  descripcion_corta     VARCHAR(200) DEFAULT NULL,       -- para el hub y meta description de categoría
  categoria_relacionada VARCHAR(60) DEFAULT NULL,          -- match exacto contra servicios.categoria/apuntes.categoria (ej. 'Matemáticas'); NULL si no aplica
  filtro_relacionado    VARCHAR(100) DEFAULT NULL,         -- LIKE alternativo (mismo patrón que seo_categorias_contenido.filtro_titulo, ej. '%PAES%'); NULL si no aplica
  orden                 INT NOT NULL DEFAULT 0,
  habilitada            TINYINT(1) NOT NULL DEFAULT 0,     -- opt-in de negocio, mismo criterio que seo_categorias_contenido.indexable
  PRIMARY KEY (id),
  UNIQUE KEY uniq_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

`categoria_relacionada`/`filtro_relacionado` son el mecanismo de cruce con servicios/apuntes (ver 1.4). Para Matemáticas y PAES hay match natural (`'Matemáticas'` exacto, `'%PAES%'` como ya usa `landing_categoria.php` para la landing de PAES). Para Métodos de estudio y Becas **ambos quedan NULL** — no existe ninguna categoría de servicio/apunte equivalente hoy, y forzar un match falso (ej. cruzar "Becas" contra "Asesoría") generaría recomendaciones sin sentido. El template del artículo simplemente omite el bloque de servicios/apuntes relacionados cuando ambos son NULL (ver Parte 4).

### 1.2 `guias_articulos`
La tabla central de contenido.

```sql
CREATE TABLE guias_articulos (
  id                  INT NOT NULL AUTO_INCREMENT,
  categoria_id        INT NOT NULL,
  titulo              VARCHAR(200) NOT NULL,
  slug                VARCHAR(220) NOT NULL,
  resumen             VARCHAR(300) DEFAULT NULL,          -- dek/subtítulo; también fallback de meta_description si esta es NULL
  cuerpo              MEDIUMTEXT NOT NULL,                 -- HTML; sanitización pendiente de definir en implementación (mismo cuidado que avisos_admin/BBCode)
  imagen_portada      VARCHAR(255) DEFAULT NULL,           -- nombre de archivo, mismo contrato que imagen_servicio.php (banco/legacy)
  autor_id            INT DEFAULT NULL,                    -- alumnos.id si el autor tiene cuenta; NULL si es autoría genérica de equipo
  autor_nombre        VARCHAR(100) NOT NULL DEFAULT 'Equipo Nubira',
  meta_description    VARCHAR(200) DEFAULT NULL,           -- override manual; NULL => se deriva de resumen
  estado              ENUM('borrador','publicado','archivado') NOT NULL DEFAULT 'borrador',
  fecha_publicacion   DATETIME DEFAULT NULL,                -- se llena al pasar a 'publicado', no al crear
  fecha_actualizacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  fuente_ia           TINYINT(1) NOT NULL DEFAULT 0,        -- trazabilidad: el borrador se originó con asistencia de IA (Parte 6)
  revisado_humano     TINYINT(1) NOT NULL DEFAULT 0,        -- gate obligatorio antes de poder publicar si fuente_ia=1
  PRIMARY KEY (id),
  UNIQUE KEY uniq_slug (slug),
  KEY idx_categoria (categoria_id),
  KEY idx_estado_fecha (estado, fecha_publicacion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 1.3 `guias_articulo_faqs`
FAQ por artículo — reemplaza el patrón hardcodeado que hoy usa `landing_categoria.php` solo para Tesis, con filas reales editables desde el panel admin.

```sql
CREATE TABLE guias_articulo_faqs (
  id           INT NOT NULL AUTO_INCREMENT,
  articulo_id  INT NOT NULL,
  pregunta     VARCHAR(200) NOT NULL,
  respuesta    TEXT NOT NULL,
  orden        INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_articulo (articulo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 1.4 Contenido relacionado — ¿tabla pivote o cruce en caliente?

**Decisión: cruce en caliente por columna compartida (`categoria`/`materia`), sin tabla pivote.** Justificación con datos reales del inventario:

- El volumen actual de `servicios` (~90-100 filas activas totales según `sitemap.php`) y `apuntes` (decenas) es demasiado bajo para justificar la carga de curación manual que exige una tabla pivote (alguien tendría que entrar al admin y vincular manualmente cada artículo con cada servicio/apunte relevante, y volver a hacerlo cada vez que se publica un servicio nuevo).
- Ningún otro mecanismo de "contenido relacionado" en el proyecto usa pivote hoy — ni la recomendación de servicios en `detalle_servicio.php:249-265` (JOIN + `CASE WHEN categoria = ?`, en caliente), ni el link "Ver más clases de X" (query condicional en caliente). Un pivote sería el primer mecanismo de este tipo en todo el proyecto, sin precedente ni necesidad demostrada.
- El cruce en caliente se automantiene: un servicio nuevo de Matemáticas aparece automáticamente como "relacionado" en cualquier artículo de Matemáticas sin ninguna acción manual, igual que ya ocurre con las recomendaciones de `detalle_servicio.php`.
- El costo de la query en caliente a este volumen (decenas de filas, con índices existentes en `categoria`/`materia`) es equivalente al que ya pagan hoy `detalle_servicio.php` y `landing_categoria.php` en cada request — no es una carga nueva de perfil de riesgo distinto.

El mecanismo concreto usa `guias_categorias.categoria_relacionada`/`filtro_relacionado` (1.1) para saber CÓMO cruzar (match exacto vs. LIKE), replicando exactamente la lógica que `landing_categoria.php`/`sitemap.php` ya usan para decidir entre `categoria = ?` y `filtro_titulo LIKE ?`. Ver queries concretas en Parte 4.

**Artículos relacionados entre sí**: mismo criterio, cruce en caliente por `categoria_id` compartido — ni siquiera necesita el campo de texto libre, ya que es una relación dentro de la propia tabla `guias_articulos`.

---

## Parte 2 — Arquitectura de URLs

Mismo mecanismo de rewrite que ya usa el proyecto (`.htaccess`, `RewriteEngine On` + `RewriteBase /`), siguiendo el patrón exacto de las reglas existentes para `/clases/{slug}` (`.htaccess:157-158`):

```apache
RewriteRule ^guias/?$                                app/guias.php                          [L,QSA]
RewriteRule ^guias/([a-z0-9\-]+)/?$                  app/guias.php?cat=$1                   [L,QSA]
RewriteRule ^guias/([a-z0-9\-]+)/([a-z0-9\-]+)/?$    app/guia_post.php?cat=$1&slug=$2       [L,QSA]
```

2 archivos nuevos (mismos nombres ya sugeridos en el sketch de `SEO-AUDIT-PLAN.md` Fase 5, se mantienen):
- `app/guias.php` — maneja tanto el hub general (`/guias`, sin `cat`) como el hub de categoría (`/guias/{cat}`, con `cat`), igual que `landing_categoria.php` resuelve `tipo`+`cat` en un solo archivo.
- `app/guia_post.php` — artículo individual.

**Ejemplos reales con las 4 categorías piloto:**
```
/guias                                                          → hub general (lista solo categorías con habilitada=1 y >=1 artículo publicado)
/guias/matematicas                                               → hub de categoría
/guias/matematicas/como-estudiar-calculo-1-desde-cero            → artículo
/guias/paes
/guias/paes/mejor-estrategia-para-la-paes-matematica-2026
/guias/metodos-de-estudio
/guias/metodos-de-estudio/tecnica-pomodoro-para-universitarios
/guias/becas
/guias/becas/becas-arancel-gratuito-requisitos-2026
```

Slugs de categoría generados una vez con `generar_slug()` (`app/helpers/seo.php:61`, ya genérico) al cargar las 15 filas de `guias_categorias`. Slug de artículo generado igual, al crear el artículo en el admin.

---

## Parte 3 — SEO automático por artículo

### Metadata (reusa `nubira_seo_meta()` tal cual, sin modificarla)

```php
$seo_title = mb_strlen("{$articulo['titulo']} | Guías Nubira") > 65
    ? mb_substr("{$articulo['titulo']} | Guías Nubira", 0, 62) . '...'
    : "{$articulo['titulo']} | Guías Nubira";

$desc_fuente = $articulo['meta_description'] ?: ($articulo['resumen'] ?: strip_tags($articulo['cuerpo']));
$seo_desc = mb_strlen($desc_fuente) > 155 ? mb_substr($desc_fuente, 0, 152) . '...' : $desc_fuente;

echo nubira_seo_meta($seo_title, $seo_desc);          // <title> + meta description + og:title + og:description
echo nubira_canonical_tag("/guias/{$cat_slug}/{$articulo['slug']}");
```

Mismo criterio de truncado (65/155 caracteres) ya usado en `detalle_servicio.php`/`ver_apunte.php` — consistencia exacta con el patrón existente, no un criterio nuevo.

### OG/Twitter adicionales (fuera de `nubira_seo_meta()` — ese helper NO los genera hoy, se agregan igual que ya hace `detalle_servicio.php:373-383` manualmente)

```php
og:type          = "article"   (no "website" — servicios usan "website"; artículos son el primer caso og:type=article del sitio)
og:image          = URL de imagen_portada (mismo resolver de 3 tamaños, variante 'main')
og:image:width/height = guardadas en BD al momento de subir la portada (evitar el getimagesize() síncrono por request que INFORME-PERFORMANCE.md ya marcó como anti-patrón en detalle_servicio.php:314)
og:url            = canonical
twitter:card      = "summary_large_image"
article:published_time = fecha_publicacion (ISO 8601, mismo formato que w3c() en sitemap.php)
article:author    = autor_nombre
```

### JSON-LD — extracción de helper reusable (punto 2 de las restricciones)

Hoy el FAQ y el Breadcrumb están armados **inline** en `landing_categoria.php:164-189`, no como funciones. Se extraen a `app/helpers/seo.php` (mismo archivo donde ya viven `nubira_seo_meta()`/`nubira_canonical_tag()`), **sin cambiar el HTML que generan** — extracción pura:

```php
function nubira_faq_ld(array $faqs): string {
    if (empty($faqs)) return '';
    $ld = ['@context' => 'https://schema.org', '@type' => 'FAQPage',
           'mainEntity' => array_map(fn($f) => ['@type' => 'Question', 'name' => $f['q'],
               'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']]], $faqs)];
    return '<script type="application/ld+json">'
         . json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
         . '</script>';
}

function nubira_breadcrumb_ld(array $items): string {
    // $items = [['name'=>'Inicio','item'=>'https://nubira.cl/explorar'], ..., ['name'=>'Página actual']]  (el último sin 'item')
    $ld = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList',
           'itemListElement' => array_values(array_map(function($it, $i) {
               $entry = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $it['name']];
               if (!empty($it['item'])) $entry['item'] = $it['item'];
               return $entry;
           }, $items, array_keys($items)))];
    return '<script type="application/ld+json">'
         . json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
         . '</script>';
}
```

`landing_categoria.php:164-189` pasa a llamar `echo nubira_faq_ld($faqs);` y `echo nubira_breadcrumb_ld([...]);` con exactamente los mismos arrays que ya construye hoy — mismo output, cero cambio de comportamiento (tarea explícita de Fase 1 del roadmap, Parte 7). Un `Article` con schema propio (headline, author, publisher, datePublished) se arma **inline en `guia_post.php`**, no como 3ra función en el helper — a diferencia de FAQ/Breadcrumb, este schema solo lo usa un tipo de página (el artículo), así que extraerlo a helper compartido sería abstraer para un único llamador.

### Qué schema va dónde

| Página | Schema.org |
|---|---|
| Hub de categoría (`/guias/{cat}`) | `BreadcrumbList` (helper extraído) únicamente. Sin `ItemList` (ver Parte 8 — prematuro al volumen actual). |
| Artículo individual (`/guias/{cat}/{slug}`) | `Article` (inline en `guia_post.php`) + `FAQPage` (helper extraído, condicional a que el artículo tenga filas en `guias_articulo_faqs`) + `BreadcrumbList` (helper extraído, ruta Inicio > Guías > {categoría} > {artículo}) |

---

## Parte 4 — Enlazado interno / contenido relacionado

Referencia de estilo: el link "Ver más clases de X" (`detalle_servicio.php`, documentado en el inventario C8) — nunca se linkea a algo que podría estar en `noindex` o no existir; se verifica la condición antes de renderizar, no se asume.

### 4.1 Servicios/tutores relacionados (en `guia_post.php`)

Solo si `guias_categorias.categoria_relacionada` o `filtro_relacionado` no son NULL para la categoría del artículo:

```php
if ($cat['categoria_relacionada'] || $cat['filtro_relacionado']) {
    if ($cat['filtro_relacionado']) {
        $sql = "SELECT s.id, s.slug, s.titulo, a.nombre AS nombre_tutor, a.foto_perfil,
                       COALESCE(dp.institucion, a.institucion) AS institucion_maestra
                FROM servicios s
                JOIN alumnos a ON a.id = s.alumno_id
                LEFT JOIN dominios_permitidos dp ON a.dominio = dp.dominio
                WHERE s.estado = 'aprobado' AND s.visible = 1
                  AND COALESCE(a.visible,1) = 1 AND a.bloqueado = 0
                  AND s.titulo LIKE ?
                ORDER BY s.id DESC LIMIT 4";
        // bind_param con $cat['filtro_relacionado']
    } else {
        // misma query, WHERE s.categoria = ? con $cat['categoria_relacionada']
    }
}
```

Mismo patrón exacto de `landing_categoria.php:79-87` (elegir entre `filtro_titulo LIKE` y `categoria =`), reutilizado tal cual. Si además `seo_categorias_contenido` tiene esa misma categoría con `indexable=1`, se agrega un link "Ver todas las clases de {categoría}" → `/clases/{slug}` — reusando el mecanismo ya existente, no uno nuevo.

### 4.2 Apuntes relacionados

Misma lógica que 4.1, contra la tabla `apuntes` (columnas `categoria`/`materia`/`asignatura`), filtrando `publico = 1 AND visible = 1` (mismo filtro que `sitemap.php` Sección C).

### 4.3 Artículos relacionados (dentro de `/guias/`)

```sql
SELECT id, slug, titulo, imagen_portada
FROM guias_articulos
WHERE categoria_id = ? AND id != ? AND estado = 'publicado'
ORDER BY fecha_publicacion DESC
LIMIT 3
```

Sin condición adicional — todo artículo publicado ya pasó el gate de `revisado_humano` (Parte 6), no hace falta repetir la validación acá.

### 4.4 Enlace recíproco desde servicios/apuntes hacia artículos (nuevo, simétrico al de 4.1)

En `detalle_servicio.php`/`ver_apunte.php`: mismo criterio que "Ver más clases de X", pero apuntando hacia `/guias/{cat}` — solo si existe una fila en `guias_categorias` con `categoria_relacionada`/`filtro_relacionado` matcheando la categoría del servicio, `habilitada=1`, y al menos 1 artículo publicado en esa categoría. Se marca como tarea de Fase 2 del roadmap (Parte 7), no de Fase 1 — no tiene sentido antes de que existan artículos reales publicados.

---

## Parte 5 — Panel administrativo

Mismo patrón ya documentado (RBAC de 1 línea + PRG), sin sistema de permisos nuevo. Nuevo archivo: `app/admin_guias.php`, mismo layout de `admin_avisos.php` (listado + formulario en la misma página).

**Alcance realista de Fase 1:**
- Listado de artículos (todas las categorías piloto), con filtro por estado (borrador/publicado/archivado) y fecha.
- Formulario crear/editar: título (slug auto-generado vía `generar_slug()`, editable), categoría (`<select>` limitado a las filas `habilitada=1` de `guias_categorias`), resumen, cuerpo (textarea; sanitización HTML a definir en el momento de implementar — mismo cuidado ya usado en `avisos_admin`/BBCode), imagen de portada (subida reusando el pipeline de 3 tamaños de `admin_banco_imagenes.php`), meta_description (opcional, con fallback automático a `resumen`), mini-CRUD de FAQs anidado (agregar/quitar pares pregunta-respuesta en el mismo formulario).
- Botón "Publicar": valida server-side que si `fuente_ia=1` entonces `revisado_humano=1` (gate de Parte 6) antes de permitir el cambio de estado; llena `fecha_publicacion` automáticamente si estaba NULL.
- **Gestión de categorías NO tiene formulario propio en Fase 1** — las 15 filas de `guias_categorias` se cargan una sola vez por SQL directo (mismo criterio que ya se usa hoy para `seo_categorias_contenido`, que tampoco tiene panel admin propio). Construir un CRUD de categorías para algo que cambia con frecuencia casi nula sería infraestructura especulativa.

**Explícitamente fuera de Fase 1** (marcado para después, Parte 7/8): programación de publicaciones (fecha futura + cron check), multimedia avanzada (galerías, embeds de video), UI de gestión de categorías, sistema de tags.

---

## Parte 6 — Módulo de IA (diseño conceptual, sin implementación)

El proyecto ya tiene integración de IA en producción: Google Gemini 2.0 Flash vía `app/datos/ia_nubira.php`, con cache file-based en `app/cache_ia/` (JSON, clave MD5, TTL 30 min) y un panel admin propio (`/admin/ia`). El módulo de IA del Centro de Recursos **reutiliza ese mismo cliente**, no crea una integración nueva.

**Casos de uso propuestos** (todos generan texto, ninguno reemplaza la revisión humana):
1. Borrador de cuerpo: el admin ingresa tema + puntos clave → Gemini genera un borrador de HTML simple para `cuerpo`.
2. FAQs sugeridas: a partir del cuerpo ya redactado, generar 3-5 pares pregunta/respuesta candidatos para `guias_articulo_faqs`.
3. Meta description sugerida: generar un texto dentro del límite de 155 caracteres a partir del cuerpo.

**Gate obligatorio (no negociable, aplicado a nivel de servidor, no solo de UI)**: cualquier contenido generado por este flujo se guarda siempre con `fuente_ia = 1` y `estado = 'borrador'` — nunca directo a `'publicado'`. El handler POST que procesa el cambio de estado a `'publicado'` en `admin_guias.php` debe rechazar la transición si `fuente_ia = 1 AND revisado_humano = 0`, devolviendo un error, no solo deshabilitar un botón en el HTML (que se puede saltar). Este es el mismo tipo de guardia de negocio que ya existe en otros flujos del proyecto (ej. `verificacion_estado`, moderación de imágenes) — aplicar un patrón ya validado a un caso nuevo, no inventar uno.

Fuera de alcance de este módulo: generación de imágenes de portada por IA (Gemini 2.0 Flash usado hoy es de texto; requeriría otro proveedor/costo, no evaluado en este documento).

---

## Parte 7 — Roadmap por fases

### Fase 1 — Infraestructura mínima + 4 categorías piloto (Impacto: Alto a mediano plazo · Esfuerzo: Alto)
**Qué y por qué**: construir la pieza completa que hoy no existe, acotada a las 4 categorías con contenido real planeado.
**Archivos a crear**: `app/guias.php`, `app/guia_post.php`, `app/admin_guias.php`.
**Archivos a modificar**: `.htaccess` (3 reglas, Parte 2), `app/sitemap.php` (nueva Sección F, mismo patrón aditivo que ya tiene la Sección E), `app/helpers/seo.php` (2 funciones nuevas: `nubira_faq_ld()`, `nubira_breadcrumb_ld()`), `app/landing_categoria.php` (reemplazar el bloque inline de JSON-LD por llamadas a los helpers extraídos — **mismo resultado exacto, extracción pura, sin refactor de comportamiento**).
**Tablas**: `guias_categorias` (15 filas cargadas, 4 con `habilitada=1`), `guias_articulos`, `guias_articulo_faqs`.
**Dependencias**: ninguna dura sobre SEO Fase 2 (ya completada), pero comparte el mismo espíritu de guarda anti-thin-content.
**Riesgos**: sanitización de HTML libre en `cuerpo` — mitigar con una whitelist de tags al guardar (a definir en implementación, mismo cuidado que ya existe en `avisos_admin`/BBCode). Superficie nueva de código (3 archivos + 3 tablas), pero 100% aditiva — no toca el flujo transaccional existente.
**Impacto SEO esperado**: Alto a mediano plazo (el contenido editorial tarda en posicionar).
**Esfuerzo**: Alto.
**Tiempo estimado**: 3-4 sesiones de código para la infraestructura, **más** el tiempo editorial (fuera del alcance de este plan) para escribir un mínimo de 3 artículos reales × 4 categorías = 12 artículos antes de poder indexar cualquiera de las 4 (restricción #6, thin content).

### Fase 2 — Enlazado interno bidireccional (Impacto: Medio-Alto · Esfuerzo: Bajo)
**Qué y por qué**: activar Parte 4 completa una vez que existan artículos reales publicados — antes no tiene sentido (no hay a qué enlazar).
**Archivos a modificar**: `app/guia_post.php` (4.1-4.3, si no se hizo ya en Fase 1), `app/detalle_servicio.php` y `app/ver_apunte.php` (4.4, link recíproco nuevo hacia artículos).
**Tablas**: ninguna nueva.
**Dependencias**: Fase 1 con al menos 1 artículo publicado por categoría piloto con `categoria_relacionada`/`filtro_relacionado` definido (Matemáticas, PAES).
**Riesgos**: bajos — mismo patrón ya probado en "Ver más clases de X".
**Impacto SEO esperado**: Medio-Alto (jugo de indexación interno, igual razón que motivó el link de servicios en SEO Fase 2).
**Esfuerzo**: Bajo.
**Tiempo estimado**: menos de 1 sesión.

### Fase 3 — Panel admin: mejoras (Impacto: Bajo-Medio · Esfuerzo: Medio)
**Qué y por qué**: programación de publicaciones y, si el catálogo de categorías crece más allá de las 15 ya definidas, UI de gestión de categorías.
**Dependencias**: Fase 1 en uso real por al menos unas semanas, con fricción operativa concreta detectada (no especulativa).
**Riesgos**: bajos, aditivo.
**Impacto esperado**: Bajo-Medio (comodidad operativa, no SEO directo).
**Esfuerzo**: Medio.
**Tiempo estimado**: 1 sesión.

### Fase 4 — Módulo de IA asistido (Impacto: variable · Esfuerzo: Medio)
**Qué y por qué**: activar Parte 6 cuando exista fricción real de tiempo editorial, no antes.
**Dependencias**: Fase 1 con volumen editorial sostenido (founder escribiendo/revisando contenido regularmente y sintiendo el cuello de botella).
**Riesgos**: calidad de contenido generado sin supervisión — mitigado por el gate `fuente_ia`/`revisado_humano` ya diseñado en Parte 6, no opcional.
**Impacto esperado**: variable, depende de cuánto acelere la producción real de contenido.
**Esfuerzo**: Medio (reutiliza cliente Gemini existente, no una integración nueva).
**Tiempo estimado**: 1-2 sesiones.

### Fase 5 — Expansión de categorías (Impacto: Medio, decreciente por categoría · Esfuerzo: Muy bajo por categoría)
**Qué y por qué**: habilitar, una por una, las 11 categorías restantes (Universidad, Ingeniería, Medicina, Derecho, Psicología, Física, Química, Programación, Vida universitaria, Noticias, Oportunidades) — mismo mecanismo exacto de Fase 1, sin nueva infraestructura.
**Archivos a modificar**: ninguno nuevo — solo `UPDATE guias_categorias SET habilitada=1 WHERE slug=?` cuando cada una tenga sus 3 artículos reales listos.
**Dependencias**: Fase 1 estable + contenido editorial real por categoría (mismo criterio de "3 artículos mínimo" de la restricción #6, aplicado individualmente, nunca en bloque).
**Riesgos**: mínimos, mecanismo ya probado.
**Impacto esperado**: Medio, decreciente (cada categoría nueva aporta menos que la anterior a medida que se cubren los temas de mayor volumen de búsqueda).
**Esfuerzo**: Muy bajo por categoría.
**Tiempo estimado**: no estimable desde este plan — depende 100% de disponibilidad editorial, igual que ya se documentó para Fase 5b de `SEO-AUDIT-PLAN.md`.

---

## Parte 8 — Qué NO hacer todavía

| Idea | Por qué sería prematuro ahora | Condición de desbloqueo |
|---|---|---|
| **Tabla pivote explícita de relación artículo↔servicio/apunte** | El cruce en caliente por `categoria`/`materia` (Parte 1.4) cubre el caso de uso sin curación manual, al volumen actual (decenas de filas en `servicios`/`apuntes`, ~12 artículos en el lanzamiento piloto). Ningún otro mecanismo de "relacionado" del proyecto usa pivote hoy. | Cuando el catálogo de artículos supere ~50-100 filas y el cruce por categoría empiece a producir falsos positivos notorios (ej. recomendar un tutor que no calza con el nivel del artículo). |
| **Sistema de tags/etiquetas multi-valor** | No existe hoy en ninguna tabla del proyecto (confirmado en el inventario, B6). Construirlo para ~12 artículos piloto sería infraestructura especulativa — `categoria_id` (single-value) alcanza sobradamente a ese volumen. | Cuando `categoria_id` deje de ser suficiente para navegar el catálogo (ej. >50 artículos y necesidad real confirmada de filtros cruzados, no solo una intuición de que "sería lindo tener tags"). |
| **Módulo de IA (Fase 4)** | Diseñado en Parte 6, pero implementarlo antes de tener fricción real de producción editorial sería resolver un problema que todavía no existe — los primeros ~12 artículos piloto se pueden (y deben) escribir a mano para validar el formato antes de automatizar nada. | Volumen editorial sostenido con cuello de botella de tiempo real y confirmado, no especulativo. |
| **Programación de publicaciones (scheduled publish)** | Fase 1 solo necesita publicar de inmediato — 1 sola persona escribiendo contenido, sin necesidad de coordinar calendario. | Cuando haya más de 1 persona escribiendo contenido, o una necesidad real de coordinar fechas de salida con alguna campaña. |
| **UI de gestión de categorías** | Solo 15 filas totales, cargadas una vez por SQL directo — mismo criterio que `seo_categorias_contenido`, que tampoco tiene panel admin propio hoy. | Si el catálogo de categorías empieza a cambiar con frecuencia real (no en el lanzamiento inicial). |
| **Expansión a las 11 categorías restantes en bloque** | Explícitamente diferido por la restricción #3 — no se crean categorías vacías en nav hasta tener contenido real. | Cada categoría individualmente, cuando tenga mínimo 3 artículos reales listos (Fase 5, aplicado una por una). |
| **`ItemList` JSON-LD en el hub de categoría** | `BreadcrumbList` ya cubre el rich result más relevante a este volumen; no hay evidencia (Search Console aún no instalado, per auditoría SEO anterior) de que `ItemList` mejoraría CTR al tamaño actual del catálogo. | Cuando Search Console esté instalado y muestre necesidad real de mejorar CTR del hub, con volumen de artículos suficiente para que un listado estructurado aporte algo (>10-15 artículos en esa categoría). |
| **Sitemap-index separado para `/guias/`** | `sitemap.php` ya tiene el mismo `TODO` documentado para todo el sitio (umbral de 45.000 URLs); el volumen de `/guias/` en Fase 1 es ~16 URLs (12 artículos + 4 hubs de categoría). | Mismo umbral ya definido en `sitemap.php` para el sitio completo — no un umbral separado solo para guías. |
| **`CHECK` constraint en `guias_articulos` para el gate `fuente_ia`/`revisado_humano`** | No se pudo confirmar la versión real de MariaDB/MySQL de Hostinger (local corre 10.4.32 vía XAMPP, pero es la versión empaquetada por XAMPP, no la del servidor de producción — a diferencia del contenido de las tablas, la versión del motor no es algo que se herede de un dump). Versiones viejas de MySQL parsean `CHECK` sin aplicarlo, lo que daría una falsa sensación de seguridad. El gate a nivel de aplicación en `admin_guias.php` es obligatorio de todas formas y ya cubre el caso — el `CHECK` sería una segunda capa, no la principal. | Confirmar en el phpMyAdmin de Hostinger (página principal, o `SELECT VERSION();`) que la versión es MariaDB 10.2+ / MySQL 8.0+ y que aplica `CHECK` de verdad. Con eso confirmado, agregar `ALTER TABLE guias_articulos ADD CONSTRAINT chk_gate_publicacion CHECK (NOT (estado='publicado' AND fuente_ia=1 AND revisado_humano=0));`. |
