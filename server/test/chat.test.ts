import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";
import { verificarDlp } from "../src/lib/dlp.js";

// Puerto del Grupo Mensajes/Chat pre-contrato — Pieza 1 (26/08/2026): bandeja_entrada.php +
// eliminar_conversacion.php + chat_previo_contrato.php + iniciar_chat.php + enviar_mensaje.php
// + cargar_mensajes.php + typing_set.php + ver_archivo_chat.php. Fixtures sintéticos —
// necesita control total sobre oculto_comprador/oculto_vendedor, cuenta_express, y el
// contenido exacto de mensajes para probar DLP de forma determinística.

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

const SESSION_A = "test-chat-session-a";
const SESSION_B = "test-chat-session-b";
const SESSION_C = "test-chat-session-c";
let usuarioAId: number; // comprador en el chat principal
let usuarioBId: number; // vendedor en el chat principal
let usuarioCId: number; // ajeno, sin acceso
let servicioId: number;
let servicioAjenoId: number; // para el test de "propio servicio"
let chatPrincipalId: number;
let chatOcultoId: number; // oculto_comprador=1 para usuarioA
let chatVencidoId: number; // último mensaje hace 8 días, para el test de iniciarChat

before(async () => {
  const ts = Date.now();
  const crearAlumno = async (nombre: string, cuentaExpress = 0) => {
    const [ins] = await pool.query(
      "INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, bio, cuenta_express) VALUES (?, ?, 'x', 1, 0, '', ?)",
      [nombre, `test-chat-${nombre.toLowerCase()}-${ts}@example.invalid`, cuentaExpress],
    );
    return (ins as { insertId: number }).insertId;
  };

  usuarioAId = await crearAlumno("TestChatA");
  usuarioBId = await crearAlumno("TestChatB");
  usuarioCId = await crearAlumno("TestChatC");

  await pool.query("INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)", [SESSION_A, usuarioAId]);
  await pool.query("INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)", [SESSION_B, usuarioBId]);
  await pool.query("INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)", [SESSION_C, usuarioCId]);

  const [insServ] = await pool.query(
    "INSERT INTO servicios (alumno_id, titulo, descripcion, categoria, modalidad, estado, visible, precio, fecha_publicacion) VALUES (?, 'Servicio Test Chat', 'desc', 'Matemáticas', 'Online', 'aprobado', 1, 10000, NOW())",
    [usuarioBId],
  );
  servicioId = (insServ as { insertId: number }).insertId;

  const [insServAjeno] = await pool.query(
    "INSERT INTO servicios (alumno_id, titulo, descripcion, categoria, modalidad, estado, visible, precio, fecha_publicacion) VALUES (?, 'Servicio Propio A', 'desc', 'Matemáticas', 'Online', 'aprobado', 1, 5000, NOW())",
    [usuarioAId],
  );
  servicioAjenoId = (insServAjeno as { insertId: number }).insertId;

  const [insChat] = await pool.query(
    "INSERT INTO conversaciones (servicio_id, comprador_id, vendedor_id, creado_en, oculto_comprador, oculto_vendedor) VALUES (?, ?, ?, NOW(), 0, 0)",
    [servicioId, usuarioAId, usuarioBId],
  );
  chatPrincipalId = (insChat as { insertId: number }).insertId;

  const [insChatOculto] = await pool.query(
    "INSERT INTO conversaciones (servicio_id, comprador_id, vendedor_id, creado_en, oculto_comprador, oculto_vendedor) VALUES (?, ?, ?, NOW(), 1, 0)",
    [servicioId, usuarioAId, usuarioBId],
  );
  chatOcultoId = (insChatOculto as { insertId: number }).insertId;

  const [insChatVencido] = await pool.query(
    "INSERT INTO conversaciones (servicio_id, comprador_id, vendedor_id, creado_en) VALUES (?, ?, ?, NOW() - INTERVAL 10 DAY)",
    [servicioId, usuarioCId, usuarioBId],
  );
  chatVencidoId = (insChatVencido as { insertId: number }).insertId;
  await pool.query("INSERT INTO mensajes (conversacion_id, remitente_id, mensaje, enviado_en, leido) VALUES (?, ?, 'Hola hace tiempo', NOW() - INTERVAL 8 DAY, 1)", [
    chatVencidoId,
    usuarioCId,
  ]);
});

after(async () => {
  await pool.query("DELETE FROM sesiones_api WHERE session_id IN (?, ?, ?)", [SESSION_A, SESSION_B, SESSION_C]);
  const chatIds = [chatPrincipalId, chatOcultoId, chatVencidoId];
  await pool.query("DELETE FROM chat_typing WHERE conversacion_id IN (?, ?, ?)", chatIds);
  await pool.query("DELETE FROM dlp_intentos WHERE conversacion_id IN (?, ?, ?)", chatIds);
  await pool.query("DELETE FROM respuestas_tutor WHERE conversacion_id IN (?, ?, ?)", chatIds);
  await pool.query("DELETE FROM mensajes WHERE conversacion_id IN (?, ?, ?)", chatIds);
  // Cualquier conversación nueva que iniciarChat haya creado durante los tests (servicioId/servicioAjenoId).
  const [extra] = await pool.query("SELECT id FROM conversaciones WHERE servicio_id IN (?, ?)", [servicioId, servicioAjenoId]);
  const extraIds = (extra as { id: number }[]).map((r) => r.id);
  if (extraIds.length > 0) {
    await pool.query(`DELETE FROM mensajes WHERE conversacion_id IN (${extraIds.map(() => "?").join(",")})`, extraIds);
    await pool.query(`DELETE FROM conversaciones WHERE id IN (${extraIds.map(() => "?").join(",")})`, extraIds);
  }
  await pool.query("DELETE FROM servicios WHERE id IN (?, ?)", [servicioId, servicioAjenoId]);
  await pool.query("DELETE FROM alumnos WHERE id IN (?, ?, ?)", [usuarioAId, usuarioBId, usuarioCId]);
  await pool.end();
});

// ============================================================================
// DLP — unit tests puros, sin BD
// ============================================================================

test("DLP: detecta correo electrónico", () => {
  const r = verificarDlp("mi correo es juan@gmail.com");
  assert.equal(r.bloqueado, true);
  assert.equal(r.categoria, "email");
});

test("DLP: detecta teléfono con formato chileno", () => {
  const r = verificarDlp("llámame al +56912345678");
  assert.equal(r.bloqueado, true);
  assert.equal(r.categoria, "telefono");
});

test("DLP: detecta redes sociales (whatsapp con variantes)", () => {
  const r = verificarDlp("mejor hablemos por whatsapp");
  assert.equal(r.bloqueado, true);
  assert.equal(r.categoria, "redes");
});

test("DLP: 'celular' SIN contexto no bloquea (evita falso positivo de biología celular)", () => {
  const r = verificarDlp("necesito ayuda con la membrana celular y división celular");
  assert.equal(r.bloqueado, false);
});

test("DLP: 'celular' CON contexto ('mi celular') sí bloquea", () => {
  const r = verificarDlp("te paso mi celular para coordinar");
  assert.equal(r.bloqueado, true);
  assert.equal(r.categoria, "intencion_contacto");
});

test("DLP: 'juntémonos' solo NO bloquea (coordinación normal de horario)", () => {
  const r = verificarDlp("juntémonos el jueves a las 5");
  assert.equal(r.bloqueado, false);
});

test("DLP: 'juntémonos' + plataforma externa SÍ bloquea", () => {
  const r = verificarDlp("juntémonos por zoom mejor");
  assert.equal(r.bloqueado, true);
  assert.equal(r.categoria, "intencion_contacto");
});

test("DLP: teléfono fraccionado en mensajes previos + actual se detecta combinado", () => {
  const r = verificarDlp("2 34", ["mi numero es 9", "8765 43"]);
  assert.equal(r.bloqueado, true);
  assert.equal(r.categoria, "telefono");
});

test("DLP: mensaje normal de clase no bloquea nada", () => {
  const r = verificarDlp("Hola, necesito ayuda con cálculo integral para mi prueba del jueves");
  assert.equal(r.bloqueado, false);
});

// ============================================================================
// Bandeja (GET /api/me/chat/bandeja) + eliminar
// ============================================================================

test("GET bandeja sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/chat/bandeja`);
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("GET bandeja: incluye el chat visible, excluye el oculto para ese lado", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/chat/bandeja`, { headers: { Cookie: `PHPSESSID=${SESSION_A}` } });
    const body = (await res.json()) as { items: { id: number; tipo: string }[] };
    assert.equal(res.status, 200);
    const ids = body.items.filter((i) => i.tipo === "negociacion").map((i) => i.id);
    assert.ok(ids.includes(chatPrincipalId), "el chat visible debe aparecer");
    assert.ok(!ids.includes(chatOcultoId), "el chat oculto_comprador=1 para A no debe aparecer");
  } finally {
    await close();
  }
});

test("POST eliminar chats: oculta solo para el usuario que pide, no para el otro lado", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/chat/bandeja/eliminar`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_A}`, "Content-Type": "application/json" },
      body: JSON.stringify({ ids: [`negociacion_${chatPrincipalId}`] }),
    });
    const body = (await res.json()) as { success: boolean; eliminados: number };
    assert.equal(res.status, 200);
    assert.equal(body.success, true);
    assert.equal(body.eliminados, 1);

    const [rows] = await pool.query("SELECT oculto_comprador, oculto_vendedor FROM conversaciones WHERE id = ?", [chatPrincipalId]);
    const fila = (rows as { oculto_comprador: number; oculto_vendedor: number }[])[0]!;
    assert.equal(fila.oculto_comprador, 1, "oculto para A (comprador)");
    assert.equal(fila.oculto_vendedor, 0, "sigue visible para B (vendedor)");

    // Revertir para no afectar otros tests que usan chatPrincipalId.
    await pool.query("UPDATE conversaciones SET oculto_comprador = 0 WHERE id = ?", [chatPrincipalId]);
  } finally {
    await close();
  }
});

// ============================================================================
// Iniciar chat (POST /api/me/chat/iniciar)
// ============================================================================

test("POST iniciar chat: propio servicio devuelve error", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/chat/iniciar`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_A}`, "Content-Type": "application/json" },
      body: JSON.stringify({ servicioId: servicioAjenoId, mensajeInicial: "hola" }),
    });
    const body = (await res.json()) as { error: string };
    assert.equal(res.status, 400);
    assert.equal(body.error, "propio_servicio");
  } finally {
    await close();
  }
});

test("POST iniciar chat: reutiliza un chat existente reciente en vez de duplicarlo", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/chat/iniciar`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_A}`, "Content-Type": "application/json" },
      body: JSON.stringify({ servicioId, mensajeInicial: "" }),
    });
    const body = (await res.json()) as { ok: boolean; chatId: number };
    assert.equal(res.status, 200);
    assert.equal(body.chatId, chatPrincipalId, "debe reusar el chat existente, no crear uno nuevo");
  } finally {
    await close();
  }
});

test("POST iniciar chat: conversación vencida (8+ días sin actividad) crea una NUEVA en vez de reusar", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/chat/iniciar`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_C}`, "Content-Type": "application/json" },
      body: JSON.stringify({ servicioId, mensajeInicial: "" }),
    });
    const body = (await res.json()) as { ok: boolean; chatId: number };
    assert.equal(res.status, 200);
    assert.notEqual(body.chatId, chatVencidoId, "no debe reusar el chat de hace 10 días");
  } finally {
    await close();
  }
});

test("POST iniciar chat: el mensaje inicial pasa por DLP (corrección deliberada vs. el PHP real)", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/chat/iniciar`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_C}`, "Content-Type": "application/json" },
      body: JSON.stringify({ servicioId, mensajeInicial: "contáctame al +56911112222" }),
    });
    const body = (await res.json()) as { ok: boolean; chatId: number };
    assert.equal(res.status, 200);

    const [rows] = await pool.query("SELECT COUNT(*) as n FROM mensajes WHERE conversacion_id = ? AND mensaje LIKE '%56911112222%'", [body.chatId]);
    assert.equal((rows as { n: number }[])[0]!.n, 0, "el mensaje con teléfono NUNCA debe quedar insertado — DLP debe haberlo bloqueado");

    const [dlpRows] = await pool.query("SELECT COUNT(*) as n FROM dlp_intentos WHERE conversacion_id = ? AND remitente_id = ?", [body.chatId, usuarioCId]);
    assert.ok((dlpRows as { n: number }[])[0]!.n > 0, "debe haber quedado registrado el intento DLP");
  } finally {
    await close();
  }
});

// ============================================================================
// Detalle del chat (GET /api/me/chat/:id)
// ============================================================================

test("GET chat detalle: usuario ajeno (no participante) devuelve 404", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/chat/${chatPrincipalId}`, { headers: { Cookie: `PHPSESSID=${SESSION_C}` } });
    assert.equal(res.status, 404);
  } finally {
    await close();
  }
});

test("GET chat detalle: participante real ve los datos correctos y el nombre corto anonimizado", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/chat/${chatPrincipalId}`, { headers: { Cookie: `PHPSESSID=${SESSION_A}` } });
    const body = (await res.json()) as { esVendedor: boolean; otroNombre: string; servicioTitulo: string };
    assert.equal(res.status, 200);
    assert.equal(body.esVendedor, false);
    assert.equal(body.otroNombre, "Testchatb");
    assert.equal(body.servicioTitulo, "Servicio Test Chat");
  } finally {
    await close();
  }
});

test("GET chat detalle: marca como leídos los mensajes ajenos (side-effect real)", async () => {
  await pool.query("INSERT INTO mensajes (conversacion_id, remitente_id, mensaje, enviado_en, leido) VALUES (?, ?, 'Sin leer todavia', NOW(), 0)", [
    chatPrincipalId,
    usuarioBId,
  ]);
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/chat/${chatPrincipalId}`, { headers: { Cookie: `PHPSESSID=${SESSION_A}` } });
    assert.equal(res.status, 200);

    const [rows] = await pool.query("SELECT leido FROM mensajes WHERE conversacion_id = ? AND remitente_id = ? ORDER BY id DESC LIMIT 1", [
      chatPrincipalId,
      usuarioBId,
    ]);
    assert.equal((rows as { leido: number }[])[0]!.leido, 1);
  } finally {
    await close();
  }
});

test("GET chat detalle: servicio con oferta activa expone precioOferta y esOferta", async () => {
  const [ins] = await pool.query(
    "INSERT INTO servicios (alumno_id, titulo, descripcion, categoria, modalidad, estado, visible, precio, precio_oferta, cupos_oferta, is_subvencionado, fecha_publicacion) VALUES (?, 'Servicio Oferta Chat', 'desc', 'Matemáticas', 'Online', 'aprobado', 1, 20000, 12000, 3, 1, NOW())",
    [usuarioBId],
  );
  const servOfertaId = (ins as { insertId: number }).insertId;
  const [insChat] = await pool.query("INSERT INTO conversaciones (servicio_id, comprador_id, vendedor_id, creado_en) VALUES (?, ?, ?, NOW())", [
    servOfertaId,
    usuarioAId,
    usuarioBId,
  ]);
  const chatOfertaId = (insChat as { insertId: number }).insertId;

  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/chat/${chatOfertaId}`, { headers: { Cookie: `PHPSESSID=${SESSION_A}` } });
    const body = (await res.json()) as { servicio: { esOferta: boolean; precioOferta: number | null } };
    assert.equal(res.status, 200);
    assert.equal(body.servicio.esOferta, true);
    assert.equal(body.servicio.precioOferta, 12000);
  } finally {
    await close();
    await pool.query("DELETE FROM conversaciones WHERE id = ?", [chatOfertaId]);
    await pool.query("DELETE FROM servicios WHERE id = ?", [servOfertaId]);
  }
});

// ============================================================================
// Enviar mensaje (POST /api/me/chat/:id/mensajes)
// ============================================================================

test("POST enviar mensaje: usuario sin acceso al chat recibe sin_acceso", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/chat/${chatPrincipalId}/mensajes`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_C}`, "Content-Type": "application/json" },
      body: JSON.stringify({ mensaje: "hola" }),
    });
    const body = (await res.json()) as { ok: boolean; error: string };
    assert.equal(res.status, 200);
    assert.equal(body.ok, false);
    assert.match(body.error, /no autorizado/i);
  } finally {
    await close();
  }
});

test("POST enviar mensaje: mensaje normal se inserta y resucita la conversación (oculto_*=0)", async () => {
  await pool.query("UPDATE conversaciones SET oculto_comprador = 1, oculto_vendedor = 1 WHERE id = ?", [chatPrincipalId]);

  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/chat/${chatPrincipalId}/mensajes`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_A}`, "Content-Type": "application/json" },
      body: JSON.stringify({ mensaje: "Hola, ¿tienes disponibilidad esta semana?" }),
    });
    const body = (await res.json()) as { ok: boolean };
    assert.equal(res.status, 200);
    assert.equal(body.ok, true);

    const [rows] = await pool.query("SELECT oculto_comprador, oculto_vendedor FROM conversaciones WHERE id = ?", [chatPrincipalId]);
    const fila = (rows as { oculto_comprador: number; oculto_vendedor: number }[])[0]!;
    assert.equal(fila.oculto_comprador, 0);
    assert.equal(fila.oculto_vendedor, 0);
  } finally {
    await close();
  }
});

test("POST enviar mensaje: mensaje con DLP (correo) es bloqueado, NO se inserta, queda auditado", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/chat/${chatPrincipalId}/mensajes`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_A}`, "Content-Type": "application/json" },
      body: JSON.stringify({ mensaje: "escríbeme a pablo@ejemplo.com mejor" }),
    });
    const body = (await res.json()) as { ok: boolean; error: string };
    assert.equal(res.status, 200);
    assert.equal(body.ok, false);
    assert.match(body.error, /correo electrónico/i);

    const [rows] = await pool.query("SELECT COUNT(*) as n FROM mensajes WHERE conversacion_id = ? AND mensaje LIKE '%pablo@ejemplo.com%'", [chatPrincipalId]);
    assert.equal((rows as { n: number }[])[0]!.n, 0);
  } finally {
    await close();
  }
});

test("POST enviar mensaje: destinatario bloqueado (suspendido) impide el envío", async () => {
  await pool.query("UPDATE alumnos SET bloqueado = 1 WHERE id = ?", [usuarioBId]);
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/chat/${chatPrincipalId}/mensajes`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_A}`, "Content-Type": "application/json" },
      body: JSON.stringify({ mensaje: "Hola, ¿sigues disponible?" }),
    });
    const body = (await res.json()) as { ok: boolean; error: string };
    assert.equal(res.status, 200);
    assert.equal(body.ok, false);
    assert.match(body.error, /no está disponible/i);
  } finally {
    await pool.query("UPDATE alumnos SET bloqueado = 0 WHERE id = ?", [usuarioBId]);
    await close();
  }
});

test("POST enviar mensaje: el vendedor respondiendo al comprador registra tiempo de respuesta real", async () => {
  await pool.query("DELETE FROM respuestas_tutor WHERE conversacion_id = ?", [chatPrincipalId]);
  await pool.query("INSERT INTO mensajes (conversacion_id, remitente_id, mensaje, enviado_en, leido) VALUES (?, ?, 'Pregunta del comprador', NOW() - INTERVAL 10 MINUTE, 1)", [
    chatPrincipalId,
    usuarioAId,
  ]);

  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/chat/${chatPrincipalId}/mensajes`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_B}`, "Content-Type": "application/json" },
      body: JSON.stringify({ mensaje: "Sí, tengo disponibilidad el jueves" }),
    });
    const body = (await res.json()) as { ok: boolean };
    assert.equal(res.status, 200);
    assert.equal(body.ok, true);

    const [rows] = await pool.query("SELECT minutos_respuesta FROM respuestas_tutor WHERE conversacion_id = ? AND tutor_id = ?", [chatPrincipalId, usuarioBId]);
    const filas = rows as { minutos_respuesta: number }[];
    assert.equal(filas.length, 1);
    assert.ok(filas[0]!.minutos_respuesta >= 9 && filas[0]!.minutos_respuesta <= 11, "debe ser ~10 minutos");
  } finally {
    await close();
  }
});

test("POST enviar mensaje: cuenta express bloquea al 3er mensaje (requiere completar registro)", async () => {
  const [insExpress] = await pool.query(
    "INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, bio, cuenta_express) VALUES ('Test Express', ?, 'x', 1, 0, '', 1)",
    [`test-express-${Date.now()}@example.invalid`],
  );
  const expressId = (insExpress as { insertId: number }).insertId;
  const sessionExpress = "test-chat-session-express";
  await pool.query("INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)", [sessionExpress, expressId]);

  const [insChat] = await pool.query("INSERT INTO conversaciones (servicio_id, comprador_id, vendedor_id, creado_en) VALUES (?, ?, ?, NOW())", [
    servicioId,
    expressId,
    usuarioBId,
  ]);
  const chatExpressId = (insChat as { insertId: number }).insertId;

  const { url, close } = listen();
  try {
    const enviar = (texto: string) =>
      fetch(`${url}/api/me/chat/${chatExpressId}/mensajes`, {
        method: "POST",
        headers: { Cookie: `PHPSESSID=${sessionExpress}`, "Content-Type": "application/json" },
        body: JSON.stringify({ mensaje: texto }),
      });

    const r1 = await enviar("Primer mensaje");
    const b1 = (await r1.json()) as { ok: boolean; mostrarBannerExpress?: boolean };
    assert.equal(b1.ok, true);

    const r2 = await enviar("Segundo mensaje");
    const b2 = (await r2.json()) as { ok: boolean; mostrarBannerExpress?: boolean };
    assert.equal(b2.ok, true);
    assert.equal(b2.mostrarBannerExpress, true, "el 2do mensaje (índice de conteo 1) debe mostrar el banner sutil");

    const r3 = await enviar("Tercer mensaje, debería bloquearse");
    const b3 = (await r3.json()) as { ok: boolean; requiereCompletar?: boolean };
    assert.equal(b3.ok, false);
    assert.equal(b3.requiereCompletar, true);

    const [rows] = await pool.query("SELECT COUNT(*) as n FROM mensajes WHERE conversacion_id = ?", [chatExpressId]);
    assert.equal((rows as { n: number }[])[0]!.n, 2, "el 3er mensaje nunca debe haberse insertado");
  } finally {
    await close();
    await pool.query("DELETE FROM mensajes WHERE conversacion_id = ?", [chatExpressId]);
    await pool.query("DELETE FROM conversaciones WHERE id = ?", [chatExpressId]);
    await pool.query("DELETE FROM sesiones_api WHERE session_id = ?", [sessionExpress]);
    await pool.query("DELETE FROM alumnos WHERE id = ?", [expressId]);
  }
});

test("POST enviar mensaje: límite de 6 mensajes antes de contratar (sin contrato_id vinculado)", async () => {
  const [insChat] = await pool.query("INSERT INTO conversaciones (servicio_id, comprador_id, vendedor_id, creado_en) VALUES (?, ?, ?, NOW())", [
    servicioId,
    usuarioAId,
    usuarioBId,
  ]);
  const chatLimiteId = (insChat as { insertId: number }).insertId;
  for (let i = 0; i < 6; i++) {
    await pool.query("INSERT INTO mensajes (conversacion_id, remitente_id, mensaje, enviado_en, leido, visible) VALUES (?, ?, ?, NOW(), 1, 1)", [
      chatLimiteId,
      usuarioAId,
      `Mensaje relleno ${i}`,
    ]);
  }

  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/chat/${chatLimiteId}/mensajes`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_A}`, "Content-Type": "application/json" },
      body: JSON.stringify({ mensaje: "Un mensaje más, debería bloquearse" }),
    });
    const body = (await res.json()) as { ok: boolean; limiteAlcanzado?: boolean };
    assert.equal(res.status, 200);
    assert.equal(body.ok, false);
    assert.equal(body.limiteAlcanzado, true);
  } finally {
    await close();
    await pool.query("DELETE FROM mensajes WHERE conversacion_id = ?", [chatLimiteId]);
    await pool.query("DELETE FROM conversaciones WHERE id = ?", [chatLimiteId]);
  }
});

// ============================================================================
// Mensajes / polling (GET /api/me/chat/:id/mensajes) + typing
// ============================================================================

test("GET mensajes: usuario ajeno recibe 403", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/chat/${chatPrincipalId}/mensajes`, { headers: { Cookie: `PHPSESSID=${SESSION_C}` } });
    assert.equal(res.status, 403);
  } finally {
    await close();
  }
});

test("POST typing + GET mensajes: detecta 'el otro está escribiendo' dentro de la ventana de 4s", async () => {
  const { url, close } = listen();
  try {
    const resTyping = await fetch(`${url}/api/me/chat/${chatPrincipalId}/typing`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_B}` },
    });
    assert.equal(resTyping.status, 200);

    const res = await fetch(`${url}/api/me/chat/${chatPrincipalId}/mensajes`, { headers: { Cookie: `PHPSESSID=${SESSION_A}` } });
    const body = (await res.json()) as { otroEscribiendo: boolean };
    assert.equal(res.status, 200);
    assert.equal(body.otroEscribiendo, true, "A debe ver que B (el otro) está escribiendo");
  } finally {
    await close();
    await pool.query("DELETE FROM chat_typing WHERE conversacion_id = ?", [chatPrincipalId]);
  }
});

// ============================================================================
// Archivo de chat (GET /api/me/chat/archivo/:mensajeId) — solo lectura
// ============================================================================

test("GET archivo chat: mensaje sin archivo devuelve 403 (denegar genérico, sin dar pistas)", async () => {
  const [ins] = await pool.query("INSERT INTO mensajes (conversacion_id, remitente_id, mensaje, enviado_en, leido) VALUES (?, ?, 'sin archivo', NOW(), 1)", [
    chatPrincipalId,
    usuarioAId,
  ]);
  const mensajeId = (ins as { insertId: number }).insertId;
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/chat/archivo/${mensajeId}`, { headers: { Cookie: `PHPSESSID=${SESSION_A}` } });
    assert.equal(res.status, 403);
  } finally {
    await close();
    await pool.query("DELETE FROM mensajes WHERE id = ?", [mensajeId]);
  }
});

test("GET archivo chat: usuario ajeno a la conversación no puede acceder aunque el archivo exista", async () => {
  const [ins] = await pool.query(
    "INSERT INTO mensajes (conversacion_id, remitente_id, mensaje, archivo_nombre, archivo_ruta, archivo_tipo, archivo_peso, visible, enviado_en, leido) VALUES (?, ?, '', 'foto.jpg', ?, 'image/jpeg', 1000, 1, NOW(), 1)",
    [chatPrincipalId, usuarioAId, `${chatPrincipalId}/no-existe-en-disco.jpg`],
  );
  const mensajeId = (ins as { insertId: number }).insertId;
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/chat/archivo/${mensajeId}`, { headers: { Cookie: `PHPSESSID=${SESSION_C}` } });
    assert.equal(res.status, 403);
  } finally {
    await close();
    await pool.query("DELETE FROM mensajes WHERE id = ?", [mensajeId]);
  }
});
