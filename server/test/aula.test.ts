import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import crypto from "node:crypto";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";

// Puerto del Grupo Mini Aula — Pieza 2 (27/08/2026), shell sin video: mini_aula.php +
// chat_mini_aula.php + entregas_servicio.php + endpoints _mini_aula. Fase 3 (sala_presencia)
// y Fase 4 (video Daily.co + pizarra Excalidraw) después. Fixtures sintéticos — necesita
// control total sobre reservas_slots (para las 4 ventanas de tiempo) y sobre el contenido de
// mensajes (DLP determinística).

// Réplica independiente de la fórmula real (aula.repository.ts) para las aserciones de
// Fase 4 — si algún día alguien cambia el salt en un solo lugar sin querer, este test debe
// notarlo por divergencia, no por coincidencia con el mismo código que está probando.
function hashSeguridadSalaEsperado(contratoId: number): string {
  return crypto.createHash("md5").update(`${contratoId}nubira_secreto_2026`).digest("hex").slice(0, 8);
}
function salaVideoUrlEsperada(contratoId: number): string {
  const hash = hashSeguridadSalaEsperado(contratoId);
  return `https://nubira-cl.daily.co/aula-${contratoId}-${hash}`;
}
function pizarraUrlEsperada(contratoId: number): string {
  const hash = hashSeguridadSalaEsperado(contratoId);
  const roomId = crypto.createHash("md5").update(`nubira_pizarra_${contratoId}_${hash}`).digest("hex").slice(0, 20);
  const key = crypto.createHash("md5").update(`key_${contratoId}_${hash}`).digest("base64url");
  return `https://excalidraw.com/#room=${roomId},${key}`;
}

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
let contratoPostClaseVentanaActividadId: number; // fuera de la gracia fija (60min), dentro del tope duro (90min)

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

  // Empezó hace 135 min, duró 60 -> terminó hace 75 min: pasó la gracia fija (60 min) pero
  // sigue dentro del tope duro (90 min) — ventana exacta donde la extensión por actividad
  // (Fase 3) es la única que puede mantener el video habilitado.
  contratoPostClaseVentanaActividadId = await crearContrato("en_progreso");
  await pool.query("INSERT INTO reservas_slots (contrato_id, servicio_id, tutor_id, alumno_id, fecha_clase, duracion_minutos, estado) VALUES (?, ?, ?, ?, NOW() - INTERVAL 135 MINUTE, 60, 'reservado')", [
    contratoPostClaseVentanaActividadId,
    servicioId,
    vendedorId,
    compradorId,
  ]);
});

after(async () => {
  await pool.query("DELETE FROM sesiones_api WHERE session_id IN (?, ?, ?, ?)", [SESSION_COMPRADOR, SESSION_VENDEDOR, SESSION_AJENO, SESSION_ADMIN]);
  const contratoIds = [
    contratoSinReservaId,
    contratoPreClaseId,
    contratoActivaId,
    contratoPostClaseGraciaId,
    contratoPostClaseCerradaId,
    contratoPostClaseVentanaActividadId,
  ];
  await pool.query(`DELETE FROM chat_aula WHERE contrato_id IN (${contratoIds.map(() => "?").join(",")})`, contratoIds);
  await pool.query(`DELETE FROM chat_typing_aula WHERE contrato_id IN (${contratoIds.map(() => "?").join(",")})`, contratoIds).catch(() => {});
  await pool.query(`DELETE FROM sala_presencia WHERE contrato_id IN (${contratoIds.map(() => "?").join(",")})`, contratoIds).catch(() => {});
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

test("GET aula detalle: fuera de la gracia fija (60min), sin heartbeat en sala_presencia -> video sigue cerrado", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/aula/${contratoPostClaseVentanaActividadId}`, { headers: { Cookie: `PHPSESSID=${SESSION_VENDEDOR}` } });
    const body = (await res.json()) as { esPostClase: boolean; videoHabilitado: boolean };
    assert.equal(body.esPostClase, true);
    assert.equal(body.videoHabilitado, false);
  } finally {
    await close();
  }
});

test("GET aula detalle: fuera de la gracia fija pero con heartbeat reciente en sala_presencia -> video se reabre (extensión por actividad, Fase 3)", async () => {
  const { url, close } = listen();
  try {
    const resPing = await fetch(`${url}/api/me/aula/${contratoPostClaseVentanaActividadId}/presencia`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_VENDEDOR}` },
    });
    assert.equal(resPing.status, 200);

    const res = await fetch(`${url}/api/me/aula/${contratoPostClaseVentanaActividadId}`, { headers: { Cookie: `PHPSESSID=${SESSION_COMPRADOR}` } });
    const body = (await res.json()) as { esPostClase: boolean; videoHabilitado: boolean };
    assert.equal(body.esPostClase, true);
    assert.equal(body.videoHabilitado, true);
  } finally {
    await close();
    await pool.query("DELETE FROM sala_presencia WHERE contrato_id = ?", [contratoPostClaseVentanaActividadId]).catch(() => {});
  }
});

test("GET aula detalle: heartbeat reciente pero ya pasó el tope duro (90min) -> video sigue cerrado", async () => {
  const { url, close } = listen();
  try {
    const resPing = await fetch(`${url}/api/me/aula/${contratoPostClaseCerradaId}/presencia`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_VENDEDOR}` },
    });
    assert.equal(resPing.status, 200);

    const res = await fetch(`${url}/api/me/aula/${contratoPostClaseCerradaId}`, { headers: { Cookie: `PHPSESSID=${SESSION_COMPRADOR}` } });
    const body = (await res.json()) as { esPostClase: boolean; videoHabilitado: boolean };
    assert.equal(body.esPostClase, true);
    assert.equal(body.videoHabilitado, false);
  } finally {
    await close();
    await pool.query("DELETE FROM sala_presencia WHERE contrato_id = ?", [contratoPostClaseCerradaId]).catch(() => {});
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

test("GET aula detalle: pizarraUrl solo aparece para el vendedor real, ni para el comprador ni para el admin en bypass", async () => {
  const { url, close } = listen();
  try {
    const resVendedor = await fetch(`${url}/api/me/aula/${contratoSinReservaId}`, { headers: { Cookie: `PHPSESSID=${SESSION_VENDEDOR}` } });
    const bodyVendedor = (await resVendedor.json()) as { pizarraUrl: string | null };
    assert.equal(bodyVendedor.pizarraUrl, pizarraUrlEsperada(contratoSinReservaId));

    const resComprador = await fetch(`${url}/api/me/aula/${contratoSinReservaId}`, { headers: { Cookie: `PHPSESSID=${SESSION_COMPRADOR}` } });
    const bodyComprador = (await resComprador.json()) as { pizarraUrl: string | null };
    assert.equal(bodyComprador.pizarraUrl, null);

    const resAdmin = await fetch(`${url}/api/me/aula/${contratoSinReservaId}`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    const bodyAdmin = (await resAdmin.json()) as { pizarraUrl: string | null; esAdmin: boolean };
    assert.equal(bodyAdmin.esAdmin, true);
    assert.equal(bodyAdmin.pizarraUrl, null);
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

// ============================================================================
// Presencia en la sala (Fase 3 — sala_presencia)
// ============================================================================

test("POST presencia: usuario ajeno (no participante, no admin) recibe 403", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/aula/${contratoSinReservaId}/presencia`, { method: "POST", headers: { Cookie: `PHPSESSID=${SESSION_AJENO}` } });
    assert.equal(res.status, 403);
  } finally {
    await close();
  }
});

test("POST presencia sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/aula/${contratoSinReservaId}/presencia`, { method: "POST" });
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("POST presencia + GET presencia: el vendedor ve al comprador como activo; el comprador no se ve a sí mismo", async () => {
  const { url, close } = listen();
  try {
    const resPing = await fetch(`${url}/api/me/aula/${contratoSinReservaId}/presencia`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_COMPRADOR}` },
    });
    const bodyPing = (await resPing.json()) as { ok: boolean };
    assert.equal(bodyPing.ok, true);

    const resVendedor = await fetch(`${url}/api/me/aula/${contratoSinReservaId}/presencia`, { headers: { Cookie: `PHPSESSID=${SESSION_VENDEDOR}` } });
    const bodyVendedor = (await resVendedor.json()) as { activo: boolean; usuarioId: number | null };
    assert.equal(bodyVendedor.activo, true);
    assert.equal(bodyVendedor.usuarioId, compradorId);

    const resComprador = await fetch(`${url}/api/me/aula/${contratoSinReservaId}/presencia`, { headers: { Cookie: `PHPSESSID=${SESSION_COMPRADOR}` } });
    const bodyComprador = (await resComprador.json()) as { activo: boolean; usuarioId: number | null };
    assert.equal(bodyComprador.activo, false);
    assert.equal(bodyComprador.usuarioId, null);
  } finally {
    await close();
    await pool.query("DELETE FROM sala_presencia WHERE contrato_id = ?", [contratoSinReservaId]).catch(() => {});
  }
});

test("DELETE presencia (salir): borra la fila propia, el otro participante deja de verlo activo de inmediato", async () => {
  const { url, close } = listen();
  try {
    await fetch(`${url}/api/me/aula/${contratoSinReservaId}/presencia`, { method: "POST", headers: { Cookie: `PHPSESSID=${SESSION_COMPRADOR}` } });
    const resSalir = await fetch(`${url}/api/me/aula/${contratoSinReservaId}/presencia`, { method: "DELETE", headers: { Cookie: `PHPSESSID=${SESSION_COMPRADOR}` } });
    assert.equal(resSalir.status, 200);

    const res = await fetch(`${url}/api/me/aula/${contratoSinReservaId}/presencia`, { headers: { Cookie: `PHPSESSID=${SESSION_VENDEDOR}` } });
    const body = (await res.json()) as { activo: boolean };
    assert.equal(body.activo, false);
  } finally {
    await close();
    await pool.query("DELETE FROM sala_presencia WHERE contrato_id = ?", [contratoSinReservaId]).catch(() => {});
  }
});

test("GET presencia: un ping con más de 25s de antigüedad ya no cuenta como activo", async () => {
  const { url, close } = listen();
  try {
    await fetch(`${url}/api/me/aula/${contratoSinReservaId}/presencia`, { method: "POST", headers: { Cookie: `PHPSESSID=${SESSION_COMPRADOR}` } });
    await pool.query("UPDATE sala_presencia SET ultimo_ping = NOW() - INTERVAL 30 SECOND WHERE contrato_id = ? AND usuario_id = ?", [
      contratoSinReservaId,
      compradorId,
    ]);

    const res = await fetch(`${url}/api/me/aula/${contratoSinReservaId}/presencia`, { headers: { Cookie: `PHPSESSID=${SESSION_VENDEDOR}` } });
    const body = (await res.json()) as { activo: boolean };
    assert.equal(body.activo, false);
  } finally {
    await close();
    await pool.query("DELETE FROM sala_presencia WHERE contrato_id = ?", [contratoSinReservaId]).catch(() => {});
  }
});

test("GET presencia: el admin puede consultar sin ser parte del contrato (observador)", async () => {
  const { url, close } = listen();
  try {
    await fetch(`${url}/api/me/aula/${contratoSinReservaId}/presencia`, { method: "POST", headers: { Cookie: `PHPSESSID=${SESSION_VENDEDOR}` } });

    const res = await fetch(`${url}/api/me/aula/${contratoSinReservaId}/presencia`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = (await res.json()) as { activo: boolean; usuarioId: number | null };
    assert.equal(body.activo, true);
    assert.equal(body.usuarioId, vendedorId);
  } finally {
    await close();
    await pool.query("DELETE FROM sala_presencia WHERE contrato_id = ?", [contratoSinReservaId]).catch(() => {});
  }
});

// ============================================================================
// Video (Fase 4 — Daily.co, iframe prebuilt vanilla daily-js)
// ============================================================================

test("GET video sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/aula/${contratoSinReservaId}/video`);
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("GET video: usuario ajeno (no participante, no admin) recibe 403", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/aula/${contratoSinReservaId}/video`, { headers: { Cookie: `PHPSESSID=${SESSION_AJENO}` } });
    assert.equal(res.status, 403);
  } finally {
    await close();
  }
});

test("GET video: video deshabilitado (pre_clase) devuelve 409 sin URL", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/aula/${contratoPreClaseId}/video`, { headers: { Cookie: `PHPSESSID=${SESSION_COMPRADOR}` } });
    assert.equal(res.status, 409);
    const body = (await res.json()) as { ok: boolean; error: string };
    assert.equal(body.ok, false);
    assert.equal(body.error, "video_deshabilitado");
  } finally {
    await close();
  }
});

test("GET video: aula activa devuelve la URL determinística real (idéntica al PHP) y el nombre real del usuario", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/aula/${contratoSinReservaId}/video`, { headers: { Cookie: `PHPSESSID=${SESSION_COMPRADOR}` } });
    assert.equal(res.status, 200);
    const body = (await res.json()) as { ok: boolean; roomUrl: string; userName: string };
    assert.equal(body.ok, true);
    assert.equal(body.roomUrl, salaVideoUrlEsperada(contratoSinReservaId));
    assert.equal(body.userName, "AulaComprador");
  } finally {
    await close();
  }
});

test("GET video: el admin puede iniciar la sala aunque no sea participante (bypass total, igual que mini_aula.php)", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/aula/${contratoPreClaseId}/video`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = (await res.json()) as { ok: boolean; roomUrl: string };
    assert.equal(body.ok, true);
    assert.equal(body.roomUrl, salaVideoUrlEsperada(contratoPreClaseId));
  } finally {
    await close();
  }
});
