import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";

const SESSION_ADMIN = "test-admin-apuntes-session";
const SESSION_NO_ADMIN = "test-admin-apuntes-session-no-admin";
const ADMIN_ID = 1; // "Soporte Nubira" — mismo fixture real que los otros tests de admin.
let alumnoNoAdminId: number;
let autorId: number;
let apunteId: number;

before(async () => {
  await pool.query("INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)", [SESSION_ADMIN, ADMIN_ID]);

  const [insAlumno] = await pool.query("INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, rol) VALUES (?, ?, 'x', 1, 0, 'alumno')", [
    "Test No Admin Apuntes",
    `test-no-admin-apuntes-${Date.now()}@example.invalid`,
  ]);
  alumnoNoAdminId = (insAlumno as { insertId: number }).insertId;
  await pool.query("INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)", [
    SESSION_NO_ADMIN,
    alumnoNoAdminId,
  ]);

  const [insAutor] = await pool.query("INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, rol) VALUES (?, ?, 'x', 1, 0, 'alumno')", [
    "Test Autor Apuntes",
    `test-autor-apuntes-${Date.now()}@example.invalid`,
  ]);
  autorId = (insAutor as { insertId: number }).insertId;

  const [insApunte] = await pool.query(
    "INSERT INTO apuntes (id_alumno, titulo, asignatura, archivo, fecha_subida, estado, publico) VALUES (?, 'Apunte Fixture Test', 'Cálculo', 'fixture.pdf', NOW(), 'aprobado', 1)",
    [autorId],
  );
  apunteId = (insApunte as { insertId: number }).insertId;
});

after(async () => {
  await pool.query("DELETE FROM apuntes WHERE id = ?", [apunteId]);
  await pool.query("DELETE FROM sesiones_api WHERE session_id IN (?, ?)", [SESSION_ADMIN, SESSION_NO_ADMIN]);
  await pool.query("DELETE FROM alumnos WHERE id IN (?, ?)", [alumnoNoAdminId, autorId]);
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

test("GET /api/admin/apuntes sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/apuntes`);
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("GET /api/admin/apuntes con sesión NO admin devuelve 403", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/apuntes`, { headers: { Cookie: `PHPSESSID=${SESSION_NO_ADMIN}` } });
    assert.equal(res.status, 403);
  } finally {
    await close();
  }
});

test("GET /api/admin/apuntes?q= trae el fixture con estructura completa", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/apuntes?q=Apunte Fixture Test`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    const a = body.apuntes.find((x: { id: number }) => x.id === apunteId);
    assert.ok(a, "debe incluir el fixture");
    assert.equal(a.publico, true);
    assert.equal(a.estado, "aprobado");
    assert.equal(a.autor, "Test Autor Apuntes");
    assert.equal(typeof a.miniaturaUrl, "string");
  } finally {
    await close();
  }
});

test("POST /api/admin/apuntes/:id/alternar invierte publico y es reversible", async () => {
  const { url, close } = listen();
  try {
    const r1 = await fetch(`${url}/api/admin/apuntes/${apunteId}/alternar`, { method: "POST", headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(r1.status, 200);
    const [rows1] = await pool.query("SELECT publico FROM apuntes WHERE id = ?", [apunteId]);
    assert.equal((rows1 as { publico: number }[])[0].publico, 0);

    const r2 = await fetch(`${url}/api/admin/apuntes/${apunteId}/alternar`, { method: "POST", headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(r2.status, 200);
    const [rows2] = await pool.query("SELECT publico FROM apuntes WHERE id = ?", [apunteId]);
    assert.equal((rows2 as { publico: number }[])[0].publico, 1);
  } finally {
    await close();
  }
});
