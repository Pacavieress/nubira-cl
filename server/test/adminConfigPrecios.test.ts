import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";

const SESSION_ADMIN = "test-admin-config-precios-session";
const SESSION_NO_ADMIN = "test-admin-config-precios-session-no-admin";
const ADMIN_ID = 1; // "Soporte Nubira" — mismo fixture real que adminDominios.test.ts.
let alumnoNoAdminId: number;

// config.valor es GLOBAL y afecta al sitio real (precio de desbloqueo de contacto, promo
// activa) — a diferencia de fixtures desechables de otros tests, ACÁ hay que snapshotear el
// valor real antes de mutar y restaurarlo exacto al terminar.
let precioOriginal: string | null;
let ofertaOriginal: string | null;

before(async () => {
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_ADMIN, ADMIN_ID],
  );

  const [insAlumno] = await pool.query(
    "INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, rol) VALUES (?, ?, 'x', 1, 0, 'alumno')",
    ["Test No Admin Precios", `test-no-admin-precios-${Date.now()}@example.invalid`],
  );
  alumnoNoAdminId = (insAlumno as { insertId: number }).insertId;
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_NO_ADMIN, alumnoNoAdminId],
  );

  const [rows] = await pool.query<{ clave: string; valor: string | null }[] & { length: number }>(
    "SELECT clave, valor FROM config WHERE clave IN ('precio_desbloqueo_contacto', 'oferta_gratis_hasta')",
  );
  for (const row of rows as unknown as { clave: string; valor: string | null }[]) {
    if (row.clave === "precio_desbloqueo_contacto") precioOriginal = row.valor;
    if (row.clave === "oferta_gratis_hasta") ofertaOriginal = row.valor;
  }
});

after(async () => {
  await pool.query("UPDATE config SET valor = ? WHERE clave = 'precio_desbloqueo_contacto'", [precioOriginal]);
  await pool.query("UPDATE config SET valor = ? WHERE clave = 'oferta_gratis_hasta'", [ofertaOriginal]);
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

test("GET /api/admin/config-precios sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/config-precios`);
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("GET /api/admin/config-precios con sesión NO admin devuelve 403", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/config-precios`, { headers: { Cookie: `PHPSESSID=${SESSION_NO_ADMIN}` } });
    assert.equal(res.status, 403);
  } finally {
    await close();
  }
});

test("GET /api/admin/config-precios con sesión admin: trae los valores reales actuales", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/config-precios`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.equal(body.precioDesbloqueoContacto, Number(precioOriginal));
    assert.equal(typeof body.ofertaVigente, "boolean");
  } finally {
    await close();
  }
});

test("PUT /api/admin/config-precios/precio: rechaza menor a 100", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/config-precios/precio`, {
      method: "PUT",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_ADMIN}` },
      body: JSON.stringify({ precio: 50 }),
    });
    assert.equal(res.status, 400);
  } finally {
    await close();
  }
});

test("PUT /api/admin/config-precios/precio: rechaza mayor a 99999", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/config-precios/precio`, {
      method: "PUT",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_ADMIN}` },
      body: JSON.stringify({ precio: 100000 }),
    });
    assert.equal(res.status, 400);
  } finally {
    await close();
  }
});

test("PUT /api/admin/config-precios/precio: valor válido se persiste", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/config-precios/precio`, {
      method: "PUT",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_ADMIN}` },
      body: JSON.stringify({ precio: 12345 }),
    });
    assert.equal(res.status, 200);

    const [rows] = await pool.query<{ valor: string }[] & { length: number }>(
      "SELECT valor FROM config WHERE clave = 'precio_desbloqueo_contacto'",
    );
    assert.equal((rows as unknown as { valor: string }[])[0]?.valor, "12345");
  } finally {
    await close();
  }
});

test("PUT /api/admin/config-precios/oferta: rechaza fecha pasada", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/config-precios/oferta`, {
      method: "PUT",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_ADMIN}` },
      body: JSON.stringify({ fecha: "2020-01-01T10:00" }),
    });
    assert.equal(res.status, 400);
  } finally {
    await close();
  }
});

test("PUT /api/admin/config-precios/oferta: fecha futura válida activa la promo", async () => {
  const { url, close } = listen();
  try {
    const futura = new Date(Date.now() + 365 * 24 * 60 * 60 * 1000);
    const isoLocal = `${futura.getFullYear()}-${String(futura.getMonth() + 1).padStart(2, "0")}-${String(futura.getDate()).padStart(2, "0")}T10:00`;

    const res = await fetch(`${url}/api/admin/config-precios/oferta`, {
      method: "PUT",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_ADMIN}` },
      body: JSON.stringify({ fecha: isoLocal }),
    });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.equal(body.ofertaVigente, true);

    const getRes = await fetch(`${url}/api/admin/config-precios`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    const getBody = await getRes.json();
    assert.equal(getBody.ofertaVigente, true);
  } finally {
    await close();
  }
});

test("PUT /api/admin/config-precios/oferta: fecha vacía desactiva la promo", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/config-precios/oferta`, {
      method: "PUT",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_ADMIN}` },
      body: JSON.stringify({ fecha: "" }),
    });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.equal(body.ofertaGratisHasta, null);
    assert.equal(body.ofertaVigente, false);
  } finally {
    await close();
  }
});
