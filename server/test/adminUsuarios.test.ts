import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";

const SESSION_ADMIN = "test-admin-usuarios-session";
const SESSION_NO_ADMIN = "test-admin-usuarios-session-no-admin";
const ADMIN_ID = 1; // "Soporte Nubira" — mismo fixture real que los otros tests de admin.
let alumnoNoAdminId: number;
let fixtureId: number;

before(async () => {
  await pool.query("INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)", [SESSION_ADMIN, ADMIN_ID]);

  const [insAlumno] = await pool.query("INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, rol) VALUES (?, ?, 'x', 1, 0, 'alumno')", [
    "Test No Admin Usuarios",
    `test-no-admin-usuarios-${Date.now()}@example.invalid`,
  ]);
  alumnoNoAdminId = (insAlumno as { insertId: number }).insertId;
  await pool.query("INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)", [
    SESSION_NO_ADMIN,
    alumnoNoAdminId,
  ]);

  const [insFixture] = await pool.query(
    "INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, confirmado, rol) VALUES (?, ?, 'x', 1, 0, 1, 'alumno')",
    ["Test Fixture Usuarios Listado", `test-fixture-usuarios-listado-${Date.now()}@example.invalid`],
  );
  fixtureId = (insFixture as { insertId: number }).insertId;
});

after(async () => {
  await pool.query("DELETE FROM sesiones_api WHERE session_id IN (?, ?)", [SESSION_ADMIN, SESSION_NO_ADMIN]);
  await pool.query("DELETE FROM alumnos WHERE id IN (?, ?)", [alumnoNoAdminId, fixtureId]);
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

test("GET /api/admin/usuarios sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/usuarios`);
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("GET /api/admin/usuarios con sesión NO admin devuelve 403", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/usuarios`, { headers: { Cookie: `PHPSESSID=${SESSION_NO_ADMIN}` } });
    assert.equal(res.status, 403);
  } finally {
    await close();
  }
});

test("GET /api/admin/usuarios trae el fixture con estructura completa", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/usuarios?q=Test Fixture Usuarios Listado`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    const u = body.usuarios.find((x: { id: number }) => x.id === fixtureId);
    assert.ok(u, "debe incluir el fixture");
    assert.equal(u.bloqueado, false);
    assert.equal(u.rol, "alumno");
    assert.equal(typeof body.totalUsersGlobal, "number");
    assert.equal(typeof body.totalPages, "number");
  } finally {
    await close();
  }
});

test("GET /api/admin/usuarios?rol=admin filtra por rol", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/usuarios?rol=admin`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.ok(!body.usuarios.some((u: { id: number }) => u.id === fixtureId), "el fixture (alumno) no debe salir con rol=admin");
    for (const u of body.usuarios) assert.equal(u.rol, "admin");
  } finally {
    await close();
  }
});
