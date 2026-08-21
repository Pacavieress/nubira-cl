import type { RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";
import type { ApunteCompartir, DatosPreguntasCompartir, FormatoShare, MateriaCompartir, OpcionLetra, PreguntaCompartir, ServicioCompartir } from "./compartir.types.js";

export async function getMateriaActiva(slug: string): Promise<MateriaCompartir | null> {
  const [rows] = await pool.query<RowDataPacket[]>("SELECT slug, nombre FROM materias WHERE slug = ? AND activa = 1 LIMIT 1", [slug]);
  return (rows[0] as MateriaCompartir) ?? null;
}

// Puerto de app/track_share.php (tipo='desafio') — tabla real ya existía con filas de
// producción (confirmado con SELECT antes de tocar nada), ver
// sql/pendientes/shares_desafio_fase1.sql.
export async function registrarShareDesafio(materiaSlug: string, formato: FormatoShare, ip: string | null, userAgent: string | null): Promise<void> {
  await pool.query("INSERT INTO shares_desafio (materia_slug, formato, ip, user_agent) VALUES (?, ?, ?, ?)", [materiaSlug, formato, ip, userAgent]);
}

// Puerto de app/track_share.php (tipo='apunte') — tabla real ya existía (ver
// sql/pendientes/shares_apunte.sql), mismo shape que shares_desafio salvo la columna clave
// (apunte_id numérico en vez de materia_slug).
export async function registrarShareApunte(apunteId: number, formato: FormatoShare, ip: string | null, userAgent: string | null): Promise<void> {
  await pool.query("INSERT INTO shares_apunte (apunte_id, formato, ip, user_agent) VALUES (?, ?, ?, ?)", [apunteId, formato, ip, userAgent]);
}

// Puerto de app/track_share.php (tipo='servicio', el default) — tabla real ya existía (ver
// track_share.php:44, confirmado con DESCRIBE antes de tocar nada), mismo shape que las
// otras 2 tablas de shares salvo la columna clave (servicio_id).
export async function registrarShareServicio(servicioId: number, formato: FormatoShare, ip: string | null, userAgent: string | null): Promise<void> {
  await pool.query("INSERT INTO shares_servicio (servicio_id, formato, ip, user_agent) VALUES (?, ?, ?, ?)", [servicioId, formato, ip, userAgent]);
}

interface PreguntaCompartirDbRow extends RowDataPacket {
  id: number;
  materia_slug: string;
  tipo: string;
  enunciado: string;
  opcion_a: string | null;
  opcion_b: string | null;
  opcion_c: string | null;
  opcion_d: string | null;
}

// Puerto exacto de nb_datos_preguntas_desafio() (imagen_compartir_desafio.php:158-203) —
// exige exactamente 3 ids positivos distintos, las 3 filas existen/activas/revisadas, Y
// las 3 comparten la MISMA materia (evita una card con badge de materia incoherente).
// respuesta_correcta NUNCA se selecciona acá — mismo criterio de seguridad que
// cargar_desafio.php: esta ruta jamás debe poder filtrar cuál opción es la correcta.
export async function getPreguntasParaCompartir(ids: number[]): Promise<DatosPreguntasCompartir | null> {
  if (ids.length !== 3 || new Set(ids).size !== 3 || ids.some((id) => !Number.isInteger(id) || id <= 0)) {
    return null;
  }

  const [rows] = await pool.query<PreguntaCompartirDbRow[]>(
    `SELECT id, materia_slug, tipo, enunciado, opcion_a, opcion_b, opcion_c, opcion_d
     FROM desafio_preguntas
     WHERE id IN (?, ?, ?) AND activa = 1 AND revisado_por_admin = 1`,
    ids,
  );
  if (rows.length !== 3) return null;

  const porId = new Map(rows.map((r) => [r.id, r]));
  const slugsUnicos = new Set(rows.map((r) => r.materia_slug));
  if (slugsUnicos.size !== 1) return null;

  const materia = await getMateriaActiva(rows[0]!.materia_slug);
  if (!materia) return null;

  const preguntas: PreguntaCompartir[] = ids.map((id) => {
    const row = porId.get(id)!;
    const opciones: PreguntaCompartir["opciones"] = {};
    const mapaCols: Record<OpcionLetra, string | null> = { a: row.opcion_a, b: row.opcion_b, c: row.opcion_c, d: row.opcion_d };
    for (const letra of ["a", "b", "c", "d"] as OpcionLetra[]) {
      const valor = mapaCols[letra];
      if (valor !== null && valor !== "") opciones[letra] = valor;
    }
    return { id: row.id, tipo: row.tipo, enunciado: row.enunciado, opciones };
  });

  return { materia, preguntas };
}

interface ApunteCompartirDbRow extends RowDataPacket {
  id: number;
  titulo: string;
  precio: string | number;
  portada: string | null;
  archivo: string | null;
  asignatura: string | null;
  promo_gratis: number;
  promo_limite: number;
  promo_contador: number;
  descargas: number;
  nombre_alumno: string | null;
  foto_perfil: string | null;
  institucion_maestra: string | null;
}

// Puerto exacto de la SELECT de nb_obtener_imagen_apunte() (imagen_compartir_apunte.php:
// 395-407) — mismo gate: solo apuntes con estado='aprobado' (app/img_apunte.php:100-102
// hace este chequeo aparte del SELECT; acá se fusiona en el propio WHERE).
export async function getApunteParaCompartir(id: number): Promise<ApunteCompartir | null> {
  const [rows] = await pool.query<ApunteCompartirDbRow[]>(
    `SELECT ap.id, ap.titulo, ap.precio, ap.portada, ap.archivo, ap.asignatura,
            ap.promo_gratis, ap.promo_limite, ap.promo_contador, ap.descargas,
            a.nombre AS nombre_alumno, a.foto_perfil,
            COALESCE(dp.institucion, NULLIF(ap.institucion, ''), a.institucion) AS institucion_maestra
     FROM apuntes ap
     JOIN alumnos a ON a.id = ap.id_alumno
     LEFT JOIN dominios_permitidos dp ON a.dominio = dp.dominio
     WHERE ap.id = ? AND ap.estado = 'aprobado'
     LIMIT 1`,
    [id],
  );
  const row = rows[0];
  if (!row) return null;

  return {
    id: row.id,
    titulo: row.titulo,
    precio: Number(row.precio),
    portada: row.portada,
    archivo: row.archivo,
    asignatura: row.asignatura,
    promoGratis: row.promo_gratis === 1,
    promoLimite: row.promo_limite,
    promoContador: row.promo_contador,
    descargas: row.descargas,
    nombreAlumno: row.nombre_alumno,
    fotoPerfil: row.foto_perfil,
    institucionMaestra: row.institucion_maestra,
  };
}

interface ServicioCompartirDbRow extends RowDataPacket {
  id: number;
  titulo: string;
  categoria: string | null;
  precio: string | number;
  precio_oferta: string | number | null;
  is_subvencionado: number;
  oferta_termino: Date | null;
  nombre_alumno: string | null;
  foto_perfil: string | null;
  institucion_maestra: string | null;
  rating_prom: string | number;
  rating_votos: number;
}

// Puerto exacto de la SELECT de nb_obtener_imagen_compartir() (imagen_compartir.php:
// 932-944) — mismo gate que img_servicio.php:117-122: estado='aprobado' Y
// COALESCE(visible,1)=1 (fusionado en el propio WHERE, igual que se hizo con apuntes).
export async function getServicioParaCompartir(id: number): Promise<ServicioCompartir | null> {
  const [rows] = await pool.query<ServicioCompartirDbRow[]>(
    `SELECT s.id, s.titulo, s.categoria, s.precio, s.precio_oferta, s.is_subvencionado, s.oferta_termino,
            a.nombre AS nombre_alumno, a.foto_perfil,
            COALESCE(dp.institucion, a.institucion) AS institucion_maestra,
            COALESCE((SELECT ROUND(AVG(v.calificacion),1) FROM valoraciones v
                      WHERE v.servicio_id = s.id AND v.calificacion > 0
                        AND v.rol_evaluado = 'vendedor'), 0) AS rating_prom,
            (SELECT COUNT(*) FROM valoraciones v
             WHERE v.servicio_id = s.id AND v.calificacion > 0
               AND v.rol_evaluado = 'vendedor') AS rating_votos
     FROM servicios s
     LEFT JOIN alumnos a ON s.alumno_id = a.id
     LEFT JOIN dominios_permitidos dp ON a.dominio = dp.dominio
     WHERE s.id = ? AND s.estado = 'aprobado' AND COALESCE(s.visible, 1) = 1
     LIMIT 1`,
    [id],
  );
  const row = rows[0];
  if (!row) return null;

  return {
    id: row.id,
    titulo: row.titulo,
    categoria: row.categoria,
    precio: Number(row.precio),
    precioOferta: row.precio_oferta !== null ? Number(row.precio_oferta) : null,
    isSubvencionado: row.is_subvencionado === 1,
    ofertaTermino: row.oferta_termino,
    nombreAlumno: row.nombre_alumno,
    fotoPerfil: row.foto_perfil,
    institucionMaestra: row.institucion_maestra,
    ratingProm: Number(row.rating_prom),
    ratingVotos: row.rating_votos,
  };
}
