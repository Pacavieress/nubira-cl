// Puerto de app/bandeja_entrada.php + app/eliminar_conversacion.php + app/chat_previo_contrato.php
// + app/iniciar_chat.php + app/enviar_mensaje.php + app/cargar_mensajes.php +
// app/render_mensajes.php + app/typing_set.php + app/ver_archivo_chat.php (Grupo Mensajes/Chat
// pre-contrato — Pieza 1, 26/08/2026).
//
// DECISIÓN DE ALCANCE — 2 archivos "bandeja" reales y distintos, no uno solo:
// app/mis_chats.php + app/accion_chat.php son una UI secundaria ("popup" de 440x640 abierto
// vía window.open() desde mis_contratos.php/mis_apuntes.php/clases_servicios.php/nav_mobile.php)
// que usa columnas de visibilidad DISTINTAS y más viejas (conversaciones.visible_comprador/
// visible_vendedor, conversaciones.estado='archivada') — confirmado con grep que NINGÚN
// componente de navegación real (sidebar.php, nav_bottom.php) enlaza a mis_chats.php. La
// bandeja REAL, enlazada desde sidebar.php y nav_bottom.php como "Mensajes" (ruta real
// /bandeja-entrada), es app/bandeja_entrada.php + app/eliminar_conversacion.php, que usan
// conversaciones.oculto_comprador/oculto_vendedor — las MISMAS columnas que
// enviar_mensaje.php ya usa para "resucitar" un chat oculto al recibir un mensaje nuevo. Se
// porta esta última. mis_chats.php/accion_chat.php quedan sin portar — nada en Next.js
// dispara ese popup todavía (tampoco lo tenía MisContratosTabs.tsx cuando se portó antes).
//
// FUERA DE ALCANCE, diferido a propósito (mismo criterio "no construir infra a medias" de
// toda esta migración):
// - Adjuntos NUEVOS (app/enviar_archivo.php): archivos de usuarios no-admin entran en cola
//   de moderación (mensajes.visible=0) hasta que un admin los apruebe — confirmado leyendo
//   ese archivo. Construir la subida SIN el panel de moderación (app/admin_moderar_archivo.php
//   + app/aplicar_censura.php, panel admin aparte) dejaría cada archivo real enviado
//   invisible para siempre al destinatario. Se difieren juntos. Los adjuntos YA EXISTENTES en
//   conversaciones reales SÍ se pueden ver/descargar (ver_archivo_chat.php SÍ se porta,
//   solo lectura).
// - Cuenta express (app/crear_cuenta_express.php, app/completar_registro_express.php): flujo
//   de REGISTRO, no de chat. Se replica el GATE (bloquear tras 2 mensajes si
//   alumnos.cuenta_express=1) porque vive dentro de enviar_mensaje.php, pero al activarse
//   redirige al sitio PHP real (/completar-registro) — mismo puente usado en el resto de la
//   migración para piezas no construidas todavía.
// - $_SESSION['ultimo_interes_categoria'] (escáner de materias, enviar_mensaje.php:216-234):
//   señal de personalización que hoy solo vive en la sesión PHP cruda (sin tabla propia) y
//   la consume vitrina.php, que no está portado. Sin infraestructura nueva que construir
//   para esto todavía, se omite — no bloquea nada del chat en sí.
// - Correos/push al recibir mensaje: mismo criterio de siempre, server/ no tiene
//   infraestructura de envío.
//
// CORRECCIÓN DELIBERADA (no repliques el bug, mismo criterio ya aplicado en Contratos/Pago):
// app/iniciar_chat.php:97-102 inserta el primer mensaje de un chat NUEVO con un INSERT
// directo, sin pasar por la capa DLP de enviar_mensaje.php — hueco real (un usuario podría
// poner su teléfono en el PRIMER mensaje y saltarse el filtro por completo). Acá el mensaje
// inicial pasa por la MISMA función de envío (con DLP) que cualquier otro mensaje.

export interface ChatBandejaItem {
  id: number;
  tipo: "negociacion" | "aula";
  fechaSort: string;
  servicioTitulo: string;
  otroId: number;
  otroNombre: string;
  otroFotoUrl: string | null;
  ultimoMensaje: string | null;
  sinLeer: number;
}

export interface ChatDetalle {
  id: number;
  servicioId: number;
  servicioTitulo: string;
  esVendedor: boolean;
  otroId: number;
  otroNombre: string;
  otroFotoUrl: string | null;
  otroOnline: boolean;
  destinatarioSuspendido: boolean;
  tutorInactivo: boolean;
  limiteMensajesAlcanzado: boolean;
  contratoId: number | null;
  servicio: {
    precio: number;
    precioOferta: number | null;
    esOferta: boolean;
    duracionMinutos: number;
  };
}

export interface MensajeChat {
  id: number;
  remitenteId: number;
  esSistema: boolean;
  mensaje: string;
  enviadoEn: string;
  leido: boolean;
  archivo: { nombre: string; tipo: string; peso: number; url: string } | null;
}

export type EnviarMensajeError =
  | { tipo: "datos_invalidos"; mensaje: string }
  | { tipo: "requiere_completar" }
  | { tipo: "limite_alcanzado"; mensaje: string }
  | { tipo: "dlp"; mensaje: string }
  | { tipo: "sin_acceso"; mensaje: string }
  | { tipo: "destinatario_no_disponible"; mensaje: string };

export type ResultadoEnviarMensaje = { ok: true; mostrarBannerExpress: boolean } | { ok: false; error: EnviarMensajeError };
