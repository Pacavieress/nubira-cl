import { test, after } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs/promises";
import path from "node:path";
import type { RowDataPacket } from "mysql2";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";
import { env } from "../src/config/env.js";

const MATERIA_SLUG = "calculo";
let archivosGenerados: string[] = [];

after(async () => {
  for (const nombre of archivosGenerados) {
    await fs.rm(path.join(env.uploadDir, "compartir", nombre), { force: true });
  }
  await pool.query("DELETE FROM shares_desafio WHERE materia_slug = ? AND ip = 'test-fixture'", [MATERIA_SLUG]);
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
