import type { RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";
import { SELECT_APUNTE, WHERE_VISIBLE as WHERE_VISIBLE_APUNTE } from "../apuntes/apuntes.repository.js";
import type { ApunteRow } from "../apuntes/apuntes.types.js";
import { SELECT_SERVICIO, WHERE_VISIBLE as WHERE_VISIBLE_SERVICIO } from "../servicios/servicios.repository.js";
import type { ServicioRow } from "../servicios/servicios.types.js";
import type { BannerRow } from "./home.types.js";

interface ServicioRowPacket extends ServicioRow, RowDataPacket {}
interface ApunteRowPacket extends ApunteRow, RowDataPacket {}
interface BannerRowPacket extends BannerRow, RowDataPacket {}

export interface HomeDataRaw {
  banner: BannerRow | null;
  serviciosRecomendados: ServicioRow[];
  serviciosNuevos: ServicioRow[];
  apuntesRecomendados: ApunteRow[];
  clasesPaes: ServicioRow[];
  ofertas: ServicioRow[];
}

// Puerto de app/helpers/usuario_helper.php::nb_condicion_foto_real() — 1/0 según si la
// foto de perfil es real (no un avatar por defecto). Requiere "alumnos" aliasado como
// se aliasa en cada SELECT reutilizado (a en servicios, al en apuntes).
const TIENE_FOTO_REAL_SERVICIO = `CASE WHEN TRIM(COALESCE(a.foto_perfil, '')) NOT IN ('default.png','default_avatar.webp','default_avatar.png','') THEN 1 ELSE 0 END`;
const TIENE_FOTO_REAL_APUNTE = `CASE WHEN TRIM(COALESCE(al.foto_perfil, '')) NOT IN ('default.png','default_avatar.webp','default_avatar.png','') THEN 1 ELSE 0 END`;
// Puerto exacto de vitrina.php:211 — horarios_json no vacío.
const TIENE_HORARIO = `CASE WHEN COALESCE(s.horarios_json, '') NOT IN ('', '{}', '[]') THEN 1 ELSE 0 END`;
// Puerto exacto de vitrina.php:213 — video de presentación aprobado.
const TIENE_VIDEO = `CASE WHEN s.video_estado = 'aprobado' THEN 1 ELSE 0 END`;

// Fetch genérico de servicios con dedup: excluye los ids ya usados en secciones
// anteriores de la misma carga (puerto de $ids_servicios_usados/$ids_usados en
// vitrina.php, que se acumulan sección tras sección). El orden real de PHP termina en
// RAND($seed) (rota cada 30 min) — acá termina en s.id DESC/ASC según la sección, mismo
// criterio determinístico ya aprobado para /api/servicios (Fase 4, decisión 1).
async function fetchServiciosDedup(
  extraWhere: string,
  extraParams: Array<string | number>,
  orderBy: string,
  limit: number,
  excluidos: number[],
): Promise<ServicioRow[]> {
  const exclusion = excluidos.length > 0 ? `AND s.id NOT IN (${excluidos.map(() => "?").join(",")})` : "";
  const [rows] = await pool.query<ServicioRowPacket[]>(
    `${SELECT_SERVICIO} ${WHERE_VISIBLE_SERVICIO} ${extraWhere} ${exclusion} ORDER BY ${orderBy} LIMIT ?`,
    [...extraParams, ...excluidos, limit],
  );
  return rows;
}

// Puerto de vitrina.php:400-409 — carrusel "Apuntes de los que aprobaron" (rama de
// fallback sin afinidad, la única alcanzable sin sesión). Usa el WHERE_VISIBLE ya
// establecido de apuntes.repository.ts en vez del WHERE distinto (más laxo) que
// vitrina.php usa acá — mismo criterio de reutilización ya aplicado en tutores.
async function fetchApuntesRecomendados(): Promise<ApunteRow[]> {
  const [rows] = await pool.query<ApunteRowPacket[]>(
    `${SELECT_APUNTE} ${WHERE_VISIBLE_APUNTE} AND ap.nivel_academico != 'paes'
     ORDER BY ${TIENE_FOTO_REAL_APUNTE} DESC, ap.id DESC
     LIMIT 10`,
  );
  return rows;
}

// Puerto de vitrina.php:677-701 — banner inline. Para un visitante sin institución
// (único caso posible en web/ hoy) el filtro de institución de PHP nunca se activa
// (`if (!$es_admin && $institucion !== '')`), así que esta query ya es el equivalente
// exacto: el primer banner activo por orden, sin importar institución.
async function fetchBanner(): Promise<BannerRow | null> {
  const [rows] = await pool.query<BannerRowPacket[]>(
    `SELECT id, titulo, imagen, enlace FROM banners
     WHERE activo = 1 AND posicion = 'vitrina_inline'
     ORDER BY orden ASC
     LIMIT 1`,
  );
  return rows[0] ?? null;
}

export async function getHomeDataRaw(): Promise<HomeDataRaw> {
  const excluidos: number[] = [];

  // 1. Tutorías recomendadas (vitrina.php:217-246, rama sin afinidad)
  const serviciosRecomendados = await fetchServiciosDedup(
    "",
    [],
    `${TIENE_FOTO_REAL_SERVICIO} DESC, ${TIENE_VIDEO} DESC, ${TIENE_HORARIO} DESC, s.id DESC`,
    8,
    excluidos,
  );
  excluidos.push(...serviciosRecomendados.map((r) => r.id));

  // 2. Tutorías nuevas (vitrina.php:257-290)
  const serviciosNuevos = await fetchServiciosDedup(
    "",
    [],
    `${TIENE_FOTO_REAL_SERVICIO} DESC, ${TIENE_HORARIO} DESC, s.id DESC`,
    8,
    excluidos,
  );
  excluidos.push(...serviciosNuevos.map((r) => r.id));

  // 3. PAES (vitrina.php:351-391) — solo se muestra si hay >=4 resultados reales
  // (mismo gate que PHP: num_rows >= 4, si no $res_clases_paes = null).
  const likePaes = "%PAES%";
  const paesCandidatos = await fetchServiciosDedup(
    "AND (s.titulo LIKE ? OR s.descripcion LIKE ? OR s.categoria LIKE ? OR s.materia LIKE ? OR s.asignatura LIKE ? OR s.area LIKE ? OR s.es_paes = 1)",
    [likePaes, likePaes, likePaes, likePaes, likePaes, likePaes],
    `${TIENE_FOTO_REAL_SERVICIO} DESC, ${TIENE_VIDEO} DESC, ${TIENE_HORARIO} DESC, s.id DESC`,
    12,
    excluidos,
  );
  const clasesPaes = paesCandidatos.length >= 4 ? paesCandidatos : [];
  if (clasesPaes.length > 0) excluidos.push(...clasesPaes.map((r) => r.id));

  // 4. Precios de última hora / Ofertas (vitrina.php:471-535) — con relleno silencioso
  // (sin badge de oferta, ver ServicioCard: ofertaVigente ya sale false para esas filas)
  // si hay ofertas reales pero menos de 6.
  const ofertasReales = await fetchServiciosDedup(
    "AND s.is_subvencionado = 1 AND (s.oferta_termino IS NULL OR s.oferta_termino >= CURDATE())",
    [],
    `${TIENE_FOTO_REAL_SERVICIO} DESC, ${TIENE_HORARIO} DESC, (s.cupos_oferta > 0) DESC, s.id DESC`,
    12,
    excluidos,
  );
  const tieneOfertasActivas = ofertasReales.some((r) => r.cupos_oferta > 0);
  let ofertas: ServicioRow[] = [];
  if (tieneOfertasActivas) {
    ofertas = [...ofertasReales];
    excluidos.push(...ofertasReales.map((r) => r.id));
    if (ofertas.length < 6) {
      const faltan = Math.min(3, 6 - ofertas.length);
      const relleno = await fetchServiciosDedup("", [], `${TIENE_FOTO_REAL_SERVICIO} DESC, ${TIENE_HORARIO} DESC, s.id ASC`, faltan, excluidos);
      ofertas.push(...relleno);
    }
  }

  const [apuntesRecomendados, banner] = await Promise.all([fetchApuntesRecomendados(), fetchBanner()]);

  return { banner, serviciosRecomendados, serviciosNuevos, apuntesRecomendados, clasesPaes, ofertas };
}
