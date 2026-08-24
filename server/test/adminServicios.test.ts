import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";

const SESSION_ADMIN = "test-admin-servicios-session";
const SESSION_NO_ADMIN = "test-admin-servicios-session-no-admin";
const ADMIN_ID = 1; // "Soporte Nubira" — mismo fixture real que los otros tests de admin.
let alumnoNoAdminId: number;
let servicioFixtureId: number;
let visibleOriginal: number | null;

before(async () => {
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_ADMIN, ADMIN_ID],
  );

  const [insAlumno] = await pool.query(
    "INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, rol) VALUES (?, ?, 'x', 1, 0, 'alumno')",
    ["Test No Admin Servicios", `test-no-admin-servicios-${Date.now()}@example.invalid`],
  );
  alumnoNoAdminId = (insAlumno as { insertId: number }).insertId;
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_NO_ADMIN, alumnoNoAdminId],
  );

  // Fixture de escritura: un servicio REAL cualquiera, snapshoteando su 'visible' original
  // para restaurarlo exacto en after() — no es un fixture desechable (a diferencia de
  // otros tests de esta ronda), toggle_visibilidad es la única mutación de este panel.
  const [rows] = await pool.query<{ id: number; visible: number | null }[] & { length: number }>(
    "SELECT id, visible FROM servicios ORDER BY id DESC LIMIT 1",
  );
  const fila = (rows as unknown as { id: number; visible: number | null }[])[0];
  if (!fila) throw new Error("Se necesita al menos un servicio real en la BD local para correr este test.");
  servicioFixtureId = fila.id;
  visibleOriginal = fila.visible;
});

after(async () => {
  await pool.query("UPDATE servicios SET visible = ? WHERE id = ?", [visibleOriginal, servicioFixtureId]);
  await pool.query("DELETE FROM sesiones_api WHERE session_id IN (?, ?)", [SESSION_ADMIN, SESSION_NO_ADMIN]);
  await pool.query("DELETE FROM alumnos WHERE id = ?", [alumnoNoAdminId]);
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

test("GET /api/admin/servicios sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/servicios`);
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("GET /api/admin/servicios con sesión NO admin devuelve 403", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/servicios`, { headers: { Cookie: `PHPSESSID=${SESSION_NO_ADMIN}` } });
    assert.equal(res.status, 403);
  } finally {
    await close();
  }
});

test("GET /api/admin/servicios con sesión admin: trae servicios reales con portadaUrl resuelta", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/servicios`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.ok(Array.isArray(body));
    assert.ok(body.length > 0, "debe haber servicios reales en la BD local");
    const primero = body[0];
    assert.equal(typeof primero.titulo, "string");
    assert.equal(typeof primero.portadaUrl, "string");
    assert.equal(typeof primero.visible, "boolean");
  } finally {
    await close();
  }
});

test("PUT /api/admin/servicios/:id/visibilidad sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/servicios/${servicioFixtureId}/visibilidad`, {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ visible: false }),
    });
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("PUT /api/admin/servicios/:id/visibilidad: oculta y luego vuelve a mostrar un servicio real", async () => {
  const { url, close } = listen();
  try {
    const resOcultar = await fetch(`${url}/api/admin/servicios/${servicioFixtureId}/visibilidad`, {
      method: "PUT",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_ADMIN}` },
      body: JSON.stringify({ visible: false }),
    });
    assert.equal(resOcultar.status, 200);

    const [rowsOcultar] = await pool.query<{ visible: number }[] & { length: number }>("SELECT visible FROM servicios WHERE id = ?", [
      servicioFixtureId,
    ]);
    assert.equal((rowsOcultar as unknown as { visible: number }[])[0]?.visible, 0);

    const resMostrar = await fetch(`${url}/api/admin/servicios/${servicioFixtureId}/visibilidad`, {
      method: "PUT",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_ADMIN}` },
      body: JSON.stringify({ visible: true }),
    });
    assert.equal(resMostrar.status, 200);

    const [rowsMostrar] = await pool.query<{ visible: number }[] & { length: number }>("SELECT visible FROM servicios WHERE id = ?", [
      servicioFixtureId,
    ]);
    assert.equal((rowsMostrar as unknown as { visible: number }[])[0]?.visible, 1);
  } finally {
    await close();
  }
});

test("PUT /api/admin/servicios/:id/visibilidad inexistente devuelve 404", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/servicios/999999999/visibilidad`, {
      method: "PUT",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_ADMIN}` },
      body: JSON.stringify({ visible: true }),
    });
    assert.equal(res.status, 404);
  } finally {
    await close();
  }
});
