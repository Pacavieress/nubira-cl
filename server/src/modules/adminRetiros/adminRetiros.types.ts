// Puerto de app/admin_retiros.php (544 líneas) + app/api_auditoria_retiro.php — Panel Admin
// Retiros. Autorizado por el usuario con alcance completo, incluyendo aprobar/rechazar
// retiros reales — a diferencia de los paneles admin anteriores de esta migración, donde las
// acciones de dinero real quedaban excluidas a propósito.
//
// El PHP real NO integra con MercadoPago ni ningún API bancario: "aprobar" es un registro
// contable — el admin transfiere el dinero manualmente desde su banca online usando los
// datos que este panel muestra, y RECIÉN DESPUÉS aprueba acá (lo que marca el estado y
// manda el correo de confirmación). Ver aula.repository.ts/miBilletera para el resto del
// contexto financiero (el saldo del tutor es 100% derivado, nunca una columna que se mute).
//
// CORRECCIÓN DELIBERADA vs. el PHP real (mismo criterio de siempre): el UPDATE de
// aprobar/rechazar ahora exige `estado='pendiente'` en el WHERE — el PHP real no lo tenía,
// permitiendo re-aprobar/re-rechazar una solicitud ya procesada (reenviaría el correo de
// nuevo, sobrescribiría fecha_pago). Sin transferencia real disparada por este código no hay
// riesgo de doble pago, pero el guard es gratis de agregar y cierra el hueco igual.
//
// ACTIVADO A PEDIDO DEL USUARIO (antes columnas muertas en el PHP real, nunca usadas):
// transferencia_id/fecha_transferencia ahora se completan con una referencia real que el
// admin ingresa al aprobar — trazabilidad real de la transferencia manual.

export const ESTADOS_RETIRO = ["pendiente", "aprobado", "rechazado"] as const;
export type EstadoRetiro = (typeof ESTADOS_RETIRO)[number];

export interface SolicitudRetiroAdminRow {
  id: number;
  usuario_id: number;
  monto: number;
  estado: EstadoRetiro;
  fecha_solicitud: Date;
  fecha_pago: Date | null;
  transferencia_id: string | null;
  nombre: string;
  correo: string;
  banco: string | null;
  tipo_cuenta: string | null;
  numero_cuenta: string | null;
  titular_nombre: string | null;
  rut: string | null;
}

export interface SolicitudRetiroAdmin {
  id: number;
  monto: number;
  estado: EstadoRetiro;
  fechaSolicitud: Date;
  fechaPago: Date | null;
  transferenciaId: string | null;
  tutorNombre: string;
  tutorCorreo: string;
  datosBancarios: { banco: string; tipoCuenta: string; numeroCuenta: string; titularNombre: string; rut: string } | null;
}

export interface ContratoAuditoriaRow {
  id: number;
  monto: number;
  monto_subsidio: number;
  monto_comision: number;
}

export interface LineaAuditoria {
  id: number;
  montoAlumno: number;
  montoSubsidio: number;
  montoComision: number;
  liquido: number;
}

export interface AuditoriaRetiro {
  contratos: LineaAuditoria[];
  totales: { alumno: number; subsidio: number; comision: number; liquido: number };
}

export interface ConfiguracionFinanciera {
  minimoRetiro: number;
  comisionActual: number;
}
