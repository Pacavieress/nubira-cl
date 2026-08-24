import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";

const SESSION_ADMIN = "test-admin-cupones-session";
const SESSION_NO_ADMIN = "test-admin-cupones-session-no-admin";
const ADMIN_ID = 1; // "Soporte Nubira" — mismo fixture real que los otros tests de admin.
let alumnoNoAdminId: number;
let cuponFixtureId: number;
const CODIGO_FIXTURE = `TEST-${Date.now()}`;
const CODIGO_CREADO = `TEST-CREADO-${Date.now()}`;
let idCreado: number | undefined;

before(async () => {
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_ADMIN, ADMIN_ID],
  );

  const [insAlumno] = await pool.query(
    "INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, rol) VALUES (?, ?, 'x', 1, 0, 'alumno')",
    ["Test No Admin Cupones", `test-no-admin-cupones-${Date.now()}@example.invalid`],
  );
  alumnoNoAdminId = (insAlumno as { insertId: number }).insertId;
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_NO_ADMIN, alumnoNoAdminId],
  );

  const [insCupon] = await pool.query(
    "INSERT INTO cupones (codigo, porcentaje_descuento, usos_maximos, usos_actuales, creado_en) VALUES (?, 20, 1, 0, CURRENT_TIMESTAMP)",
    [CODIGO_FIXTURE],
  );
  cuponFixtureId = (insCupon as { insertId: number }).insertId;
});

after(async () => {
  await pool.query("DELETE FROM cupones WHERE id = ? OR codigo IN (?, ?)", [cuponFixtureId, CODIGO_FIXTURE, CODIGO_CREADO]);
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

test("GET /api/admin/cupones sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/cupones`);
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("GET /api/admin/cupones con sesión NO admin devuelve 403", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/cupones`, { headers: { Cookie: `PHPSESSID=${SESSION_NO_ADMIN}` } });
    assert.equal(res.status, 403);
  } finally {
    await close();
  }
});

test("GET /api/admin/cupones trae cupones y servicios reales con estructura completa", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/cupones`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.ok(Array.isArray(body.cupones));
    assert.ok(Array.isArray(body.servicios));
    const fixture = body.cupones.find((c: { id: number }) => c.id === cuponFixtureId);
    assert.ok(fixture, "debe incluir el cupón del fixture");
    assert.equal(fixture.codigo, CODIGO_FIXTURE);
    assert.equal(fixture.porcentajeDescuento, 20);
    assert.equal(fixture.servicioId, null);
  } finally {
    await close();
  }
});

test("POST /api/admin/cupones sin código devuelve 400", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/cupones`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_ADMIN}` },
      body: JSON.stringify({ porcentajeDescuento: 10, usosMaximos: 1 }),
    });
    assert.equal(res.status, 400);
  } finally {
    await close();
  }
});

test("POST /api/admin/cupones con código duplicado devuelve 409", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/cupones`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_ADMIN}` },
      body: JSON.stringify({ codigo: CODIGO_FIXTURE, porcentajeDescuento: 10, usosMaximos: 1 }),
    });
    assert.equal(res.status, 409);
  } finally {
    await close();
  }
});

test("POST /api/admin/cupones crea un cupón real y DELETE lo elimina", async () => {
  const { url, close } = listen();
  try {
    const resCrear = await fetch(`${url}/api/admin/cupones`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_ADMIN}` },
      body: JSON.stringify({ codigo: CODIGO_CREADO, porcentajeDescuento: 50, usosMaximos: 3 }),
    });
    assert.equal(resCrear.status, 201);
    const creado = await resCrear.json();
    assert.equal(creado.codigo, CODIGO_CREADO);
    assert.equal(creado.porcentajeDescuento, 50);
    assert.equal(creado.usosActuales, 0);
    idCreado = creado.id;

    const [rows] = await pool.query("SELECT codigo FROM cupones WHERE id = ?", [idCreado]);
    assert.equal((rows as { codigo: string }[])[0]?.codigo, CODIGO_CREADO);

    const resEliminar = await fetch(`${url}/api/admin/cupones/${idCreado}`, {
      method: "DELETE",
      headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` },
    });
    assert.equal(resEliminar.status, 200);

    const [rowsDespues] = await pool.query("SELECT id FROM cupones WHERE id = ?", [idCreado]);
    assert.equal((rowsDespues as unknown[]).length, 0);
  } finally {
    await close();
  }
});

test("DELETE /api/admin/cupones/:id inexistente devuelve 404", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/cupones/999999999`, {
      method: "DELETE",
      headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` },
    });
    assert.equal(res.status, 404);
  } finally {
    await close();
  }
});
