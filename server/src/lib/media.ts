// Helpers de resolución de URLs de media, compartidos entre el módulo servicios y tutores
// (extraído de servicios.mapper.ts en Fase 6 para no duplicarlo en tutores.mapper.ts).

const NB_BANCO_WEB = "/upload/banco/";
const NB_SERVICIOS_WEB = "/upload/servicios/";
const NB_PLACEHOLDER = "placeholder";
const NB_PERFIL_FOTOS_WEB = "/app/perfil/fotos/";

// Prefija assetsBaseUrl SOLO a rutas internas (relativas) — una URL ya absoluta
// (ej. ui-avatars.com) se deja intacta. Sin esto, un consumidor externo (web/) recibiría
// <img src="/upload/..."> resuelto contra SU PROPIO origen, no el del sitio real.
function conBase(assetsBaseUrl: string, ruta: string): string {
  return ruta.startsWith("http") ? ruta : `${assetsBaseUrl}${ruta}`;
}

// Mismo criterio que app/componentes/render_card.php:85-87: si el tutor no tiene
// foto_perfil, cae a un avatar generado externamente con su nombre (no un placeholder
// local) — se replica tal cual, no una decisión nueva de esta fase.
export function resolverFotoTutor(
  fotoPerfil: string | null,
  nombreTutor: string | null,
  assetsBaseUrl: string,
): string {
  if (fotoPerfil) return conBase(assetsBaseUrl, `${NB_PERFIL_FOTOS_WEB}${fotoPerfil}`);
  const nombre = encodeURIComponent(nombreTutor ?? "");
  return `https://ui-avatars.com/api/?name=${nombre}&background=54A6D8&color=fff&size=128&bold=true`;
}

function baseName(archivo: string): string {
  const idx = archivo.lastIndexOf(".");
  return idx === -1 ? archivo : archivo.slice(0, idx);
}

export interface PortadaVariantes {
  thumb: string;
  card: string;
  main: string;
}

// Simplificación consciente (Fase 4, decisión 2d ya aprobada): construye la URL esperada
// del pipeline de 3 tamaños SIN verificar existencia en filesystem, a diferencia de
// app/helpers/imagen_servicio.php (que sí valida is_file() con fallback en cascada).
// Node no debe asumir que comparte filesystem con PHP — hoy es cierto por coincidencia
// del entorno local (mismo repo/máquina), no por diseño; esa asunción se rompería si
// Node y PHP terminan en servidores distintos (decisión de hosting todavía pendiente).
// Riesgo aceptado: alguna imagen legacy previa al pipeline podría devolver una URL rota.
const NB_PORTADAS_WEB = "/upload/portadas/";
const NB_PREVIEW_WEB = "/upload/preview/";
const NB_APUNTES_WEB = "/upload/apuntes/";
const NB_PLACEHOLDER_APUNTE = "/img/logo2.webp";
const EXTS_IMAGEN = new Set(["jpg", "jpeg", "png", "webp", "gif", "bmp"]);

// BUG REAL CORREGIDO (encontrado en vivo: cards de apuntes en la home sin imagen,
// devolvían 404). La versión anterior asumía que la columna `portada` siempre apunta a
// un archivo dentro de /upload/portadas/ — verificado contra el filesystem real
// (C:/nubira/upload/, mismo docroot que sirve nubira.local) que eso es falso: esa carpeta
// tiene UN solo archivo en today. De 62 apuntes con `portada` poblada, 61 tienen
// portada = "{id}.webp" — el nombre que arma el pipeline de miniaturas automático, cuyo
// archivo real vive en /upload/preview/, NO en /upload/portadas/ (confirmado: 71 archivos
// ahí, incluidos 320.webp/321.webp). Solo 1 apunte (id 291) tiene un nombre realmente
// custom ("apt_...webp") y ESE sí vive en /upload/portadas/ — es el único archivo que hay
// ahí. Puerto de obtenerMiniaturaApunte() (app/helpers/portada_helper.php:93-128) con esa
// distinción: portada="{id}.webp" (patrón automático) → /upload/preview/; portada con
// cualquier otro nombre (subida manual) → /upload/portadas/. Sigue sin validar
// file_exists() (Node no comparte filesystem por diseño, solo por coincidencia local) —
// la distinción es 100% determinística a partir de los valores ya en la fila, no requiere
// tocar disco. El parámetro $previewBD de la función real nunca se usa en su cuerpo
// (columna `preview` vestigial, confirmado leyendo el helper completo) — no se replica acá.
export function resolverPortadaApunte(
  id: number,
  portada: string | null,
  archivo: string | null,
  assetsBaseUrl: string,
): string {
  if (portada) {
    const carpeta = portada === `${id}.webp` ? NB_PREVIEW_WEB : NB_PORTADAS_WEB;
    return conBase(assetsBaseUrl, `${carpeta}${portada}`);
  }
  if (archivo) {
    const ext = archivo.slice(archivo.lastIndexOf(".") + 1).toLowerCase();
    if (EXTS_IMAGEN.has(ext)) return conBase(assetsBaseUrl, `${NB_APUNTES_WEB}${archivo}`);
  }
  return conBase(assetsBaseUrl, NB_PLACEHOLDER_APUNTE);
}

export function resolverPortada(
  bancoArchivo: string | null,
  imagenLegacy: string | null,
  assetsBaseUrl: string,
): PortadaVariantes {
  let webDir = NB_BANCO_WEB;
  let archivo = bancoArchivo;

  if (!archivo) {
    if (imagenLegacy) {
      webDir = NB_SERVICIOS_WEB;
      archivo = imagenLegacy;
    } else {
      archivo = NB_PLACEHOLDER;
    }
  }

  const base = baseName(archivo);
  return {
    thumb: conBase(assetsBaseUrl, `${webDir}${base}_thumb.webp`),
    card: conBase(assetsBaseUrl, `${webDir}${base}_card.webp`),
    main: conBase(assetsBaseUrl, `${webDir}${base}.webp`),
  };
}
