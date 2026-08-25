import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";

const SESSION_ADMIN = "test-admin-chats-session";
const SESSION_NO_ADMIN = "test-admin-chats-session-no-admin";
const ADMIN_ID = 1; // "Soporte Nubira" — mismo fixture real que los otros tests de admin.
let alumnoNoAdminId: number;
let compradorId: number;
let vendedorId: number;
let servicioId: number;
let chatId: number;
let mensajeId: number;
let mensajeModeracionId: number;
let dlpId: number;

before(async () => {
  await pool.query("INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)", [SESSION_ADMIN, ADMIN_ID]);

  const [insAlumno] = await pool.query("INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, rol) VALUES (?, ?, 'x', 1, 0, 'alumno')", [
    "Test No Admin Chats",
    `test-no-admin-chats-${Date.now()}@example.invalid`,
  ]);
  alumnoNoAdminId = (insAlumno as { insertId: number }).insertId;
  await pool.query("INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)", [
    SESSION_NO_ADMIN,
    alumnoNoAdminId,
  ]);

  const [insComprador] = await pool.query("INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, rol) VALUES (?, ?, 'x', 1, 0, 'alumno')", [
    "Test Comprador Chats",
    `test-comprador-chats-${Date.now()}@example.invalid`,
  ]);
  compradorId = (insComprador as { insertId: number }).insertId;

  const [insVendedor] = await pool.query("INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, rol) VALUES (?, ?, 'x', 1, 0, 'alumno')", [
    "Test Vendedor Chats",
    `test-vendedor-chats-${Date.now()}@example.invalid`,
  ]);
  vendedorId = (insVendedor as { insertId: number }).insertId;

  const [insServicio] = await pool.query(
    "INSERT INTO servicios (alumno_id, titulo, categoria, precio, estado, visible) VALUES (?, 'Test servicio fixture chats', 'Otros', 5000, 'aprobado', 1)",
    [vendedorId],
  );
  servicioId = (insServicio as { insertId: number }).insertId;

  const [insChat] = await pool.query(
    "INSERT INTO conversaciones (servicio_id, comprador_id, vendedor_id, creado_en, ultima_interaccion, eliminado) VALUES (?, ?, ?, NOW(), NOW(), 0)",
    [servicioId, compradorId, vendedorId],
  );
  chatId = (insChat as { insertId: number }).insertId;

  const [insMensaje] = await pool.query(
    "INSERT INTO mensajes (conversacion_id, remitente_id, mensaje, enviado_en, visible) VALUES (?, ?, 'Hola, mensaje de prueba', NOW(), 1)",
    [chatId, compradorId],
  );
  mensajeId = (insMensaje as { insertId: number }).insertId;

  const [insMensajeMod] = await pool.query(
    "INSERT INTO mensajes (conversacion_id, remitente_id, mensaje, archivo_ruta, archivo_nombre, archivo_tipo, enviado_en, visible) VALUES (?, ?, '', 'fixture_test.jpg', 'fixture.jpg', 'image/jpeg', NOW(), 0)",
    [chatId, vendedorId],
  );
  mensajeModeracionId = (insMensajeMod as { insertId: number }).insertId;

  const [insDlp] = await pool.query(
    "INSERT INTO dlp_intentos (conversacion_id, remitente_id, categoria, texto_intentado, fecha, revisado_admin) VALUES (?, ?, 'telefono', 'llamame al 912345678', NOW(), 0)",
    [chatId, compradorId],
  );
  dlpId = (insDlp as { insertId: number }).insertId;
});

after(async () => {
  await pool.query("DELETE FROM dlp_intentos WHERE id = ?", [dlpId]);
  await pool.query("DELETE FROM mensajes WHERE id IN (?, ?)", [mensajeId, mensajeModeracionId]);
  await pool.query("DELETE FROM conversaciones WHERE id = ?", [chatId]);
  await pool.query("DELETE FROM servicios WHERE id = ?", [servicioId]);
  await pool.query("DELETE FROM sesiones_api WHERE session_id IN (?, ?)", [SESSION_ADMIN, SESSION_NO_ADMIN]);
  await pool.query("DELETE FROM alumnos WHERE id IN (?, ?, ?)", [alumnoNoAdminId, compradorId, vendedorId]);
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

test("GET /api/admin/chats sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/chats`);
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("GET /api/admin/chats con sesión NO admin devuelve 403", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/chats`, { headers: { Cookie: `PHPSESSID=${SESSION_NO_ADMIN}` } });
    assert.equal(res.status, 403);
  } finally {
    await close();
  }
});

test("GET /api/admin/chats (activos) trae el chat fixture", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/chats`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.equal(body.estado, "activos");
    const c = body.chats.find((x: { id: number }) => x.id === chatId);
    assert.ok(c, "debe incluir el chat fixture");
    assert.equal(c.compradorNombre, "Test Comprador Chats");
    assert.equal(c.vendedorNombre, "Test Vendedor Chats");
  } finally {
    await close();
  }
});

test("GET /api/admin/chats?q=<id> encuentra el chat por ID", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/chats?q=${chatId}`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.ok(body.chats.some((x: { id: number }) => x.id === chatId));
  } finally {
    await close();
  }
});

test("GET /api/admin/chats/contadores trae los 7 contadores esperados", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/chats/contadores`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    for (const k of ["activos", "cerrados", "contrato", "cotizacion", "inactivos", "alertasDlp", "moderacion"]) {
      assert.equal(typeof body[k], "number", `${k} debe ser number`);
    }
    assert.ok(body.alertasDlp >= 1, "debe contar la conversacion con DLP pendiente");
    assert.ok(body.moderacion >= 1, "debe contar el archivo pendiente de moderacion");
  } finally {
    await close();
  }
});

test("GET /api/admin/chats/:id trae info, mensajes y DLP del chat fixture", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/chats/${chatId}`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.equal(body.info.id, chatId);
    assert.equal(body.info.compradorNombre, "Test Comprador Chats");
    assert.ok(body.mensajes.some((m: { id: number }) => m.id === mensajeId));
    assert.ok(body.dlp.some((d: { id: number }) => d.id === dlpId));
    assert.equal(body.dlp.find((d: { id: number }) => d.id === dlpId).revisadoAdmin, false);
    assert.ok(body.metadata.totalMensajes >= 2);
  } finally {
    await close();
  }
});

test("GET /api/admin/chats/moderacion trae el archivo fixture pendiente", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/chats/moderacion`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    const m = body.archivos.find((x: { id: number }) => x.id === mensajeModeracionId);
    assert.ok(m, "debe incluir el mensaje fixture pendiente de moderacion");
    assert.equal(m.remitenteNombre, "Test Vendedor Chats");
  } finally {
    await close();
  }
});

test("POST eliminar/restaurar chat alterna conversaciones.eliminado", async () => {
  const { url, close } = listen();
  try {
    const r1 = await fetch(`${url}/api/admin/chats/${chatId}/eliminar`, { method: "POST", headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(r1.status, 200);
    const [rows1] = await pool.query("SELECT eliminado FROM conversaciones WHERE id = ?", [chatId]);
    assert.equal((rows1 as { eliminado: number }[])[0].eliminado, 1);

    const r2 = await fetch(`${url}/api/admin/chats/${chatId}/restaurar`, { method: "POST", headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(r2.status, 200);
    const [rows2] = await pool.query("SELECT eliminado FROM conversaciones WHERE id = ?", [chatId]);
    assert.equal((rows2 as { eliminado: number }[])[0].eliminado, 0);
  } finally {
    await close();
  }
});

test("POST marcar-revisado-dlp marca revisado_admin=1 para el chat", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/chats/${chatId}/marcar-revisado-dlp`, { method: "POST", headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const [rows] = await pool.query("SELECT revisado_admin FROM dlp_intentos WHERE id = ?", [dlpId]);
    assert.equal((rows as { revisado_admin: number }[])[0].revisado_admin, 1);

    // Revertir para no afectar el contador de "alertasDlp" en otra corrida del mismo fixture.
    await pool.query("UPDATE dlp_intentos SET revisado_admin = 0 WHERE id = ?", [dlpId]);
  } finally {
    await close();
  }
});

test("POST moderacion/:msgId/aprobar hace visible el mensaje con archivo", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/chats/moderacion/${mensajeModeracionId}/aprobar`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` },
    });
    assert.equal(res.status, 200);
    const [rows] = await pool.query("SELECT visible FROM mensajes WHERE id = ?", [mensajeModeracionId]);
    assert.equal((rows as { visible: number }[])[0].visible, 1);
  } finally {
    await close();
  }
});
