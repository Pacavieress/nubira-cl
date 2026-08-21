import fs from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { Resvg } from "@resvg/resvg-js";
import sharp from "sharp";

// Motor de generación de imágenes "compartir" (desafio por ahora) — puerto DELIBERADAMENTE
// distinto de app/helpers/imagen_compartir.php: el PHP real dibuja con GD (imagettftext,
// imagettfbbox, primitivas de bajo nivel medidas a mano). Node no tiene GD; la alternativa
// más fiel sería replicar cada llamada GD con una librería de canvas — se probó primero
// con sharp's renderer SVG nativo (librsvg) y el @font-face embebido con data URI NO se
// aplicaba (fallback silencioso a una fuente genérica, verificado visualmente). Se
// resolvió con @resvg/resvg-js (Rust/resvg vía NAPI, sin navegador headless, mismo peso de
// dependencia que sharp) — SÍ carga los .ttf reales como archivos locales (`fontFiles`) y
// SÍ centra texto nativamente vía `text-anchor="middle"`, evitando además la matemática
// manual de bbox/centrado que el PHP real necesitaba solo por las limitaciones de GD.
// Resultado: visualmente equivalente al diseño real, NO byte-a-byte idéntico al output de
// GD — decisión consciente, no un descuido (el objetivo de una card para redes sociales es
// verse bien, no ser un port pixel-perfecto).

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const FONTS_DIR = path.resolve(__dirname, "../assets/fonts");
const FONT_FILES = [path.join(FONTS_DIR, "Inter-Regular.ttf"), path.join(FONTS_DIR, "Inter-SemiBold.ttf"), path.join(FONTS_DIR, "Inter-Bold.ttf")];

// Puerto exacto de nb_paleta_marca() (imagen_compartir.php:343-351).
export const PALETA_MARCA = {
  bg: "#F0F6FA",
  acento: "#54A6D8",
  txt: "#1A1A1A",
  txt2: "#6B7280",
  blanco: "#FFFFFF",
};

export type PesoFuente = "regular" | "semibold" | "bold";

const PESO_A_CSS: Record<PesoFuente, number> = { regular: 400, semibold: 600, bold: 700 };

function escaparXml(texto: string): string {
  return texto.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
}

// Puerto de nb_ancho_texto() (imagettfbbox real) — mide el ancho REAL renderizando un SVG
// de 1 línea y leyendo su bounding box, en vez de una heurística de ancho-promedio-por-
// caracter (que con Inter, un font proporcional, sería notoriamente impreciso para
// mayúsculas/números vs minúsculas angostas como "i"/"l").
export function medirAnchoTexto(texto: string, peso: PesoFuente, size: number): number {
  if (texto === "") return 0;
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10">
    <text x="0" y="${size}" font-family="Inter" font-weight="${PESO_A_CSS[peso]}" font-size="${size}">${escaparXml(texto)}</text>
  </svg>`;
  const resvg = new Resvg(svg, { font: { fontFiles: FONT_FILES, loadSystemFonts: false, defaultFontFamily: "Inter" } });
  const bbox = resvg.getBBox();
  return bbox ? bbox.width : 0;
}

// Puerto de nb_wrap_texto() — greedy word-wrap, con tope de líneas (la última línea que
// cabe se queda con todo el resto si se excede el máximo, igual criterio que el PHP real
// para nunca perder contenido silenciosamente por exceso de líneas).
export function envolverTexto(texto: string, peso: PesoFuente, size: number, maxAncho: number, maxLineas: number): string[] {
  const palabras = texto.split(/\s+/).filter(Boolean);
  if (palabras.length === 0) return [];

  const lineas: string[] = [];
  let actual = "";

  for (const palabra of palabras) {
    const candidata = actual === "" ? palabra : `${actual} ${palabra}`;
    if (medirAnchoTexto(candidata, peso, size) <= maxAncho || actual === "") {
      actual = candidata;
    } else {
      lineas.push(actual);
      actual = palabra;
      if (lineas.length === maxLineas - 1) break;
    }
  }
  if (actual) lineas.push(actual);

  // Si sobraron palabras sin procesar (tope de líneas alcanzado), se agregan a la última
  // línea tal cual — mismo criterio "no perder contenido" que arriba.
  const usadas = lineas.join(" ").split(/\s+/).length;
  if (usadas < palabras.length && lineas.length > 0) {
    lineas[lineas.length - 1] += " " + palabras.slice(usadas).join(" ");
  }

  return lineas;
}

export interface ElementoSvg {
  svg: string;
}

// Texto centrado horizontalmente — usa text-anchor nativo de SVG, sin necesitar medir
// ancho primero (a diferencia del nb_texto_centrado del PHP real, que si lo necesitaba
// por las limitaciones de imagettftext).
export function textoCentrado(texto: string, peso: PesoFuente, size: number, color: string, xCentro: number, yBaseline: number): string {
  return `<text x="${xCentro}" y="${yBaseline}" font-family="Inter" font-weight="${PESO_A_CSS[peso]}" font-size="${size}" fill="${color}" text-anchor="middle">${escaparXml(texto)}</text>`;
}

// preservarEspacios: por defecto SVG aplica xml:space="default" (whitespace-collapse),
// que TRUNCA espacios al inicio/fin del contenido de <text> — a diferencia de GD
// (imagettftext dibuja bytes literales, sin ningún procesamiento de whitespace tipo XML).
// Hallazgo real (verificado visualmente, no una sospecha): un título partido en 2 <text>
// adyacentes ("Clases particulares de " + "Lenguaje" coloreado) perdía el espacio entre
// ambos — "particulares deLenguaje". Necesario cuando un segmento de texto termina o
// empieza con un espacio significativo que debe sobrevivir al render.
export function textoIzquierda(texto: string, peso: PesoFuente, size: number, color: string, x: number, yBaseline: number, preservarEspacios = false): string {
  const attrEspacio = preservarEspacios ? ' xml:space="preserve"' : "";
  return `<text x="${x}" y="${yBaseline}" font-family="Inter" font-weight="${PESO_A_CSS[peso]}" font-size="${size}" fill="${color}"${attrEspacio}>${escaparXml(texto)}</text>`;
}

// Puerto de nb_texto_derecha() — texto alineado a la derecha de xDerecha (text-anchor
// nativo, sin necesitar medir ancho primero como sí lo requería la versión GD real).
export function textoDerecha(texto: string, peso: PesoFuente, size: number, color: string, xDerecha: number, yBaseline: number): string {
  return `<text x="${xDerecha}" y="${yBaseline}" font-family="Inter" font-weight="${PESO_A_CSS[peso]}" font-size="${size}" fill="${color}" text-anchor="end">${escaparXml(texto)}</text>`;
}

// Texto centrado como UN bloque, con un prefijo y un sufijo de color distinto (ej. "Clases
// particulares de " + "Lenguaje" en acento) — DOS <tspan> dentro de un mismo <text>, no dos
// elementos <text> posicionados por separado. Necesario porque medir el ancho del prefijo
// vía medirAnchoTexto() (bbox de TINTA, no de avance de glifo) ignora por completo un
// espacio final — un espacio no tiene tinta — así que dos <text> separados posicionados con
// x = xIzq + anchoMedido(prefijo) pierden ese espacio en el layout (bug real, encontrado en
// la card de servicios: "particulares deLenguaje", sin espacio, incluso con
// xml:space="preserve" en el prefijo — preserve mantiene el CARÁCTER pero no corrige la
// medición usada para posicionar el siguiente elemento). Un <text> con tspans resuelve esto
// de raíz: el flujo de glifos nativo de SVG sí respeta el avance real de cada carácter,
// espacios incluidos, y text-anchor="middle" en el <text> exterior centra el CONTENIDO
// COMPLETO (todos los tspans concatenados) como una sola unidad.
export function textoCentradoDosColores(prefijo: string, sufijo: string, peso: PesoFuente, size: number, colorPrefijo: string, colorSufijo: string, xCentro: number, yBaseline: number): string {
  return `<text x="${xCentro}" y="${yBaseline}" font-family="Inter" font-weight="${PESO_A_CSS[peso]}" font-size="${size}" text-anchor="middle" xml:space="preserve"><tspan fill="${colorPrefijo}">${escaparXml(prefijo)}</tspan><tspan fill="${colorSufijo}">${escaparXml(sufijo)}</tspan></text>`;
}

// Badge tipo "pill" con punto + texto, centrado horizontalmente en xCentro — puerto visual
// de nb_dibujar_badge_categoria() (borde de acento, relleno blanco, punto + texto de acento).
export function badgePill(texto: string, xCentro: number, yTop: number, colorBorde: string, colorTexto: string): { svg: string; alto: number } {
  const size = 24;
  const padX = 16;
  const padY = 9;
  const dotR = 6;
  const gapDot = 14;
  const anchoTexto = medirAnchoTexto(texto, "semibold", size);
  const alto = Math.round(size * 1.15) + padY * 2;
  const ancho = dotR * 2 + gapDot + anchoTexto + padX * 2;
  const x = Math.round(xCentro - ancho / 2);
  const r = Math.round(alto / 2);
  const cyDot = yTop + Math.round(alto / 2);
  const yTextoBaseline = yTop + Math.round(alto / 2) + Math.round(size * 0.35);

  const svg = `
    <rect x="${x}" y="${yTop}" width="${ancho}" height="${alto}" rx="${r}" fill="${colorBorde}" />
    <rect x="${x + 2}" y="${yTop + 2}" width="${ancho - 4}" height="${alto - 4}" rx="${r - 2}" fill="#FFFFFF" />
    <circle cx="${x + padX + dotR}" cy="${cyDot}" r="${dotR}" fill="${colorTexto}" />
    ${textoIzquierda(texto, "semibold", size, colorTexto, x + padX + dotR * 2 + gapDot, yTextoBaseline)}
  `;
  return { svg, alto };
}

// Badge tipo "pill" con punto + texto, ANCLADO A LA IZQUIERDA en x — mismo diseño visual
// que badgePill (arriba) pero con el ancla real de nb_dibujar_badge_categoria()
// (imagen_compartir.php:367-386), que posiciona desde el borde izquierdo, no desde un
// centro — necesario cuando el elemento siguiente en la misma fila depende de dónde
// termina este (o cuando simplemente no hay nada que centrar, como en servicios).
export function badgePillIzquierda(texto: string, x: number, yTop: number, colorBorde: string, colorTexto: string): { svg: string; alto: number; ancho: number } {
  const size = 24;
  const padX = 16;
  const padY = 9;
  const dotR = 6;
  const gapDot = 14;
  const anchoTexto = medirAnchoTexto(texto, "semibold", size);
  const alto = Math.round(size * 1.15) + padY * 2;
  const ancho = dotR * 2 + gapDot + anchoTexto + padX * 2;
  const r = Math.round(alto / 2);
  const cyDot = yTop + Math.round(alto / 2);
  const yTextoBaseline = yTop + Math.round(alto / 2) + Math.round(size * 0.35);

  const svg = `
    <rect x="${x}" y="${yTop}" width="${ancho}" height="${alto}" rx="${r}" fill="${colorBorde}" />
    <rect x="${x + 2}" y="${yTop + 2}" width="${ancho - 4}" height="${alto - 4}" rx="${r - 2}" fill="#FFFFFF" />
    <circle cx="${x + padX + dotR}" cy="${cyDot}" r="${dotR}" fill="${colorTexto}" />
    ${textoIzquierda(texto, "semibold", size, colorTexto, x + padX + dotR * 2 + gapDot, yTextoBaseline)}
  `;
  return { svg, alto, ancho };
}

// Pill de fondo SÓLIDO (sin punto, sin borde) — puerto de nb_dibujar_badge_pill()
// (imagen_compartir.php:354-365), el badge genérico usado para "Disponible".
export function pillSolidoIzquierda(texto: string, x: number, yTop: number, colorFondo: string, colorTexto: string, size = 20, padX = 16, padY = 10): { svg: string; alto: number; ancho: number } {
  const anchoTexto = medirAnchoTexto(texto, "semibold", size);
  const alto = Math.round(size * 1.15) + padY * 2;
  const ancho = anchoTexto + padX * 2;
  const r = Math.round(alto / 2);
  const yBaseline = yTop + alto - padY - Math.round(size * 0.22);

  const svg = `
    <rect x="${x}" y="${yTop}" width="${ancho}" height="${alto}" rx="${r}" fill="${colorFondo}" />
    ${textoIzquierda(texto, "semibold", size, colorTexto, x + padX, yBaseline)}
  `;
  return { svg, alto, ancho };
}

// Estrella de 5 puntas rellena, centrada en (cx,cy) — puerto exacto de nb_estrella()
// (imagen_compartir.php:220-234), misma razón de pentagrama (0.382) para el radio interior.
export function estrella(cx: number, cy: number, radio: number, color: string): string {
  const rIn = radio * 0.382;
  const puntos: string[] = [];
  for (let i = 0; i < 10; i++) {
    const ang = -Math.PI / 2 + (i * Math.PI) / 5;
    const rad = i % 2 === 0 ? radio : rIn;
    puntos.push(`${(cx + rad * Math.cos(ang)).toFixed(2)},${(cy + rad * Math.sin(ang)).toFixed(2)}`);
  }
  return `<polygon points="${puntos.join(" ")}" fill="${color}" />`;
}

// Línea "★ 4,7 (12)" / "★ Nuevo", ANCLADA A LA IZQUIERDA en x — puerto de
// nb_cat_rating_render() (imagen_compartir.php:236-272) restringido al caso $cat='' (único
// que usa el formato POST de servicios; el prefijo de categoría en esa misma función solo
// lo usa el formato HISTORY, fuera de alcance de esta pieza).
export function estrellaConRating(promedio: number, votos: number, x: number, yBaseline: number, size: number, color: string): string {
  const hayResenas = promedio > 0;
  const peso: PesoFuente = hayResenas ? "bold" : "semibold";
  const texto = hayResenas ? `${promedio.toFixed(1).replace(".", ",")} (${votos})` : "Nuevo";
  const starR = Math.round(size * 0.54);
  const gStar = Math.round(size * 0.46);
  const cyStar = yBaseline - Math.round(size * 0.35);
  return `${estrella(x + starR, cyStar, starR, color)}${textoIzquierda(texto, peso, size, color, x + starR * 2 + gStar, yBaseline)}`;
}

// Botón sólido centrado con texto blanco — puerto visual de
// nb_dibujar_boton_generico_desafio() (imagen_compartir_desafio.php:14-25).
export function botonGenerico(texto: string, xCentro: number, yTop: number, colorFondo: string): { svg: string; alto: number } {
  const size = 30;
  const padX = 48;
  const padY = 22;
  const anchoTexto = medirAnchoTexto(texto, "bold", size);
  const alto = Math.round(size * 1.15) + padY * 2;
  const ancho = anchoTexto + padX * 2;
  const x = Math.round(xCentro - ancho / 2);
  const r = Math.round(alto / 2);
  const yTextoBaseline = yTop + alto - padY - Math.round(size * 0.22);

  const svg = `
    <rect x="${x}" y="${yTop}" width="${ancho}" height="${alto}" rx="${r}" fill="${colorFondo}" />
    ${textoCentrado(texto, "bold", size, "#FFFFFF", xCentro, yTextoBaseline)}
  `;
  return { svg, alto };
}

// Botón sólido ANCLADO A LA IZQUIERDA en x — puerto de nb_dibujar_boton_agendar()
// (imagen_compartir.php:413-425). A diferencia de botonGenerico (centrado, tamaños fijos
// calibrados para "Jugar ahora" del Desafío), acá size/padX/padY son parámetros porque el
// botón real de servicios usa una métrica distinta (26/40/18 vs. 30/48/22).
export function botonIzquierdo(texto: string, x: number, yTop: number, colorFondo: string, size = 26, padX = 40, padY = 18): { svg: string; alto: number; ancho: number } {
  const anchoTexto = medirAnchoTexto(texto, "bold", size);
  const alto = Math.round(size * 1.15) + padY * 2;
  const ancho = anchoTexto + padX * 2;
  const r = Math.round(alto / 2);
  const yBaseline = yTop + alto - padY - Math.round(size * 0.22);

  const svg = `
    <rect x="${x}" y="${yTop}" width="${ancho}" height="${alto}" rx="${r}" fill="${colorFondo}" />
    ${textoIzquierda(texto, "bold", size, "#FFFFFF", x + padX, yBaseline)}
  `;
  return { svg, alto, ancho };
}

// Círculo numerado (1/2/3) — puerto visual de la parte "número" de
// nb_desafio_preguntas_dibujar_bloque() (imagen_compartir_desafio.php:236-239).
export function circuloNumerado(numero: number, cx: number, cy: number, diametro: number, colorFondo: string, colorTexto: string): string {
  const size = Math.round(diametro * 0.45);
  const yBaseline = cy + Math.round(size * 0.35);
  return `
    <circle cx="${cx}" cy="${cy}" r="${diametro / 2}" fill="${colorFondo}" />
    ${textoCentrado(String(numero), "bold", size, colorTexto, cx, yBaseline)}
  `;
}

const EXTS_IMAGEN_SOPORTADAS = new Set(["jpg", "jpeg", "png", "webp", "gif"]);

// Lee un archivo local real y lo embebe como data URI PNG — SIEMPRE normalizado por sharp,
// nunca el buffer crudo del archivo original. Hallazgo real (verificado con una imagen de
// producción real, no una sospecha): resvg falla en SILENCIO al decodificar <image> con
// webp embebido (el mismo formato que usa TODO el pipeline de portadas del sitio, ver
// media.ts) — sin error, sin entrada en imagesToResolve(), el elemento simplemente no se
// pinta. sharp SÍ decodifica webp de forma confiable (ya se usa para eso en todo
// server/), así que la normalización pasa siempre por ahí antes de llegar a resvg, evitando
// depender de qué formatos decodifica bien el renderer de turno. `null` si el archivo no
// existe o no es un formato soportado — el caller decide el fallback visual (mismo patrón
// defensivo que nb_dibujar_portada_rect/nb_dibujar_avatar, que nunca dejan un rect
// roto/vacío).
export async function cargarImagenComoDataUri(rutaAbsoluta: string): Promise<string | null> {
  const ext = path.extname(rutaAbsoluta).slice(1).toLowerCase();
  if (!EXTS_IMAGEN_SOPORTADAS.has(ext)) return null;
  try {
    const buffer = await fs.readFile(rutaAbsoluta);
    const png = await sharp(buffer).png().toBuffer();
    return `data:image/png;base64,${png.toString("base64")}`;
  } catch {
    return null;
  }
}

let contadorIds = 0;
function idUnico(prefijo: string): string {
  contadorIds += 1;
  return `${prefijo}${contadorIds}`;
}

// Puerto visual de nb_dibujar_portada_rect() (imagen_compartir_apunte.php:57-118) — rect
// redondeado, recorte "cover" (sin deformar) y gradiente oscuro en el tercio inferior para
// legibilidad. Reemplaza TODA la matemática manual de escala/offset/máscara-pixel-a-pixel
// del PHP real por 2 capacidades nativas de SVG: `preserveAspectRatio="xMidYMid slice"`
// (equivalente exacto a CSS object-fit:cover) y un <linearGradient> — no hay pixel a pixel
// que replicar. Sin dataUri (imagen no encontrada en disco) dibuja un placeholder sólido de
// marca, igual que el PHP real (nunca un rectángulo roto/vacío).
export function imagenPortadaRecortada(dataUri: string | null, x: number, y: number, w: number, h: number, radio: number, conGradiente = true): string {
  const clipId = idUnico("clipPortada");
  const gradId = idUnico("gradPortada");

  if (!dataUri) {
    return `<rect x="${x}" y="${y}" width="${w}" height="${h}" rx="${radio}" fill="#E0EEF7" />`;
  }

  const gradiente = conGradiente
    ? `<defs>
        <linearGradient id="${gradId}" x1="0" y1="0" x2="0" y2="1">
          <stop offset="60%" stop-color="#000000" stop-opacity="0" />
          <stop offset="100%" stop-color="#000000" stop-opacity="0.45" />
        </linearGradient>
      </defs>
      <rect x="${x}" y="${y}" width="${w}" height="${h}" fill="url(#${gradId})" clip-path="url(#${clipId})" />`
    : "";

  return `
    <defs>
      <clipPath id="${clipId}">
        <rect x="${x}" y="${y}" width="${w}" height="${h}" rx="${radio}" />
      </clipPath>
    </defs>
    <image href="${dataUri}" x="${x}" y="${y}" width="${w}" height="${h}" preserveAspectRatio="xMidYMid slice" clip-path="url(#${clipId})" />
    ${gradiente}
  `;
}

// Puerto visual de nb_dibujar_avatar() (imagen_compartir.php:291+) — anillo de acento +
// foto circular recortada (cover, vía clipPath circular en vez de la máscara pixel a pixel
// de nb_recorte_circular), o círculo relleno + iniciales si no hay foto real en disco
// (mismo criterio que necesidad_avatar_inicial() en app/helpers/foto_tutor.php).
export function avatarCircular(dataUri: string | null, cx: number, cyTop: number, diametro: number, colorAcento: string, colorBlanco: string, iniciales: string): string {
  const r = diametro / 2;
  const cy = cyTop + r;
  const anillo = `<circle cx="${cx}" cy="${cy}" r="${r + 4}" fill="${colorAcento}" />`;

  if (!dataUri) {
    const size = Math.round(diametro * 0.38);
    return `${anillo}<circle cx="${cx}" cy="${cy}" r="${r}" fill="${colorAcento}" />${textoCentrado(iniciales, "bold", size, colorBlanco, cx, cy + Math.round(size * 0.35))}`;
  }

  const clipId = idUnico("clipAvatar");
  return `
    ${anillo}
    <defs>
      <clipPath id="${clipId}">
        <circle cx="${cx}" cy="${cy}" r="${r}" />
      </clipPath>
    </defs>
    <image href="${dataUri}" x="${cx - r}" y="${cy - r}" width="${diametro}" height="${diametro}" preserveAspectRatio="xMidYMid slice" clip-path="url(#${clipId})" />
  `;
}

// Ensambla el documento SVG completo (fondo + fuentes embebidas), lo rasteriza con resvg
// (PNG) y usa sharp SOLO para la conversión final PNG->JPEG (resvg no exporta JPEG
// directo) — mismo criterio, dos librerías, cada una en lo suyo.
export async function renderizarCardJpeg(cuerpoSvg: string, ancho: number, alto: number, calidad = 90): Promise<Buffer> {
  const svgCompleto = `<svg xmlns="http://www.w3.org/2000/svg" width="${ancho}" height="${alto}" viewBox="0 0 ${ancho} ${alto}">
    <rect width="${ancho}" height="${alto}" fill="${PALETA_MARCA.bg}" />
    ${cuerpoSvg}
  </svg>`;

  const resvg = new Resvg(svgCompleto, {
    font: { fontFiles: FONT_FILES, loadSystemFonts: false, defaultFontFamily: "Inter" },
  });
  const png = resvg.render().asPng();
  return sharp(png).jpeg({ quality: calidad }).toBuffer();
}
