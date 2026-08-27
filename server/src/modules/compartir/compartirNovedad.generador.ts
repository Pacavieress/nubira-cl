import crypto from "node:crypto";
import { envolverTexto, marcoRedondeado, PALETA_MARCA, renderizarCardJpeg, textoCentrado, textoDerecha, textoIzquierda } from "../../lib/svgCard.js";
import type { NovedadCompartir } from "./compartir.types.js";

// Puerto VISUAL (mismo criterio que compartirServicio.generador.ts — ver nota en
// lib/svgCard.ts) de nb_generar_imagen_novedad_post/history() (imagen_compartir.php:700-830).
// Sin avatar/foto/nombre de tutor: una novedad es texto que el propio admin redactó (título +
// cuerpo), cero datos de usuario en esta card. El círculo superior es un placeholder liso
// (mismo criterio que el PHP real: ícono/imagen de la novedad queda para otra pieza, ver
// CLAUDE.md — "Pendiente: subir imagen/ícono real en cards de novedades").
const VERSION_IMAGEN_NOVEDAD = "node-v1";

export function fingerprintNovedad(n: NovedadCompartir): string {
  const base = [VERSION_IMAGEN_NOVEDAD, n.id, n.titulo, n.cuerpo].join("|");
  return crypto.createHash("md5").update(base).digest("hex").slice(0, 10);
}

export async function generarImagenNovedadPost(n: NovedadCompartir): Promise<Buffer> {
  const W = 1080;
  const H = 1080;
  const padX = 100;
  const maxW = W - padX * 2;
  const szTit = 48;
  const lhTit = 60;
  const szCuerpo = 28;
  const lhCuerpo = 42;
  const gapTitCuerpo = 40;

  const lineasTit = envolverTexto(n.titulo.trim(), "bold", szTit, maxW, 2);
  const lineasCuerpo = envolverTexto(n.cuerpo.trim(), "semibold", szCuerpo, maxW, 5);

  const alturaDisponible = 900;
  const altoTit = lineasTit.length * lhTit;
  const altoCuerpo = lineasCuerpo.length * lhCuerpo;
  const altoBloque = altoTit + gapTitCuerpo + altoCuerpo;
  const yBloque = Math.floor((alturaDisponible - altoBloque) / 2);

  const diamIcono = 90;
  const gapIconoTit = 30;
  const cyIcono = yBloque - gapIconoTit - Math.floor(diamIcono / 2);

  const partes: string[] = [];
  partes.push(`<circle cx="${W / 2}" cy="${cyIcono}" r="${diamIcono / 2}" fill="${PALETA_MARCA.acento}" />`);

  const yTit = yBloque + Math.round(szTit * 0.75);
  lineasTit.forEach((ln, i) => partes.push(textoCentrado(ln, "bold", szTit, PALETA_MARCA.txt, W / 2, yTit + i * lhTit)));

  const yCuerpo = yTit + (lineasTit.length - 1) * lhTit + gapTitCuerpo;
  lineasCuerpo.forEach((ln, i) => partes.push(textoCentrado(ln, "semibold", szCuerpo, PALETA_MARCA.txt2, W / 2, yCuerpo + i * lhCuerpo)));

  // Misma posición Y=990 que nb_generar_imagen_post() (servicios) — consistencia visual
  // entre ambos tipos de card, ver nota original en imagen_compartir.php.
  partes.push(textoDerecha("Nubira.cl", "bold", 28, PALETA_MARCA.acento, W * 0.75, 990));

  return renderizarCardJpeg(partes.join("\n"), W, H, 90);
}

export async function generarImagenNovedadHistory(n: NovedadCompartir): Promise<Buffer> {
  const W = 1080;
  const H = 1920;
  const cBorde = "#E5E7EB";

  const cardX1 = 90;
  const cardX2 = 990;
  const cardY1 = 250;
  const cardY2 = 1580;
  const cardR = 40;

  const inX1 = 130;
  const inX2 = 950;
  const inY1 = 290;
  const inY2 = 1420;
  const inR = 30;

  const partes: string[] = [];
  partes.push(marcoRedondeado(cardX1, cardY1, cardX2 - cardX1, cardY2 - cardY1, cardR, cBorde, PALETA_MARCA.blanco));
  partes.push(marcoRedondeado(inX1, inY1, inX2 - inX1, inY2 - inY1, inR, cBorde, PALETA_MARCA.blanco));

  const maxTxt = inX2 - 40 - (inX1 + 40);
  const szTit = 44;
  const lhTit = 54;
  const szCuerpo = 28;
  const lhCuerpo = 40;
  const gapTitCuerpo = 50;

  const lineasTit = envolverTexto(n.titulo.trim(), "bold", szTit, maxTxt, 2);
  const lineasCuerpo = envolverTexto(n.cuerpo.trim(), "semibold", szCuerpo, maxTxt, 6);

  const altoTit = lineasTit.length * lhTit;
  const altoCuerpo = lineasCuerpo.length * lhCuerpo;
  const altoBloque = altoTit + gapTitCuerpo + altoCuerpo;
  const yBloque = inY1 + Math.floor((inY2 - inY1 - altoBloque) / 2);

  const diamIcono = 90;
  const gapIconoTit = 30;
  const cyIcono = yBloque - gapIconoTit - Math.floor(diamIcono / 2);
  partes.push(`<circle cx="${W / 2}" cy="${cyIcono}" r="${diamIcono / 2}" fill="${PALETA_MARCA.acento}" />`);

  const yTit = yBloque + Math.round(szTit * 0.75);
  lineasTit.forEach((ln, i) => partes.push(textoCentrado(ln, "bold", szTit, PALETA_MARCA.txt, W / 2, yTit + i * lhTit)));

  const yCuerpo = yTit + (lineasTit.length - 1) * lhTit + gapTitCuerpo;
  lineasCuerpo.forEach((ln, i) => partes.push(textoCentrado(ln, "semibold", szCuerpo, PALETA_MARCA.txt2, W / 2, yCuerpo + i * lhCuerpo)));

  // Nubira.cl fuera del marco interno, abajo-izquierda dentro de la card (igual que servicio HISTORY).
  partes.push(textoIzquierda("Nubira.cl", "bold", 28, PALETA_MARCA.acento, cardX1 + 50, cardY2 - 60));

  return renderizarCardJpeg(partes.join("\n"), W, H, 90);
}
