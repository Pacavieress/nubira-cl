# Auditoría de Mini Aula Virtual — Nubira

**Fecha:** 22/07/2026
**Alcance:** `app/mini_aula.php` y todo lo que consume (endpoints, componentes, iframes). Solo lectura — sin modificaciones.
**Por qué importa:** es la pieza donde ocurre la clase pagada. Un fallo aquí no es un bug cosmético — es un servicio que el alumno pagó y no recibió, o un tutor que no puede cobrar porque el alumno no pudo "Finalizar y Pagar".
**Escenario guía:** usuario en metro/wifi inestable/cambio wifi↔datos, en iOS/Android (browser y PWA instalada) y escritorio.

---

## 1. Mapa de componentes y dependencias

```
mini_aula.php (shell, 838 líneas)
│
├── Video      → Daily.co (WebRTC gestionado) vía <script src="unpkg.com/@daily-co/daily-js"> SIN VERSIÓN FIJADA
│                 - Sala creada server-side por curl a api.daily.co en CADA carga de página (línea 169-193)
│                 - callFrame.createFrame() + .join() client-side (línea 607-641)
│
├── Chat       → iframe a app/chat_mini_aula.php (704 líneas)
│                 - Prerender server-side de mensajes (cargar_mensajes_chat_mini_aula.php)
│                 - Envío: POST a enviar_mensajes_chat_mini_aula.php (mensajes optimistas + reintento)
│                 - Polling propio adaptativo 3s→20s con guard document.hidden (líneas 640-663)
│                 - Tabla: chat_aula (no `conversaciones`/`mensajes` del chat pre-contrato)
│
├── Pizarra    → iframe embebido a EXCALIDRAW.COM (servicio externo de terceros), solo visible para el vendedor
│                 - URL con room/key generados por hash local (línea 164-167) — sin backend propio
│
├── Materiales → iframe a app/entregas_servicio.php (lista de archivos, sin polling propio)
│                 - Refresco: mini_aula.php sondea app/count_files.php cada 7s y recarga el iframe si cambia el conteo
│
├── Badge "Reunión activa" → polling a app/ping_reunion.php cada 8s (fuera de la llamada) / cada 15s (dentro, como heartbeat)
│                 - Estado guardado en un ARCHIVO PLANO por contrato: app/sala_activa_<id>.txt (usuario_id|timestamp)
│
├── Badge "Chat" (no leído) → polling a app/notificaciones_chat_mini_aula.php cada 8s
│
├── Timer de llamada → 100% client-side (variable JS `callStartTime`), sin persistencia
│
├── header_aula.php (desktop) → polling propio e independiente a contar_alertas_sistema.php cada 15s
├── sidebar.php (desktop)     → ya migrado al evento centralizado `nubira:alertas` (ver INFORME-PERFORMANCE.md)
│
└── Micro-endpoint interno: mini_aula.php?ajax_status=1 (línea 115-119) — polling propio del vendedor
    cada 5s mientras espera que el alumno confirme el fin de la clase
```

**Total de "cosas vivas" simultáneamente durante una clase activa** (peor caso: vendedor, en la sala, con chat cerrado):
`checkNewFiles` (7s) + badge chat (8s) + badge reunión (8s) + `reunionPinger` (15s, solo si en llamada) + `header_aula.php` poll (15s) + polling propio del chat si el panel está abierto (3-20s adaptativo) = **hasta 6 timers independientes corriendo en paralelo**, ninguno coordinado entre sí.

---

## 2. Resiliencia a pérdida de señal — lo más importante

### 2.1 Qué pasa exactamente cuando se corta la conexión

Recorrido de **cada** llamada de red activa en el aula:

| Origen | Qué hace si falla | Feedback al usuario |
|---|---|---|
| `checkNewFiles()` — `mini_aula.php:556-575` | `catch (e) {}` — silencioso, sin retry | Ninguno |
| Badge chat — `mini_aula.php:717-723` | `catch(e){}` — silencioso | Ninguno |
| Badge reunión — `mini_aula.php:725-739` | `catch(e){}` — silencioso | Ninguno |
| `reunionPinger` (heartbeat en llamada) — `mini_aula.php:638-640` | `.catch(()=>{})` — silencioso | Ninguno |
| `header_aula.php` poll (15s) — línea 170-173 | `.catch(e => {})` — silencioso | Ninguno |
| `ajax_status` (vendedor esperando) — `mini_aula.php:579-593` | `catch(e){}` — silencioso | Ninguno |
| Polling del chat — `chat_mini_aula.php:590-638,646-654` | `catch (e) { return false; }` — silencioso, pero **si tiene éxito** el intervalo se resetea a 3s y si falla simplemente reintenta en el próximo ciclo (backoff pasivo, no error activo) | Ninguno (no hay banner de "reconectando") |
| Envío de mensaje — `chat_mini_aula.php:522-558` | **Único caso con feedback real**: burbuja pasa a `bubble-failed` (rojo) + botón de reintentar + toast "Error de conexión" | **Sí** — el único punto de todo el aula con manejo de error visible al usuario |
| `callFrame.join()` (Daily.co) — `mini_aula.php:624-635` | `.catch(err => { alert(...); colgarLlamada(); })` | Un `alert()` nativo del navegador (bloqueante, feo, pero visible) — **solo en el intento inicial de unirse**, no durante una llamada ya en curso |
| Llamada en curso que se corta por red | **Sin manejo propio.** Depende 100% de la reconexión interna de Daily.co. Si Daily se rinde, dispara `left-meeting` → `colgarLlamada()`, que es la **misma función que se ejecuta al colgar voluntariamente** | **Ninguno específico** — para el usuario, "se cortó por mala señal" y "colgué yo" se ven exactamente igual |

**Conclusión de esta sección: no existe ningún indicador de "sin conexión / reconectando" en toda la página.** De los ~7 mecanismos de red activos durante una clase, **6 fallan en silencio absoluto** y el único que sí avisa (envío de chat) lo hace con un toast genérico, no con contexto de "perdiste la conexión".

### 2.2 Agravante: `enable_network_ui: false` en la sala de Daily.co

**Archivo:línea:** `app/mini_aula.php:181`
```php
"enable_network_ui" => false,
```
Esto **desactiva explícitamente** el indicador nativo de calidad/pérdida de red que Daily.co trae integrado en su propio iframe. Es decir: el vendor SÍ tiene un widget de "reconectando..." listo para usar, y Nubira lo apaga, sin reemplazarlo por nada propio. Combinado con que no hay ningún listener para los eventos `'network-quality-change'`, `'network-connection'`, ni `'error'`/`'nonfatal-error'` del SDK de Daily (solo están enganchados `left-meeting`, `participant-joined`, `joined-meeting`, `track-stopped` — línea 614-621), el resultado práctico es: **durante un corte de señal en plena videollamada, el usuario ve el video congelado o en negro, sin ningún mensaje, hasta que Daily decide expulsarlo o hasta que la red vuelve por sí sola.**

### 2.3 Al volver la señal: ¿se recupera solo?

- **Chat:** sí, solo. El polling adaptativo (`agendarPoll()`, línea 646-654) simplemente sigue intentando en su próximo ciclo (hasta 20s de espera en el peor caso) y al recuperar conexión trae los mensajes que faltaban vía el fetch normal — no hay pérdida de datos porque el chat es pull completo del HTML renderizado, no un delta.
- **Badges (archivos/chat/reunión):** sí, solos, mismo mecanismo de polling que simplemente retoma en el próximo tick.
- **Video (Daily.co):** **depende de si el SDK logró mantener la sesión WebRTC o si te expulsó.** Si Daily reconecta internamente (frecuente en cortes cortos, unos segundos), no hay acción del usuario. Si Daily te desconecta (`left-meeting`), **no hay reconexión automática** — `colgarLlamada()` te deja en la pantalla de "Entrar a la Sala" y debes tocar el botón de nuevo manualmente. No hay retry ni backoff propio en `iniciarClase()`.
- **Timer de la llamada:** se pierde siempre que `colgarLlamada()` se ejecuta (por desconexión o por colgar voluntario) — es una variable JS (`callStartTime`), sin backend. Al volver a entrar, el cronómetro visual arranca de 00:00 aunque la clase lleve rato.

### 2.4 Si recarga la página a mitad de clase

| Qué | Se pierde | Se mantiene |
|---|---|---|
| Estado del contrato (activo/finalizado, quién ya confirmó) | — | ✅ se recalcula fresco desde BD en cada carga |
| Mensajes de chat | — | ✅ persistidos en `chat_aula`, se re-renderizan completos |
| Materiales/archivos | — | ✅ persistidos en BD |
| **Pestaña activa** | ❌ **siempre vuelve a "Material"** | — |
| **Timer visual de la llamada** | ❌ **vuelve a 00:00** | (la sala de Daily sigue viva en su infraestructura, no se pierde la reunión en sí, solo el contador visual) |
| **Estar dentro de la videollamada** | ❌ **hay que tocar "Entrar a la Sala" otra vez** | La sala de Daily persiste (`exp` a 30 días, línea 179), así que technically reconecta al mismo room — pero no es automático |
| Panel de chat abierto/cerrado | ❌ vuelve a cerrado | — |

**Motivo de la pérdida de pestaña**: `$default_tab = $_GET['tab'] ?? 'archivos';` (línea 152) — el tab activo se maneja 100% client-side vía `switchTab()`, sin `history.pushState`/`replaceState` que actualice la URL. Un F5 en medio de la reunión te devuelve a la pestaña "Material" y te obliga a: click en "Reunión" → click en "Entrar a la Sala" → esperar a unirte de nuevo.

**Caso límite real:** si la clase se extendió más allá de `duracion_minutos` (`clase_fin_ts`) y el usuario recarga para intentar arreglar un video congelado, `video_habilitado` pasa a `false` y el botón "Entrar a la Sala" queda **deshabilitado con candado** ("Sala cerrada") — línea 437-444. Un tutor/alumno con una clase que se alargó 2 minutos y que recarga para solucionar un problema de conexión **puede quedar bloqueado fuera de su propia clase en curso.**

### 2.5 ¿Cómo se entera un lado de que el OTRO se desconectó?

Dos mecanismos, ninguno confiable dentro de una llamada activa:

1. **`ping_reunion.php` (archivo `sala_activa_<id>.txt`):** guarda solo `usuario_id|timestamp` del **último** que hizo ping — un solo slot, no dos. Si ambos están en la sala, el archivo solo refleja quién pingeó más recientemente. Y el badge que consume este dato (`badge-reunion`) **solo se actualiza cuando el que consulta NO está en la llamada** (`mini_aula.php:726`: `if (jitsiHidden)` — el chequeo se salta si ya estás viendo el video). Es decir: **mientras ambos están en la videollamada, este mecanismo no le dice a ninguno de los dos si el otro se cayó.**
2. **Eventos de Daily.co:** el único indicio real vendría de `participant-joined`/`track-stopped`, pero no hay listener para `participant-left` ni para cambios de estado de red del participante remoto. El video tile del otro participante simplemente se congela o desaparece, sin mensaje explicativo ("Juan se desconectó" / "reconectando a Juan...").

**Conclusión:** si tu compañero de clase pierde señal, tu única señal es notar que su video/audio dejó de moverse. No hay ningún texto o alerta que lo confirme.

### 2.6 Eventos `online`/`offline` del navegador

**No se usan en ningún lado.** Verificado por grep en `mini_aula.php` y `chat_mini_aula.php`: cero ocurrencias de `window.addEventListener('online', ...)` u `'offline'`. Es la forma más barata de mostrar un banner "Sin conexión" instantáneo (no depende de esperar a que un fetch falle) y no está implementada.

### 2.7 `visibilitychange` (cambiar de app en móvil y volver)

- **`chat_mini_aula.php` (línea 656-663):** sí lo maneja, y bien — pausa el polling al ocultarse, y al volver visible fuerza un poll inmediato con reseteo a intervalo mínimo. Este es el único componente del aula con esta protección.
- **`mini_aula.php` (shell principal):** **no lo maneja en ninguno de sus 4 timers propios** (`checkNewFiles`, badge chat, badge reunión, `reunionPinger`). Si el usuario cambia de app 10 minutos (para ver el WhatsApp del alumno, por ejemplo) y vuelve, estos polls simplemente siguieron corriendo cada 7-15s todo ese tiempo en segundo plano (con el consumo de datos/batería correspondiente) — no hay pausa ni resync forzado al volver. En la práctica, iOS/Android suelen throttlear timers de pestañas en background, así que el comportamiento real varía por navegador/OS y **no se puede confirmar sin prueba en dispositivo real** (ver sección 6).
- **La videollamada de Daily.co en sí** no tiene ningún hook de `visibilitychange` propio en el código de Nubira — el comportamiento al poner la app en segundo plano depende enteramente de cómo Daily.co maneja su propio ciclo de vida (probablemente sigue transmitiendo audio, corta video, según el SDK) — **no verificable sin prueba real.**

---

## 3. Responsive / multiplataforma

### 3.1 Layout en viewport móvil (375px-430px)

- El sistema de tabs (`archivos`/`video`/`pizarra`) + chat como panel superpuesto (no en grid) está bien pensado para pantallas chicas — un solo panel visible a la vez, sin scroll horizontal forzado.
- El **PiP (picture-in-picture) de video** al navegar a otra pestaña mientras estás en llamada (`.pip-mode`, línea 228-247) se reduce a **140×190px en móvil** (línea 246) — funcional, pero en un `iPhone SE` (375px de ancho) ocupando la esquina inferior derecha con `right: 16px`, es razonablemente chico; no verificable sin prueba visual si se solapa con el botón de "Publicar" del `nav_bottom.php` global si este llegara a estar presente (no debería, `mini_aula.php` no incluye `nav_bottom.php`, confirmado — no hay conflicto ahí).
- El botón de colgar (`btn-colgar`, línea 452) está en `bottom-8 left-8` fijo — en pantallas muy pequeñas en landscape (ver 3.5) puede quedar más cerca del borde de lo ideal, pero sin métricas de dispositivo real no se puede confirmar solapamiento.

### 3.2 Safe areas iOS (notch / home indicator) — **hallazgo crítico**

`mini_aula.php:200` activa `viewport-fit=cover` (agregado recientemente en la migración PWA de toda la plataforma). Esto le dice a iOS que el contenido puede extenderse **debajo** del notch/Dynamic Island y de la barra del home indicator. **Verifiqué que en `mini_aula.php` no existe ni una sola referencia a `env(safe-area-inset-*)`** (grep sin resultados) en:
- El header fijo superior (`#app-header`, línea 252) — puede quedar parcialmente tapado por el notch/Dynamic Island en iPhones modernos.
- El botón de colgar flotante (`#btn-colgar`, `bottom-8 left-8` fijo) — sin padding para el home indicator.
- El timer de llamada (`#video-timer`, `top-6 right-6` fijo) — mismo problema, puede solaparse con la isla dinámica.
- El panel de chat en modo móvil full-screen (`#tools-panel`, `top:0 !important; bottom:0 !important`, línea 216-218) — sin respetar ninguna zona segura.

**Único componente que sí lo hace bien:** el footer del chat dentro del iframe (`chat_mini_aula.php:171`, clase `.pb-safe` con `padding-bottom: env(safe-area-inset-bottom)`), aplicado solo a su propio `<footer>`.

Esto es exactamente el mismo patrón de riesgo que quedó anotado como pendiente para `admin_marketing_cards.php` en el CLAUDE.md del proyecto (overflow de badge), pero aquí el impacto es mayor porque es la pantalla de la clase pagada, no un panel admin.

### 3.3 Teclado móvil abierto (chat)

Bien resuelto, con trabajo explícito y documentado en el propio código:
- `chat_mini_aula.php` usa `html`/`body` en `position: fixed` (línea 127-144) para evitar que iOS mueva el viewport al enfocar el textarea.
- Pre-anclaje en `touchstart` (antes del evento `focus`) para eliminar el "salto" visual del primer toque (línea 429-433).
- Múltiples reanclajes durante los 500ms de animación del teclado (línea 438-453).
- `font-size: 16px !important` en inputs (línea 167) — evita el zoom automático de iOS Safari al enfocar un campo con fuente menor a 16px.
- Comunicación con el padre vía `postMessage` (`mini_aula.php:803-834`) para ajustar la altura del panel cuando el teclado empuja el `visualViewport` — mecanismo cruzado iframe↔padre bien pensado.

Esta es, con diferencia, la parte más robusta de todo el aula — se nota que hubo iteración específica sobre el problema real de iOS.

### 3.4 PWA instalada (standalone)

- No se detectó ninguna dependencia de UI del navegador (barra de direcciones, botón atrás del navegador) que rompa en modo standalone — la navegación usa botones propios (`onclick="window.location.href=..."`, línea 257).
- El `alert()` nativo usado en el catch de `callFrame.join()` (línea 633) y los `confirm()` de `confirmarFinalizacion()`/`confirmarVendedor()` (línea 681, 694) **sí funcionan en PWA standalone**, pero su estilo nativo del sistema operativo desentona visualmente con el resto de la UI custom — no es un bug funcional, es una inconsistencia de diseño.
- No verificable sin prueba real: si Daily.co/Excalidraw (ambos cargados en iframe) tienen algún comportamiento distinto dentro de un WebView de PWA instalada vs. Safari/Chrome normal (permisos de cámara/micrófono se piden distinto en algunos WebViews).

### 3.5 Orientación landscape en móvil

No hay ningún media query ni lógica JS que distinga landscape de portrait en `mini_aula.php` (solo hay un breakpoint por ancho, `max-width: 767px`, que trata igual un teléfono en portrait que uno en landscape angosto). En landscape en un teléfono (alto ~375-430px), el `#tools-panel` full-screen (chat) y los controles flotantes de video (timer arriba, colgar abajo) podrían quedar muy apretados verticalmente — **no verificable sin prueba real en dispositivo.**

---

## 4. Performance específica del aula

### 4.1 Inventario de polling activo durante una clase (peor caso, ambos participantes en llamada)

| Polling | Intervalo | Guard `document.hidden` | Peso por request |
|---|---|---|---|
| `checkNewFiles()` | 7s | ❌ No | `count_files.php`: 1 query autenticada + 1 COUNT — liviano |
| Badge chat (`notificaciones_chat_mini_aula.php`) | 8s | ❌ No | 1 COUNT — liviano |
| Badge reunión (`ping_reunion.php?accion=estado`) | 8s (solo si NO estás viendo el tab de video) | ❌ No | Lectura de 1 archivo plano — muy liviano |
| `reunionPinger` (heartbeat en llamada) | 15s | N/A (solo corre mientras estás en la llamada, correcto) | Escritura de 1 archivo plano — muy liviano |
| `header_aula.php` alertas | 15s | ❌ No | **`contar_alertas_sistema.php` completo: ~7 queries de usuario + (si el rol fuese admin) hasta 15 más** — el más pesado de todos, y el único no migrado al patrón centralizado `nubira:alertas` que ya usan `sidebar.php`/`header.php`/`nav_bottom.php` (ver INFORME-PERFORMANCE.md hallazgo #1/#2) |
| Chat (`cargar_mensajes_chat_mini_aula.php`) | 3s→20s adaptativo | ✅ Sí | Trae el HTML completo de mensajes en cada poll (mismo patrón de "full refetch" señalado en INFORME-PERFORMANCE.md hallazgo #7 para el chat pre-contrato — aquí aplica igual) |
| `ajax_status` (vendedor esperando cierre) | 5s | ❌ No (pero de corta duración: solo hasta que el alumno confirme) | 1 query de contrato — liviano |

**Cruce con INFORME-PERFORMANCE.md:**
- **Hallazgo #1/#2** (caché de sesión de `header.php`/`nav_bottom.php` recién corregida): `header_aula.php` **no recibió ese fix** — sigue con su propio polling de 15s sin caché y sin el guard `document.hidden`, y además sigue con el `UPDATE alumnos SET ultima_sesion` sin throttle de 5 minutos que sí tiene `header.php` (línea 23 de `header_aula.php`, comparar con el patrón ya corregido). Es la misma clase de problema, en un archivo que quedó fuera de esa ronda de fixes.
- **Hallazgo #7** (chat con refetch completo en cada poll): aplica igual a `chat_mini_aula.php`/`cargar_mensajes_chat_mini_aula.php` — no se revisó ese archivo línea por línea en esta auditoría (fuera del alcance pedido, que era `mini_aula.php` y lo que consume directamente), pero el patrón de polling observado desde `chat_mini_aula.php:592` (`fetch` con el HTML completo, comparación de strings) es idéntico al ya documentado.

### 4.2 ¿Dónde SÍ correspondería pausar con `document.hidden` y dónde no?

- **`reunionPinger`** (heartbeat de "estoy en la llamada"): correctamente **no** debería pausarse por `document.hidden` — si el usuario cambia de app brevemente para revisar algo, el audio de Daily sigue sonando y sigue "en la reunión"; pausar el heartbeat lo marcaría como desconectado erróneamente ante el otro participante. Este es de los pocos casos donde ignorar `visibilitychange` es la decisión correcta — y de hecho hoy no lo pausa (correcto, aunque probablemente por omisión más que por diseño consciente, dado que ningún otro handler de este archivo lo considera tampoco).
- **`checkNewFiles`, badges de chat/reunión, `header_aula.php` polling**: estos sí deberían respetar `document.hidden` — no hay razón funcional para seguir consultando archivos nuevos o alertas de sistema mientras la pestaña está oculta, y hoy ninguno lo hace.

---

## 5. Lista priorizada de problemas

| # | Problema | Archivo:Línea | Severidad | Evidencia |
|---|---|---|---|---|
| 1 | `enable_network_ui: false` apaga el indicador nativo de reconexión de Daily.co sin reemplazo propio; combinado con ausencia total de banner "sin conexión" en las 7 llamadas de red del aula | `mini_aula.php:181` + ausencia de listeners `online`/`offline` en todo el archivo | **Crítico** | Grep confirma cero listeners `online`/`offline`; solo 4 eventos de Daily enganchados (`left-meeting`, `participant-joined`, `joined-meeting`, `track-stopped`), ninguno de red |
| 2 | Recargar la página durante la videollamada expulsa de la llamada sin reconexión automática, y si el horario de la clase ya venció, el botón "Entrar a la Sala" queda bloqueado — un usuario que recarga para arreglar un problema de conexión puede terminar sin poder re-entrar a su propia clase en curso | `mini_aula.php:433-444` (candado), sin lógica de auto-rejoin en `iniciarClase()` | **Crítico** | Lectura directa: `video_habilitado` se recalcula server-side en cada carga desde `clase_fin_ts`, sin margen de gracia para sesiones ya iniciadas |
| 3 | Sin `env(safe-area-inset-*)` en ningún elemento fijo del shell del aula (header, botón de colgar, timer, panel de chat full-screen móvil), pese a que `viewport-fit=cover` ya está activo | `mini_aula.php:200,252,448,452,492` | **Alto** | Grep sin resultados de `safe-area`/`env(` en `mini_aula.php`; solo el footer interno de `chat_mini_aula.php` lo tiene |
| 4 | Ningún participante se entera de forma confiable de que el otro se desconectó mientras ambos están en la llamada — el único mecanismo (`ping_reunion.php` + badge) se desactiva justo cuando estás viendo el video (`if (jitsiHidden)`) | `mini_aula.php:726-739` | **Alto** | Lectura directa del condicional; además `sala_activa_<id>.txt` solo guarda un slot (último que pingeó), no soporta 2 presencias simultáneas |
| 5 | Reload durante clase activa siempre vuelve a la pestaña "Material" (pierde el tab de video/pizarra/chat activo) por manejo 100% client-side sin `history.pushState` | `mini_aula.php:152,505-552` | **Alto** | `$default_tab` solo lee `$_GET['tab']` una vez al cargar; `switchTab()` nunca actualiza la URL |
| 6 | `header_aula.php` mantiene su propio polling de 15s a `contar_alertas_sistema.php` (el endpoint más pesado del sitio) sin guard `document.hidden` y sin el fix de throttle de `ultima_sesion` que sí recibió `header.php` — quedó fuera de la ronda de correcciones de performance ya aplicada al resto del sitio | `app/componentes/header_aula.php:23,169-177` | **Alto** | Comparación directa con el `header.php` ya corregido (ver INFORME-PERFORMANCE.md); `header_aula.php` no fue tocado en esa ronda |
| 7 | Los 4 timers propios de `mini_aula.php` (archivos, badge chat, badge reunión, ajax_status) no respetan `document.hidden` — corren indefinidamente aunque la pestaña esté oculta | `mini_aula.php:576,594,638-640,717-739` | **Medio** | Grep confirma ausencia de `document.hidden`/`visibilitychange` en el bloque de script principal (línea 501-835), salvo el fix de teclado (que es otro tema) |
| 8 | Timer visual de la llamada es puramente client-side (variable JS), se resetea a 00:00 en cualquier reload o reconexión, sin reflejar cuánto duró realmente la clase | `mini_aula.php:763-801` | **Medio** | Lectura directa: `callStartTime` nunca se persiste ni se recupera de ningún backend |
| 9 | Llamada a la API de Daily (`curl_exec`) en cada carga de página **sin `CURLOPT_TIMEOUT`** y sin verificar la respuesta — si la API de Daily está lenta o caída, cada carga de `mini_aula.php` (salvo en pre-clase) puede colgarse esperando la respuesta, y un error de la API pasa completamente desapercibido | `mini_aula.php:169-193` | **Medio** | Lectura directa: no hay `CURLOPT_TIMEOUT`/`CURLOPT_CONNECTTIMEOUT`, y `$respuesta_api` se descarta sin chequear código de estado ni parsear error |
| 10 | Script de Daily.co cargado desde unpkg sin versión fijada (`@daily-co/daily-js` sin `@x.y.z`); si el CDN falla en cargar (blip de red durante el load inicial), `window.DailyIframe` queda `undefined` y el click en "Entrar a la Sala" lanza una excepción no controlada (no hay chequeo `typeof window.DailyIframe`) | `mini_aula.php:203,607` | **Medio** | Lectura directa del `<script>` tag y de `iniciarClase()` — sin guard de existencia |
| 11 | No hay confirmación (`beforeunload`) antes de recargar/cerrar la pestaña durante una llamada activa — un gesto accidental (pull-to-refresh en móvil, back gesture) corta la videollamada sin aviso | Ausente en todo `mini_aula.php` | **Medio** | Grep sin resultados de `beforeunload` |
| 12 | Sin ningún indicador visual de "conectando/reconectando" en el botón "Entrar a la Sala" entre el click y el `.then()`/`.catch()` de `.join()` — el único feedback intermedio es el placeholder desapareciendo a los 300ms fijos, independiente de si la conexión real ya se estableció | `mini_aula.php:598-641` | **Bajo** | Lectura directa: `setTimeout(..., 300)` es un delay fijo de animación, no un estado real de conexión |
| 13 | Feature de "typing indicator" en el chat del aula está deshabilitada del lado emisor (comentario explícito en el código) pero el indicador visual receptor sigue presente en el HTML/CSS — código muerto a medio construir | `chat_mini_aula.php:364-372` | **Bajo** | Comentario propio: "TYPING DESACTIVADO — feature pendiente de backend" |

---

## 6. Plan de mejoras (ordenado por impacto, quick wins marcados)

1. **Agregar banner "Sin conexión" global usando eventos `online`/`offline`** — quick win, ~15 líneas de JS en `mini_aula.php`, cero riesgo, resuelve directamente el hallazgo #1 para todo lo que no sea la videollamada en sí (chat, archivos, badges).
2. **Reactivar `enable_network_ui` en la config de la sala de Daily** (quitar la línea 181 o ponerla en `true`) — quick win literal de 1 línea, recupera gratis el indicador nativo de reconexión de Daily sin escribir código propio. Evaluar si se apagó a propósito por diseño visual; si es así, reemplazar por listeners propios de `'network-quality-change'` antes de quitarlo.
3. **Agregar margen de gracia post-clase para reentrar a una llamada ya iniciada** (hallazgo #2) — ej. permitir reunirse si `callFrame` ya existió en esta sesión, o extender `video_habilitado` unos minutos si hubo un `reunionPinger` activo recientemente. Requiere diseño, no es quick win.
4. **`env(safe-area-inset-*)` en header, botón de colgar, timer y panel de chat móvil** (hallazgo #3) — quick win de CSS, mismo patrón que ya existe en `chat_mini_aula.php:171`, solo replicarlo en 4 lugares de `mini_aula.php`.
5. **Persistir el tab activo en la URL** (`history.replaceState` en `switchTab()`) para que un reload mantenga video/pizarra/chat — quick win moderado, evita el hallazgo #5 sin tocar backend.
6. **Migrar `header_aula.php` al mismo fix que ya recibió `header.php`** (caché de sesión + guard `document.hidden` en su polling de 15s) — mismo patrón ya aplicado y probado en el resto del sitio, solo falta replicarlo acá. Quick win dado que ya existe la plantilla.
7. **Agregar `document.hidden` guard a los 4 timers propios de `mini_aula.php`** — quick win, mismo patrón ya usado correctamente en `chat_mini_aula.php`.
8. **`CURLOPT_TIMEOUT`/`CURLOPT_CONNECTTIMEOUT` + chequeo de respuesta en la llamada a la API de Daily** — quick win de robustez, evita que un problema de Daily cuelgue la carga de la página del aula.
9. **Fijar versión de `@daily-co/daily-js`** + chequeo `typeof window.DailyIframe === 'function'` antes de usar — quick win, previene el crash no controlado del hallazgo #10.
10. **`beforeunload` con confirmación mientras `window.enLlamada === true`** — quick win chico, evita cuelgues accidentales.
11. Mecanismo de presencia dual en `ping_reunion.php` (hoy es un solo slot por archivo) para que cada lado sepa en tiempo real si el otro sigue conectado **incluso estando ambos en la llamada** — requiere rediseño (¿tabla en BD con una fila por usuario en vez de archivo plano?, o aprovechar los propios eventos de Daily del lado del participante remoto). No es quick win.
12. Indicador de "conectando..." real (basado en el estado de la promesa `.join()`, no en un `setTimeout` fijo) en el botón de entrar a la sala — quick win menor de UX.

---

## 7. Qué no se puede verificar solo leyendo código (requiere prueba en dispositivo real)

- **Comportamiento real de Daily.co ante un corte de red real** (metro, wifi→datos): si su SDK reconecta solo en cuántos segundos, si mantiene el audio y corta solo el video, o si expulsa directamente. El código de Nubira no controla esto, solo reacciona a los eventos que Daily decida disparar.
- **Comportamiento de los 4 timers sin guard `document.hidden`** cuando el navegador/OS (Safari iOS en background, Chrome Android con Doze) throttlea o suspende JS en pestañas no visibles — el código no lo previene, pero el sistema operativo puede mitigarlo por su cuenta; el nivel real de "daño" (batería/datos consumidos) solo se mide con un dispositivo real y una sesión larga en background.
- **Solapamiento visual real de elementos fijos con notch/Dynamic Island/home indicator** en modelos específicos de iPhone — el hallazgo #3 es una ausencia de código confirmada, pero el impacto visual exacto (cuántos px se tapan) depende del modelo de dispositivo.
- **Comportamiento del WebView de la PWA instalada** al pedir permisos de cámara/micrófono para Daily.co, y si Excalidraw (iframe de tercero) funciona igual en modo standalone.
- **Landscape en teléfono real** — sin métricas de viewport real no se puede confirmar si hay elementos que se superponen.
- **Latencia real de la llamada `curl_exec` a la API de Daily** bajo condiciones normales de Hostinger — el hallazgo #9 es sobre la ausencia de timeout, no sobre si hoy efectivamente tarda mucho (no medido).
- **Si iOS Safari realmente dispara el zoom automático pese al `font-size: 16px`** en todos los campos relevantes — regla general conocida, pero no probada en este flujo específico.

---

## Nota final

A diferencia de la auditoría de performance general, donde la mayoría de los problemas eran "cuesta más de lo necesario", varios hallazgos de este informe (#1, #2, #4) son **fallas de comunicación con el usuario en el peor momento posible**: cuando algo ya salió mal técnicamente (corte de señal), el aula no le dice nada, y en el caso del reload post-horario, activamente le cierra la puerta. La parte mejor resuelta de todo el archivo es, con distancia, el manejo del teclado móvil en el chat (sección 3.3) — vale la pena usar ese mismo nivel de cuidado como estándar para el resto de los puntos de esta lista.
