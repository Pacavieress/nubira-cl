import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";

const SESSION_ADMIN = "test-admin-dominios-session";
const SESSION_NO_ADMIN = "test-admin-dominios-session-no-admin";
// id=1 "Soporte Nubira" — admin real confirmado vía SELECT antes de escribir estos tests
// (rol='admin', visible=1, bloqueado=0). Solo se usa para autenticar, nunca se muta.
const ADMIN_ID = 1;
let alumnoNoAdminId: number;
let dominioCreadoId: number | null = null;

before(async () => {
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_ADMIN, ADMIN_ID],
  );

  const [insAlumno] = await pool.query(
    "INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, rol) VALUES (?, ?, 'x', 1, 0, 'alumno')",
    ["Test No Admin", `test-no-admin-dominios-${Date.now()}@example.invalid`],
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
  if (dominioCreadoId !== null) {
    await pool.query("DELETE FROM dominios_permitidos WHERE id = ?", [dominioCreadoId]);
  }
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

test("GET /api/admin/dominios sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/dominios`);
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("GET /api/admin/dominios con sesión NO admin devuelve 403", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/dominios`, { headers: { Cookie: `PHPSESSID=${SESSION_NO_ADMIN}` } });
    assert.equal(res.status, 403);
  } finally {
    await close();
  }
});

test("GET /api/admin/dominios con sesión admin: devuelve el listado real con totalUsuarios", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/dominios`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.ok(Array.isArray(body));
    assert.ok(body.length > 0, "debe haber dominios reales en la BD local");
    const primero = body[0];
    assert.equal(typeof primero.id, "number");
    assert.equal(typeof primero.dominio, "string");
    assert.equal(typeof primero.institucion, "string");
    assert.equal(typeof primero.totalUsuarios, "number");
  } finally {
    await close();
  }
});

test("POST /api/admin/dominios: normaliza dominio (quita https://www./@, baja a minúsculas) e institución a MAYÚSCULAS", async () => {
  const { url, close } = listen();
  try {
    const dominioUnico = `test-fixture-${Date.now()}.cl`;
    const res = await fetch(`${url}/api/admin/dominios`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_ADMIN}` },
      body: JSON.stringify({ dominio: `https://www.${dominioUnico}`, institucion: "  universidad de prueba  " }),
    });
    assert.equal(res.status, 201);
    const body = await res.json();
    assert.equal(body.dominio, dominioUnico);
    assert.equal(body.institucion, "UNIVERSIDAD DE PRUEBA");
    dominioCreadoId = body.id;
  } finally {
    await close();
  }
});

test("POST /api/admin/dominios: dominio duplicado devuelve 409", async () => {
  const { url, close } = listen();
  try {
    assert.ok(dominioCreadoId, "requiere el fixture del test anterior");
    const [rows] = await pool.query<{ dominio: string }[] & { length: number }>("SELECT dominio FROM dominios_permitidos WHERE id = ?", [
      dominioCreadoId,
    ]);
    const dominioExistente = (rows as unknown as { dominio: string }[])[0]!.dominio;

    const res = await fetch(`${url}/api/admin/dominios`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_ADMIN}` },
      body: JSON.stringify({ dominio: dominioExistente, institucion: "OTRO NOMBRE" }),
    });
    assert.equal(res.status, 409);
  } finally {
    await close();
  }
});

test("PUT /api/admin/dominios/:id: renombra la institución (el dominio en sí no es editable)", async () => {
  const { url, close } = listen();
  try {
    assert.ok(dominioCreadoId);
    const res = await fetch(`${url}/api/admin/dominios/${dominioCreadoId}`, {
      method: "PUT",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_ADMIN}` },
      body: JSON.stringify({ institucion: "nombre renombrado" }),
    });
    assert.equal(res.status, 200);

    const [rows] = await pool.query<{ institucion: string }[] & { length: number }>(
      "SELECT institucion FROM dominios_permitidos WHERE id = ?",
      [dominioCreadoId],
    );
    assert.equal((rows as unknown as { institucion: string }[])[0]?.institucion, "NOMBRE RENOMBRADO");
  } finally {
    await close();
  }
});

test("PUT /api/admin/dominios/:id inexistente devuelve 404", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/dominios/999999999`, {
      method: "PUT",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_ADMIN}` },
      body: JSON.stringify({ institucion: "X" }),
    });
    assert.equal(res.status, 404);
  } finally {
    await close();
  }
});

test("DELETE /api/admin/dominios/:id: elimina el fixture creado", async () => {
  const { url, close } = listen();
  try {
    assert.ok(dominioCreadoId);
    const res = await fetch(`${url}/api/admin/dominios/${dominioCreadoId}`, {
      method: "DELETE",
      headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` },
    });
    assert.equal(res.status, 200);

    const [rows] = await pool.query("SELECT id FROM dominios_permitidos WHERE id = ?", [dominioCreadoId]);
    assert.equal((rows as unknown[]).length, 0);
    dominioCreadoId = null;
  } finally {
    await close();
  }
});

test("DELETE /api/admin/dominios/:id inexistente devuelve 404", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/dominios/999999999`, {
      method: "DELETE",
      headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` },
    });
    assert.equal(res.status, 404);
  } finally {
    await close();
  }
});
