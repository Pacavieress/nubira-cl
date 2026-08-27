import type { ResultSetHeader, RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";
import type { ContratoAuditoriaRow, EstadoRetiro, SolicitudRetiroAdminRow } from "./adminRetiros.types.js";

interface SolicitudRetiroAdminDbRow extends SolicitudRetiroAdminRow, RowDataPacket {}

// Puerto exacto de admin_retiros.php:65-138 (filtros + query principal). `estado` llega ya
// resuelto por el controller (incluye el default 'pendiente' del PHP real cuando no viene
// query param) — acá 'todas' es la única forma de pedir sin filtro de estado.
export async function listarSolicitudesRetiro(estado: EstadoRetiro | "todas", institucion: string): Promise<SolicitudRetiroAdminRow[]> {
  const condiciones: string[] = [];
  const params: string[] = [];

  if (estado !== "todas") {
    condiciones.push("r.estado = ?");
    params.push(estado);
  }
  if (institucion) {
    condiciones.push("LOWER(r.institucion) = ?");
    params.push(institucion.toLowerCase());
  }
  const where = condiciones.length > 0 ? `WHERE ${condiciones.join(" AND ")}` : "";

  const [rows] = await pool.query<SolicitudRetiroAdminDbRow[]>(
    `SELECT r.id, r.usuario_id, r.monto, r.estado, r.fecha_solicitud, r.fecha_pago, r.transferencia_id,
            a.nombre, a.correo, d.banco, d.tipo_cuenta, d.numero_cuenta, d.titular_nombre, d.rut
     FROM solicitudes_retiro r
     JOIN alumnos a ON r.usuario_id = a.id
     LEFT JOIN datos_pago_usuario d ON r.usuario_id = d.usuario_id
     ${where}
     ORDER BY r.fecha_solicitud DESC`,
    params,
  );
  return rows;
}

// CORRECCIÓN DELIBERADA vs. admin_retiros.php:100-104 — agrega `AND estado = 'pendiente'` al
// WHERE (el PHP real solo filtraba por id, permitiendo re-aprobar una solicitud ya
// procesada). Sin transferencia real disparada acá no hay riesgo de doble pago, pero evita
// reenviar el correo de confirmación y sobrescribir fecha_pago/transferencia_id por un
// doble-submit. transferenciaId es la referencia real de la transferencia manual que el
// admin ingresa — activado a pedido del usuario (antes una columna muerta en el PHP real).
export async function aprobarRetiro(id: number, transferenciaId: string): Promise<boolean> {
  const [result] = await pool.query<ResultSetHeader>(
    "UPDATE solicitudes_retiro SET estado = 'aprobado', fecha_pago = NOW(), fecha_transferencia = NOW(), transferencia_id = ? WHERE id = ? AND estado = 'pendiente'",
    [transferenciaId, id],
  );
  return result.affectedRows > 0;
}

// Mismo criterio que aprobarRetiro: guard `estado = 'pendiente'` que el PHP real no tenía.
export async function rechazarRetiro(id: number): Promise<boolean> {
  const [result] = await pool.query<ResultSetHeader>(
    "UPDATE solicitudes_retiro SET estado = 'rechazado' WHERE id = ? AND estado = 'pendiente'",
    [id],
  );
  return result.affectedRows > 0;
}

interface InfoCorreoDbRow extends RowDataPacket {
  monto: number;
  nombre: string;
  correo: string;
}

// Puerto de las 2 queries idénticas de admin_retiros.php:107/122 (una para el correo de
// aprobado, otra para el de rechazo — acá unificadas en una sola, ambos casos necesitan lo
// mismo). Se llama DESPUÉS de un aprobarRetiro/rechazarRetiro exitoso, nunca antes — si el
// guard de estado bloqueó la mutación, no corresponde mandar ningún correo.
export async function getInfoParaCorreo(id: number): Promise<{ monto: number; nombre: string; correo: string } | null> {
  const [rows] = await pool.query<InfoCorreoDbRow[]>(
    "SELECT r.monto, a.nombre, a.correo FROM solicitudes_retiro r JOIN alumnos a ON r.usuario_id = a.id WHERE r.id = ?",
    [id],
  );
  return rows[0] ?? null;
}

interface ContratoAuditoriaDbRow extends ContratoAuditoriaRow, RowDataPacket {}

// Puerto exacto de api_auditoria_retiro.php:11-15.
export async function getAuditoriaContratos(solicitudId: number): Promise<ContratoAuditoriaRow[]> {
  const [rows] = await pool.query<ContratoAuditoriaDbRow[]>(
    "SELECT id, monto, monto_subsidio, monto_comision FROM contratos WHERE solicitud_retiro_id = ?",
    [solicitudId],
  );
  return rows;
}
