import type { ResultSetHeader, RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";
import type {
  AlumnoParaPublicarRow,
  CrearApunteInput,
  CrearServicioInput,
} from "./publicar.types.js";

interface AlumnoRowPacket extends AlumnoParaPublicarRow, RowDataPacket {}

export async function getAlumnoParaPublicar(usuarioId: number): Promise<AlumnoParaPublicarRow | null> {
  const [rows] = await pool.query<AlumnoRowPacket[]>(
    "SELECT nombre, correo, institucion, universidad, servicios_publicados_total FROM alumnos WHERE id = ? LIMIT 1",
    [usuarioId],
  );
  return rows[0] ?? null;
}

// Puerto exacto de la selección aleatoria de publicar_servicio.php:170-175 — la portada de
// un servicio nuevo SIEMPRE viene del banco compartido, nunca de un archivo subido por el
// usuario (confirmado leyendo el form real: no hay ningún <input type=file> para imagen ahí).
export async function elegirImagenBancoPorCategoria(categoria: string): Promise<number | null> {
  const [rows] = await pool.query<RowDataPacket[]>(
    "SELECT id FROM banco_imagenes WHERE activa = 1 AND categoria = ? ORDER BY RAND() LIMIT 1",
    [categoria],
  );
  return rows[0] ? (rows[0].id as number) : null;
}

export async function insertarServicio(
  alumnoId: number,
  input: CrearServicioInput,
  institucion: string,
  nombreOferente: string,
  correo: string,
  imagenBancoId: number,
  estadoInicial: "pendiente" | "pendiente_pago",
): Promise<number> {
  const [result] = await pool.query<ResultSetHeader>(
    `INSERT INTO servicios
       (alumno_id, institucion, titulo, descripcion, nombre_oferente, categoria, modalidad, ubicacion, precio, correo, imagen, imagen_banco_id, estado, fecha_publicacion, es_paes)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '', ?, ?, NOW(), ?)`,
    [
      alumnoId,
      institucion,
      input.titulo,
      input.descripcion,
      nombreOferente,
      input.categoria,
      input.modalidad,
      input.ubicacion,
      input.precio,
      correo,
      imagenBancoId,
      estadoInicial,
      input.esPaes ? 1 : 0,
    ],
  );
  return result.insertId;
}

export async function actualizarSlugServicio(servicioId: number, slug: string): Promise<void> {
  if (!slug) return;
  await pool.query("UPDATE servicios SET slug = ? WHERE id = ?", [slug, servicioId]);
}

export async function incrementarContadorPublicaciones(alumnoId: number): Promise<void> {
  await pool.query("UPDATE alumnos SET servicios_publicados_total = servicios_publicados_total + 1 WHERE id = ?", [
    alumnoId,
  ]);
}

// Puerto de app/guardar_horario_servicio.php:48-51 — UPDATE con ownership inline en el
// WHERE en vez del SELECT previo del PHP real (que solo existía para distinguir el mensaje
// de error "no tienes permiso" de "no existe"): acá el servicio_id siempre viene de un id
// recién creado por el propio flujo de publicar, así que esa distinción no aporta nada real.
export async function guardarHorarioServicio(servicioId: number, alumnoId: number, horariosJson: string): Promise<boolean> {
  const [result] = await pool.query<ResultSetHeader>(
    "UPDATE servicios SET horarios_json = ? WHERE id = ? AND alumno_id = ?",
    [horariosJson, servicioId, alumnoId],
  );
  return result.affectedRows > 0;
}

// Puerto exacto de app/eliminar_servicio_incompleto.php:24-34 — mismas 4 condiciones de
// seguridad (dueño real, estado='pendiente', sin horario guardado, creado hace <10 min):
// nunca borra un servicio que ya haya avanzado más allá de "recién creado, a medio publicar".
export async function eliminarServicioIncompleto(servicioId: number, alumnoId: number): Promise<boolean> {
  const [result] = await pool.query<ResultSetHeader>(
    `DELETE FROM servicios
     WHERE id = ?
       AND alumno_id = ?
       AND estado = 'pendiente'
       AND (horarios_json IS NULL OR horarios_json = '')
       AND fecha_publicacion >= (NOW() - INTERVAL 10 MINUTE)`,
    [servicioId, alumnoId],
  );
  return result.affectedRows > 0;
}

export async function insertarApunte(alumnoId: number, input: CrearApunteInput, institucion: string, archivoNombre: string): Promise<number> {
  const [result] = await pool.query<ResultSetHeader>(
    `INSERT INTO apuntes
       (titulo, semestre, anio, descripcion, archivo, id_alumno, institucion, publico, precio, fecha_subida, asignatura, materia, subtema, nivel_academico)
     VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, NOW(), ?, ?, ?, ?)`,
    [
      input.titulo,
      input.semestre,
      input.anio,
      input.descripcion,
      archivoNombre,
      alumnoId,
      institucion,
      input.precio,
      input.asignatura,
      input.materia,
      input.subtema,
      input.nivelAcademico,
    ],
  );
  return result.insertId;
}

export async function actualizarPreviewApunte(apunteId: number, previewNombre: string): Promise<void> {
  await pool.query("UPDATE apuntes SET preview = ?, portada = ? WHERE id = ?", [previewNombre, previewNombre, apunteId]);
}
