import crypto from "node:crypto";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { env } from "../../config/env.js";
import { abreviarInstitucion } from "../../lib/institucion.js";
import {
  avatarCircular,
  botonGenerico,
  cargarImagenComoDataUri,
  envolverTexto,
  imagenPortadaRecortada,
  PALETA_MARCA,
  renderizarCardJpeg,
  textoCentrado,
  textoIzquierda,
} from "../../lib/svgCard.js";
import type { ApunteCompartir } from "./compartir.types.js";

// Puerto VISUAL (ver nota de alcance en svgCard.ts) de nb_generar_imagen_apunte_post()
// (imagen_compartir_apunte.php:206-278) — SOLO formato POST 4:5. Deliberadamente SIN el
// formato HISTORY 9:16 (misma disciplina de slices que Compartir Desafío: primero el
// formato más simple, el otro queda para otra pieza si hace falta).
const VERSION_IMAGEN_APUNTE = "node-v1";
const W = 1080;
const H = 1350;
const M = 110;

const __dirname = path.dirname(fileURLToPath(import.meta.url));
// app/perfil/fotos/ vive FUERA de /upload/ (raíz del repo, junto al sitio PHP) — mismo
// criterio de filesystem compartido ya aceptado en Publicar (env.uploadDir), pero esta
// carpeta específica no cuelga de /upload/, así que no reutiliza esa misma variable.
const FOTOS_TUTOR_DIR = path.resolve(__dirname, "../../../../app/perfil/fotos");

export function fingerprintApunte(a: ApunteCompartir): string {
  const base = [
    VERSION_IMAGEN_APUNTE,
    a.id,
    a.titulo,
    a.precio,
    a.portada ?? "",
    a.fotoPerfil ?? "",
    a.promoGratis ? 1 : 0,
    a.promoContador,
    a.descargas,
  ].join("|");
  return crypto.createHash("md5").update(base).digest("hex").slice(0, 10);
}

// Puerto de nb_ruta_portada_apunte() (imagen_compartir_apunte.php:16-39) — mismo fallback
// de 3 niveles que resolverPortadaApunte() (server/src/lib/media.ts) ya usa para la URL
// pública; acá se necesita la ruta de FILESYSTEM real (para leer bytes), no una URL, por
// eso es una función aparte en vez de reutilizar esa — misma lógica, "salida" distinta.
function rutaPortadaApunte(a: ApunteCompartir): string | null {
  if (a.portada) {
    const carpeta = a.portada === `${a.id}.webp` ? "preview" : "portadas";
    return path.join(env.uploadDir, carpeta, path.basename(a.portada));
  }
  if (a.archivo) {
    const ext = path.extname(a.archivo).slice(1).toLowerCase();
    if (["jpg", "jpeg", "png", "webp", "gif"].includes(ext)) {
      return path.join(env.uploadDir, "apuntes", path.basename(a.archivo));
    }
  }
  return null;
}

function rutaFotoTutor(a: ApunteCompartir): string | null {
  if (!a.fotoPerfil) return null;
  return path.join(FOTOS_TUTOR_DIR, path.basename(a.fotoPerfil));
}

// Mismas 2 iniciales (nombre + apellido) que nb_dibujar_avatar() — no solo la primera
// letra (bug real ya corregido en el PHP, documentado en su propio comentario).
function iniciales(nombre: string | null): string {
  const partes = (nombre?.trim() || "?").split(/\s+/);
  let ini = (partes[0]?.[0] ?? "?").toUpperCase();
  if (partes[1]) ini += partes[1][0]!.toUpperCase();
  return ini;
}

export async function generarImagenApuntePost(a: ApunteCompartir): Promise<Buffer> {
  const partes: string[] = [];

  const dataUriPortada = await cargarImagenComoDataUri(rutaPortadaApunte(a) ?? "");
  partes.push(imagenPortadaRecortada(dataUriPortada, M, 90, W - M * 2, 620, 32, true));
  let y = 90 + 620 + 55;

  const lineasTitulo = envolverTexto(a.titulo, "semibold", 40, W - M * 2, 2);
  lineasTitulo.forEach((linea) => {
    partes.push(textoCentrado(linea, "semibold", 40, PALETA_MARCA.txt, W / 2, y));
    y += 52;
  });
  y += 26;

  const institucionAbrev = a.institucionMaestra ? abreviarInstitucion(a.institucionMaestra, 22) : "";
  const lineaMateria = [a.asignatura, institucionAbrev].filter(Boolean).join(" · ").toUpperCase() || "NUBIRA";
  partes.push(textoCentrado(lineaMateria, "regular", 24, PALETA_MARCA.txt2, W / 2, y));
  y += 66;

  if (a.descargas > 0) {
    const txt = a.descargas === 1 ? "1 descarga" : `${a.descargas} descargas`;
    partes.push(textoCentrado(txt, "regular", 22, PALETA_MARCA.txt2, W / 2, y));
    y += 44;
  }

  const diamAv = 72;
  const dataUriFoto = await cargarImagenComoDataUri(rutaFotoTutor(a) ?? "");
  const avCx = M + Math.round(diamAv / 2);
  partes.push(avatarCircular(dataUriFoto, avCx, y, diamAv, PALETA_MARCA.acento, PALETA_MARCA.blanco, iniciales(a.nombreAlumno)));
  const nombreLineas = envolverTexto(a.nombreAlumno ?? "Tutor Nubira", "semibold", 26, W - M * 2 - diamAv - 20, 1);
  partes.push(textoIzquierda(nombreLineas[0] ?? "", "semibold", 26, PALETA_MARCA.txt, M + diamAv + 20, y + Math.round(diamAv / 2) + 9));
  y += diamAv + 95;

  // Precio — mismo criterio de promo que el PHP real, con tachado del precio original si
  // corresponde.
  const promoActiva = a.promoGratis && a.promoContador < a.promoLimite;
  if (promoActiva && a.precio > 0) {
    const txtGratis = "¡Gratis!";
    const original = `$${Math.round(a.precio).toLocaleString("es-CL")}`;
    // Ancho aproximado: sin medir exacto (evita 2 renders extra solo para centrar 2
    // textos consecutivos) — offsets fijos calibrados visualmente, suficiente para un
    // precio con pocos dígitos como los de apuntes reales.
    partes.push(textoCentrado(txtGratis, "bold", 48, PALETA_MARCA.txt, W / 2 - 90, y));
    partes.push(textoIzquierda(original, "regular", 32, PALETA_MARCA.txt2, W / 2 + 40, y - 8));
    partes.push(`<line x1="${W / 2 + 40}" y1="${y - 17}" x2="${W / 2 + 40 + original.length * 18}" y2="${y - 17}" stroke="${PALETA_MARCA.txt2}" stroke-width="3" />`);
  } else if (a.precio > 0) {
    partes.push(textoCentrado(`$${Math.round(a.precio).toLocaleString("es-CL")} CLP`, "bold", 48, PALETA_MARCA.txt, W / 2, y));
  } else {
    partes.push(textoCentrado("Gratis", "bold", 48, PALETA_MARCA.txt, W / 2, y));
  }
  y += 95;

  // botonGenerico centra en xCentro; el PHP real ancla el botón a la izquierda (x=M) — se
  // le pasa un xCentro equivalente a "ancla izquierda + mitad del ancho real del botón"
  // (mismo ancho que ya devuelve la función) para lograr el mismo anclaje visual.
  const anchoBotonAprox = 220;
  partes.push(botonGenerico("Ver apunte", M + anchoBotonAprox / 2, y, PALETA_MARCA.acento).svg);
  partes.push(textoCentrado("Nubira.cl", "bold", 28, PALETA_MARCA.acento, W - M - 70, y + 42));

  return renderizarCardJpeg(partes.join("\n"), W, H, 90);
}
