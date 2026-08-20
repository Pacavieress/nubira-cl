import type { RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";
import type { ContratoAgendaRow } from "./misContratos.types.js";

interface ContratoAgendaDbRow extends ContratoAgendaRow, RowDataPacket {}

// Puerto exacto de mis_contratos.php:32-47 (mismo JOIN/LEFT JOIN, mismo ORDER BY).
export async function getContratosComoComprador(compradorId: number): Promise<ContratoAgendaRow[]> {
  const [rows] = await pool.query<ContratoAgendaDbRow[]>(
    `SELECT c.id, c.estado, c.monto, c.fecha_creacion, c.fecha_estimada,
            r.fecha_clase, r.duracion_minutos,
            s.titulo AS servicio_titulo, s.imagen, s.imagen_banco_id, s.categoria, bi.archivo AS banco_archivo,
            v.nombre AS otra_persona_nombre
     FROM contratos c
     JOIN servicios s ON c.servicio_id = s.id
     LEFT JOIN banco_imagenes bi ON bi.id = s.imagen_banco_id
     JOIN alumnos v ON c.vendedor_id = v.id
     LEFT JOIN reservas_slots r ON r.contrato_id = c.id
     WHERE c.comprador_id = ?
     ORDER BY COALESCE(r.fecha_clase, c.fecha_estimada, c.fecha_creacion) ASC`,
    [compradorId],
  );
  return rows;
}

// Puerto exacto de mis_contratos.php:50-65.
export async function getContratosComoVendedor(vendedorId: number): Promise<ContratoAgendaRow[]> {
  const [rows] = await pool.query<ContratoAgendaDbRow[]>(
    `SELECT c.id, c.estado, c.monto, c.fecha_creacion, c.fecha_estimada,
            r.fecha_clase, r.duracion_minutos,
            s.titulo AS servicio_titulo, s.imagen, s.imagen_banco_id, s.categoria, bi.archivo AS banco_archivo,
            a.nombre AS otra_persona_nombre
     FROM contratos c
     JOIN servicios s ON c.servicio_id = s.id
     LEFT JOIN banco_imagenes bi ON bi.id = s.imagen_banco_id
     JOIN alumnos a ON c.comprador_id = a.id
     LEFT JOIN reservas_slots r ON r.contrato_id = c.id
     WHERE c.vendedor_id = ?
     ORDER BY COALESCE(r.fecha_clase, c.fecha_estimada, c.fecha_creacion) ASC`,
    [vendedorId],
  );
  return rows;
}
