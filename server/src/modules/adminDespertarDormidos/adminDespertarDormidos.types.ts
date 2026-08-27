// Puerto del modo WEB de app/enviar_despertar_dormidos.php — panel de campaña "Despertar
// Dormidos" (usuarios confirmados que nunca publicaron ni compraron), selección manual por
// checkbox, con cupón opcional. El modo CLI del PHP real (invocación por línea de comandos,
// admin_id=0) NO se porta — no tiene equivalente en un panel admin de Next.
//
// CORRECCIÓN DELIBERADA vs. el PHP real (autorizada explícitamente por el usuario, decisión
// de diseño ya tomada — no inventada acá): ni el listado ni el envío real respetaban la
// tabla `unsubscribed` ni mandaban header List-Unsubscribe — un usuario que se dio de baja
// por otro canal (ej. la campaña de Avisos o "Recuperar Gmail") igual podía recibir este
// correo. Se reutiliza la misma lógica ya construida y probada en app/helpers/campanas.php
// (generarUnsubUrl, el patrón de enviarDormidoConUnsubscribe) en vez de replicar el hueco.
//
// Tope duro de destinatarios por envío (decisión de diseño confirmada): el envío sigue
// siendo síncrono (una request HTTP, sleep entre correos, sin cola) — sin infraestructura
// de jobs asíncronos, que está fuera de alcance de esta pieza — pero ahora con un límite
// máximo que evita que un envío masivo sin querer haga timeout a mitad de camino.
export const TOPE_DESTINATARIOS_POR_ENVIO = 150;

export type EstadoEnvioDormido = "pendiente" | "enviado" | "fallo";

export interface UsuarioDormido {
  alumnoId: number;
  nombre: string;
  correo: string;
  estado: EstadoEnvioDormido;
  fechaEnviado: string | null;
}

export interface DespertarDormidosResumen {
  usuarios: UsuarioDormido[];
  stats: { total: number; enviados: number; pendientes: number; fallidos: number };
}

export interface CuponGlobalInfo {
  ok: true;
  porcentaje: number;
  fechaExpiracion: string | null;
}
export interface CuponGlobalError {
  ok: false;
  error: string;
}
export type CuponGlobalResultado = CuponGlobalInfo | CuponGlobalError;

export interface EnviarDormidosResultado {
  enviados: number;
  fallidos: number;
  omitidos: number;
}
