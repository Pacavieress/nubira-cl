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

const ART_SLUG = "guia-definitiva-para-aprobar-calculo-i-estrategias-de-estudio-para-el-primer-ano-universitario";

test("GET /api/guias/:cat/:slug con slug de artículo inexistente devuelve 404", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/guias/matematicas/no-existe`);
    assert.equal(res.status, 404);
  } finally {
    await close();
  }
});

test("GET /api/guias/:cat/:slug con categoría inexistente devuelve 404", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/guias/no-existe/${ART_SLUG}`);
    assert.equal(res.status, 404);
  } finally {
    await close();
  }
});

test("GET /api/guias/matematicas/:slug (público, real): artículo real con FAQs, tutores relacionados y link a /clases/matematicas", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/guias/matematicas/${ART_SLUG}`);
    assert.equal(res.status, 200);

    const body = (await res.json()) as {
      modo: string;
      categoria: { nombre: string; soloTutores: boolean };
      articulo: { titulo: string; cuerpoHtml: string; autorNombre: string };
      faqs: { pregunta: string; respuesta: string }[];
      tutoresRelacionados: { id: number; url: string; titulo: string }[];
      linkVerClases: string | null;
      linkVerApuntes: string | null;
      mostrarBreadcrumb: boolean;
    };

    assert.equal(body.modo, "articulo");
    assert.equal(body.categoria.nombre, "Matemáticas");
    assert.equal(body.categoria.soloTutores, false);
    assert.ok(body.articulo.titulo.startsWith("Guía Definitiva"));
    assert.ok(body.articulo.cuerpoHtml.length > 0, "el cuerpo del artículo real no debe venir vacío");
    assert.equal(body.faqs.length, 4);
    assert.ok(body.tutoresRelacionados.length > 0, "Matemáticas tiene 10 servicios reales, deben aparecer tutores relacionados");
    assert.equal(body.linkVerClases, "matematicas");
    assert.equal(body.linkVerApuntes, null);
    assert.equal(body.mostrarBreadcrumb, true);
  } finally {
    await close();
  }
});

test("GET /api/guias/para-tutores/:slug sin sesión devuelve 401 sin_sesion (mismo gate que el hub)", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/guias/para-tutores/cualquier-slug`);
    assert.equal(res.status, 401);
    const body = (await res.json()) as { error: string };
    assert.equal(body.error, "sin_sesion");
  } finally {
    await close();
  }
});

test("GET /api/guias/para-tutores/:slug con sesión de tutor activo real pasa el gate (404 esperado: no hay artículo publicado en Para Tutores hoy)", async () => {
  const SESSION = "test-guia-articulo-tutor-session";
  const USUARIO_ID = 167; // tutor real confirmado en piezas anteriores
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION, USUARIO_ID],
  );

  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/guias/para-tutores/cualquier-slug`, { headers: { Cookie: `PHPSESSID=${SESSION}` } });
    // El gate de tutor pasa (no 401/403) — 404 es el resultado correcto porque no existe
    // ningún artículo publicado en Para Tutores hoy (confirmado: 1 solo artículo real,
    // en Matemáticas). Si el gate hubiera fallado, sería 401 o 403, no 404.
    assert.equal(res.status, 404);
    const body = (await res.json()) as { error: string };
    assert.equal(body.error, "not_found");
  } finally {
    await close();
    await pool.query("DELETE FROM sesiones_api WHERE session_id = ?", [SESSION]);
  }
});
