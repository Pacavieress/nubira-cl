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

test("GET /api/servicios devuelve 200 con data[] y meta", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/servicios?limit=5`);
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

test("la paginación no repite IDs entre página 1 y página 2 (valida la Decisión 1: orden determinístico)", async () => {
  const { url, close } = listen();
  try {
    const res1 = await fetch(`${url}/api/servicios?limit=2&page=1`);
    const body1 = (await res1.json()) as { data: { id: number }[] };
    const res2 = await fetch(`${url}/api/servicios?limit=2&page=2`);
    const body2 = (await res2.json()) as { data: { id: number }[] };

    const ids1 = body1.data.map((s) => s.id);
    const ids2 = body2.data.map((s) => s.id);
    const overlap = ids1.filter((id) => ids2.includes(id));
    assert.deepEqual(overlap, []);
  } finally {
    await close();
  }
});

test("GET /api/servicios/:id inexistente devuelve 404", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/servicios/999999999`);
    const body = (await res.json()) as { error: string };
    assert.equal(res.status, 404);
    assert.equal(body.error, "not_found");
  } finally {
    await close();
  }
});

test("GET /api/servicios/:id inválido (no numérico) devuelve 400", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/servicios/abc`);
    const body = (await res.json()) as { error: string };
    assert.equal(res.status, 400);
    assert.equal(body.error, "invalid_id");
  } finally {
    await close();
  }
});

test("GET /api/servicios/:id existente devuelve precio/rating como number, no string", async () => {
  const { url, close } = listen();
  try {
    const listRes = await fetch(`${url}/api/servicios?limit=1`);
    const listBody = (await listRes.json()) as { data: { id: number }[] };
    const first = listBody.data[0];
    if (!first) {
      // BD local sin servicios aprobados/visibles hoy — nada que comparar.
      return;
    }

    const res = await fetch(`${url}/api/servicios/${first.id}`);
    const body = (await res.json()) as {
      id: number;
      precio: number | null;
      rating: { promedio: number | null };
    };
    assert.equal(res.status, 200);
    assert.equal(body.id, first.id);
    if (body.precio !== null) assert.equal(typeof body.precio, "number");
    if (body.rating.promedio !== null) assert.equal(typeof body.rating.promedio, "number");
  } finally {
    await close();
  }
});
