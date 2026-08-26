// Puerto de app/datos_bancarios.php:26-76 (lectura de saldo + historial), y [26/08/2026]
// también de app/solicitar_retiro.php (118 líneas) y app/editar_datos_bancarios.php (298
// líneas) — completos, no solo lectura. El comentario viejo de este archivo (ya no
// vigente, se deja como rastro) decía que no se podía replicar el POST real porque el
// csrf_token vive en la sesión PHP, inaccesible desde Node. Ese razonamiento quedó
// obsoleto: el resto de este port ya resolvió el mismo problema con requireAuth (cookie de
// sesiones_api) en vez de CSRF — mismo criterio ya usado en perfil.controller.ts::
// putMiPerfilBio ("acá no hay CSRF de sesión PHP que validar porque requireAuth ya cubre
// el mismo problema que el CSRF busca prevenir"). Se aplica acá igual.
//
// Deliberadamente NO portado: el push notification a admin (id=1) que
// solicitar_retiro.php:108-111 dispara vía enviar_push_nubira()/OneSignal — es
// infraestructura de notificaciones propia de PHP sin equivalente en server/, y no es
// parte de mover el dinero en sí (el admin sigue viendo la solicitud en /admin/retiros
// igual, solo sin el push inmediato).

export interface SolicitudRetiroRow {
  monto: number;
  fecha_solicitud: Date;
  estado: string;
}

export interface SolicitudRetiro {
  monto: number;
  fechaSolicitud: Date;
  estado: string;
}

export interface DatosBancariosRow {
  banco: string;
  numero_cuenta: string | null;
}

export interface MiBilleteraPublico {
  saldoDisponible: number;
  saldoParaMostrar: number;
  minimoRetiro: number;
  comisionActual: number;
  gananciasApuntes: number;
  gananciasServicios: number;
  totalRetirado: number;
  // numeroCuentaEnmascarado: SOLO los últimos 4 dígitos, enmascarado en server/ (mapper) —
  // el número de cuenta completo (datos_pago_usuario.numero_cuenta) NUNCA sale de server/
  // hacia web/, ni siquiera para enmascararlo del lado del cliente. Mejora deliberada de
  // seguridad respecto al PHP real, que sí trae la fila completa a la vista y enmascara en
  // el render — acá se enmascara antes de que el dato cruce la red hacia web/.
  datosBancarios: { banco: string; numeroCuentaEnmascarado: string } | null;
  historialRetiros: SolicitudRetiro[];
}

// Fila completa de datos_pago_usuario — a diferencia de DatosBancariosRow (arriba, solo
// banco+numero_cuenta para el resumen enmascarado), esta SÍ lleva el número de cuenta sin
// enmascarar: es lo que necesita el propio dueño para ver/editar su formulario. Nunca se
// expone a otro usuario que no sea el dueño (requireAuth ya garantiza usuarioId = dueño).
export interface DatosBancariosCompletosRow {
  banco: string;
  tipo_cuenta: string;
  numero_cuenta: string;
  titular_nombre: string;
  rut: string;
}

export interface DatosBancariosCompletos {
  banco: string;
  tipoCuenta: string;
  numeroCuenta: string;
  titularNombre: string;
  rut: string;
}

export interface DatosBancariosParaEditar {
  bancos: string[];
  datos: DatosBancariosCompletos | null;
}

// Puerto exacto de editar_datos_bancarios.php:43-47 (mismos 5 campos, mismo trim).
export interface GuardarDatosBancariosInput {
  banco: string;
  tipoCuenta: string;
  numeroCuenta: string;
  titularNombre: string;
  rut: string;
}
