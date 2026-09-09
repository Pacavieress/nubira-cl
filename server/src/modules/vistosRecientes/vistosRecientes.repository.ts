import type { RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";
import { SELECT_APUNTE, WHERE_VISIBLE as WHERE_VISIBLE_APUNTE } from "../apuntes/apuntes.repository.js";
import type { ApunteRow } from "../apuntes/apuntes.types.js";
import { SELECT_SERVICIO, WHERE_VISIBLE as WHERE_VISIBLE_SERVICIO } from "../servicios/servicios.repository.js";
import type { ServicioRow } from "../servicios/servicios.types.js";

interface ServicioRowPacket extends ServicioRow, RowDataPacket {}
interface ApunteRowPacket extends ApunteRow, RowDataPacket {}
interface LogRowPacket extends RowDataPacket {
  entidad_tipo: string;
  entidad_id: number;
}

export type ItemOrdenado = { tipo: "servicio"; fila: ServicioRow } | { tipo: "apunte"; fila: ApunteRow };

// Réplica exacta de la query de app/cargar_vistos.php:26-33 — últimas 10 vistas
// (servicio/apunte) del usuario, agrupadas por entidad y ordenadas por la vista más
// reciente primero.
const SELECT_LOG_VISTOS = `
  SELECT entidad_tipo, entidad_id, MAX(fecha) as fecha_reciente
  FROM nubira_behavior_logs
  WHERE usuario_id = ?
  AND tipo_evento = 'view'
  AND entidad_tipo IN ('servicio', 'apunte')
  GROUP BY entidad_tipo, entidad_id
  ORDER BY fecha_reciente DESC
  LIMIT 10
`;

// Puerto exacto de app/cargar_vistos.php:42 — bajo 3 vistas, la sección entera no se
// muestra (no tiene sentido "sigue donde lo dejaste" con 1-2 vistas sueltas).
const UMBRAL_MINIMO = 3;

export async function fetchVistosRecientes(usuarioId: number): Promise<ItemOrdenado[]> {
  const [logRows] = await pool.query<LogRowPacket[]>(SELECT_LOG_VISTOS, [usuarioId]);

  if (logRows.length < UMBRAL_MINIMO) return [];

  // Puerto de app/cargar_vistos.php:51-52 — normaliza igual que el PHP (trim+lowercase);
  // cualquier valor que no sea literalmente "servicio" se trata como apunte.
  const idsOrdenados = logRows.map((row) => ({
    tipo: (row.entidad_tipo.toLowerCase().trim() === "servicio" ? "servicio" : "apunte") as "servicio" | "apunte",
    id: row.entidad_id,
  }));

  const idsServicios = idsOrdenados.filter((item) => item.tipo === "servicio").map((item) => item.id);
  const idsApuntes = idsOrdenados.filter((item) => item.tipo === "apunte").map((item) => item.id);

  const mapaServicios = new Map<number, ServicioRow>();
  if (idsServicios.length > 0) {
    const [rows] = await pool.query<ServicioRowPacket[]>(
      `${SELECT_SERVICIO} ${WHERE_VISIBLE_SERVICIO} AND s.id IN (${idsServicios.map(() => "?").join(",")})`,
      idsServicios,
    );
    for (const row of rows) mapaServicios.set(row.id, row);
  }

  const mapaApuntes = new Map<number, ApunteRow>();
  if (idsApuntes.length > 0) {
    const [rows] = await pool.query<ApunteRowPacket[]>(
      `${SELECT_APUNTE} ${WHERE_VISIBLE_APUNTE} AND ap.id IN (${idsApuntes.map(() => "?").join(",")})`,
      idsApuntes,
    );
    for (const row of rows) mapaApuntes.set(row.id, row);
  }

  // Reordena según el log original — el IN (...) no preserva orden. Mismo criterio que
  // el foreach de cargar_vistos.php:96-200: cualquier id que no resolvió a una fila real
  // (no visible/aprobada, o borrada) se descarta en silencio, sin romper el resto.
  const resultado: ItemOrdenado[] = [];
  for (const item of idsOrdenados) {
    if (item.tipo === "servicio") {
      const fila = mapaServicios.get(item.id);
      if (fila) resultado.push({ tipo: "servicio", fila });
    } else {
      const fila = mapaApuntes.get(item.id);
      if (fila) resultado.push({ tipo: "apunte", fila });
    }
  }

  return resultado;
}
