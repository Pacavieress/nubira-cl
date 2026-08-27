import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";

// Puerto del Grupo Mini Aula — Pieza 2 (27/08/2026), shell sin video: mini_aula.php +
// chat_mini_aula.php + entregas_servicio.php + endpoints _mini_aula. Fixtures sintéticos —
// necesita control total sobre reservas_slots (para las 4 ventanas de tiempo) y sobre el
// contenido de mensajes (DLP determinística).

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

const SESSION_COMPRADOR = "test-aula-session-comprador";
const SESSION_VENDEDOR = "test-aula-session-vendedor";
const SESSION_AJENO = "test-aula-session-ajeno";
const SESSION_ADMIN = "test-aula-session-admin";
const ADMIN_ID = 1; // "Soporte Nubira" — mismo fixture real que el resto de tests admin.
let compradorId: number;
let vendedorId: number;
let ajenoId: number;
let servicioId: number;

let contratoSinReservaId: number; // sin reservas_slots -> siempre "aula_activa" (ventana infinita)
let contratoPreClaseId: number;
let contratoActivaId: number;
let contratoPostClaseGraciaId: number;
let contratoPostClaseCerradaId: number;

before(async () => {
  const ts = Date.now();
  const crearAlumno = async (nombre: string) => {
    const [ins] = await pool.query("INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, bio) VALUES (?, ?, 'x', 1, 0, '')", [
      nombre,
      `test-aula-${nombre.toLowerCase()}-${ts}@example.invalid`,
    ]);
    return (ins as { insertId: number }).insertId;
  };
  compradorId = await crearAlumno("AulaComprador");
  vendedorId = await crearAlumno("AulaVendedor");
  ajenoId = await crearAlumno("AulaAjeno");

  await pool.query("INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)", [SESSION_COMPRADOR, compradorId]);
  await pool.query("INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)", [SESSION_VENDEDOR, vendedorId]);
  await pool.query("INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)", [SESSION_AJENO, ajenoId]);
  await pool.query("INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)", [SESSION_ADMIN, ADMIN_ID]);

  const [insServ] = await pool.query(
    "INSERT INTO servicios (alumno_id, titulo, descripcion, categoria, modalidad, estado, visible, precio, fecha_publicacion) VALUES (?, 'Servicio Test Aula', 'desc', 'Matemáticas', 'Online', 'aprobado', 1, 10000, NOW())",
    [vendedorId],
  );
  servicioId = (insServ as { insertId: number }).insertId;

  const crearContrato = async (estado: string) => {
    const [ins] = await pool.query(
      "INSERT INTO contratos (servicio_id, comprador_id, vendedor_id, monto, estado, fecha_creacion) VALUES (?, ?, ?, 10000, ?, NOW())",
      [servicioId, compradorId, vendedorId, estado],
    );
    return (ins as { insertId: number }).insertId;
  };

  contratoSinReservaId = await crearContrato("en_progreso");

  contratoPreClaseId = await crearContrato("en_progreso");
  await pool.query("INSERT INTO reservas_slots (contrato_id, servicio_id, tutor_id, alumno_id, fecha_clase, duracion_minutos, estado) VALUES (?, ?, ?, ?, NOW() + INTERVAL 2 HOUR, 60, 'reservado')", [
    contratoPreClaseId,
    servicioId,
    vendedorId,
    compradorId,
  ]);

  contratoActivaId = await crearContrato("en_progreso");
  await pool.query("INSERT INTO reservas_slots (contrato_id, servicio_id, tutor_id, alumno_id, fecha_clase, duracion_minutos, estado) VALUES (?, ?, ?, ?, NOW() - INTERVAL 10 MINUTE, 60, 'reservado')", [
    contratoActivaId,
    servicioId,
    vendedorId,
    compradorId,
  ]);

  // Terminó hace 30 min (60 min de duración + comenzó hace 90 min) -> dentro de la gracia fija de 60 min.
  contratoPostClaseGraciaId = await crearContrato("en_progreso");
  await pool.query("INSERT INTO reservas_slots (contrato_id, servicio_id, tutor_id, alumno_id, fecha_clase, duracion_minutos, estado) VALUES (?, ?, ?, ?, NOW() - INTERVAL 90 MINUTE, 60, 'reservado')", [
    contratoPostClaseGraciaId,
    servicioId,
    vendedorId,
    compradorId,
  ]);

  // Terminó hace 3 horas -> fuera de cualquier gracia.
  contratoPostClaseCerradaId = await crearContrato("en_progreso");
  await pool.query("INSERT INTO reservas_slots (contrato_id, servicio_id, tutor_id, alumno_id, fecha_clase, duracion_minutos, estado) VALUES (?, ?, ?, ?, NOW() - INTERVAL 4 HOUR, 60, 'reservado')", [
    contratoPostClaseCerradaId,
    servicioId,
    vendedorId,
    compradorId,
  ]);
});

after(async () => {
  await pool.query("DELETE FROM sesiones_api WHERE session_id IN (?, ?, ?, ?)", [SESSION_COMPRADOR, SESSION_VENDEDOR, SESSION_AJENO, SESSION_ADMIN]);
  const contratoIds = [contratoSinReservaId, contratoPreClaseId, contratoActivaId, contratoPostClaseGraciaId, contratoPostClaseCerradaId];
  await pool.query(`DELETE FROM chat_aula WHERE contrato_id IN (${contratoIds.map(() => "?").join(",")})`, contratoIds);
  await pool.query(`DELETE FROM chat_typing_aula WHERE contrato_id IN (${contratoIds.map(() => "?").join(",")})`, contratoIds).catch(() => {});
  await pool.query(`DELETE FROM dlp_intentos WHERE conversacion_id IN (${contratoIds.map(() => "?").join(",")})`, contratoIds);
  await pool.query(`DELETE FROM contrato_archivos WHERE contrato_id IN (${contratoIds.map(() => "?").join(",")})`, contratoIds);
  await pool.query(`DELETE FROM reservas_slots WHERE contrato_id IN (${contratoIds.map(() => "?").join(",")})`, contratoIds);
  await pool.query(`DELETE FROM contratos WHERE id IN (${contratoIds.map(() => "?").join(",")})`, contratoIds);
  await pool.query("DELETE FROM servicios WHERE id = ?", [servicioId]);
  await pool.query("DELETE FROM alumnos WHERE id IN (?, ?, ?)", [compradorId, vendedorId, ajenoId]);
  await pool.end();
});

// ============================================================================
// Detalle del aula — ventanas de tiempo
// ============================================================================

test("GET aula detalle sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/aula/${contratoSinReservaId}`);
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("GET aula detalle: usuario ajeno (no participante, no admin) recibe 404", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/aula/${contratoSinReservaId}`, { headers: { Cookie: `PHPSESSID=${SESSION_AJENO}` } });
    assert.equal(res.status, 404);
  } finally {
    await close();
  }
});

test("GET aula detalle: sin reserva (contrato libre) siempre está en aula_activa", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/aula/${contratoSinReservaId}`, { headers: { Cookie: `PHPSESSID=${SESSION_COMPRADOR}` } });
    const body = (await res.json()) as { esAulaActiva: boolean; videoHabilitado: boolean; tieneReserva: boolean };
    assert.equal(res.status, 200);
    assert.equal(body.tieneReserva, false);
    assert.equal(body.esAulaActiva, true);
    assert.equal(body.videoHabilitado, true);
  } finally {
    await close();
  }
});

test("GET aula detalle: clase en 2 horas -> pre_clase, video deshabilitado", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/aula/${contratoPreClaseId}`, { headers: { Cookie: `PHPSESSID=${SESSION_COMPRADOR}` } });
    const body = (await res.json()) as { esPreClase: boolean; esAulaActiva: boolean; videoHabilitado: boolean; fechaAmigable: string | null };
    assert.equal(res.status, 200);
    assert.equal(body.esPreClase, true);
    assert.equal(body.esAulaActiva, false);
    assert.equal(body.videoHabilitado, false);
    assert.ok(body.fechaAmigable);
  } finally {
    await close();
  }
});

test("GET aula detalle: clase empezó hace 10 min (60 min de duración) -> aula_activa", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/aula/${contratoActivaId}`, { headers: { Cookie: `PHPSESSID=${SESSION_VENDEDOR}` } });
    const body = (await res.json()) as { esAulaActiva: boolean; videoHabilitado: boolean };
    assert.equal(res.status, 200);
    assert.equal(body.esAulaActiva, true);
    assert.equal(body.videoHabilitado, true);
  } finally {
    await close();
  }
});

test("GET aula detalle: terminó hace 30 min -> post_clase pero dentro de la gracia fija (60 min), video sigue habilitado", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/aula/${contratoPostClaseGraciaId}`, { headers: { Cookie: `PHPSESSID=${SESSION_COMPRADOR}` } });
    const body = (await res.json()) as { esPostClase: boolean; videoHabilitado: boolean };
    assert.equal(res.status, 200);
    assert.equal(body.esPostClase, true);
    assert.equal(body.videoHabilitado, true);
  } finally {
    await close();
  }
});

test("GET aula detalle: terminó hace 3 horas -> post_clase, fuera de la gracia, video cerrado", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/aula/${contratoPostClaseCerradaId}`, { headers: { Cookie: `PHPSESSID=${SESSION_VENDEDOR}` } });
    const body = (await res.json()) as { esPostClase: boolean; videoHabilitado: boolean };
    assert.equal(res.status, 200);
    assert.equal(body.esPostClase, true);
    assert.equal(body.videoHabilitado, false);
  } finally {
    await close();
  }
});

test("GET aula detalle: admin entra en bypass total (siempre aula_activa) aunque no sea participante", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/aula/${contratoPreClaseId}`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    const body = (await res.json()) as { esAdmin: boolean; esAulaActiva: boolean; esPreClase: boolean; videoHabilitado: boolean };
    assert.equal(res.status, 200);
    assert.equal(body.esAdmin, true);
    assert.equal(body.esAulaActiva, true);
    assert.equal(body.esPreClase, false);
    assert.equal(body.videoHabilitado, true);
  } finally {
    await close();
  }
});

test("GET aula detalle: comprador puede finalizar cuando el contrato está en_progreso y no ha finalizado antes", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/aula/${contratoSinReservaId}`, { headers: { Cookie: `PHPSESSID=${SESSION_COMPRADOR}` } });
    const body = (await res.json()) as { compradorPuedeFinalizar: boolean; vendedorEsperandoAlumno: boolean };
    assert.equal(res.status, 200);
    assert.equal(body.compradorPuedeFinalizar, true);
  } finally {
    await close();
  }
});

// ============================================================================
// Chat del aula
// ============================================================================

test("GET mensajes aula: usuario ajeno recibe 403", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/aula/${contratoSinReservaId}/mensajes`, { headers: { Cookie: `PHPSESSID=${SESSION_AJENO}` } });
    assert.equal(res.status, 403);
  } finally {
    await close();
  }
});

test("POST enviar mensaje aula: mensaje normal se inserta en chat_aula", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/aula/${contratoSinReservaId}/mensajes`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_COMPRADOR}`, "Content-Type": "application/json" },
      body: JSON.stringify({ mensaje: "¿Puedes revisar el ejercicio 4?" }),
    });
    const body = (await res.json()) as { ok: boolean };
    assert.equal(res.status, 200);
    assert.equal(body.ok, true);

    const [rows] = await pool.query("SELECT COUNT(*) as n FROM chat_aula WHERE contrato_id = ? AND mensaje LIKE '%ejercicio 4%'", [contratoSinReservaId]);
    assert.equal((rows as { n: number }[])[0]!.n, 1);
  } finally {
    await close();
  }
});

test("POST enviar mensaje aula: DLP bloquea (redes sociales), NO inserta, deja auditoría", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/aula/${contratoSinReservaId}/mensajes`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_VENDEDOR}`, "Content-Type": "application/json" },
      body: JSON.stringify({ mensaje: "escríbeme por instagram mejor" }),
    });
    const body = (await res.json()) as { ok: boolean; error: string };
    assert.equal(res.status, 200);
    assert.equal(body.ok, false);
    assert.match(body.error, /red social/i);
  } finally {
    await close();
  }
});

test("POST enviar mensaje aula: teléfono fraccionado en varios mensajes SÍ se detecta (corrección real vs. el PHP, que no tenía esta regla acá)", async () => {
  const { url, close } = listen();
  try {
    await fetch(`${url}/api/me/aula/${contratoSinReservaId}/mensajes`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_COMPRADOR}`, "Content-Type": "application/json" },
      body: JSON.stringify({ mensaje: "mi numero es 9" }),
    });
    const res2 = await fetch(`${url}/api/me/aula/${contratoSinReservaId}/mensajes`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_COMPRADOR}`, "Content-Type": "application/json" },
      body: JSON.stringify({ mensaje: "8765 4321" }),
    });
    const body2 = (await res2.json()) as { ok: boolean; error?: string };
    assert.equal(res2.status, 200);
    assert.equal(body2.ok, false);
  } finally {
    await close();
  }
});

test("POST enviar mensaje aula: contrato cancelado bloquea el envío (aula cerrada)", async () => {
  const [ins] = await pool.query("INSERT INTO contratos (servicio_id, comprador_id, vendedor_id, monto, estado, fecha_creacion) VALUES (?, ?, ?, 10000, 'cancelado', NOW())", [
    servicioId,
    compradorId,
    vendedorId,
  ]);
  const contratoCanceladoId = (ins as { insertId: number }).insertId;
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/aula/${contratoCanceladoId}/mensajes`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_COMPRADOR}`, "Content-Type": "application/json" },
      body: JSON.stringify({ mensaje: "Hola" }),
    });
    const body = (await res.json()) as { ok: boolean; error: string };
    assert.equal(res.status, 200);
    assert.equal(body.ok, false);
    assert.match(body.error, /cerrada/i);
  } finally {
    await close();
    await pool.query("DELETE FROM contratos WHERE id = ?", [contratoCanceladoId]);
  }
});

test("POST enviar mensaje aula: cuenta suspendida no puede enviar (asimétrico, solo bloquea al remitente)", async () => {
  await pool.query("UPDATE alumnos SET bloqueado = 1 WHERE id = ?", [vendedorId]);
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/aula/${contratoSinReservaId}/mensajes`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_VENDEDOR}`, "Content-Type": "application/json" },
      body: JSON.stringify({ mensaje: "Hola desde una cuenta suspendida" }),
    });
    const body = (await res.json()) as { ok: boolean; error: string };
    assert.equal(res.status, 200);
    assert.equal(body.ok, false);
    assert.match(body.error, /suspendida/i);
  } finally {
    await pool.query("UPDATE alumnos SET bloqueado = 0 WHERE id = ?", [vendedorId]);
    await close();
  }
});

test("POST typing aula + GET mensajes: detecta 'el otro está escribiendo'", async () => {
  const { url, close } = listen();
  try {
    const resTyping = await fetch(`${url}/api/me/aula/${contratoSinReservaId}/typing`, { method: "POST", headers: { Cookie: `PHPSESSID=${SESSION_VENDEDOR}` } });
    assert.equal(resTyping.status, 200);

    const res = await fetch(`${url}/api/me/aula/${contratoSinReservaId}/mensajes`, { headers: { Cookie: `PHPSESSID=${SESSION_COMPRADOR}` } });
    const body = (await res.json()) as { otroEscribiendo: boolean };
    assert.equal(res.status, 200);
    assert.equal(body.otroEscribiendo, true);
  } finally {
    await close();
    await pool.query("DELETE FROM chat_typing_aula WHERE contrato_id = ?", [contratoSinReservaId]).catch(() => {});
  }
});

test("GET mensajes aula: el admin lee sin marcar como leído (solo observador)", async () => {
  await pool.query("INSERT INTO chat_aula (contrato_id, remitente_id, mensaje, fecha, visto) VALUES (?, ?, 'mensaje sin leer', NOW(), 0)", [
    contratoSinReservaId,
    compradorId,
  ]);
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/aula/${contratoSinReservaId}/mensajes`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);

    const [rows] = await pool.query("SELECT visto FROM chat_aula WHERE contrato_id = ? AND mensaje = 'mensaje sin leer'", [contratoSinReservaId]);
    assert.equal((rows as { visto: number }[])[0]!.visto, 0, "el admin no debe marcar mensajes como leídos");
  } finally {
    await close();
  }
});

// ============================================================================
// Estado combinado (badges)
// ============================================================================

test("GET estado aula: cuenta mensajes no leídos y archivos totales", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/aula/${contratoSinReservaId}/estado`, { headers: { Cookie: `PHPSESSID=${SESSION_VENDEDOR}` } });
    const body = (await res.json()) as { chatNoLeidos: number; totalArchivos: number };
    assert.equal(res.status, 200);
    assert.equal(typeof body.chatNoLeidos, "number");
    assert.equal(typeof body.totalArchivos, "number");
  } finally {
    await close();
  }
});

// ============================================================================
// Materiales — subida (multipart/form-data)
// ============================================================================

test("POST subir archivo: extensión no permitida es rechazada", async () => {
  const { url, close } = listen();
  try {
    const fd = new FormData();
    fd.append("archivo", new Blob([Buffer.from("contenido")]), "virus.exe");
    const res = await fetch(`${url}/api/me/aula/${contratoSinReservaId}/archivos`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_COMPRADOR}` },
      body: fd,
    });
    const body = (await res.json()) as { ok: boolean; error: string };
    assert.equal(res.status, 400);
    assert.equal(body.ok, false);
    assert.match(body.error, /no permitido/i);
  } finally {
    await close();
  }
});

test("POST subir archivo: contenido real que NO coincide con la extensión declarada es rechazado (sniffing por magic bytes)", async () => {
  const { url, close } = listen();
  try {
    const fd = new FormData();
    fd.append("archivo", new Blob([Buffer.from("esto no es un pdf real")]), "documento.pdf");
    const res = await fetch(`${url}/api/me/aula/${contratoSinReservaId}/archivos`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_COMPRADOR}` },
      body: fd,
    });
    const body = (await res.json()) as { ok: boolean; error: string };
    assert.equal(res.status, 400);
    assert.equal(body.ok, false);
    assert.match(body.error, /no coincide/i);
  } finally {
    await close();
  }
});

test("POST subir archivo: PDF real se acepta, se lista y se puede descargar", async () => {
  const { url, close } = listen();
  let archivoId: number | undefined;
  try {
    const pdfReal = Buffer.concat([Buffer.from("%PDF-1.4\n"), Buffer.from("contenido de prueba")]);
    const fd = new FormData();
    fd.append("archivo", new Blob([pdfReal], { type: "application/pdf" }), "apunte.pdf");
    const res = await fetch(`${url}/api/me/aula/${contratoSinReservaId}/archivos`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_COMPRADOR}` },
      body: fd,
    });
    const body = (await res.json()) as { ok: boolean; archivoId: number };
    assert.equal(res.status, 200);
    assert.equal(body.ok, true);
    archivoId = body.archivoId;

    const resLista = await fetch(`${url}/api/me/aula/${contratoSinReservaId}/archivos`, { headers: { Cookie: `PHPSESSID=${SESSION_VENDEDOR}` } });
    const listaBody = (await resLista.json()) as { archivos: { id: number; nombreOriginal: string }[] };
    assert.ok(listaBody.archivos.some((a) => a.id === archivoId && a.nombreOriginal === "apunte.pdf"));

    const resDescarga = await fetch(`${url}/api/me/aula/archivo/${archivoId}`, { headers: { Cookie: `PHPSESSID=${SESSION_VENDEDOR}` } });
    assert.equal(resDescarga.status, 200);
    assert.equal(resDescarga.headers.get("content-type"), "application/pdf");
    const contenido = await resDescarga.text();
    assert.match(contenido, /^%PDF/);
  } finally {
    await close();
    if (archivoId) {
      const fs = await import("node:fs/promises");
      const { env } = await import("../src/config/env.js");
      const path = await import("node:path");
      const [rows] = await pool.query("SELECT ruta_archivo FROM contrato_archivos WHERE id = ?", [archivoId]);
      const ruta = (rows as { ruta_archivo: string }[])[0]?.ruta_archivo;
      if (ruta) await fs.unlink(path.join(env.materialesAulaDir, ruta)).catch(() => {});
      await pool.query("DELETE FROM contrato_archivos WHERE id = ?", [archivoId]);
    }
  }
});

test("GET archivo aula: usuario ajeno no puede descargar aunque conozca el id", async () => {
  const [ins] = await pool.query(
    "INSERT INTO contrato_archivos (contrato_id, usuario_id, nombre_original, ruta_archivo, tipo_mime, peso_kb, fecha) VALUES (?, ?, 'secreto.pdf', 'no-existe.pdf', 'application/pdf', 10, NOW())",
    [contratoSinReservaId, compradorId],
  );
  const archivoId = (ins as { insertId: number }).insertId;
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/aula/archivo/${archivoId}`, { headers: { Cookie: `PHPSESSID=${SESSION_AJENO}` } });
    assert.equal(res.status, 403);
  } finally {
    await close();
    await pool.query("DELETE FROM contrato_archivos WHERE id = ?", [archivoId]);
  }
});
