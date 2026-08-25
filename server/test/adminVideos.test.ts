import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";

const SESSION_ADMIN = "test-admin-videos-session";
const SESSION_NO_ADMIN = "test-admin-videos-session-no-admin";
const ADMIN_ID = 1; // "Soporte Nubira" — mismo fixture real que los otros tests de admin.
let alumnoNoAdminId: number;
let servicioFixtureId: number;
let videoPathOriginal: string | null;
let videoEstadoOriginal: string | null;
let videoSubidoEnOriginal: Date | null;

before(async () => {
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_ADMIN, ADMIN_ID],
  );

  const [insAlumno] = await pool.query(
    "INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, rol) VALUES (?, ?, 'x', 1, 0, 'alumno')",
    ["Test No Admin Videos", `test-no-admin-videos-${Date.now()}@example.invalid`],
  );
  alumnoNoAdminId = (insAlumno as { insertId: number }).insertId;
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_NO_ADMIN, alumnoNoAdminId],
  );

  const [servicioReal] = await pool.query("SELECT id, video_path, video_estado, video_subido_en FROM servicios ORDER BY id DESC LIMIT 1");
  const s = (servicioReal as unknown as { id: number; video_path: string | null; video_estado: string | null; video_subido_en: Date | null }[])[0];
  if (!s) throw new Error("Se necesita al menos un servicio real en la BD local para correr este test.");
  servicioFixtureId = s.id;
  videoPathOriginal = s.video_path;
  videoEstadoOriginal = s.video_estado;
  videoSubidoEnOriginal = s.video_subido_en;

  await pool.query("UPDATE servicios SET video_path = ?, video_estado = 'pendiente', video_subido_en = NOW() WHERE id = ?", [
    "test_video_fixture.mp4",
    servicioFixtureId,
  ]);
});

after(async () => {
  await pool.query("UPDATE servicios SET video_path = ?, video_estado = ?, video_subido_en = ? WHERE id = ?", [
    videoPathOriginal,
    videoEstadoOriginal,
    videoSubidoEnOriginal,
    servicioFixtureId,
  ]);
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

test("GET /api/admin/videos sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/videos`);
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("GET /api/admin/videos con sesión NO admin devuelve 403", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/videos`, { headers: { Cookie: `PHPSESSID=${SESSION_NO_ADMIN}` } });
    assert.equal(res.status, 403);
  } finally {
    await close();
  }
});

test("GET /api/admin/videos (default, pendiente) trae el fixture con estructura completa", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/videos`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.equal(body.filtro, "pendiente");
    assert.ok(Array.isArray(body.videos));
    assert.ok(typeof body.totalPendientes === "number");
    const fixture = body.videos.find((v: { id: number }) => v.id === servicioFixtureId);
    assert.ok(fixture, "debe incluir el servicio del fixture");
    assert.equal(fixture.videoEstado, "pendiente");
    assert.equal(fixture.videoPath, "test_video_fixture.mp4");
    assert.equal(typeof fixture.tutorNombre, "string");
    assert.equal(typeof fixture.tutorCorreo, "string");
  } finally {
    await close();
  }
});

test("GET /api/admin/videos?filtro=aprobado: el fixture (pendiente) no aparece", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/videos?filtro=aprobado`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.equal(body.filtro, "aprobado");
    assert.ok(!body.videos.some((v: { id: number }) => v.id === servicioFixtureId));
    for (const v of body.videos) assert.equal(v.videoEstado, "aprobado");
  } finally {
    await close();
  }
});

test("GET /api/admin/videos?filtro=todos incluye el fixture sin filtrar por estado", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/videos?filtro=todos`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.equal(body.filtro, "todos");
    assert.ok(body.videos.some((v: { id: number }) => v.id === servicioFixtureId));
  } finally {
    await close();
  }
});
