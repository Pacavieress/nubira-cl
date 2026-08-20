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

test("GET /api/guias (hub general): solo categorías habilitadas, no-tutores, CON al menos 1 artículo publicado", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/guias`);
    assert.equal(res.status, 200);
    const body = (await res.json()) as { modo: string; categorias: { slug: string; totalArticulos: number }[] };
    assert.equal(body.modo, "general");

    // Matemáticas tiene 1 artículo publicado real (confirmado con query directa) — debe
    // aparecer. "Para Tutores" (solo_tutores=1) NUNCA debe aparecer acá, sin importar sesión.
    const matematicas = body.categorias.find((c) => c.slug === "matematicas");
    assert.ok(matematicas, "Matemáticas debe aparecer en el hub general (tiene 1 artículo publicado)");
    assert.equal(matematicas!.totalArticulos, 1);

    assert.ok(!body.categorias.some((c) => c.slug === "para-tutores"), "Para Tutores NUNCA debe aparecer en el hub general");
  } finally {
    await close();
  }
});

test("GET /api/guias/:slug con slug inexistente devuelve 404", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/guias/esto-no-existe`);
    assert.equal(res.status, 404);
  } finally {
    await close();
  }
});

test("GET /api/guias/matematicas: categoría pública devuelve sus artículos sin necesitar sesión", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/guias/matematicas`);
    assert.equal(res.status, 200);
    const body = (await res.json()) as {
      modo: string;
      categoria: { nombre: string; soloTutores: boolean };
      articulos: { titulo: string; slug: string }[];
      noindex: boolean;
    };
    assert.equal(body.modo, "categoria");
    assert.equal(body.categoria.nombre, "Matemáticas");
    assert.equal(body.categoria.soloTutores, false);
    assert.equal(body.articulos.length, 1);
    assert.equal(body.articulos[0]!.slug, "guia-definitiva-para-aprobar-calculo-i-estrategias-de-estudio-para-el-primer-ano-universitario");
    // Umbral anti-thin-content: 1 artículo < 3 -> noindex=true (mismo criterio que landing_categoria.php).
    assert.equal(body.noindex, true);
  } finally {
    await close();
  }
});

test("GET /api/guias/para-tutores sin sesión devuelve 401 sin_sesion (equivalente al redirect real a /login)", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/guias/para-tutores`);
    assert.equal(res.status, 401);
    const body = (await res.json()) as { error: string };
    assert.equal(body.error, "sin_sesion");
  } finally {
    await close();
  }
});

test("GET /api/guias/para-tutores con sesión de usuario que NO es tutor activo devuelve 403 no_tutor", async () => {
  const SESSION = "test-guias-no-tutor-session";
  // Usuario sintético sin servicios/apuntes/valoraciones -> nb_es_tutor_activo() debe dar false.
  const USUARIO_ID = 888888899;
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION, USUARIO_ID],
  );

  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/guias/para-tutores`, { headers: { Cookie: `PHPSESSID=${SESSION}` } });
    assert.equal(res.status, 403);
    const body = (await res.json()) as { error: string };
    assert.equal(body.error, "no_tutor");
  } finally {
    await close();
    await pool.query("DELETE FROM sesiones_api WHERE session_id = ?", [SESSION]);
  }
});

test("GET /api/guias/para-tutores con sesión de un tutor activo real devuelve 200", async () => {
  const SESSION = "test-guias-tutor-real-session";
  // id=167 confirmado en piezas anteriores de esta migración: vendedor real con contratos
  // y ventas_apuntes -> nb_es_tutor_activo() debe dar true (tiene servicio/apunte aprobado).
  const USUARIO_ID = 167;
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION, USUARIO_ID],
  );

  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/guias/para-tutores`, { headers: { Cookie: `PHPSESSID=${SESSION}` } });
    assert.equal(res.status, 200);
    const body = (await res.json()) as { categoria: { soloTutores: boolean } };
    assert.equal(body.categoria.soloTutores, true);
  } finally {
    await close();
    await pool.query("DELETE FROM sesiones_api WHERE session_id = ?", [SESSION]);
  }
});
