import type { RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";
import type { AutoresFiltros } from "./adminAutores.types.js";

interface AutorRow extends RowDataPacket {
  id_usuario: number;
  nombre_usuario: string;
  correo: string;
  institucion: string | null;
  foto_perfil: string | null;
  bio: string | null;
  tipo: string | null;
  cantidad_servicios: number;
  servicios_con_horario: number;
  ultima_publicacion: Date | null;
  total_conversaciones: number;
  ultimo_asunto: string | null;
  ultimo_mensaje: string | null;
  fecha_ultimo_correo: Date | null;
  exito_ultimo: number | null;
  portada_servicio: string | null;
}

// Puerto exacto de admin_autores_servicios.php:32-104 — mismo GROUP BY/HAVING (el filtro
// "incompleto" usa el mismo criterio: falta foto/bio/tipo, O tiene algún servicio sin
// horario cargado), mismo ORDER BY cantidad_servicios DESC. El HAVING no lleva parámetros
// (solo referencia expresiones agregadas), así que es seguro concatenarlo tal cual.
export async function listarAutores(filtros: AutoresFiltros): Promise<AutorRow[]> {
  let sql = `
    SELECT
        a.id AS id_usuario,
        a.nombre AS nombre_usuario,
        a.correo,
        a.institucion,
        a.foto_perfil,
        a.bio,
        a.tipo,
        COUNT(DISTINCT s.id) AS cantidad_servicios,
        COUNT(DISTINCT CASE WHEN s.horarios_json IS NOT NULL
                            AND s.horarios_json != ''
                            AND s.horarios_json LIKE '% - %'
                            THEN s.id END) AS servicios_con_horario,
        MAX(s.fecha_publicacion) AS ultima_publicacion,
        (SELECT COUNT(*) FROM conversaciones c WHERE c.comprador_id = a.id OR c.vendedor_id = a.id) AS total_conversaciones,
        (SELECT asunto FROM correos_admin ca WHERE ca.destinatario = a.correo ORDER BY ca.fecha_envio DESC LIMIT 1) AS ultimo_asunto,
        (SELECT mensaje FROM correos_admin ca WHERE ca.destinatario = a.correo ORDER BY ca.fecha_envio DESC LIMIT 1) AS ultimo_mensaje,
        (SELECT fecha_envio FROM correos_admin ca WHERE ca.destinatario = a.correo ORDER BY ca.fecha_envio DESC LIMIT 1) AS fecha_ultimo_correo,
        (SELECT exito FROM correos_admin ca WHERE ca.destinatario = a.correo ORDER BY ca.fecha_envio DESC LIMIT 1) AS exito_ultimo,
        (SELECT s2.imagen FROM servicios s2 WHERE s2.alumno_id = a.id AND s2.imagen IS NOT NULL AND s2.imagen != '' ORDER BY s2.fecha_publicacion DESC LIMIT 1) AS portada_servicio
    FROM servicios s
    INNER JOIN alumnos a ON a.id = s.alumno_id AND s.estado = 'aprobado'
  `;

  const params: string[] = [];
  if (filtros.q) {
    sql += " WHERE (a.nombre LIKE ? OR a.correo LIKE ? OR a.institucion LIKE ?)";
    const like = `%${filtros.q}%`;
    params.push(like, like, like);
  }
  sql += " GROUP BY a.id";
  if (filtros.filtro === "incompleto") {
    sql += ` HAVING (a.foto_perfil IS NULL OR a.foto_perfil = '' OR a.bio IS NULL OR a.bio = '' OR a.tipo IS NULL OR a.tipo = '')
                  OR (COUNT(DISTINCT CASE WHEN s.horarios_json IS NOT NULL AND s.horarios_json != '' AND s.horarios_json LIKE '% - %' THEN s.id END) < COUNT(DISTINCT s.id))`;
  }
  sql += " ORDER BY cantidad_servicios DESC";

  const [rows] = await pool.query<AutorRow[]>(sql, params);
  return rows;
}
