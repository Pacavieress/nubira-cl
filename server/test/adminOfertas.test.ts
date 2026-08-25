import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";

const SESSION_ADMIN = "test-admin-ofertas-session";
const SESSION_NO_ADMIN = "test-admin-ofertas-session-no-admin";
const ADMIN_ID = 1; // "Soporte Nubira" — mismo fixture real que los otros tests de admin.
let alumnoNoAdminId: number;
let servicioFixtureId: number;
let precioOriginal: number;

before(async () => {
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_ADMIN, ADMIN_ID],
  );

  const [insAlumno] = await pool.query(
    "INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, rol) VALUES (?, ?, 'x', 1, 0, 'alumno')",
    ["Test No Admin Ofertas", `test-no-admin-ofertas-${Date.now()}@example.invalid`],
  );
  alumnoNoAdminId = (insAlumno as { insertId: number }).insertId;
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_NO_ADMIN, alumnoNoAdminId],
  );

  const [servicioReal] = await pool.query("SELECT id, precio FROM servicios WHERE estado = 'aprobado' ORDER BY id DESC LIMIT 1");
  const s = (servicioReal as unknown as { id: number; precio: number }[])[0];
  if (!s) throw new Error("Se necesita al menos un servicio aprobado real en la BD local para correr este test.");
  servicioFixtureId = s.id;
  precioOriginal = s.precio;

  // Aseguramos estado limpio (sin oferta previa) antes de correr los tests.
  await pool.query("UPDATE servicios SET precio_oferta = NULL, cupos_oferta = 0, is_subvencionado = 0, oferta_termino = NULL WHERE id = ?", [
    servicioFixtureId,
  ]);
});

after(async () => {
  await pool.query("UPDATE servicios SET precio_oferta = NULL, cupos_oferta = 0, is_subvencionado = 0, oferta_termino = NULL WHERE id = ?", [
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

test("GET /api/admin/ofertas sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/ofertas`);
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("GET /api/admin/ofertas con sesión NO admin devuelve 403", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/ofertas`, { headers: { Cookie: `PHPSESSID=${SESSION_NO_ADMIN}` } });
    assert.equal(res.status, 403);
  } finally {
    await close();
  }
});

test("GET /api/admin/ofertas trae servicios reales con estructura completa", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/ofertas`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.equal(body.orden, "recientes");
    assert.ok(Array.isArray(body.servicios));
    const fixture = body.servicios.find((s: { id: number }) => s.id === servicioFixtureId);
    assert.ok(fixture, "debe incluir el servicio del fixture");
    assert.equal(fixture.isSubvencionado, false);
  } finally {
    await close();
  }
});

test("POST /api/admin/ofertas/:id/aplicar-oferta con % calcula precioOferta desde el precio real", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/ofertas/${servicioFixtureId}/aplicar-oferta`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_ADMIN}` },
      body: JSON.stringify({ tipo: "porcentaje", pctOferta: 20, cupos: 5 }),
    });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.equal(body.precioOferta, Math.round(precioOriginal * 0.8));
    assert.equal(body.cupos, 5);

    // precio_oferta es DECIMAL — mysql2 lo devuelve como string sin decimalNumbers:true,
    // mismo gotcha ya documentado en adminLoginFallos para columnas COUNT(*).
    const [rows] = await pool.query("SELECT precio_oferta, cupos_oferta, is_subvencionado FROM servicios WHERE id = ?", [servicioFixtureId]);
    const row = (rows as { precio_oferta: string; cupos_oferta: number; is_subvencionado: number }[])[0];
    assert.equal(Number(row.precio_oferta), Math.round(precioOriginal * 0.8));
    assert.equal(row.cupos_oferta, 5);
    assert.equal(row.is_subvencionado, 1);
  } finally {
    await close();
  }
});

test("POST /api/admin/ofertas/:id/aplicar-oferta con fecha pasada devuelve 400", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/ofertas/${servicioFixtureId}/aplicar-oferta`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_ADMIN}` },
      body: JSON.stringify({ tipo: "porcentaje", pctOferta: 20, cupos: 5, ofertaTermino: "2020-01-01" }),
    });
    assert.equal(res.status, 400);
  } finally {
    await close();
  }
});

test("POST /api/admin/ofertas/:id/quitar-oferta limpia la oferta aplicada", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/ofertas/${servicioFixtureId}/quitar-oferta`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` },
    });
    assert.equal(res.status, 200);

    const [rows] = await pool.query("SELECT precio_oferta, cupos_oferta, is_subvencionado FROM servicios WHERE id = ?", [servicioFixtureId]);
    const row = (rows as { precio_oferta: number | null; cupos_oferta: number; is_subvencionado: number }[])[0];
    assert.equal(row.precio_oferta, null);
    assert.equal(row.cupos_oferta, 0);
    assert.equal(row.is_subvencionado, 0);
  } finally {
    await close();
  }
});

test("POST /api/admin/ofertas/:id/aplicar-oferta en servicio inexistente devuelve 404", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/ofertas/999999999/aplicar-oferta`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_ADMIN}` },
      body: JSON.stringify({ tipo: "precio", precioOferta: 1000, cupos: 1 }),
    });
    assert.equal(res.status, 404);
  } finally {
    await close();
  }
});
