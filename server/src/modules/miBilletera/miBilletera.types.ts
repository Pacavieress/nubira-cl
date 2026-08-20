// Puerto de app/datos_bancarios.php:26-76 — SOLO lectura (cálculo de saldo + historial de
// retiros). La acción real de dinero ("Solicitar Retiro", línea 180-186) es un
// <form method="POST"> nativo hacia /solicitar-retiro protegido con un csrf_token que vive
// en la sesión PHP ($_SESSION['csrf_token']) — Node no tiene acceso a esa sesión PHP más
// allá de sesiones_api (usuario_id), así que no se puede replicar ese POST de forma segura
// desde acá. web/ enlaza directo a la página PHP real para esa acción (mismo patrón que
// "Editar Servicio"/"Editar Apunte" en /mis-publicaciones) — no se introduce ningún camino
// nuevo para mover dinero, solo se lee el estado ya calculado en server/PHP real.

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
