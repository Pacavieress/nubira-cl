# Auditoría SEO Nubira — Pasada 2: Plan Maestro

Base: `SEO-AUDIT-INVENTARIO.md` (Pasada 1) + verificación adicional de 3 archivos no cubiertos en detalle ahí (`app/landing_categoria.php`, `app/helpers/institucion.php`, `app/publicar_servicio.php`), necesaria para no proponer sobre supuestos. Cada vez que este documento usa un dato nuevo no citado en el inventario, se marca explícitamente como tal.

Este documento es un **plan**, no código. No se modificó, creó ni eliminó ningún archivo del proyecto salvo este mismo `.md`.

---

# Parte 1 — Diagnóstico

Un hallazgo por fila. Severidad: **Crítico** (bloquea indexación o visibilidad básica) / **Alto** (deja valor real sobre la mesa) / **Medio** (mejora real pero no urgente) / **Bajo** (cosmético o de bajo impacto medible).

| # | Hallazgo (ref. inventario) | Qué está mal | Por qué importa para SEO | Severidad |
|---|---|---|---|---|
| 1 | `vitrina.php` sin ningún `<h1>` (C.13) | La página de mayor tráfico esperado del sitio (`/explorar`, destino del 301 desde `/`) no declara ningún encabezado principal. | El H1 es la señal on-page más básica de "de qué trata esta página" para un crawler. Sin él, Google tiene que inferir el tema completo desde texto suelto — en la página con más autoridad de dominio interno (la que recibe el enlace desde `/`). | **Crítico** |
| 2 | `descubre.php` sin ningún `<h1>` (C.13) | Igual que el punto 1, en una página secundaria de descubrimiento. | Mismo problema, en una página de menor prioridad relativa — pero sigue siendo un fundamento roto. | **Alto** |
| 3 | `ver_apunte.php` con 2 `<h1>` idénticos en el DOM (C.13, líneas 511/671) | Un bloque móvil y uno desktop, cada uno con su propio `<h1>` igual, ocultos entre sí por CSS pero ambos presentes en el HTML servido. | Un crawler no ejecuta el CSS de la misma forma que un navegador para decidir "cuál H1 es el real" — ver 2 H1 iguales no rompe la página, pero es una señal de estructura semántica descuidada y es trivial de arreglar sin tocar el diseño visual. | **Alto** |
| 4 | `detalle_servicio.php` y `ver_apunte.php` no usan `nubira_seo_meta()` (C.10) | Cada uno arma su propio `<title>`/`<meta description>` con lógica inline duplicada, en vez de reusar el helper central. | **No es un problema de SEO en sí** — ambos SÍ generan title/description dinámicos y razonables. Es un problema de mantenibilidad: 2 lugares donde un bug de truncado futuro puede aparecer sin que el otro se entere. Bajo esfuerzo, vale ordenarlo. | **Medio** |
| 5 | `NO EXISTE` JSON-LD en `vitrina.php`, `vitrina_apuntes.php`, `clases_servicios.php`, `perfil.php`, `descubre.php` (C.11) | 5 templates públicos sin ningún schema.org, más allá del `Organization` sitewide de `head_common.php`. | `vitrina.php` y `perfil.php` son las 2 ausencias que más importan: la home sin `WebSite`/`Organization` reforzado, y los perfiles de tutor sin `ProfilePage`/`Person` (pierden la chance de rich snippets de perfil). | **Medio** |
| 6 | `NO EXISTE` `BreadcrumbList` JSON-LD en `landing_categoria.php`, pese a que el breadcrumb visual (`<nav aria-label="Breadcrumb">`) ya existe en el HTML (línea 193-199, hallazgo nuevo de esta pasada) | El breadcrumb se ve, pero no está marcado como dato estructurado. | Es el quick win más barato de todo este plan: el HTML ya tiene toda la información (Inicio / Clases / {categoría}), solo falta envolverla en JSON-LD. Habilita breadcrumbs enriquecidos en resultados de búsqueda. | **Alto** (por relación esfuerzo/impacto, no por gravedad) |
| 7 | Atributo `alt` ausente en `render_card.php:134-136` (C.14) | Las tarjetas que arma el fallback de recomendaciones IA no describen la imagen. | Afecta a Google Images y accesibilidad, pero solo en el camino de fallback (no el listado principal, que sí tiene `alt`). | **Bajo** |
| 8 | Tailwind CDN sin purgar, en 114 archivos (D.18) | Cada carga de página recompila las clases utilitarias en el navegador (JIT), en vez de servir un CSS ya generado y purgado. | Afecta Core Web Vitals (peso de JS/CSS que se descarga y ejecuta en cada visita, posible parpadeo de estilos) — factor de ranking real, aunque secundario frente a contenido/estructura. | **Medio** |
| 9 | N+1 HTTP real en el fallback de recomendaciones IA (D.16, `vitrina.php:2131-2133`) | N peticiones fetch separadas (una por tarjeta) en el camino de respaldo cuando el método principal falla. | Solo se activa cuando falla el camino optimizado — impacto en Core Web Vitals limitado a ese escenario de fallback, no al flujo normal. | **Bajo** |
| 10 | `servicios.institucion` vacía en ≈91% de los servicios activos de producción (B.7) | El dato que alimentaría cualquier landing por universidad, o que enriquecería el `Service` JSON-LD (`areaServed`) con la institución real en vez del genérico `'Chile'` fijo, no existe en la gran mayoría de las filas. | Bloquea por completo cualquier pSEO por universidad (ver Parte 5) y limita la especificidad del schema.org ya existente en `detalle_servicio.php`. Causa raíz identificada en esta pasada: `app/publicar_servicio.php:61` — `$institucion = $_SESSION['institucion'] ?? ''` se lee de la sesión al momento de publicar, sin fallback ni validación; si la sesión no la trae, el servicio queda con institución vacía para siempre (no hay recálculo posterior). | **Crítico** (para cualquier estrategia de universidad), **Bajo** (para el resto del sitio, que no depende de este dato) |
| 11 | Categorías duplicadas `"Otros"` / `"Otro"` en producción (B.7: 5 y 4 servicios respectivamente) | `servicios.categoria` es `varchar(40)` libre, sin `ENUM` ni tabla de referencia — nada impide 2 strings casi idénticos coexistiendo. | Divide el volumen de la categoría "genérica" en 2 mitades más chicas, y es exactamente el tipo de inconsistencia que puede confundir cualquier landing agregada por categoría. | **Alto** |
| 12 | Discrepancia entre el umbral técnico ya codificado (`≥3` servicios, en `landing_categoria.php:120` y `sitemap.php`) y la decisión de negocio del usuario (solo Matemáticas indexable hoy) — **hallazgo nuevo de esta pasada, ya anticipado en la nota 4 del inventario** | El código, tal como está hoy, no distingue "esta categoría tiene volumen técnico (≥3)" de "esta categoría está aprobada para indexar por decisión de producto". Con los números reales de B.7, 8 de 9 categorías con datos ya pasan el filtro de `≥3` — si no se agrega un interruptor explícito, cualquier ajuste futuro de datos podría indexar categorías no aprobadas sin que nadie lo decida activamente. | **Crítico** para no violar la decisión de negocio ya tomada — hay que resolverlo antes de tocar cualquier categoría. Ver Fase 2 en Parte 4. | **Crítico** |
| 13 | Tabla `seo_categorias_contenido` ya tiene 6 filas cargadas (Cálculo, Inglés, Tesis, Física, Química, Biología — **dato nuevo de esta pasada**), pero **Cálculo, Inglés y Tesis no aparecen en absoluto en los datos reales de servicios activos de B.7** (0 servicios en esas 3 categorías) | Hay contenido editorial curado (`titulo_h1`, `parrafo_intro`) para categorías que hoy no tienen ningún servicio real que mostrar. | Si esas 3 landings llegaran a ser accesibles sin el filtro `$total < 3` (por ejemplo, por un bug futuro, o si alguien las agrega manualmente al allowlist sin revisar volumen), servirían página con contenido pero **0 resultados** — el patrón exacto de thin content que la restricción de diseño #5 prohíbe. Hoy están protegidas por el `noindex` automático de `landing_categoria.php:120`, así que no es un problema activo, pero es una trampa latente si se toca el interruptor sin revisar volumen real primero. | **Medio** (mitigado hoy, pero merece quedar explícito) |
| 14 | `NO EXISTE` infraestructura de blog/contenido editorial en ningún archivo del proyecto (confirmado con grep en `.htaccess` y búsqueda de archivos `*blog*` — 0 resultados) | No hay tabla, ruta ni template para contenido tipo guía/artículo. | Es la pieza que más le falta al proyecto para SEO de intención informacional (searches tipo "cómo prepararse para PAES matemática") — hoy todo el sitio es transaccional (servicio/apunte), sin nada que capture ese tráfico de la parte alta del funnel. | **Alto** (oportunidad, no un defecto existente) |
| 15 | Perfiles de tutor (`/perfil/{hash}`) no aparecen en ninguna sección de `sitemap.php` — **hallazgo nuevo de esta pasada** (releído `sitemap.php` completo: solo tiene secciones A/B/C/E, sin sección de perfiles) | Las páginas de perfil de tutor no están en el sitemap, aunque sí son rastreables vía enlaces internos desde servicios/apuntes. | Impacto acotado — Google puede descubrirlas igual siguiendo enlaces — pero incluirlas en el sitemap es una señal adicional de que son páginas que vale la pena indexar, sin costo real. | **Bajo** |
| 16 | `index.php` (raíz) hace un redirect 302 a `/explorar`, mientras `.htaccess` ya intercepta la misma ruta con un 301 antes de que `index.php` se ejecute (A.2) | Código muerto en la práctica, pero confuso de leer. | Sin impacto real medible (el 301 de `.htaccess` gana siempre) — se menciona por completitud, no amerita acción prioritaria. | **Bajo** |

---

# Parte 2 — Arquitectura de URLs propuesta

Regla general aplicada en todo este plan, por la restricción de diseño #1: **ninguna URL existente cambia**. Todo lo nuevo se agrega como rutas adicionales en `.htaccess`, sin tocar las reglas ya activas para `/servicios/*`.

| URL | Estado | Archivo destino | Notas |
|---|---|---|---|
| `/explorar` | Existente, sin cambios | `app/vitrina.php` | Se le agrega H1 (Fase 0) y JSON-LD opcional (Fase 4) — mismo archivo, sin tocar la URL. |
| `/servicios/{slug}-{id}` | Existente, **NO TOCAR** | `app/detalle_servicio.php` | Restricción de diseño #1. |
| `/apunte/{hash}` | Existente, **NO TOCAR por ahora** | `app/ver_apunte.php` | Ver Parte 5 — evaluado y explícitamente diferido, con plan de redirects sketched ahí por si se retoma. |
| `/clases/{categoria}` | Existente, **ya construida**, uso restringido por decisión de negocio | `app/landing_categoria.php` | Hoy vive en el código para las 16 categorías del mapa (`nubira_categorias_seo()`), pero solo debe **indexarse** para las categorías en el allowlist (Fase 2): Matemáticas ahora, Química/Biología/Idiomas después. El resto sigue existiendo (no se borra la ruta) pero con `noindex`. |
| `/apuntes/{categoria}` | Existente, mismo mecanismo que arriba | `app/landing_categoria.php` (tipo=apuntes) | Mismo criterio de allowlist. Ojo: el comentario del propio archivo dice "apuntes diferido hasta recategorizar" (línea 3) — confirmar con el usuario si ese diferimiento sigue vigente antes de habilitar cualquier landing de apuntes. |
| `/clases` (hub) | **NUEVO** | `app/landing_categorias_hub.php` (nuevo archivo) | Página que lista únicamente las categorías con `indexable=1` (ver Parte 3). No tiene sentido crearla hasta la Fase 3 (2+ categorías habilitadas) — antes de eso, un hub de 1 sola categoría no aporta nada. |
| `/carrera/{slug}` | **BLOQUEADO** | — | No se genera ninguna ruta. Ver Parte 5 — ni siquiera se perfiló el volumen de `alumnos.carrera` todavía. |
| `/universidad/{slug}` | **BLOQUEADO** | — | Explícitamente vetado por decisión ya tomada por el usuario (condición de desbloqueo: ≥5 servicios con `institucion` normalizada en ≥3 universidades). |
| `/guias` (blog, hub) | **NUEVO** | `app/guias.php` (nuevo archivo) | Listado de posts publicados, paginado. |
| `/guias/{slug}` (blog, post) | **NUEVO** | `app/guia_post.php` (nuevo archivo) | Un post individual (pilar o cluster). |

Reglas `.htaccess` nuevas propuestas (aditivas, no reemplazan ninguna existente):
```apache
RewriteRule ^clases/?$                     app/landing_categorias_hub.php   [L,QSA]
RewriteRule ^guias/?$                      app/guias.php                    [L,QSA]
RewriteRule ^guias/([a-z0-9\-]+)/?$        app/guia_post.php?slug=$1        [L,QSA]
```

---

# Parte 3 — Especificación técnica por área

## Metadata automática — fórmulas exactas

**Servicios y apuntes (existentes, sin cambios de fondo — Fase 4 solo migra al helper, no cambia el resultado)**:
- `detalle_servicio.php` (ya en código, `detalle_servicio.php:276-293`): `title = "{titulo}{' (PAES)' si es_paes} en {institución||'Chile'} | Nubira"`, truncado a 65 chars; `description = "{Modalidad} de {categoría} con {primer_nombre_tutor}. {primeros 100 chars de descripción}. Contrata en Nubira.{' (Preparación PAES)' si aplica}"`, truncado a 155 chars.
- `ver_apunte.php` (ya en código, `ver_apunte.php:361-362`): `title = "{titulo} | Nubira"`; `description = primeros 155 chars de la descripción del apunte`.

**Landing de categoría (existente, `landing_categoria.php:125-128`, sin cambios de fórmula)**:
- `title = "{Clases|Apuntes} de {categoría} universidad Chile | Nubira"`.
- `description` = override manual desde `seo_categorias_contenido.meta_description` si existe, si no: `"Encuentra {clases particulares y tutorías|apuntes y resúmenes} de {categoría} en universidades chilenas (PUC, USACH, U. de Chile, UNAB y más). Pago protegido con Garantía Nubira."`.
- `h1` = override manual desde `seo_categorias_contenido.titulo_h1`, si no: `"{Clases|Apuntes} de {categoría} en Chile"`.

**Guías Nubira (nuevo)**:
- `title = "{titulo_post} | Guías Nubira"` (máx 65 chars, mismo criterio de truncado que servicios).
- `description` = campo manual `resumen` (máx 155 chars, cargado por quien escribe el post — no autogenerado, ya que es contenido editorial).
- `canonical = nubira_canonical_tag("/guias/{slug}")` — reusa el helper existente tal cual.
- `og:type = article`, con `article:published_time` y `article:author` adicionales (no existen hoy en ningún template, se agregan solo acá).

## schema.org — qué tipo en qué página

| Página | Tipo(s) | Estado |
|---|---|---|
| Todas (vía `head_common.php`) | `Organization` | Existente, sin cambios. |
| `detalle_servicio.php` | `Service` + `Offer` + `AggregateRating` (condicional) | Existente, sin cambios. Mejora opcional de bajo riesgo: si `institucion` no está vacía, usar su valor real en `areaServed` en vez del `'Chile'` fijo actual (línea 380 del inventario original) — depende de la Fase 1 (higiene de datos) para tener valores reales que usar. |
| `ver_apunte.php` | `LearningResource` | Existente, sin cambios. |
| `landing_categoria.php` | `FAQPage` (condicional, hoy solo Tesis) | Existente. **Nuevo en este plan**: agregar `BreadcrumbList` (hallazgo #6 de la Parte 1) — dato ya disponible en el propio archivo (`$tipo_palabra`, `$categoria`), solo falta el bloque JSON-LD. |
| `vitrina.php` | `NO EXISTE` hoy | **Nuevo, opcional, Fase 4**: `WebSite` con `SearchAction` (habilita sitelinks searchbox si Google lo adopta) — bajo riesgo, bajo esfuerzo, resultado no garantizado (depende de Google). |
| `perfil.php` | `NO EXISTE` hoy | **Nuevo, opcional, Fase 4**: `ProfilePage` envolviendo un `Person`, con `aggregateRating` si el tutor tiene calificación — mismo patrón que ya usa `detalle_servicio.php` para el rating condicional. |
| `guia_post.php` | **Nuevo** | `Article` (o `BlogPosting`), con `headline`, `datePublished`, `author` (fijo `"Equipo Nubira"` salvo que se defina autoría real), `mainEntityOfPage`. |
| `guias.php` (hub) | **Nuevo, opcional** | `CollectionPage` o simplemente omitir — bajo impacto, no priorizar. |

## Sitemaps

- **Mantener un único `sitemap.php` dinámico** (no partir en sitemap-index) — el propio archivo ya tiene el `TODO` correcto de partir "si supera 45.000 URLs"; con ~90 URLs reales hoy (80 servicios + apuntes + 8 estáticas + landings), está lejísimos de ese umbral. No se justifica el esfuerzo ahora.
- **Nueva sección — perfiles de tutor** (hallazgo #15): agregar al `sitemap.php` una sección que liste `/perfil/{hash}` de tutores con al menos 1 servicio activo (mismo filtro de visibilidad que ya usa la Sección B). Bajo esfuerzo, 1 bloque de código adicional en el mismo patrón que las secciones existentes.
- **Nueva sección — Guías** (cuando exista el blog): listar posts con `estado='publicado'`.
- **Sección E (landings de categoría) — cambiar el filtro**: hoy es `COUNT(*) >= 3`; debe pasar a `COUNT(*) >= 3 AND categoria.indexable = 1` (ver tabla nueva abajo) para respetar el allowlist de negocio, no solo el umbral técnico.
- **Caché de archivo, reutilizando el patrón ya existente de `cache_ia/`**: el propio `sitemap.php` tiene un `TODO` ("cachear 6h si la BD crece") sin implementar. Con el volumen actual no es urgente, pero es un cambio de muy bajo riesgo (leer/escribir 1 archivo XML en disco con un TTL, exactamente como ya hace `cache_ia/*.json`) — se puede adelantar en la misma fase que se toque el archivo por otro motivo, para no tener que volver a abrirlo después. **Regeneración**: no requiere un cron nuevo — puede invalidarse simplemente comparando `filemtime()` del archivo de caché contra el TTL en cada request (mismo patrón de `cache_ia/`), sin depender de ningún cron de Hostinger.

## `robots.txt` propuesto

```
User-agent: *
Allow: /
Disallow: /admin
Disallow: /bandeja-entrada
Disallow: /mini-aula
Disallow: /app/
Sitemap: https://nubira.cl/sitemap.xml
```
Único cambio: se agrega `Disallow: /app/`. Justificación: todas las rutas públicas reales pasan por URLs "bonitas" (`/explorar`, `/servicios/...`, etc.) que internamente apuntan a archivos dentro de `/app/`, pero **nada impide hoy que alguien acceda directo a `/app/vitrina.php`** y genere una URL alternativa con el mismo contenido que `/explorar` (contenido duplicado potencial, aunque mitigado por el `canonical` ya presente en esas páginas). Bloquear `/app/` en `robots.txt` es defensa adicional de bajo costo — no rompe nada porque ninguna URL pública real vive ahí directamente (todas pasan por las reglas de `.htaccess`).

## Enlazado interno automático — reglas de query concretas

1. **Servicio → landing de su categoría** (nuevo, 1 línea de link en `detalle_servicio.php`): mostrar `"Ver más clases de {categoría}"` → `/clases/{slug}`, **solo si esa categoría está en el allowlist `indexable=1`** (si no, omitir el link — no tiene sentido enlazar a una página en `noindex`).
2. **Landing de categoría → Guía relacionada** (nuevo, cuando exista el blog): en `landing_categoria.php`, query simple:
   ```sql
   SELECT slug, titulo FROM guias_posts
   WHERE categoria = ? AND estado = 'publicado' AND tipo_pilar = 'pilar'
   ORDER BY fecha_publicacion DESC LIMIT 1
   ```
   Si hay resultado, mostrar una caja "Guía relacionada: {titulo}" enlazando al post.
3. **Guía (post) → servicios reales de esa categoría** (nuevo, obligatorio por la restricción de no-thin-content): cada post debe mostrar 3-4 tarjetas reales de servicios de su categoría, reusando el mismo query pattern que ya usa `landing_categoria.php` (`WHERE s.categoria = ? AND estado='aprobado' AND visible=1 ...`, vía `render_card_servicio_grid()`, ya existente — no se crea un renderizador nuevo). Si no hay servicios reales de esa categoría, el post debe enlazar genéricamente a `/explorar` en vez de mostrar tarjetas vacías.
4. **Cluster → Pilar** (nuevo, relación explícita, no automática por texto): cada post de tipo `cluster` guarda `pilar_id` apuntando a su post pilar; el template muestra siempre un link fijo "Parte de la guía: {título del pilar}" — evita depender de coincidencias de texto para armar la jerarquía temática.

## Tablas nuevas de BD — todas aditivas, ninguna altera una tabla existente destructivamente

**1. `guias_posts` (nueva)**:
```sql
CREATE TABLE guias_posts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(180) NOT NULL UNIQUE,
  titulo VARCHAR(200) NOT NULL,
  resumen VARCHAR(160) NOT NULL,           -- meta description manual
  cuerpo LONGTEXT NOT NULL,                -- HTML controlado, mismo criterio de sanitización que avisos_admin (BBCode limitado) a definir en fase de implementación
  categoria VARCHAR(40) NULL,              -- match contra nubira_categorias_seo(), nullable si el post no aplica a 1 sola categoría
  tipo_pilar ENUM('pilar','cluster') NOT NULL DEFAULT 'cluster',
  pilar_id INT NULL,                       -- self-reference: cluster → su pilar
  autor VARCHAR(100) NOT NULL DEFAULT 'Equipo Nubira',
  imagen_portada VARCHAR(255) NULL,
  estado ENUM('borrador','publicado') NOT NULL DEFAULT 'borrador',
  fecha_publicacion DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (pilar_id) REFERENCES guias_posts(id)
);
```

**2. Extender `seo_categorias_contenido` (ya existe, 6 filas) — agregar 1 columna, no romper nada**:
```sql
ALTER TABLE seo_categorias_contenido
  ADD COLUMN indexable TINYINT(1) NOT NULL DEFAULT 0 AFTER filtro_titulo;
```
Es el interruptor explícito que resuelve el hallazgo #12 de la Parte 1 — separa "tiene volumen técnico" (`COUNT >= 3`, ya en código) de "está aprobado para indexar" (decisión de negocio). Para Matemáticas: `UPDATE seo_categorias_contenido SET indexable=1 WHERE categoria='Matemáticas'` (requiere primero un `INSERT` para Matemáticas, ya que hoy esa categoría **no tiene fila** en `seo_categorias_contenido` — las 6 filas existentes son Cálculo/Inglés/Tesis/Física/Química/Biología, ninguna es Matemáticas).

**3. `seo_categorias_faq` (nueva, opcional, solo si se decide generalizar el FAQ más allá de Tesis)**:
```sql
CREATE TABLE seo_categorias_faq (
  id INT AUTO_INCREMENT PRIMARY KEY,
  categoria_contenido_id INT NOT NULL,
  pregunta VARCHAR(200) NOT NULL,
  respuesta TEXT NOT NULL,
  orden INT NOT NULL DEFAULT 0,
  FOREIGN KEY (categoria_contenido_id) REFERENCES seo_categorias_contenido(id)
);
```
No priorizada en el roadmap (Parte 4) — ver Parte 5.

---

# Parte 4 — Roadmap por fases

Ordenado por ROI: alto impacto / bajo esfuerzo primero.

## Fase 0 — Fixes quirúrgicos (Impacto: Alto/Crítico · Esfuerzo: Bajo)
**Qué y por qué**: corregir los 3 defectos on-page más baratos de arreglar y con mayor impacto estructural (hallazgos #1, #2, #3, #6, #7 de la Parte 1).
**Archivos a modificar** (existentes, ninguno nuevo):
- `app/vitrina.php` — agregar 1 `<h1>` visualmente integrado al diseño actual (hoy no tiene ninguno que reemplazar).
- `app/descubre.php` — ídem.
- `app/ver_apunte.php` — colapsar los 2 `<h1>` (líneas 511 y 671) a 1 solo, usando CSS (`order`/posicionamiento) para que el único `<h1>` real se muestre en el lugar correcto según el viewport, en vez de duplicar la etiqueta semántica.
- `app/componentes/render_card.php` — agregar `alt="{titulo escapado}"` a la imagen de portada (línea 134-136), mismo criterio que ya usa `cargar_servicios.php`.
- `app/landing_categoria.php` — agregar bloque `BreadcrumbList` JSON-LD (dato ya disponible en el archivo, solo falta emitirlo).
- `robots.txt` — agregar `Disallow: /app/`.
**Dependencias**: ninguna.
**Riesgos y efectos secundarios**: mínimos — son cambios aislados de HTML/JSON-LD, no tocan lógica de negocio ni URLs. Único cuidado real: al colapsar los 2 H1 de `ver_apunte.php`, verificar visualmente en ambos breakpoints (móvil/desktop) que el texto se sigue viendo en el lugar correcto — es un cambio de CSS, no de contenido.
**Impacto SEO esperado**: Alto (el H1 de `vitrina.php` en particular).
**Esfuerzo**: Bajo.
**Tiempo estimado**: 1 sesión de trabajo.

## Fase 1 — Higiene de datos (Impacto: Crítico, como bloqueante de otras fases · Esfuerzo: Medio, con incertidumbre)
**Qué y por qué**: ya exigida explícitamente por el usuario. Resolver por qué `institucion` llega vacía, normalizar los 7 valores sucios existentes, y unificar `"Otro"`/`"Otros"`.
**Archivos a modificar**: no se pueden listar con certeza total todavía — el diagnóstico de esta pasada llegó hasta `app/publicar_servicio.php:61` (`$institucion = $_SESSION['institucion'] ?? ''`), pero falta rastrear **de dónde sale ese valor de sesión** (candidatos: `login.php`, `register.php` — no auditados línea por línea en esta pasada) antes de decidir si el fix va en el flujo de login, en el de publicación, o en ambos. Esto requiere una sesión de investigación dedicada (solo lectura) antes de tocar nada.
- Normalización de los 7 valores existentes (USM, USACH, PUCV, IACC×2, UCSC, UAI): **no requiere script** — son 7 filas, se puede hacer con `UPDATE` manual vía phpMyAdmin, usando `abreviar_institucion()` (`app/helpers/institucion.php`, ya existente) solo como referencia de criterio, no como código a ejecutar en masa.
- Unificación `"Otro"`/`"Otros"`: revisar cuál es el valor que usa el formulario de publicación por defecto (`servicios.categoria` tiene default `'Otro'` a nivel de columna, confirmado en el schema) vs. cuál usa `nubira_categorias_seo()` como convención (que explícitamente excluye "Otros" de su mapa, según el comentario del propio helper) — decidir un único valor y correr 1 `UPDATE` de consolidación.
**Dependencias**: bloquea cualquier landing por universidad o carrera (ya definido así por el usuario). **No bloquea** la Fase 2 (Matemáticas no depende de este dato).
**Riesgos**: el riesgo real está en tocar `publicar_servicio.php` — es el flujo de creación de ingresos de la plataforma, cualquier cambio ahí debe probarse a fondo antes de subir a producción (deploy manual, sin rollback automático).
**Impacto SEO esperado**: Bajo-inmediato (no genera tráfico por sí solo), Alto-habilitante (desbloquea todo lo de universidad a futuro).
**Esfuerzo**: Medio (la parte de investigación es la incierta; los `UPDATE` en sí son triviales).
**Tiempo estimado**: 1 sesión de diagnóstico + 1 sesión de fix, una vez que el diagnóstico determine el archivo exacto a tocar.

**Hallazgo de esta sesión — el backfill automático NO resuelve la mayoría del problema, y no se debe asumir que sí**: se ejecutó el diagnóstico real (BD local) de cuántos registros con `institucion` vacía se pueden completar automáticamente usando `alumnos.universidad` como fuente:

| | Servicios | Apuntes |
|---|---|---|
| Con `institucion` vacía | 35 | 43 |
| Completables vía `alumnos.universidad` | 3 (≈9%) | 0 (0%) |
| **Quedan vacíos sin ninguna solución automática** | **32** | **43** |

**La gran mayoría de los registros con `institucion` vacía no tienen el dato en NINGÚN lugar del sistema** — ni en `servicios`/`apuntes.institucion`, ni en `alumnos.universidad` (porque el tutor nunca pasó por `completar_perfil.php`/`editar_datos.php`, los únicos 2 formularios que escriben esa columna). No hay ningún backfill automático posible para esos casos — el dato simplemente no fue capturado nunca.

Esto queda **documentado como hallazgo, sin resolver aquí** — la decisión de cómo cerrar ese ~85-100% restante (ej. pedir la institución como campo obligatorio al publicar un servicio de ahora en más, o una campaña dirigida a tutores existentes para que completen su perfil) queda pendiente de que el usuario la tome en otra sesión. El backfill vía `alumnos.universidad` (script en `sql/pendientes/fase1_backfill_institucion_servicios.sql`, generado con los 3 casos rescatables de BD local, pendiente de adaptar a los IDs reales de producción) es un complemento menor, no una solución del problema de fondo.

## Fase 2 — Habilitar Matemáticas + interruptor `indexable` (Impacto: Alto · Esfuerzo: Bajo-Medio)
**Qué y por qué**: resolver el hallazgo #12 (discrepancia entre umbral técnico y decisión de negocio) y lanzar la única landing de categoría aprobada hoy.
**Archivos a crear**: ninguno.
**Archivos a modificar**:
- BD: `ALTER TABLE seo_categorias_contenido ADD COLUMN indexable ...` + `INSERT`/`UPDATE` para la fila de Matemáticas.
- `app/landing_categoria.php` — el `SELECT` que ya trae `seo_categorias_contenido` (líneas 27-47) debe leer también `indexable`; el cálculo de `$noindex` (línea 120) pasa de `($total < 3)` a `($total < 3 || !$indexable)`.
- `app/sitemap.php` (Sección E) — el filtro de conteo por categoría debe cruzar contra `seo_categorias_contenido.indexable = 1`, no solo `COUNT >= 3`.
- `app/detalle_servicio.php` — agregar el link interno "Ver más clases de Matemáticas" (regla #1 de enlazado interno, Parte 3), condicionado a que la categoría del servicio esté marcada `indexable`.
**Dependencias**: idealmente después de Fase 0 (no estricta). Independiente de Fase 1.
**Riesgos**: bajo — los cambios son aditivos y acotados a 3 archivos + 1 `ALTER TABLE` no destructivo. El único riesgo real es de negocio, no técnico: verificar que Matemáticas efectivamente tenga contenido de calidad suficiente (30 servicios reales) antes de indexar.
**Impacto SEO esperado**: Alto (primera pieza de pSEO real del sitio, con volumen genuino detrás).
**Esfuerzo**: Bajo-Medio.
**Tiempo estimado**: 1 sesión.

## Fase 3 — Segunda ola: Química, Biología, Idiomas (Impacto: Medio · Esfuerzo: Muy bajo)
**Qué y por qué**: repetir mecánicamente la Fase 2 para las 3 categorías siguientes por volumen (7 servicios cada una), una vez validado el comportamiento de Matemáticas.
**Archivos a modificar**: ninguno nuevo — solo `UPDATE seo_categorias_contenido SET indexable=1 WHERE categoria IN (...)` (Química y Biología ya tienen fila cargada; falta crear la de Idiomas).
**Dependencias**: depende de Fase 2 (mismo mecanismo). El usuario debe decidir el criterio de "cuándo" (ej. cuando Matemáticas muestre impresiones/clics estables en Search Console, o transcurrido un período fijo — no hay un número correcto objetivamente, es una decisión de producto a tomar en su momento, no algo que este plan deba fijar de antemano).
**Riesgos**: mínimos, mismo mecanismo ya probado en Fase 2.
**Impacto SEO esperado**: Medio (categorías más chicas que Matemáticas).
**Esfuerzo**: Muy bajo.
**Tiempo estimado**: menos de 1 sesión.

## Fase 4 — Consolidación de metadata y schema opcional (Impacto: Medio · Esfuerzo: Bajo)
**Qué y por qué**: resolver el hallazgo #4 (DRY de title/description) y agregar schema opcional a `vitrina.php`/`perfil.php`.
**Archivos a modificar**: `detalle_servicio.php`, `ver_apunte.php` (migrar a `nubira_seo_meta()`, mismo resultado visual, sin cambiar URLs ni contenido); `vitrina.php` (agregar `WebSite`+`SearchAction`); `perfil.php` (agregar `ProfilePage`/`Person`).
**Dependencias**: ninguna dura. Puede hacerse en paralelo a las fases 2-3.
**Riesgos**: bajo — es refactor de presentación, no de lógica de negocio. Único cuidado: confirmar que el truncado de 65/155 caracteres se preserva igual al migrar `detalle_servicio.php`/`ver_apunte.php` al helper (el helper no trunca por sí solo, hay que pasarle el string ya truncado).
**Impacto SEO esperado**: Medio.
**Esfuerzo**: Bajo.
**Tiempo estimado**: 1 sesión.

## Fase 5 — Guías Nubira (blog): infraestructura (Impacto: Alto a mediano plazo · Esfuerzo: Alto)
**Qué y por qué**: construir la pieza que hoy no existe en absoluto para capturar intención informacional.
**Archivos a crear**: `app/guias.php` (hub/listado), `app/guia_post.php` (detalle), y la tabla `guias_posts` (Parte 3).
**Archivos a modificar**: `.htaccess` (2 reglas nuevas, Parte 2), `app/sitemap.php` (nueva sección), `app/landing_categoria.php` (regla de enlazado #2 de Parte 3).
**Dependencias**: tiene más sentido **después** de Fase 2 — sin al menos 1 landing de categoría indexable, un post de blog no tiene a dónde enlazar con "jugo" de indexación real.
**Riesgos**: es la fase de mayor superficie nueva del plan — nuevas rutas, nueva tabla, nuevos templates. Mitigado por ser 100% aditivo (no toca ningún archivo del flujo transaccional existente). Riesgo de contenido: el HTML de `cuerpo` necesita algún criterio de sanitización antes de mostrarse (mismo tipo de cuidado que ya existe en `avisos_admin`/BBCode) — a definir en el momento de implementar, no en este plan.
**Impacto SEO esperado**: Alto, pero **a mediano plazo** — el contenido editorial tarda en posicionar, y depende 100% de que alguien (el usuario, no Claude Code de forma autónoma) escriba contenido real y de calidad.
**Esfuerzo**: Alto.
**Tiempo estimado**: 2-3 sesiones para la infraestructura (sin contar el tiempo de redacción de contenido, que es responsabilidad editorial separada).

## Fase 5b — Guías Nubira: primeros posts (Impacto: depende del contenido · Esfuerzo: Alto, editorial)
**Qué y por qué**: escribir 2-3 posts pilares reales, empezando por el tema con más volumen de respaldo (Matemáticas/PAES, dado que es la única categoría habilitada).
**Archivos**: ninguno de código — es contenido, cargado vía inserts a `guias_posts` (o un panel admin simple, no incluido en el alcance técnico de este plan salvo que se pida aparte).
**Dependencias**: Fase 5 (infraestructura) + Fase 2 (para poder enlazar a una landing real).
**Riesgos**: ninguno técnico. El riesgo es de tiempo/calidad editorial, fuera del control de este plan.
**Impacto SEO esperado**: variable, depende de la calidad y originalidad del contenido.
**Esfuerzo**: Alto (editorial, no de código).
**Tiempo estimado**: no estimable desde este plan — depende de disponibilidad del founder para escribir o revisar contenido.

## Fase 6 (diferida, opcional) — Purga de Tailwind
Ver Parte 5 — no forma parte del roadmap activo por ahora, se documenta como consideración futura.

---

# Parte 5 — Qué NO hacer todavía

| Idea | Por qué sería prematuro ahora | Condición de desbloqueo |
|---|---|---|
| **Landings por universidad** | Ya bloqueado por decisión explícita del usuario. Datos reales (B.7): 7 servicios con institución llena, en 6 valores distintos, ninguno con más de 1 servicio. | ≥5 servicios activos con `institucion` normalizada en al menos 3 universidades (umbral ya fijado por el usuario). |
| **Landings por carrera** | A diferencia de `institucion`, **nunca se perfiló el volumen real de `alumnos.carrera`** en esta auditoría — no hay ni siquiera el dato para decidir si está bloqueado o viable. Además, `carrera` vive en `alumnos` (el tutor), no en `servicios` — no está claro que sea la dimensión correcta para una landing de "materia", ya que un tutor puede enseñar algo distinto a su propia carrera. Esto es una pregunta de producto además de una de datos. | Perfilar `alumnos.carrera` con la misma metodología que se usó para `institucion` en B.7, y decidir primero si "carrera del tutor" es realmente la señal correcta para este tipo de landing. |
| **Matriz combinada materia × universidad** | Requiere volumen suficiente en ambas dimensiones simultáneamente — más restrictivo aún que cualquiera de las 2 por separado. Hoy, 0 celdas tienen más de 1 servicio. | Ambas condiciones de arriba cumplidas a la vez, y además ≥1 combinación específica con volumen real (no solo el total por dimensión). |
| **Landings de categorías fuera del allowlist** (Otros/Otro, Asesoría, Historia, Física, Economía, Diseño, y las que ya tienen contenido curado sin servicios: Cálculo, Inglés, Tesis) | Ya protegidas por el `noindex` automático (`$total < 3`) para las de bajo volumen, pero **Cálculo, Inglés y Tesis tienen contenido curado en `seo_categorias_contenido` con 0 servicios reales detrás** — el riesgo de thin content si algún día se activan sin revisar volumen primero es real y ya quedó documentado (hallazgo #13). | Cada una individualmente, cuando alcance ≥3 servicios reales Y se agregue explícitamente al allowlist `indexable=1` (nunca automático). |
| **Sitemap-index / partición del sitemap** | El propio código ya tiene el `TODO` correcto ("si supera 45.000 URLs") — hoy el sitio tiene del orden de 90 URLs indexables totales. Construir la partición ahora sería trabajo sin ningún beneficio medible. | Acercarse al umbral de 45.000 URLs (o a un fragmento razonable de él, ej. 5.000-10.000, para tener margen de anticipación). |
| **Migrar `/apunte/{hash}` a slugs legibles** | Restricción de diseño #2 exige, si se propone, un plan completo de redirects y una explicación del riesgo — se cumple acá, mostrando por qué **no** se prioriza: no hay evidencia de que el hash Base64 esté limitando el ranking de apuntes hoy (Google indexa URLs no legibles sin problema; el slug es un factor de ranking menor, no bloqueante), mientras que el riesgo de una migración mal ejecutada (pérdida temporal de señales de indexación acumuladas) es real y no está compensado por un beneficio medido. **Si se retomara en el futuro**, el plan mínimo sería: (a) agregar columna `slug` a `apuntes` (aditivo); (b) generar slugs para el histórico completo; (c) nueva regla `.htaccess` `^apunte/([a-z0-9\-]+)-(\d+)/?$` que conviva con la regla actual de hash; (d) una vez estable, agregar 301 desde `/apunte/{hash}` hacia `/apunte/{slug}-{id}` (usando `nubira_desencriptar_id()`, ya existente, para resolver el id desde el hash viejo); (e) actualizar `sitemap.php` Sección C para emitir las URLs nuevas. | Evidencia real (Search Console) de que las URLs actuales están limitando el CTR o el ranking de apuntes específicamente — no una intuición de que "se ve mejor". |
| **Purgar/compilar Tailwind fuera de CDN** | Impacto real en Core Web Vitals, pero esfuerzo relativamente alto para un equipo de una persona (requiere build local con Node, luego subir el CSS resultante por FTP — viable en Hostinger porque el build no corre en el servidor, solo el archivo final, pero requiere disciplina de proceso para no desincronizar clases nuevas del HTML con el CSS ya purgado). No es de los quick wins de la Fase 0. | Cuando las fases de contenido (2-5) estén estables y no haya cambios de clases Tailwind constantes en desarrollo activo — purgar mientras el HTML cambia seguido generaría re-trabajo constante. |
| **Generalizar el FAQ (`seo_categorias_faq`) a todas las categorías** | Hoy es un array hardcoded solo para Tesis — funciona bien para 1 caso. Construir la tabla/UI de gestión antes de tener un segundo caso de uso confirmado sería infraestructura especulativa. | Cuando Matemáticas (Fase 2) esté indexada y se decida activamente que necesita FAQ propio — recién ahí vale la pena generalizar el mecanismo. |
| **Hub `/clases` (listado de todas las landings)** | Con 1 sola categoría indexable (Matemáticas, Fase 2), un hub no tiene nada que "hubear" — sería una página que lista una sola cosa. | Al menos 2 categorías con `indexable=1` simultáneas (es decir, después de la Fase 3). |
