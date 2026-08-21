import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";

const SESSION_ADMIN = "test-admin-autores-session";
const SESSION_NO_ADMIN = "test-admin-autores-session-no-admin";
const ADMIN_ID = 1; // "Soporte Nubira" — mismo fixture real que los otros tests de admin.
let alumnoNoAdminId: number;

before(async () => {
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_ADMIN, ADMIN_ID],
  );

  const [insAlumno] = await pool.query(
    "INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, rol) VALUES (?, ?, 'x', 1, 0, 'alumno')",
    ["Test No Admin Autores", `test-no-admin-autores-${Date.now()}@example.invalid`],
  );
  alumnoNoAdminId = (insAlumno as { insertId: number }).insertId;
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_NO_ADMIN, alumnoNoAdminId],
  );
});

after(async () => {
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

test("GET /api/admin/autores sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/autores`);
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("GET /api/admin/autores con sesión NO admin devuelve 403", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/autores`, { headers: { Cookie: `PHPSESSID=${SESSION_NO_ADMIN}` } });
    assert.equal(res.status, 403);
  } finally {
    await close();
  }
});

test("GET /api/admin/autores con sesión admin: trae autores reales con al menos 1 servicio aprobado", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/autores`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.ok(Array.isArray(body));
    assert.ok(body.length > 0, "debe haber autores reales en la BD local");
    assert.ok(body.every((a: { cantidadServicios: number }) => a.cantidadServicios >= 1));

    const primero = body[0];
    assert.equal(typeof primero.nombre, "string");
    assert.ok(primero.portadaUrl.includes("/upload/servicios/"));
    // Orden DESC por cantidad de servicios (mismo ORDER BY que el PHP real).
    for (let i = 1; i < body.length; i++) {
      assert.ok(body[i - 1].cantidadServicios >= body[i].cantidadServicios);
    }
  } finally {
    await close();
  }
});

test("GET /api/admin/autores?filtro=incompleto: solo trae autores con algo pendiente (foto/bio/tipo/horarios)", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/autores?filtro=incompleto`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.ok(
      body.every(
        (a: { fotoPerfil: string | null; bio: string | null; tipo: string | null; cantidadServicios: number; serviciosConHorario: number }) =>
          !a.fotoPerfil || !a.bio || !a.tipo || a.serviciosConHorario < a.cantidadServicios,
      ),
    );
  } finally {
    await close();
  }
});

test("GET /api/admin/autores?q=: busca por nombre/correo/institución, resultado es subconjunto del listado completo", async () => {
  const { url, close } = listen();
  try {
    const resTodos = await fetch(`${url}/api/admin/autores`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    const todos = await resTodos.json();
    const primerNombre = (todos[0].nombre as string).slice(0, 3);

    const res = await fetch(`${url}/api/admin/autores?q=${encodeURIComponent(primerNombre)}`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.ok(body.length > 0);
    assert.ok(body.length <= todos.length);
  } finally {
    await close();
  }
});
