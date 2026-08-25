import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";

const SESSION_ADMIN = "test-admin-reclamos-session";
const SESSION_NO_ADMIN = "test-admin-reclamos-session-no-admin";
const ADMIN_ID = 1; // "Soporte Nubira" — mismo fixture real que los otros tests de admin.
let alumnoNoAdminId: number;
let alumnoTicketId: number;
let ticketPendienteId: number;
let ticketResueltoId: number;

before(async () => {
  await pool.query("INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)", [SESSION_ADMIN, ADMIN_ID]);

  const [insAlumno] = await pool.query("INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, rol) VALUES (?, ?, 'x', 1, 0, 'alumno')", [
    "Test No Admin Reclamos",
    `test-no-admin-reclamos-${Date.now()}@example.invalid`,
  ]);
  alumnoNoAdminId = (insAlumno as { insertId: number }).insertId;
  await pool.query("INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)", [
    SESSION_NO_ADMIN,
    alumnoNoAdminId,
  ]);

  const [insAlumnoTicket] = await pool.query("INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, rol) VALUES (?, ?, 'x', 1, 0, 'alumno')", [
    "Test Autor Reclamos",
    `test-autor-reclamos-${Date.now()}@example.invalid`,
  ]);
  alumnoTicketId = (insAlumnoTicket as { insertId: number }).insertId;

  const [insTicket1] = await pool.query(
    "INSERT INTO reclamos_sugerencias (usuario_id, categoria, texto, fecha, estado) VALUES (?, 'otro', 'Asunto de prueba:\nTexto del ticket pendiente', NOW(), 'pendiente')",
    [alumnoTicketId],
  );
  ticketPendienteId = (insTicket1 as { insertId: number }).insertId;

  const [insTicket2] = await pool.query(
    "INSERT INTO reclamos_sugerencias (usuario_id, categoria, texto, fecha, estado) VALUES (?, 'otro', 'Ticket ya resuelto', NOW(), 'resuelto')",
    [alumnoTicketId],
  );
  ticketResueltoId = (insTicket2 as { insertId: number }).insertId;
});

after(async () => {
  await pool.query("DELETE FROM reclamos_mensajes WHERE reclamo_id IN (?, ?)", [ticketPendienteId, ticketResueltoId]);
  await pool.query("DELETE FROM reclamos_sugerencias WHERE id IN (?, ?)", [ticketPendienteId, ticketResueltoId]);
  await pool.query("DELETE FROM sesiones_api WHERE session_id IN (?, ?)", [SESSION_ADMIN, SESSION_NO_ADMIN]);
  await pool.query("DELETE FROM alumnos WHERE id IN (?, ?)", [alumnoNoAdminId, alumnoTicketId]);
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

test("GET /api/admin/reclamos sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/reclamos`);
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("GET /api/admin/reclamos con sesión NO admin devuelve 403", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/reclamos`, { headers: { Cookie: `PHPSESSID=${SESSION_NO_ADMIN}` } });
    assert.equal(res.status, 403);
  } finally {
    await close();
  }
});

test("GET /api/admin/reclamos (default, activos) trae el ticket pendiente con hilo armado", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/reclamos`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.equal(body.estado, "activos");
    assert.ok(typeof body.contadores.activos === "number");
    const t = body.tickets.find((x: { id: number }) => x.id === ticketPendienteId);
    assert.ok(t, "debe incluir el ticket pendiente");
    assert.equal(t.chatThread.length, 1);
    assert.equal(t.chatThread[0].remitente, "usuario");
    assert.equal(t.urgente, false);
    assert.ok(!body.tickets.some((x: { id: number }) => x.id === ticketResueltoId), "el resuelto no debe salir en activos");
  } finally {
    await close();
  }
});

test("POST /api/admin/reclamos/:id/responder inserta mensaje admin y pasa a en_proceso", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/reclamos/${ticketPendienteId}/responder`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_ADMIN}` },
      body: JSON.stringify({ respuesta: "Respuesta de prueba" }),
    });
    assert.equal(res.status, 200);

    const [rows] = await pool.query("SELECT estado, respuesta_admin FROM reclamos_sugerencias WHERE id = ?", [ticketPendienteId]);
    const row = (rows as { estado: string; respuesta_admin: string }[])[0];
    assert.equal(row.estado, "en_proceso");
    assert.equal(row.respuesta_admin, "Respuesta de prueba");

    const [msgs] = await pool.query("SELECT remitente, mensaje FROM reclamos_mensajes WHERE reclamo_id = ?", [ticketPendienteId]);
    assert.equal((msgs as unknown[]).length, 1);
  } finally {
    await close();
  }
});

test("POST /api/admin/reclamos/:id/resolver cierra el ticket", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/reclamos/${ticketPendienteId}/resolver`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` },
    });
    assert.equal(res.status, 200);
    const [rows] = await pool.query("SELECT estado FROM reclamos_sugerencias WHERE id = ?", [ticketPendienteId]);
    assert.equal((rows as { estado: string }[])[0].estado, "resuelto");
  } finally {
    await close();
  }
});

test("papelera -> restaurar deja el ticket en 'pendiente'", async () => {
  const { url, close } = listen();
  try {
    const r1 = await fetch(`${url}/api/admin/reclamos/${ticketResueltoId}/papelera`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` },
    });
    assert.equal(r1.status, 200);
    const [rows1] = await pool.query("SELECT estado FROM reclamos_sugerencias WHERE id = ?", [ticketResueltoId]);
    assert.equal((rows1 as { estado: string }[])[0].estado, "eliminado");

    const r2 = await fetch(`${url}/api/admin/reclamos/${ticketResueltoId}/restaurar`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` },
    });
    assert.equal(r2.status, 200);
    const [rows2] = await pool.query("SELECT estado FROM reclamos_sugerencias WHERE id = ?", [ticketResueltoId]);
    assert.equal((rows2 as { estado: string }[])[0].estado, "pendiente");
  } finally {
    await close();
  }
});

test("DELETE /api/admin/reclamos/:id borra ticket y sus mensajes de la BD", async () => {
  const { url, close } = listen();
  try {
    const [ins] = await pool.query(
      "INSERT INTO reclamos_sugerencias (usuario_id, categoria, texto, fecha, estado) VALUES (?, 'otro', 'Ticket a borrar', NOW(), 'eliminado')",
      [alumnoTicketId],
    );
    const idBorrar = (ins as { insertId: number }).insertId;
    await pool.query("INSERT INTO reclamos_mensajes (reclamo_id, remitente, mensaje, fecha) VALUES (?, 'admin', 'msg', NOW())", [idBorrar]);

    const res = await fetch(`${url}/api/admin/reclamos/${idBorrar}`, { method: "DELETE", headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);

    const [rowsTicket] = await pool.query("SELECT id FROM reclamos_sugerencias WHERE id = ?", [idBorrar]);
    assert.equal((rowsTicket as unknown[]).length, 0);
    const [rowsMsg] = await pool.query("SELECT id FROM reclamos_mensajes WHERE reclamo_id = ?", [idBorrar]);
    assert.equal((rowsMsg as unknown[]).length, 0);
  } finally {
    await close();
  }
});

test("POST /api/admin/reclamos/bulk mueve varios tickets a papelera", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/reclamos/bulk`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_ADMIN}` },
      body: JSON.stringify({ ids: [ticketPendienteId, ticketResueltoId], accion: "papelera" }),
    });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.equal(body.afectados, 2);

    const [rows] = await pool.query("SELECT id, estado FROM reclamos_sugerencias WHERE id IN (?, ?)", [ticketPendienteId, ticketResueltoId]);
    for (const r of rows as { estado: string }[]) assert.equal(r.estado, "eliminado");

    // Devolver a un estado neutro para no afectar otros tests si se re-corren en la misma BD.
    await pool.query("UPDATE reclamos_sugerencias SET estado='pendiente' WHERE id IN (?, ?)", [ticketPendienteId, ticketResueltoId]);
  } finally {
    await close();
  }
});
