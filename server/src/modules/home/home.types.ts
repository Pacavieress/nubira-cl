import type { ApuntePublico } from "../apuntes/apuntes.types.js";
import type { ServicioPublico } from "../servicios/servicios.types.js";

// Puerto de app/vitrina.php — SOLO las secciones realmente activas en producción. Ese
// archivo tiene ~2221 líneas pero 4 secciones completas están MUERTAS (confirmado con
// grep de `if (false)` / flags hardcodeados a `false`, nunca reactivadas): "Responden en
// menos de 1 hora" ($mostrar_seccion_rapidos), "Zona IA"/seccion_recomendaciones.php,
// "Apuntes recién publicados", "Apuntes PAES" ($mostrar_seccion_paes). No se portan —
// portarlas sería replicar código que el sitio real no muestra a nadie.
//
// "Sigue donde lo dejaste" tampoco se porta — depende de $_SESSION (gated por
// `!$is_guest`), mismo criterio que el resto de web/ (sin sesión, siempre visitante).
//
// El motor de afinidad/personalización de vitrina.php (categoría favorita vía
// tracker_intereses, señal de chat, fingerprinting de institución por cookie
// nubira_device_id) NO se porta: para cualquier visitante sin esas señales — el único
// caso posible en web/ hoy, que no tiene sesión ni cookies propias — vitrina.php cae
// igual a su propio fallback (orden por perfil completo + determinístico en vez de
// RAND($seed), mismo criterio ya usado en servicios/apuntes/búsqueda). No hay pérdida de
// fidelidad: es literalmente la misma rama de código que ve cualquier visitante nuevo.
//
// Sin banner: vitrina.php:678-701 consulta $banner_inline pero JAMÁS lo renderiza en
// ningún lugar del archivo (confirmado con grep de "banner" en todo vitrina.php) — es
// código muerto, no una sección condicional como las otras 4. No se porta ni la query.

export interface HomeData {
  serviciosRecomendados: ServicioPublico[];
  serviciosNuevos: ServicioPublico[];
  apuntesRecomendados: ApuntePublico[];
  clasesPaes: ServicioPublico[];
  ofertas: ServicioPublico[];
}
