// Puerto de admin_chats.php ("Master Tracker" — monitoreo de chats + sistema DLP). Alcance
// confirmado explícitamente con el usuario antes de construir (mismo criterio que Videos/
// Log Fail/Apuntes): se portan 3 mutaciones seguras (eliminar_chat/restaurar_chat -toggle de
// `conversaciones.eliminado`-, marcar_revisado_dlp -toggle de `dlp_intentos.revisado_admin`-,
// aprobar_archivo -toggle de `mensajes.visible`-), todas DB-only y reversibles.
//
// EXCLUIDO Y NO RETOMABLE SIN APROBACIÓN EXPLÍCITA DEDICADA (ver memoria de sesión):
// - liberar_mensaje_dlp: inserta el mensaje bloqueado directo en una conversación real entre
//   otros 2 usuarios + dispara notificación push/correo real al destinatario — el admin habla
//   "como" el usuario. Nunca portar sin que el usuario lo apruebe en su propia conversación.
// EXCLUIDO (mismo bucket que "eliminar" en Apuntes):
// - rechazar_archivo: borra el archivo físico del disco (chat_archivos/) — filesystem delete.
//
// Ambas quedan como link directo al panel PHP real. La tabla `dlp_intentos` local todavía no
// tiene la columna `liberado` (auto-migración de admin_chats.php:47-55 nunca corrió) — como
// liberar_mensaje_dlp no se porta, esa columna ni se selecciona ni se expone acá.
export interface ChatListado {
  id: number;
  contratoId: number | null;
  fechaOrden: string | null;
  eliminado: boolean;
  compradorId: number;
  compradorNombre: string;
  compradorFoto: string | null;
  vendedorId: number;
  vendedorNombre: string;
  vendedorFoto: string | null;
  servicioTitulo: string | null;
}

export interface ContadoresChats {
  activos: number;
  cerrados: number;
  contrato: number;
  cotizacion: number;
  inactivos: number;
  alertasDlp: number;
  moderacion: number;
}

export interface MensajeChat {
  id: number;
  remitenteId: number;
  mensaje: string;
  archivoNombre: string | null;
  archivoRuta: string | null;
  archivoTipo: string | null;
  archivoPeso: number | null;
  enviadoEn: string | null;
}

export interface DlpIntento {
  id: number;
  categoria: string;
  textoIntentado: string;
  fecha: string | null;
  revisadoAdmin: boolean;
  remitenteNombre: string;
}

export interface ChatInfo {
  id: number;
  compradorId: number;
  compradorNombre: string;
  compradorFoto: string | null;
  vendedorId: number;
  vendedorNombre: string;
  vendedorFoto: string | null;
  servicioTitulo: string | null;
  contratoId: number | null;
  eliminado: boolean;
}

export interface MetadataChat {
  totalMensajes: number;
  archivos: number;
  primero: string | null;
  ultimo: string | null;
}

export interface ChatDetalle {
  info: ChatInfo;
  mensajes: MensajeChat[];
  dlp: DlpIntento[];
  metadata: MetadataChat;
}

export interface ArchivoModeracion {
  id: number;
  conversacionId: number;
  archivoRuta: string;
  archivoNombre: string | null;
  archivoTipo: string | null;
  archivoPeso: number | null;
  enviadoEn: string | null;
  remitenteNombre: string;
}
