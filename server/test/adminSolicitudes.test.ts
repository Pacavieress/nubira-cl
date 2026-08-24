import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";

const SESSION_ADMIN = "test-admin-solicitudes-session";
const SESSION_NO_ADMIN = "test-admin-solicitudes-session-no-admin";
const ADMIN_ID = 1; // "Soporte Nubira" — mismo fixture real que los otros tests de admin.
let alumnoNoAdminId: number;
let solicitudPendienteId: number;
let solicitudRevisadaId: number;

before(async () => {
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_ADMIN, ADMIN_ID],
  );

  const [insAlumno] = await pool.query(
    "INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, rol) VALUES (?, ?, 'x', 1, 0, 'alumno')",
    ["Test No Admin Solicitudes", `test-no-admin-solicitudes-${Date.now()}@example.invalid`],
  );
  alumnoNoAdminId = (insAlumno as { insertId: number }).insertId;
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_NO_ADMIN, alumnoNoAdminId],
  );

  const [insPendiente] = await pool.query(
    "INSERT INTO solicitudes_instituciones (institucion, email, estado, correo_enviado) VALUES (?, ?, 'pendiente', 0)",
    [`Test Institucion Pendiente ${Date.now()}`, `test-solicitud-pendiente-${Date.now()}@example.invalid`],
  );
  solicitudPendienteId = (insPendiente as { insertId: number }).insertId;

  const [insRevisada] = await pool.query(
    "INSERT INTO solicitudes_instituciones (institucion, email, estado, correo_enviado) VALUES (?, ?, 'revisada', 1)",
    [`Test Institucion Revisada ${Date.now()}`, `test-solicitud-revisada-${Date.now()}@example.invalid`],
  );
  solicitudRevisadaId = (insRevisada as { insertId: number }).insertId;
});

after(async () => {
  await pool.query("DELETE FROM solicitudes_instituciones WHERE id IN (?, ?)", [solicitudPendienteId, solicitudRevisadaId]);
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

test("GET /api/admin/solicitudes sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/solicitudes`);
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("GET /api/admin/solicitudes con sesión NO admin devuelve 403", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/solicitudes`, { headers: { Cookie: `PHPSESSID=${SESSION_NO_ADMIN}` } });
    assert.equal(res.status, 403);
  } finally {
    await close();
  }
});

test("GET /api/admin/solicitudes (sin filtro) trae todas las solicitudes con estructura completa", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/solicitudes`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.equal(body.estado, "");
    assert.ok(Array.isArray(body.solicitudes));
    const pendiente = body.solicitudes.find((s: { id: number }) => s.id === solicitudPendienteId);
    assert.ok(pendiente, "debe incluir la solicitud pendiente del fixture");
    assert.equal(pendiente.estado, "pendiente");
    assert.equal(pendiente.correoEnviado, false);
    const revisada = body.solicitudes.find((s: { id: number }) => s.id === solicitudRevisadaId);
    assert.ok(revisada, "debe incluir la solicitud revisada del fixture");
    assert.equal(revisada.estado, "revisada");
    assert.equal(revisada.correoEnviado, true);
  } finally {
    await close();
  }
});

test("GET /api/admin/solicitudes?estado=pendiente: todas las devueltas están pendientes", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/solicitudes?estado=pendiente`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.equal(body.estado, "pendiente");
    assert.ok(body.solicitudes.some((s: { id: number }) => s.id === solicitudPendienteId));
    for (const s of body.solicitudes) assert.equal(s.estado, "pendiente");
  } finally {
    await close();
  }
});

test("GET /api/admin/solicitudes?estado=revisada: todas las devueltas están revisadas", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/solicitudes?estado=revisada`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.equal(body.estado, "revisada");
    assert.ok(body.solicitudes.some((s: { id: number }) => s.id === solicitudRevisadaId));
    for (const s of body.solicitudes) assert.equal(s.estado, "revisada");
  } finally {
    await close();
  }
});
