import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";

const SESSION_VALID = "test-soporte-session";
const USUARIO_ID = 888888900; // sintético — reclamos_sugerencias.usuario_id sin FK real (verificado con INSERT de prueba abajo)
const OTRO_USUARIO_ID = 888888901; // sintético, "atacante" para el test de scoping por dueño

const idsTicketsCreados: number[] = [];
const idsMensajesCreados: number[] = [];

before(async () => {
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_VALID, USUARIO_ID],
  );
});

after(async () => {
  await pool.query("DELETE FROM sesiones_api WHERE session_id = ?", [SESSION_VALID]);
  if (idsMensajesCreados.length > 0) {
    await pool.query(`DELETE FROM reclamos_mensajes WHERE id IN (${idsMensajesCreados.map(() => "?").join(",")})`, idsMensajesCreados);
  }
  if (idsTicketsCreados.length > 0) {
    await pool.query(`DELETE FROM reclamos_sugerencias WHERE id IN (${idsTicketsCreados.map(() => "?").join(",")})`, idsTicketsCreados);
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

async function crearTicketDirecto(categoria = "tecnico"): Promise<number> {
  const [ins] = await pool.query(
    "INSERT INTO reclamos_sugerencias (usuario_id, texto, categoria, fecha, estado, revisado_usuario) VALUES (?, 'MI PROBLEMA:\\nNo puedo pagar', ?, NOW(), 'pendiente', 1)",
    [USUARIO_ID, categoria],
  );
  const id = (ins as { insertId: number }).insertId;
  idsTicketsCreados.push(id);
  return id;
}

test("GET /api/me/soporte sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/soporte`);
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("POST /api/me/soporte crea un ticket con formato ASUNTO:\\nmensaje, categoría inválida cae a 'otro'", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/soporte`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_VALID}` },
      body: JSON.stringify({ asunto: "No puedo pagar", mensaje: "El pago falla siempre", categoria: "categoria-inventada" }),
    });
    assert.equal(res.status, 201);
    const body = (await res.json()) as { id: number };
    idsTicketsCreados.push(body.id);

    const [rows] = await pool.query<import("mysql2").RowDataPacket[]>("SELECT texto, categoria, estado FROM reclamos_sugerencias WHERE id = ?", [body.id]);
    assert.equal(rows[0]!.texto, "NO PUEDO PAGAR:\nEl pago falla siempre");
    assert.equal(rows[0]!.categoria, "otro");
    assert.equal(rows[0]!.estado, "pendiente");
  } finally {
    await close();
  }
});

test("POST /api/me/soporte sin asunto/mensaje devuelve 400", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/soporte`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_VALID}` },
      body: JSON.stringify({ asunto: "", mensaje: "" }),
    });
    assert.equal(res.status, 400);
  } finally {
    await close();
  }
});

test("GET /api/me/soporte: el asunto se extrae correctamente y el primer mensaje del hilo NO trae el prefijo ASUNTO:\\n", async () => {
  const ticketId = await crearTicketDirecto();

  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/soporte`, { headers: { Cookie: `PHPSESSID=${SESSION_VALID}` } });
    assert.equal(res.status, 200);
    const body = (await res.json()) as {
      tickets: { id: number; asunto: string; hilo: { remitente: string; mensaje: string }[] }[];
    };
    const ticket = body.tickets.find((t) => t.id === ticketId);
    assert.ok(ticket, "el ticket debe aparecer en el historial");
    assert.equal(ticket!.asunto, "Mi problema");
    assert.equal(ticket!.hilo.length, 1);
    assert.equal(ticket!.hilo[0]!.mensaje, "No puedo pagar");
    assert.equal(ticket!.hilo[0]!.remitente, "usuario");
  } finally {
    await close();
  }
});

test("POST /api/me/soporte/:id/responder: otro usuario NO puede responder un ticket ajeno (404)", async () => {
  const ticketId = await crearTicketDirecto();
  const sessionAtacante = "test-soporte-atacante";
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [sessionAtacante, OTRO_USUARIO_ID],
  );

  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/soporte/${ticketId}/responder`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${sessionAtacante}` },
      body: JSON.stringify({ mensaje: "Intento ajeno" }),
    });
    assert.equal(res.status, 404);
  } finally {
    await close();
    await pool.query("DELETE FROM sesiones_api WHERE session_id = ?", [sessionAtacante]);
  }
});

test("POST /api/me/soporte/:id/responder (dueño real): agrega mensaje, reabre a 'pendiente', hilo queda ordenado por fecha", async () => {
  const ticketId = await crearTicketDirecto();
  // Simula que un admin ya respondió vía reclamos_mensajes, y que el ticket estaba 'en_proceso'.
  await pool.query("UPDATE reclamos_sugerencias SET estado = 'en_proceso' WHERE id = ?", [ticketId]);
  const [insAdmin] = await pool.query(
    "INSERT INTO reclamos_mensajes (reclamo_id, remitente, mensaje, fecha) VALUES (?, 'admin', 'Revisando tu caso', NOW())",
    [ticketId],
  );
  idsMensajesCreados.push((insAdmin as { insertId: number }).insertId);

  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/soporte/${ticketId}/responder`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_VALID}` },
      body: JSON.stringify({ mensaje: "Gracias, quedo atento" }),
    });
    assert.equal(res.status, 204);

    const getRes = await fetch(`${url}/api/me/soporte`, { headers: { Cookie: `PHPSESSID=${SESSION_VALID}` } });
    const body = (await getRes.json()) as { tickets: { id: number; estado: string; hilo: { remitente: string; mensaje: string }[] }[] };
    const ticket = body.tickets.find((t) => t.id === ticketId)!;
    assert.equal(ticket.estado, "pendiente", "responder debe reabrir el ticket a pendiente");
    assert.equal(ticket.hilo.length, 3);
    assert.deepEqual(
      ticket.hilo.map((h) => h.remitente),
      ["usuario", "admin", "usuario"],
    );
    assert.equal(ticket.hilo[2]!.mensaje, "Gracias, quedo atento");

    // Ese mismo reclamos_mensajes se creó dentro del endpoint — lo agregamos para limpieza.
    const [rows] = await pool.query<import("mysql2").RowDataPacket[]>(
      "SELECT id FROM reclamos_mensajes WHERE reclamo_id = ? AND remitente = 'usuario'",
      [ticketId],
    );
    for (const r of rows as { id: number }[]) idsMensajesCreados.push(r.id);
  } finally {
    await close();
  }
});

test("GET /api/me/soporte: respuesta_admin duplicada en reclamos_mensajes NO aparece 2 veces en el hilo", async () => {
  const ticketId = await crearTicketDirecto();
  await pool.query("UPDATE reclamos_sugerencias SET respuesta_admin = 'Ya lo revisamos' WHERE id = ?", [ticketId]);
  const [insDup] = await pool.query(
    "INSERT INTO reclamos_mensajes (reclamo_id, remitente, mensaje, fecha) VALUES (?, 'admin', 'Ya lo revisamos', NOW())",
    [ticketId],
  );
  idsMensajesCreados.push((insDup as { insertId: number }).insertId);

  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/soporte`, { headers: { Cookie: `PHPSESSID=${SESSION_VALID}` } });
    const body = (await res.json()) as { tickets: { id: number; hilo: { mensaje: string }[] }[] };
    const ticket = body.tickets.find((t) => t.id === ticketId)!;
    const respuestasAdmin = ticket.hilo.filter((h) => h.mensaje === "Ya lo revisamos");
    assert.equal(respuestasAdmin.length, 1, "la respuesta_admin duplicada en reclamos_mensajes no debe duplicarse en el hilo");
  } finally {
    await close();
  }
});

test("POST /api/me/soporte/:id/resolver marca estado='resuelto'; otro usuario no puede resolver un ticket ajeno", async () => {
  const ticketId = await crearTicketDirecto();
  const sessionAtacante = "test-soporte-atacante-resolver";
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [sessionAtacante, OTRO_USUARIO_ID],
  );

  const { url, close } = listen();
  try {
    const resAtacante = await fetch(`${url}/api/me/soporte/${ticketId}/resolver`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${sessionAtacante}` },
    });
    assert.equal(resAtacante.status, 404);

    const resDueno = await fetch(`${url}/api/me/soporte/${ticketId}/resolver`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_VALID}` },
    });
    assert.equal(resDueno.status, 204);

    const [rows] = await pool.query<import("mysql2").RowDataPacket[]>("SELECT estado FROM reclamos_sugerencias WHERE id = ?", [ticketId]);
    assert.equal(rows[0]!.estado, "resuelto");
  } finally {
    await close();
    await pool.query("DELETE FROM sesiones_api WHERE session_id = ?", [sessionAtacante]);
  }
});

test("POST /api/me/soporte/:id/leido marca revisado_usuario=1", async () => {
  const ticketId = await crearTicketDirecto();
  await pool.query("UPDATE reclamos_sugerencias SET revisado_usuario = 0 WHERE id = ?", [ticketId]);

  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/soporte/${ticketId}/leido`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_VALID}` },
    });
    assert.equal(res.status, 204);

    const [rows] = await pool.query<import("mysql2").RowDataPacket[]>("SELECT revisado_usuario FROM reclamos_sugerencias WHERE id = ?", [ticketId]);
    assert.equal(rows[0]!.revisado_usuario, 1);
  } finally {
    await close();
  }
});

test("POST /api/me/soporte/eliminar (soft delete, bulk): oculta los tickets del historial sin borrar la fila", async () => {
  const idA = await crearTicketDirecto();
  const idB = await crearTicketDirecto();

  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/soporte/eliminar`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_VALID}` },
      body: JSON.stringify({ ids: [idA, idB] }),
    });
    assert.equal(res.status, 200);
    const body = (await res.json()) as { afectados: number };
    assert.equal(body.afectados, 2);

    const [rows] = await pool.query<import("mysql2").RowDataPacket[]>(
      `SELECT id, estado FROM reclamos_sugerencias WHERE id IN (?, ?)`,
      [idA, idB],
    );
    assert.ok((rows as { estado: string }[]).every((r) => r.estado === "eliminado"), "soft delete: la fila sigue existiendo con estado='eliminado'");

    const getRes = await fetch(`${url}/api/me/soporte`, { headers: { Cookie: `PHPSESSID=${SESSION_VALID}` } });
    const getBody = (await getRes.json()) as { tickets: { id: number }[] };
    assert.ok(!getBody.tickets.some((t) => t.id === idA || t.id === idB), "tickets eliminados no deben aparecer en el historial");
  } finally {
    await close();
  }
});
