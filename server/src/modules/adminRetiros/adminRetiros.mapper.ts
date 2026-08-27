import type { AuditoriaRetiro, ContratoAuditoriaRow, LineaAuditoria, SolicitudRetiroAdmin, SolicitudRetiroAdminRow } from "./adminRetiros.types.js";

export function mapSolicitudRetiroAdmin(row: SolicitudRetiroAdminRow): SolicitudRetiroAdmin {
  const tieneDatosBancarios = row.banco && row.tipo_cuenta && row.numero_cuenta && row.titular_nombre && row.rut;
  return {
    id: row.id,
    monto: row.monto,
    estado: row.estado,
    fechaSolicitud: row.fecha_solicitud,
    fechaPago: row.fecha_pago,
    transferenciaId: row.transferencia_id,
    tutorNombre: row.nombre,
    tutorCorreo: row.correo,
    datosBancarios: tieneDatosBancarios
      ? { banco: row.banco!, tipoCuenta: row.tipo_cuenta!, numeroCuenta: row.numero_cuenta!, titularNombre: row.titular_nombre!, rut: row.rut! }
      : null,
  };
}

// Puerto exacto de api_auditoria_retiro.php:34-39 (misma fórmula de líquido por contrato,
// mismos totales acumulados).
export function mapAuditoria(rows: ContratoAuditoriaRow[]): AuditoriaRetiro {
  const contratos: LineaAuditoria[] = rows.map((c) => ({
    id: c.id,
    montoAlumno: c.monto,
    montoSubsidio: c.monto_subsidio,
    montoComision: c.monto_comision,
    liquido: c.monto + c.monto_subsidio - c.monto_comision,
  }));

  const totales = contratos.reduce(
    (acc, c) => ({
      alumno: acc.alumno + c.montoAlumno,
      subsidio: acc.subsidio + c.montoSubsidio,
      comision: acc.comision + c.montoComision,
      liquido: acc.liquido + c.liquido,
    }),
    { alumno: 0, subsidio: 0, comision: 0, liquido: 0 },
  );

  return { contratos, totales };
}
