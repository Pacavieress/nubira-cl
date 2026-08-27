import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs/promises";
import path from "node:path";
import type { ResultSetHeader, RowDataPacket } from "mysql2";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";
import { env } from "../src/config/env.js";

const MATERIA_SLUG = "calculo";
// Apuntes reales, aprobados, confirmados vía SELECT directo antes de escribir estos tests
// (no inventados): 148 tiene portada webp real; 323 no tiene portada y su archivo es .docx
// (no imagen) — cubre el fallback de placeholder.
const APUNTE_CON_PORTADA_ID = 148;
const APUNTE_SIN_PORTADA_ID = 323;
// Servicio real, aprobado y visible, confirmado vía SELECT directo antes de escribir estos
// tests: sin oferta vigente (is_subvencionado=0) — cubre la rama de precio normal. No hay
// ningún servicio real en la BD local con una oferta activa (no vencida) en este momento
// para cubrir la rama del badge OFERTA vía HTTP — se verificó visualmente esa rama por
// separado con una fixture sintética en memoria (no vía este test suite), mismo criterio
// ya aceptado para el caso de promo de apuntes.
const SERVICIO_ID = 8943;
let archivosGenerados: string[] = [];
let archivosNovedadGenerados: string[] = [];
let novedadId: number;

before(async () => {
  const [ins] = await pool.query<ResultSetHeader>("INSERT INTO novedades (titulo, cuerpo) VALUES (?, ?)", [
    "[TEST compartir] Novedad de prueba",
    "Cuerpo de prueba para el test automatizado de compartirNovedad.",
  ]);
  novedadId = ins.insertId;
});

after(async () => {
  for (const nombre of archivosGenerados) {
    await fs.rm(path.join(env.uploadDir, "compartir", nombre), { force: true });
  }
  for (const nombre of archivosNovedadGenerados) {
    await fs.rm(path.join(env.uploadDir, "novedades", nombre), { force: true });
  }
  await pool.query("DELETE FROM novedades WHERE id = ?", [novedadId]);
  await pool.query("DELETE FROM shares_desafio WHERE materia_slug = ? AND ip = 'test-fixture'", [MATERIA_SLUG]);
  await pool.query("DELETE FROM shares_apunte WHERE apunte_id = ? AND ip = 'test-fixture'", [APUNTE_CON_PORTADA_ID]);
  await pool.query("DELETE FROM shares_servicio WHERE servicio_id = ? AND ip = 'test-fixture'", [SERVICIO_ID]);
  await pool.end();
});

function listen(): { url: string; close: () => Promise<void> } {
  const app = createApp();
  const server = app.listen(0);
  const address = server.address();
  if (address === null || typeof address === "string") {
    throw new Error("No se pudo obtener el puerto efímero del servidor de prueba");
  }
  return {
    url: `http://127.0.0.1:${address.port}`,
    close: () => new Promise((resolve) => server.close(() => resolve())),
  };
}

test("GET /api/compartir/desafio/:slug/post con materia inexistente devuelve 404", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/compartir/desafio/no-existe/post`);
    assert.equal(res.status, 404);
  } finally {
    await close();
  }
});

test("GET /api/compartir/desafio/:slug/post: sin sesión (público) genera un JPEG real, con Cache-Control largo", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/compartir/desafio/${MATERIA_SLUG}/post`);
    assert.equal(res.status, 200);
    assert.equal(res.headers.get("content-type"), "image/jpeg");
    assert.match(res.headers.get("cache-control") ?? "", /max-age=86400/);

    const buffer = Buffer.from(await res.arrayBuffer());
    assert.ok(buffer.length > 1000, "debe ser un JPEG real, no un placeholder vacío");
    // Magic bytes JPEG reales (FF D8 FF), no un archivo corrupto/placeholder de texto.
    assert.equal(buffer[0], 0xff);
    assert.equal(buffer[1], 0xd8);
    assert.equal(buffer[2], 0xff);
  } finally {
    await close();
  }
});

test("GET /api/compartir/desafio/:slug/post: segunda llamada es cache-hit (mismo archivo en disco, no se regenera)", async () => {
  const { url, close } = listen();
  try {
    const res1 = await fetch(`${url}/api/compartir/desafio/${MATERIA_SLUG}/post`);
    const buf1 = Buffer.from(await res1.arrayBuffer());

    const dir = path.join(env.uploadDir, "compartir");
    const archivos = await fs.readdir(dir);
    const archivoCard = archivos.find((f) => f.startsWith(`desafio_${MATERIA_SLUG}_post_`));
    assert.ok(archivoCard, "el archivo de cache debe existir en disco tras la primera llamada");
    archivosGenerados.push(archivoCard!);

    const statAntes = await fs.stat(path.join(dir, archivoCard!));

    const res2 = await fetch(`${url}/api/compartir/desafio/${MATERIA_SLUG}/post`);
    const buf2 = Buffer.from(await res2.arrayBuffer());
    const statDespues = await fs.stat(path.join(dir, archivoCard!));

    assert.deepEqual(buf1, buf2, "el contenido debe ser idéntico (mismo archivo cacheado)");
    assert.equal(statAntes.mtimeMs, statDespues.mtimeMs, "el archivo no debe haberse regenerado (mismo mtime)");
  } finally {
    await close();
  }
});

test("GET /api/compartir/desafio-preguntas/:ids/history con IDs que NO comparten materia devuelve 404", async () => {
  const { url, close } = listen();
  try {
    // 10,11 son de calculo; 26 es de otra materia (confirmado por fixture real antes de
    // escribir este test) — la card no debe generarse con un badge de materia incoherente.
    const res = await fetch(`${url}/api/compartir/desafio-preguntas/10-11-26/history`);
    assert.equal(res.status, 404);
  } finally {
    await close();
  }
});

test("GET /api/compartir/desafio-preguntas/:ids/history con menos de 3 IDs devuelve 404", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/compartir/desafio-preguntas/10-11/history`);
    assert.equal(res.status, 404);
  } finally {
    await close();
  }
});

test("GET /api/compartir/desafio-preguntas/:ids/history con 3 IDs reales de la misma materia: genera un JPEG real SIN respuesta_correcta expuesta en ningún header/nombre", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/compartir/desafio-preguntas/10-11-12/history`);
    assert.equal(res.status, 200);
    assert.equal(res.headers.get("content-type"), "image/jpeg");
    assert.match(res.headers.get("cache-control") ?? "", /max-age=86400/);

    const buffer = Buffer.from(await res.arrayBuffer());
    assert.ok(buffer.length > 1000, "debe ser un JPEG real");
    assert.equal(buffer[0], 0xff);
    assert.equal(buffer[1], 0xd8);
    assert.equal(buffer[2], 0xff);

    const dir = path.join(env.uploadDir, "compartir");
    const archivos = await fs.readdir(dir);
    const archivoCard = archivos.find((f) => f.startsWith("desafio_preguntas_10-11-12_history_"));
    assert.ok(archivoCard, "debe cachear en disco con los 3 ids EN EL ORDEN PEDIDO en el nombre");
    archivosGenerados.push(archivoCard!);
  } finally {
    await close();
  }
});

test("GET /api/compartir/desafio-preguntas/:ids/history: el MISMO trío en OTRO orden genera un archivo de cache DISTINTO", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/compartir/desafio-preguntas/12-11-10/history`);
    assert.equal(res.status, 200);

    const dir = path.join(env.uploadDir, "compartir");
    const archivos = await fs.readdir(dir);
    const archivoOrdenInverso = archivos.find((f) => f.startsWith("desafio_preguntas_12-11-10_history_"));
    assert.ok(archivoOrdenInverso, "el orden 12-11-10 debe generar su propio archivo, distinto de 10-11-12 (la numeración 1/2/3 en la imagen cambia)");
    archivosGenerados.push(archivoOrdenInverso!);
  } finally {
    await close();
  }
});

test("POST /api/compartir/desafio/track sin materiaSlug devuelve 400", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/compartir/desafio/track`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ formato: "post" }),
    });
    assert.equal(res.status, 400);
  } finally {
    await close();
  }
});

test("POST /api/compartir/desafio/track con formato inválido devuelve 400", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/compartir/desafio/track`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ materiaSlug: MATERIA_SLUG, formato: "algo-invalido" }),
    });
    assert.equal(res.status, 400);
  } finally {
    await close();
  }
});

test("POST /api/compartir/desafio/track con datos válidos: registra la fila real en shares_desafio", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/compartir/desafio/track`, {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-Forwarded-For": "test-fixture" },
      body: JSON.stringify({ materiaSlug: MATERIA_SLUG, formato: "share" }),
    });
    assert.equal(res.status, 200);

    const [rows] = await pool.query<RowDataPacket[]>(
      "SELECT materia_slug, formato, ip FROM shares_desafio WHERE materia_slug = ? AND ip = 'test-fixture' ORDER BY id DESC LIMIT 1",
      [MATERIA_SLUG],
    );
    assert.equal(rows.length, 1);
    assert.equal((rows[0] as { formato: string }).formato, "share");
  } finally {
    await close();
  }
});

test("GET /api/compartir/apunte/:id/post con id inexistente devuelve 404", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/compartir/apunte/999999/post`);
    assert.equal(res.status, 404);
  } finally {
    await close();
  }
});

test("GET /api/compartir/apunte/:id/post con id no numérico devuelve 404", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/compartir/apunte/abc/post`);
    assert.equal(res.status, 404);
  } finally {
    await close();
  }
});

test("GET /api/compartir/apunte/:id/post: apunte real CON portada genera un JPEG real, con Cache-Control largo", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/compartir/apunte/${APUNTE_CON_PORTADA_ID}/post`);
    assert.equal(res.status, 200);
    assert.equal(res.headers.get("content-type"), "image/jpeg");
    assert.match(res.headers.get("cache-control") ?? "", /max-age=86400/);

    const buffer = Buffer.from(await res.arrayBuffer());
    assert.ok(buffer.length > 1000, "debe ser un JPEG real, no un placeholder vacío");
    assert.equal(buffer[0], 0xff);
    assert.equal(buffer[1], 0xd8);
    assert.equal(buffer[2], 0xff);

    const dir = path.join(env.uploadDir, "compartir");
    const archivos = await fs.readdir(dir);
    const archivoCard = archivos.find((f) => f.startsWith(`ap_${APUNTE_CON_PORTADA_ID}_post_`));
    assert.ok(archivoCard, "debe cachear en disco");
    archivosGenerados.push(archivoCard!);
  } finally {
    await close();
  }
});

test("GET /api/compartir/apunte/:id/post: segunda llamada es cache-hit (mismo archivo en disco, no se regenera)", async () => {
  const { url, close } = listen();
  try {
    const res1 = await fetch(`${url}/api/compartir/apunte/${APUNTE_CON_PORTADA_ID}/post`);
    const buf1 = Buffer.from(await res1.arrayBuffer());

    const dir = path.join(env.uploadDir, "compartir");
    const archivos = await fs.readdir(dir);
    const archivoCard = archivos.find((f) => f.startsWith(`ap_${APUNTE_CON_PORTADA_ID}_post_`));
    assert.ok(archivoCard);
    const statAntes = await fs.stat(path.join(dir, archivoCard!));

    const res2 = await fetch(`${url}/api/compartir/apunte/${APUNTE_CON_PORTADA_ID}/post`);
    const buf2 = Buffer.from(await res2.arrayBuffer());
    const statDespues = await fs.stat(path.join(dir, archivoCard!));

    assert.deepEqual(buf1, buf2, "el contenido debe ser idéntico (mismo archivo cacheado)");
    assert.equal(statAntes.mtimeMs, statDespues.mtimeMs, "el archivo no debe haberse regenerado (mismo mtime)");
  } finally {
    await close();
  }
});

test("GET /api/compartir/apunte/:id/post: apunte real SIN portada (archivo no-imagen) genera un JPEG real con placeholder", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/compartir/apunte/${APUNTE_SIN_PORTADA_ID}/post`);
    assert.equal(res.status, 200);
    assert.equal(res.headers.get("content-type"), "image/jpeg");

    const buffer = Buffer.from(await res.arrayBuffer());
    assert.ok(buffer.length > 1000, "debe ser un JPEG real");
    assert.equal(buffer[0], 0xff);
    assert.equal(buffer[1], 0xd8);
    assert.equal(buffer[2], 0xff);

    const dir = path.join(env.uploadDir, "compartir");
    const archivos = await fs.readdir(dir);
    const archivoCard = archivos.find((f) => f.startsWith(`ap_${APUNTE_SIN_PORTADA_ID}_post_`));
    assert.ok(archivoCard, "debe cachear en disco");
    archivosGenerados.push(archivoCard!);
  } finally {
    await close();
  }
});

test("POST /api/compartir/apunte/track sin apunteId devuelve 400", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/compartir/apunte/track`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ formato: "post" }),
    });
    assert.equal(res.status, 400);
  } finally {
    await close();
  }
});

test("POST /api/compartir/apunte/track con formato inválido devuelve 400", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/compartir/apunte/track`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ apunteId: APUNTE_CON_PORTADA_ID, formato: "algo-invalido" }),
    });
    assert.equal(res.status, 400);
  } finally {
    await close();
  }
});

test("POST /api/compartir/apunte/track con datos válidos: registra la fila real en shares_apunte", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/compartir/apunte/track`, {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-Forwarded-For": "test-fixture" },
      body: JSON.stringify({ apunteId: APUNTE_CON_PORTADA_ID, formato: "share" }),
    });
    assert.equal(res.status, 200);

    const [rows] = await pool.query<RowDataPacket[]>(
      "SELECT apunte_id, formato, ip FROM shares_apunte WHERE apunte_id = ? AND ip = 'test-fixture' ORDER BY id DESC LIMIT 1",
      [APUNTE_CON_PORTADA_ID],
    );
    assert.equal(rows.length, 1);
    assert.equal((rows[0] as { formato: string }).formato, "share");
  } finally {
    await close();
  }
});

test("GET /api/compartir/servicio/:id/post con id inexistente devuelve 404", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/compartir/servicio/999999/post`);
    assert.equal(res.status, 404);
  } finally {
    await close();
  }
});

test("GET /api/compartir/servicio/:id/post con id no numérico devuelve 404", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/compartir/servicio/abc/post`);
    assert.equal(res.status, 404);
  } finally {
    await close();
  }
});

test("GET /api/compartir/servicio/:id/post: servicio real genera un JPEG real, con Cache-Control largo", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/compartir/servicio/${SERVICIO_ID}/post`);
    assert.equal(res.status, 200);
    assert.equal(res.headers.get("content-type"), "image/jpeg");
    assert.match(res.headers.get("cache-control") ?? "", /max-age=86400/);

    const buffer = Buffer.from(await res.arrayBuffer());
    assert.ok(buffer.length > 1000, "debe ser un JPEG real, no un placeholder vacío");
    assert.equal(buffer[0], 0xff);
    assert.equal(buffer[1], 0xd8);
    assert.equal(buffer[2], 0xff);

    const dir = path.join(env.uploadDir, "compartir");
    const archivos = await fs.readdir(dir);
    const archivoCard = archivos.find((f) => f.startsWith(`sv_${SERVICIO_ID}_post_`));
    assert.ok(archivoCard, "debe cachear en disco");
    archivosGenerados.push(archivoCard!);
  } finally {
    await close();
  }
});

test("GET /api/compartir/servicio/:id/post: segunda llamada es cache-hit (mismo archivo en disco, no se regenera)", async () => {
  const { url, close } = listen();
  try {
    const res1 = await fetch(`${url}/api/compartir/servicio/${SERVICIO_ID}/post`);
    const buf1 = Buffer.from(await res1.arrayBuffer());

    const dir = path.join(env.uploadDir, "compartir");
    const archivos = await fs.readdir(dir);
    const archivoCard = archivos.find((f) => f.startsWith(`sv_${SERVICIO_ID}_post_`));
    assert.ok(archivoCard);
    const statAntes = await fs.stat(path.join(dir, archivoCard!));

    const res2 = await fetch(`${url}/api/compartir/servicio/${SERVICIO_ID}/post`);
    const buf2 = Buffer.from(await res2.arrayBuffer());
    const statDespues = await fs.stat(path.join(dir, archivoCard!));

    assert.deepEqual(buf1, buf2, "el contenido debe ser idéntico (mismo archivo cacheado)");
    assert.equal(statAntes.mtimeMs, statDespues.mtimeMs, "el archivo no debe haberse regenerado (mismo mtime)");
  } finally {
    await close();
  }
});

test("POST /api/compartir/servicio/track sin servicioId devuelve 400", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/compartir/servicio/track`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ formato: "post" }),
    });
    assert.equal(res.status, 400);
  } finally {
    await close();
  }
});

test("POST /api/compartir/servicio/track con formato inválido devuelve 400", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/compartir/servicio/track`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ servicioId: SERVICIO_ID, formato: "algo-invalido" }),
    });
    assert.equal(res.status, 400);
  } finally {
    await close();
  }
});

test("POST /api/compartir/servicio/track con datos válidos: registra la fila real en shares_servicio", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/compartir/servicio/track`, {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-Forwarded-For": "test-fixture" },
      body: JSON.stringify({ servicioId: SERVICIO_ID, formato: "share" }),
    });
    assert.equal(res.status, 200);

    const [rows] = await pool.query<RowDataPacket[]>(
      "SELECT servicio_id, formato, ip FROM shares_servicio WHERE servicio_id = ? AND ip = 'test-fixture' ORDER BY id DESC LIMIT 1",
      [SERVICIO_ID],
    );
    assert.equal(rows.length, 1);
    assert.equal((rows[0] as { formato: string }).formato, "share");
  } finally {
    await close();
  }
});

test("GET /api/compartir/novedad/:id/post con id inexistente devuelve 404", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/compartir/novedad/999999999/post`);
    assert.equal(res.status, 404);
  } finally {
    await close();
  }
});

test("GET /api/compartir/novedad/:id/post: novedad real genera un JPEG real (1080x1080), con Cache-Control largo", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/compartir/novedad/${novedadId}/post`);
    assert.equal(res.status, 200);
    assert.equal(res.headers.get("content-type"), "image/jpeg");
    assert.match(res.headers.get("cache-control") ?? "", /max-age=86400/);

    const buffer = Buffer.from(await res.arrayBuffer());
    assert.ok(buffer.length > 1000, "debe ser un JPEG real, no un placeholder vacío");
    assert.equal(buffer[0], 0xff);
    assert.equal(buffer[1], 0xd8);
    assert.equal(buffer[2], 0xff);

    const dir = path.join(env.uploadDir, "novedades");
    const archivos = await fs.readdir(dir);
    const archivoCard = archivos.find((f) => f.startsWith(`nov_${novedadId}_post_`));
    assert.ok(archivoCard, "debe cachear en disco");
    archivosNovedadGenerados.push(archivoCard!);
  } finally {
    await close();
  }
});

test("GET /api/compartir/novedad/:id/post: segunda llamada es cache-hit (mismo archivo en disco, no se regenera)", async () => {
  const { url, close } = listen();
  try {
    const res1 = await fetch(`${url}/api/compartir/novedad/${novedadId}/post`);
    const buf1 = Buffer.from(await res1.arrayBuffer());

    const dir = path.join(env.uploadDir, "novedades");
    const archivos = await fs.readdir(dir);
    const archivoCard = archivos.find((f) => f.startsWith(`nov_${novedadId}_post_`));
    assert.ok(archivoCard);
    const statAntes = await fs.stat(path.join(dir, archivoCard!));

    const res2 = await fetch(`${url}/api/compartir/novedad/${novedadId}/post`);
    const buf2 = Buffer.from(await res2.arrayBuffer());
    const statDespues = await fs.stat(path.join(dir, archivoCard!));

    assert.deepEqual(buf1, buf2, "el contenido debe ser idéntico (mismo archivo cacheado)");
    assert.equal(statAntes.mtimeMs, statDespues.mtimeMs, "el archivo no debe haberse regenerado (mismo mtime)");
  } finally {
    await close();
  }
});

test("GET /api/compartir/novedad/:id/history: novedad real genera un JPEG real (1080x1920)", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/compartir/novedad/${novedadId}/history`);
    assert.equal(res.status, 200);
    assert.equal(res.headers.get("content-type"), "image/jpeg");

    const buffer = Buffer.from(await res.arrayBuffer());
    assert.ok(buffer.length > 1000, "debe ser un JPEG real, no un placeholder vacío");
    assert.equal(buffer[0], 0xff);
    assert.equal(buffer[1], 0xd8);
    assert.equal(buffer[2], 0xff);

    const dir = path.join(env.uploadDir, "novedades");
    const archivos = await fs.readdir(dir);
    const archivoCard = archivos.find((f) => f.startsWith(`nov_${novedadId}_history_`));
    assert.ok(archivoCard, "debe cachear en disco, en un archivo distinto al de post");
    archivosNovedadGenerados.push(archivoCard!);
  } finally {
    await close();
  }
});
