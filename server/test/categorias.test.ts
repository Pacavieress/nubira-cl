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

test("GET /api/categorias devuelve 200 y un array", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/categorias`);
    const body = (await res.json()) as { data: string[] };
    assert.equal(res.status, 200);
    assert.ok(Array.isArray(body.data));
  } finally {
    await close();
  }
});

test("una ruta inexistente devuelve 404 con error not_found", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/ruta-que-no-existe`);
    const body = (await res.json()) as { error: string };
    assert.equal(res.status, 404);
    assert.equal(body.error, "not_found");
  } finally {
    await close();
  }
});

test("CORS_ORIGIN vacío (default del .env de test) no agrega Access-Control-Allow-Origin", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/categorias`, {
      headers: { Origin: "http://origen-no-permitido.example" },
    });
    assert.equal(res.headers.get("access-control-allow-origin"), null);
  } finally {
    await close();
  }
});
