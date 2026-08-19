import { test, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";

after(async () => {
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

test("GET /api/apuntes devuelve 200 con data[] y meta", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/apuntes?limit=5`);
    const body = (await res.json()) as {
      data: unknown[];
      meta: { page: number; limit: number; hayMas: boolean };
    };
    assert.equal(res.status, 200);
    assert.ok(Array.isArray(body.data));
    assert.ok(body.data.length <= 5);
    assert.equal(body.meta.page, 1);
    assert.equal(body.meta.limit, 5);
    assert.equal(typeof body.meta.hayMas, "boolean");
  } finally {
    await close();
  }
});

test("la paginación no repite IDs entre página 1 y página 2 (orden determinístico, sin RAND)", async () => {
  const { url, close } = listen();
  try {
    const res1 = await fetch(`${url}/api/apuntes?limit=2&page=1`);
    const body1 = (await res1.json()) as { data: { id: number }[] };
    const res2 = await fetch(`${url}/api/apuntes?limit=2&page=2`);
    const body2 = (await res2.json()) as { data: { id: number }[] };

    const ids1 = body1.data.map((a) => a.id);
    const ids2 = body2.data.map((a) => a.id);
    const overlap = ids1.filter((id) => ids2.includes(id));
    assert.deepEqual(overlap, []);
  } finally {
    await close();
  }
});

test("GET /api/apuntes?precio=gratis solo devuelve apuntes con precio 0", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/apuntes?precio=gratis&limit=50`);
    const body = (await res.json()) as { data: { precio: number }[] };
    assert.equal(res.status, 200);
    for (const apunte of body.data) assert.equal(apunte.precio, 0);
  } finally {
    await close();
  }
});

test("GET /api/apuntes: cada fila trae portadaUrl absoluta y url interna /apuntes/:id", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/apuntes?limit=5`);
    const body = (await res.json()) as { data: { id: number; portadaUrl: string; url: string }[] };
    for (const apunte of body.data) {
      assert.ok(apunte.portadaUrl.startsWith("http"));
      assert.equal(apunte.url, `/apuntes/${apunte.id}`);
    }
  } finally {
    await close();
  }
});
