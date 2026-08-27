// Puerto de app/mini_aula.php (shell, sin video/WebRTC) + app/chat_mini_aula.php +
// app/cargar_mensajes_chat_mini_aula.php + app/enviar_mensajes_chat_mini_aula.php +
// app/typing_set_mini_aula.php + app/entregas_servicio.php + app/notificaciones_chat_mini_aula.php
// + app/count_files.php (los últimos 2 fusionados en un solo endpoint de estado, ver
// aula.repository.ts) — Grupo Mini Aula, Pieza 2 (27/08/2026).
//
// FUERA DE ALCANCE A PROPÓSITO en esta pieza (confirmado con el usuario, fases separadas):
// - Video/WebRTC (Daily.co): la pestaña "Reunión" muestra el estado/mensaje correcto
//   (según horario), pero "Entrar a la Sala" bridgea al sitio PHP real en vez de unirse
//   a una llamada — la integración de Daily (vanilla vs. @daily-co/daily-react) es su
//   propia decisión de arquitectura, no algo para decidir de paso acá.
// - Pizarra (Excalidraw): agrupada con video en la Fase 4 del diseño acordado.
// - Presencia real (reemplazo de sala_activa_<id>.txt por una tabla): es su propia Fase 3.
//   Acá la ventana de gracia post-horario es FIJA (60 min), sin la extensión por heartbeat
//   que el PHP real tiene — esa extensión depende exactamente del mecanismo que la Fase 3
//   va a reemplazar, así que no tiene sentido construirla dos veces.
//
// CORRECCIÓN DELIBERADA (mismo criterio "no repliques el bug" de todo el port):
// enviar_mensajes_chat_mini_aula.php NO tiene la regla 5d de enviar_mensaje.php (teléfono
// fraccionado en varios mensajes consecutivos) — es el mismo bloque DLP copy-pasteado sin
// esa regla. Acá ambos chats (pre-contrato y aula) usan la MISMA lib/dlp.ts, así que la
// regla 5d queda activa en los dos por igual, no solo donde alguien se acordó de copiarla.
//
// OBSERVACIÓN (no corregida, fuera de alcance): $es_finalizado en el PHP real compara
// contra 'finalizado' — valor que NO existe en el ENUM real de contratos.estado — así que
// siempre es false hoy (mismo patrón de bug ya encontrado 3 veces en Contratos/Pago). Acá
// se replica ese comportamiento real (esFinalizado calculado pero efectivamente inerte),
// no se "arregla" a qué debería significar realmente — eso es una decisión de producto
// (¿cuenta finalizado_comprador? ¿liberado?) que no corresponde asumir de paso.

export interface AulaDetalle {
  id: number;
  servicioId: number;
  servicioTitulo: string;
  esVendedor: boolean;
  esComprador: boolean;
  esAdmin: boolean;
  otroNombre: string;
  estado: string;
  tieneReserva: boolean;
  fechaAmigable: string | null;
  claseIniTs: string;
  claseFinTs: string;
  ventanaAperturaTs: string;
  finGraciaTs: string;
  esPreClase: boolean;
  esAulaActiva: boolean;
  esPostClase: boolean;
  videoHabilitado: boolean;
  esFinalizado: boolean;
  finalizadoComprador: boolean;
  finalizadoVendedor: boolean;
  compradorPuedeFinalizar: boolean;
  compradorEsperandoInicio: boolean;
  vendedorEsperandoAlumno: boolean;
  vendedorPuedeConfirmar: boolean;
}

export interface MensajeAula {
  id: number;
  remitenteId: number;
  mensaje: string;
  fecha: string;
  visto: boolean;
}

export type EnviarMensajeAulaError =
  | { tipo: "datos_invalidos" }
  | { tipo: "suspendido"; mensaje: string }
  | { tipo: "dlp"; mensaje: string }
  | { tipo: "sin_acceso"; mensaje: string }
  | { tipo: "aula_cerrada"; mensaje: string };

export type ResultadoEnviarMensajeAula = { ok: true } | { ok: false; error: EnviarMensajeAulaError };

export interface ArchivoContrato {
  id: number;
  nombreOriginal: string;
  pesoKb: number;
  fecha: string;
  esMio: boolean;
  subidoPor: string;
  url: string;
}

export interface EstadoAula {
  chatNoLeidos: number;
  totalArchivos: number;
}
