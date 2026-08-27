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
//
// ACTUALIZADO (Fase 3, 27/08/2026): sala_presencia ya reemplazó sala_activa_<id>.txt como
// fuente de la extensión de gracia por actividad — ver getAulaDetalle()/registrarPresenciaSala()
// en aula.repository.ts. Los endpoints de presencia (entrar/ping/salir/estado) existen y
// están probados, pero nada en el shell de Next los llama todavía: "Entrar a la Sala" sigue
// bridgeando al PHP real (que sigue usando su propio archivo, sin tocar), así que hoy no hay
// tráfico real escribiendo en la tabla — quedará operativo de punta a punta recién cuando la
// Fase 4 construya la videollamada real en Next y la conecte a estos mismos endpoints. Por
// eso tampoco se agregó un badge "el otro ya está en la sala" en AulaShell.tsx todavía: sin
// nadie escribiendo en la tabla, sería una UI que nunca podría mostrar nada real.
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

export interface EstadoPresenciaSala {
  activo: boolean;
  usuarioId: number | null;
}
