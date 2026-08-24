import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";

const SESSION_ADMIN = "test-admin-aulas-session";
const SESSION_NO_ADMIN = "test-admin-aulas-session-no-admin";
const ADMIN_ID = 1; // "Soporte Nubira" — mismo fixture real que los otros tests de admin.
let alumnoNoAdminId: number;
let contratoConAulaId: number;

before(async () => {
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_ADMIN, ADMIN_ID],
  );

  const [insAlumno] = await pool.query(
    "INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, rol) VALUES (?, ?, 'x', 1, 0, 'alumno')",
    ["Test No Admin Aulas", `test-no-admin-aulas-${Date.now()}@example.invalid`],
  );
  alumnoNoAdminId = (insAlumno as { insertId: number }).insertId;
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_NO_ADMIN, alumnoNoAdminId],
  );

  // Fixture de lectura: un contrato real con mensajes reales en chat_aula. El INNER JOIN
  // contra contratos es necesario — hay filas huérfanas en chat_aula (contrato_id 69/81)
  // que referencian contratos ya eliminados, hallazgo de datos preexistente y ajeno a esta
  // pieza (getAulaMensajes ya devuelve 404 correctamente para esos casos).
  const [rows] = await pool.query(
    "SELECT ca.contrato_id, COUNT(*) n FROM chat_aula ca JOIN contratos c ON c.id = ca.contrato_id GROUP BY ca.contrato_id ORDER BY n DESC LIMIT 1",
  );
  const fila = (rows as unknown as { contrato_id: number; n: number }[])[0];
  if (!fila) throw new Error("Se necesita al menos un contrato con mensajes reales en chat_aula para correr este test.");
  contratoConAulaId = fila.contrato_id;
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

test("GET /api/admin/aulas sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/aulas`);
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("GET /api/admin/aulas con sesión NO admin devuelve 403", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/aulas`, { headers: { Cookie: `PHPSESSID=${SESSION_NO_ADMIN}` } });
    assert.equal(res.status, 403);
  } finally {
    await close();
  }
});

test("GET /api/admin/aulas con sesión admin: trae contratos reales con avatares resueltos", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/aulas`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.ok(Array.isArray(body));
    assert.ok(body.length > 0, "debe haber contratos reales en la BD local");
    const primero = body[0];
    assert.equal(typeof primero.compradorNombre, "string");
    assert.equal(typeof primero.compradorFotoUrl, "string");
    assert.equal(typeof primero.enVivo, "boolean");
    assert.equal(typeof primero.cerrado, "boolean");
  } finally {
    await close();
  }
});

test("GET /api/admin/aulas?orden=asc vs orden=desc: orden real de fechaReferencia se invierte", async () => {
  const { url, close } = listen();
  try {
    const resDesc = await fetch(`${url}/api/admin/aulas?orden=desc`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    const bodyDesc = await resDesc.json();
    const resAsc = await fetch(`${url}/api/admin/aulas?orden=asc`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    const bodyAsc = await resAsc.json();

    const idsDesc = bodyDesc.map((a: { id: number }) => a.id);
    const idsAsc = bodyAsc.map((a: { id: number }) => a.id);
    assert.deepEqual(idsDesc, [...idsAsc].reverse());
  } finally {
    await close();
  }
});

test("GET /api/admin/aulas?q=zzz_no_existe_zzz: sin resultados", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/aulas?q=zzz_no_existe_zzz`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.deepEqual(body, []);
  } finally {
    await close();
  }
});

test("GET /api/admin/aulas/:id/mensajes gates + trae el historial real de un contrato con mensajes de aula", async () => {
  const { url, close } = listen();
  try {
    const resNoSesion = await fetch(`${url}/api/admin/aulas/${contratoConAulaId}/mensajes`);
    assert.equal(resNoSesion.status, 401);

    const resNoAdmin = await fetch(`${url}/api/admin/aulas/${contratoConAulaId}/mensajes`, { headers: { Cookie: `PHPSESSID=${SESSION_NO_ADMIN}` } });
    assert.equal(resNoAdmin.status, 403);

    const res = await fetch(`${url}/api/admin/aulas/${contratoConAulaId}/mensajes`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();

    assert.equal(typeof body.compradorId, "number");
    assert.ok(Array.isArray(body.mensajes));
    const mensajesAula = body.mensajes.filter((m: { origen: string }) => m.origen === "aula");
    assert.ok(mensajesAula.length > 0, "el fixture fue elegido por tener mensajes reales en chat_aula");
    assert.equal(typeof mensajesAula[0].mensaje, "string");
    assert.equal(typeof mensajesAula[0].remitenteId, "number");

    // Orden real: enviado_en ASC dentro de cada origen.
    const fechasAula = mensajesAula.map((m: { enviadoEn: string }) => new Date(m.enviadoEn).getTime());
    const ordenadas = [...fechasAula].sort((a, b) => a - b);
    assert.deepEqual(fechasAula, ordenadas);
  } finally {
    await close();
  }
});

test("GET /api/admin/aulas/:id/mensajes de un contrato inexistente devuelve 404", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/aulas/999999999/mensajes`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 404);
  } finally {
    await close();
  }
});

test("GET /api/admin/aulas/:id/mensajes con id inválido devuelve 400", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/aulas/abc/mensajes`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 400);
  } finally {
    await close();
  }
});
