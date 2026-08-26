import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";

// Puerto del Grupo de Contratación (26/08/2026) — contratar_servicio.php, crear_contrato.php,
// app/api/slots_disponibles.php, generar_slot_excepcion.php, pagar_slot_excepcion.php,
// finalizar_servicio.php, finalizar_servicio_tutor.php. Fixtures 100% sintéticos (alumnos/
// servicio/cupón desechables): esta
// pieza exige control total sobre horarios_json, duración, precio y usos de cupón para poder
// simular la condición de carrera real de forma determinística — datos orgánicos no lo
// garantizan (mismo criterio ya usado en miBilletera.test.ts/metricasDetalle.test.ts).

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

// Próxima ocurrencia de un día de semana (0=Domingo) a una hora dada, como
// 'YYYY-MM-DD HH:mm:ss' — construido con componentes explícitos (nunca parseando un ISO
// string), mismo criterio anti-ambigüedad de zona horaria que contratos.repository.ts.
function proximaFecha(diaSemana: number, hora: string, semanasAdelante = 1): string {
  const hoy = new Date();
  const cursor = new Date(hoy.getFullYear(), hoy.getMonth(), hoy.getDate());
  cursor.setDate(cursor.getDate() + ((diaSemana - cursor.getDay() + 7) % 7 || 7) + (semanasAdelante - 1) * 7);
  const y = cursor.getFullYear();
  const m = String(cursor.getMonth() + 1).padStart(2, "0");
  const d = String(cursor.getDate()).padStart(2, "0");
  return `${y}-${m}-${d} ${hora}:00`;
}

const SESSION_COMPRADOR = "test-contratos-session-comprador";
const SESSION_COMPRADOR2 = "test-contratos-session-comprador2";
const SESSION_VENDEDOR = "test-contratos-session-vendedor";
let compradorId: number;
let comprador2Id: number;
let vendedorId: number;
let servicioId: number; // 15000 CLP, Lunes 09:00-12:00, duracion 60min
let servicioOfertaId: number; // con oferta activa
let servicioSinHorarioId: number;
let cuponGlobalId: number; // 20%, usos_maximos=0 (ilimitado)
let cuponUnUsoId: number; // 50%, usos_maximos=1, usos_actuales=0 — para la carrera
let contratoParaFinalizar: number;
let contratoParaConfirmarCierre: number;
let contratoSinLiberar: number;
let conversacionId: number;

const FECHA_LUNES = proximaFecha(1, "10:00", 2); // Lunes en 2 semanas — evita colisión con reservas de otros tests

before(async () => {
  const ts = Date.now();
  const [insVend] = await pool.query("INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, bio) VALUES ('Test Vendedor Contrato', ?, 'x', 1, 0, '')", [
    `test-vend-contrato-${ts}@example.invalid`,
  ]);
  vendedorId = (insVend as { insertId: number }).insertId;

  const [insComp] = await pool.query("INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, bio) VALUES ('Test Comprador Contrato', ?, 'x', 1, 0, '')", [
    `test-comp-contrato-${ts}@example.invalid`,
  ]);
  compradorId = (insComp as { insertId: number }).insertId;

  const [insComp2] = await pool.query("INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, bio) VALUES ('Test Comprador2 Contrato', ?, 'x', 1, 0, '')", [
    `test-comp2-contrato-${ts}@example.invalid`,
  ]);
  comprador2Id = (insComp2 as { insertId: number }).insertId;

  await pool.query("INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)", [SESSION_COMPRADOR, compradorId]);
  await pool.query("INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)", [SESSION_COMPRADOR2, comprador2Id]);
  await pool.query("INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)", [SESSION_VENDEDOR, vendedorId]);

  const horariosLunes = JSON.stringify({
    Lunes: ["09:00 - 12:00"],
    Martes: [],
    Miércoles: [],
    Jueves: [],
    Viernes: [],
    Sábado: [],
    Domingo: [],
  });

  const [insServ] = await pool.query(
    "INSERT INTO servicios (alumno_id, titulo, descripcion, categoria, modalidad, estado, visible, precio, duracion_minutos, horarios_json, is_subvencionado, fecha_publicacion) VALUES (?, 'Servicio Test Contrato', 'desc', 'Matemáticas', 'Online', 'aprobado', 1, 15000, 60, ?, 0, NOW())",
    [vendedorId, horariosLunes],
  );
  servicioId = (insServ as { insertId: number }).insertId;

  const [insServOferta] = await pool.query(
    "INSERT INTO servicios (alumno_id, titulo, descripcion, categoria, modalidad, estado, visible, precio, precio_oferta, cupos_oferta, is_subvencionado, duracion_minutos, horarios_json, fecha_publicacion) VALUES (?, 'Servicio Test Oferta', 'desc', 'Matemáticas', 'Online', 'aprobado', 1, 20000, 12000, 5, 1, 60, ?, NOW())",
    [vendedorId, horariosLunes],
  );
  servicioOfertaId = (insServOferta as { insertId: number }).insertId;

  const [insServSinHorario] = await pool.query(
    "INSERT INTO servicios (alumno_id, titulo, descripcion, categoria, modalidad, estado, visible, precio, fecha_publicacion) VALUES (?, 'Servicio Sin Horario', 'desc', 'Matemáticas', 'Online', 'aprobado', 1, 5000, NOW())",
    [vendedorId],
  );
  servicioSinHorarioId = (insServSinHorario as { insertId: number }).insertId;

  const codigoGlobal = `TESTGLOBAL${ts}`;
  const [insCuponGlobal] = await pool.query(
    "INSERT INTO cupones (codigo, porcentaje_descuento, usos_maximos, usos_actuales, servicio_id) VALUES (?, 20, 0, 0, NULL)",
    [codigoGlobal],
  );
  cuponGlobalId = (insCuponGlobal as { insertId: number }).insertId;

  const codigoUnUso = `TESTUNUSO${ts}`;
  const [insCuponUnUso] = await pool.query(
    "INSERT INTO cupones (codigo, porcentaje_descuento, usos_maximos, usos_actuales, servicio_id) VALUES (?, 50, 1, 0, NULL)",
    [codigoUnUso],
  );
  cuponUnUsoId = (insCuponUnUso as { insertId: number }).insertId;

  // Contrato ya en_progreso, para probar finalizar_servicio.php (botón "Finalizar y Pagar"
  // del comprador).
  const [insContrato] = await pool.query(
    "INSERT INTO contratos (servicio_id, comprador_id, vendedor_id, monto, estado, fecha_creacion) VALUES (?, ?, ?, 15000, 'en_progreso', NOW())",
    [servicioId, compradorId, vendedorId],
  );
  contratoParaFinalizar = (insContrato as { insertId: number }).insertId;

  // Contrato con finalizado_comprador=1 ya puesto, para probar finalizar_servicio_tutor.php
  // (botón "Confirmar Cierre" del vendedor) directo, sin depender del test anterior.
  const [insContratoCierre] = await pool.query(
    "INSERT INTO contratos (servicio_id, comprador_id, vendedor_id, monto, estado, finalizado_comprador, fecha_creacion) VALUES (?, ?, ?, 15000, 'liberado', 1, NOW())",
    [servicioId, compradorId, vendedorId],
  );
  contratoParaConfirmarCierre = (insContratoCierre as { insertId: number }).insertId;

  // Contrato SIN finalizado_comprador, para probar el bloqueo "debe esperar al comprador".
  const [insContratoSinLiberar] = await pool.query(
    "INSERT INTO contratos (servicio_id, comprador_id, vendedor_id, monto, estado, fecha_creacion) VALUES (?, ?, ?, 15000, 'en_progreso', NOW())",
    [servicioId, compradorId, vendedorId],
  );
  contratoSinLiberar = (insContratoSinLiberar as { insertId: number }).insertId;

  // Conversación real, para probar generar_slot_excepcion / pagar_slot_excepcion.
  const [insConv] = await pool.query(
    "INSERT INTO conversaciones (servicio_id, comprador_id, vendedor_id, creado_en, estado) VALUES (?, ?, ?, NOW(), 'activa')",
    [servicioId, compradorId, vendedorId],
  );
  conversacionId = (insConv as { insertId: number }).insertId;

  (global as unknown as { __codigoGlobal: string; __codigoUnUso: string }).__codigoGlobal = codigoGlobal;
  (global as unknown as { __codigoGlobal: string; __codigoUnUso: string }).__codigoUnUso = codigoUnUso;
});

after(async () => {
  await pool.query("DELETE FROM sesiones_api WHERE session_id IN (?, ?, ?)", [SESSION_COMPRADOR, SESSION_COMPRADOR2, SESSION_VENDEDOR]);
  await pool.query("DELETE FROM mensajes WHERE conversacion_id = ? OR conversacion_id IN (SELECT id FROM conversaciones WHERE servicio_id IN (?, ?, ?))", [
    conversacionId,
    servicioId,
    servicioOfertaId,
    servicioSinHorarioId,
  ]);
  await pool.query("DELETE FROM reservas_slots WHERE servicio_id IN (?, ?, ?)", [servicioId, servicioOfertaId, servicioSinHorarioId]);
  await pool.query("DELETE FROM slots_excepcion WHERE servicio_id IN (?, ?, ?)", [servicioId, servicioOfertaId, servicioSinHorarioId]);
  await pool.query("DELETE FROM conversaciones WHERE servicio_id IN (?, ?, ?)", [servicioId, servicioOfertaId, servicioSinHorarioId]);
  await pool.query("DELETE FROM contratos WHERE servicio_id IN (?, ?, ?)", [servicioId, servicioOfertaId, servicioSinHorarioId]);
  await pool.query("DELETE FROM cupones WHERE id IN (?, ?)", [cuponGlobalId, cuponUnUsoId]);
  await pool.query("DELETE FROM servicios WHERE id IN (?, ?, ?)", [servicioId, servicioOfertaId, servicioSinHorarioId]);
  await pool.query("DELETE FROM alumnos WHERE id IN (?, ?, ?)", [vendedorId, compradorId, comprador2Id]);
  await pool.end();
});

// ============================================================================
// GET /api/me/contratos/checkout/:servicioId
// ============================================================================

test("GET checkout sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/contratos/checkout/${servicioId}`);
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("GET checkout: servicio propio devuelve 403", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/contratos/checkout/${servicioId}`, { headers: { Cookie: `PHPSESSID=${SESSION_VENDEDOR}` } });
    assert.equal(res.status, 403);
  } finally {
    await close();
  }
});

test("GET checkout: servicio real sin oferta devuelve montoInicial = precio", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/contratos/checkout/${servicioId}`, { headers: { Cookie: `PHPSESSID=${SESSION_COMPRADOR}` } });
    const body = (await res.json()) as { servicio: { montoInicial: number; esOferta: boolean; precioOriginal: number } };
    assert.equal(res.status, 200);
    assert.equal(body.servicio.montoInicial, 15000);
    assert.equal(body.servicio.esOferta, false);
  } finally {
    await close();
  }
});

test("GET checkout: servicio con oferta activa devuelve montoInicial = precio_oferta", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/contratos/checkout/${servicioOfertaId}`, { headers: { Cookie: `PHPSESSID=${SESSION_COMPRADOR}` } });
    const body = (await res.json()) as { servicio: { montoInicial: number; esOferta: boolean; precioOriginal: number } };
    assert.equal(res.status, 200);
    assert.equal(body.servicio.esOferta, true);
    assert.equal(body.servicio.montoInicial, 12000);
    assert.equal(body.servicio.precioOriginal, 20000);
  } finally {
    await close();
  }
});

test("GET checkout: cupón global válido aplica el descuento correcto", async () => {
  const codigo = (global as unknown as { __codigoGlobal: string }).__codigoGlobal;
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/contratos/checkout/${servicioId}?codigoBeca=${codigo}`, { headers: { Cookie: `PHPSESSID=${SESSION_COMPRADOR}` } });
    const body = (await res.json()) as { cupon: { ok: boolean; montoFinal: number } };
    assert.equal(res.status, 200);
    assert.equal(body.cupon.ok, true);
    assert.equal(body.cupon.montoFinal, 12000); // 15000 - 20%
  } finally {
    await close();
  }
});

test("GET checkout: cupón inexistente no bloquea la página, solo informa el error", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/contratos/checkout/${servicioId}?codigoBeca=NOEXISTE999`, { headers: { Cookie: `PHPSESSID=${SESSION_COMPRADOR}` } });
    const body = (await res.json()) as { cupon: { ok: boolean; error?: string } };
    assert.equal(res.status, 200);
    assert.equal(body.cupon.ok, false);
  } finally {
    await close();
  }
});

// ============================================================================
// GET /api/me/contratos/slots-disponibles
// ============================================================================

test("GET slots-disponibles: día con horarios real devuelve slots, incluido el buscado", async () => {
  const fecha = FECHA_LUNES.slice(0, 10);
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/contratos/slots-disponibles?servicioId=${servicioId}&fecha=${fecha}`, {
      headers: { Cookie: `PHPSESSID=${SESSION_COMPRADOR}` },
    });
    const body = (await res.json()) as { slots: { datetime: string; disponible: boolean }[] };
    assert.equal(res.status, 200);
    assert.ok(body.slots.length > 0, "debería haber slots generados dentro de 09:00-12:00");
    const elBuscado = body.slots.find((s) => s.datetime === FECHA_LUNES);
    assert.ok(elBuscado, "el slot de las 10:00 debe estar entre los generados");
    assert.equal(elBuscado!.disponible, true);
  } finally {
    await close();
  }
});

test("GET slots-disponibles: servicio sin horarios_json devuelve motivo sin_horarios", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/contratos/slots-disponibles?servicioId=${servicioSinHorarioId}&fecha=2027-01-04`, {
      headers: { Cookie: `PHPSESSID=${SESSION_COMPRADOR}` },
    });
    const body = (await res.json()) as { slots: unknown[]; motivo?: string };
    assert.equal(res.status, 200);
    assert.equal(body.slots.length, 0);
    assert.equal(body.motivo, "sin_horarios");
  } finally {
    await close();
  }
});

// ============================================================================
// POST /api/me/contratos (crear)
// ============================================================================

test("POST crear contrato: fecha con formato inválido devuelve 400", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/contratos`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_COMPRADOR}`, "Content-Type": "application/json" },
      body: JSON.stringify({ servicioId, vendedorId, fechaClase: "no-es-una-fecha", monto: 15000, precioOriginal: 15000 }),
    });
    const body = (await res.json()) as { error: string };
    assert.equal(res.status, 400);
    assert.equal(body.error, "fecha_invalida");
  } finally {
    await close();
  }
});

test("POST crear contrato: precio esperado menor al real devuelve precio_cambio", async () => {
  const fecha = proximaFecha(1, "11:00", 3);
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/contratos`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_COMPRADOR}`, "Content-Type": "application/json" },
      body: JSON.stringify({ servicioId, vendedorId, fechaClase: fecha, monto: 5000, precioOriginal: 15000 }),
    });
    const body = (await res.json()) as { error: string };
    assert.equal(res.status, 400);
    assert.equal(body.error, "precio_cambio");
  } finally {
    await close();
  }
});

test("POST crear contrato: happy path crea contrato pendiente_pago + reserva + conversación + mensaje", async () => {
  const fecha = proximaFecha(1, "09:00", 3);
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/contratos`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_COMPRADOR}`, "Content-Type": "application/json" },
      body: JSON.stringify({ servicioId, vendedorId, fechaClase: fecha, monto: 15000, precioOriginal: 15000, notas: "Hola, prueba" }),
    });
    const body = (await res.json()) as { ok: boolean; contratoId: number; montoFinal: number };
    assert.equal(res.status, 200);
    assert.equal(body.ok, true);
    assert.equal(body.montoFinal, 15000);

    const [contratoRows] = await pool.query("SELECT estado, monto FROM contratos WHERE id = ?", [body.contratoId]);
    const contrato = (contratoRows as { estado: string; monto: number }[])[0];
    assert.equal(contrato.estado, "pendiente_pago");
    assert.equal(contrato.monto, 15000);

    const [reservaRows] = await pool.query("SELECT id FROM reservas_slots WHERE contrato_id = ?", [body.contratoId]);
    assert.equal((reservaRows as unknown[]).length, 1, "debe haber creado la reserva del horario");

    const [mensajeRows] = await pool.query("SELECT mensaje FROM mensajes WHERE contrato_id = ?", [body.contratoId]);
    assert.equal((mensajeRows as { mensaje: string }[]).length, 1);
    assert.ok((mensajeRows as { mensaje: string }[])[0]!.mensaje.includes("Hola, prueba"));
  } finally {
    await close();
  }
});

test("POST crear contrato: CARRERA REAL — 2 compradores simultáneos por el mismo horario, solo 1 gana", async () => {
  const fecha = proximaFecha(1, "10:00", 4); // semana propia, sin colisión con otros tests
  const { url, close } = listen();
  try {
    const hacerRequest = (session: string) =>
      fetch(`${url}/api/me/contratos`, {
        method: "POST",
        headers: { Cookie: `PHPSESSID=${session}`, "Content-Type": "application/json" },
        body: JSON.stringify({ servicioId, vendedorId, fechaClase: fecha, monto: 15000, precioOriginal: 15000 }),
      });

    // Disparados EN PARALELO de verdad (sin await entre ellos) — es la única forma real de
    // probar que el FOR UPDATE de reservas_slots sirve; probarlos uno tras otro no
    // demostraría nada sobre la condición de carrera real.
    const [resA, resB] = await Promise.all([hacerRequest(SESSION_COMPRADOR), hacerRequest(SESSION_COMPRADOR2)]);
    const [bodyA, bodyB] = await Promise.all([resA.json(), resB.json()]) as [
      { ok?: boolean; contratoId?: number; error?: string },
      { ok?: boolean; contratoId?: number; error?: string },
    ];

    const resultados = [
      { res: resA, body: bodyA },
      { res: resB, body: bodyB },
    ];
    const ganadores = resultados.filter((r) => r.res.status === 200 && r.body.ok);
    const perdedores = resultados.filter((r) => r.res.status === 400 && r.body.error === "horario_ocupado");

    assert.equal(ganadores.length, 1, "exactamente UNO de los 2 requests simultáneos debe crear el contrato");
    assert.equal(perdedores.length, 1, "el otro debe recibir horario_ocupado, no un contrato duplicado");

    const [reservasEnEseHorario] = await pool.query(
      "SELECT COUNT(*) as n FROM reservas_slots WHERE tutor_id = ? AND fecha_clase = ? AND estado IN ('reservado','en_curso')",
      [vendedorId, fecha],
    );
    assert.equal((reservasEnEseHorario as { n: number }[])[0]!.n, 1, "solo debe existir UNA reserva real para ese horario exacto, pese a los 2 intentos simultáneos");
  } finally {
    await close();
  }
});

test("POST crear contrato: CARRERA REAL — 2 compradores simultáneos con un cupón de 1 solo uso, solo 1 lo consigue", async () => {
  const codigo = (global as unknown as { __codigoUnUso: string }).__codigoUnUso;
  const fechaA = proximaFecha(1, "09:30", 5);
  const fechaB = proximaFecha(1, "10:30", 5); // horario DISTINTO — lo único que compite acá es el cupón, no el slot
  const { url, close } = listen();
  try {
    const hacerRequest = (session: string, fecha: string) =>
      fetch(`${url}/api/me/contratos`, {
        method: "POST",
        headers: { Cookie: `PHPSESSID=${session}`, "Content-Type": "application/json" },
        body: JSON.stringify({ servicioId, vendedorId, fechaClase: fecha, monto: 7500, precioOriginal: 15000, codigoBeca: codigo }),
      });

    const [resA, resB] = await Promise.all([hacerRequest(SESSION_COMPRADOR, fechaA), hacerRequest(SESSION_COMPRADOR2, fechaB)]);
    const [bodyA, bodyB] = (await Promise.all([resA.json(), resB.json()])) as [
      { ok?: boolean; error?: string },
      { ok?: boolean; error?: string },
    ];

    const resultados = [
      { res: resA, body: bodyA },
      { res: resB, body: bodyB },
    ];
    const ganadores = resultados.filter((r) => r.res.status === 200 && r.body.ok);
    // El request que pierde la carrera del FOR UPDATE en `cupones` vuelve a leer la fila
    // recién liberada por el ganador (usos_actuales ya en 1) y cae en el chequeo de límite
    // de usos (crearContrato, ANTES de calcular el descuento) -> cupon_invalido, no
    // precio_cambio (ese error es para el caso "el precio cambió DESPUÉS de aplicar el
    // descuento", un caso distinto).
    const perdedores = resultados.filter((r) => r.res.status === 400 && r.body.error === "cupon_invalido");

    assert.equal(ganadores.length, 1, "exactamente UNO de los 2 debe conseguir el descuento del cupón de 1 solo uso");
    assert.equal(perdedores.length, 1, "el otro debe fallar (el cupón ya no está disponible para su transacción)");

    const [cuponRows] = await pool.query("SELECT usos_actuales FROM cupones WHERE id = ?", [cuponUnUsoId]);
    assert.equal((cuponRows as { usos_actuales: number }[])[0]!.usos_actuales, 1, "el cupón debe quedar consumido exactamente 1 vez, nunca 2");
  } finally {
    await close();
  }
});

// ============================================================================
// POST /api/me/contratos/:id/finalizar (comprador, "Finalizar y Pagar") +
// POST /api/me/contratos/:id/confirmar-cierre (vendedor, "Confirmar Cierre")
// — puerto de finalizar_servicio.php / finalizar_servicio_tutor.php, mecanismo real
// wireado desde mini_aula.php (ver nota de reemplazo en contratos.repository.ts).
// ============================================================================

test("POST finalizar (comprador): otro usuario (no comprador) recibe sin_permiso", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/contratos/${contratoParaFinalizar}/finalizar`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_VENDEDOR}` },
    });
    const body = (await res.json()) as { error: string };
    assert.equal(res.status, 400);
    assert.equal(body.error, "sin_permiso");
  } finally {
    await close();
  }
});

test("POST finalizar (comprador): el comprador real libera el pago en un solo paso (estado='liberado' + finalizado_comprador=1)", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/contratos/${contratoParaFinalizar}/finalizar`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_COMPRADOR}` },
    });
    const body = (await res.json()) as { ok: boolean };
    assert.equal(res.status, 200);
    assert.equal(body.ok, true);

    const [rows] = await pool.query("SELECT estado, finalizado_comprador FROM contratos WHERE id = ?", [contratoParaFinalizar]);
    const c = (rows as { estado: string; finalizado_comprador: number }[])[0]!;
    assert.equal(c.estado, "liberado");
    assert.equal(c.finalizado_comprador, 1);
  } finally {
    await close();
  }
});

test("POST confirmar-cierre (vendedor): si el comprador todavía no liberó, recibe debe_esperar_comprador", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/contratos/${contratoSinLiberar}/confirmar-cierre`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_VENDEDOR}` },
    });
    const body = (await res.json()) as { error: string };
    assert.equal(res.status, 400);
    assert.equal(body.error, "debe_esperar_comprador");
  } finally {
    await close();
  }
});

test("POST confirmar-cierre (vendedor): otro usuario (no vendedor) recibe sin_permiso", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/contratos/${contratoParaConfirmarCierre}/confirmar-cierre`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_COMPRADOR}` },
    });
    const body = (await res.json()) as { error: string };
    assert.equal(res.status, 400);
    assert.equal(body.error, "sin_permiso");
  } finally {
    await close();
  }
});

test("POST confirmar-cierre (vendedor): con finalizado_comprador=1 ya puesto, el vendedor real cierra (finalizado_vendedor=1)", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/contratos/${contratoParaConfirmarCierre}/confirmar-cierre`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_VENDEDOR}` },
    });
    const body = (await res.json()) as { ok: boolean };
    assert.equal(res.status, 200);
    assert.equal(body.ok, true);

    const [rows] = await pool.query("SELECT estado, finalizado_comprador, finalizado_vendedor FROM contratos WHERE id = ?", [contratoParaConfirmarCierre]);
    const c = (rows as { estado: string; finalizado_comprador: number; finalizado_vendedor: number }[])[0]!;
    assert.equal(c.estado, "liberado");
    assert.equal(c.finalizado_comprador, 1);
    assert.equal(c.finalizado_vendedor, 1);
  } finally {
    await close();
  }
});

// ============================================================================
// Slots de excepción (chat -> reserva -> pago)
// ============================================================================

let tokenExcepcion: string;

test("POST generar slot de excepción: solo el vendedor de la conversación puede generarlo", async () => {
  const fecha = proximaFecha(3, "10:00", 2); // Miércoles, un día sin horario publicado -> es justo el caso de uso real
  const { url, close } = listen();
  try {
    const resNoAutorizado = await fetch(`${url}/api/me/contratos/slots-excepcion`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_COMPRADOR}`, "Content-Type": "application/json" },
      body: JSON.stringify({ conversacionId, fecha: fecha.slice(0, 10), hora: "10:00" }),
    });
    const bodyNoAutorizado = (await resNoAutorizado.json()) as { error: string };
    assert.equal(resNoAutorizado.status, 400);
    assert.equal(bodyNoAutorizado.error, "no_autorizado");

    const res = await fetch(`${url}/api/me/contratos/slots-excepcion`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_VENDEDOR}`, "Content-Type": "application/json" },
      body: JSON.stringify({ conversacionId, fecha: fecha.slice(0, 10), hora: "10:00" }),
    });
    const body = (await res.json()) as { ok: boolean };
    assert.equal(res.status, 200);
    assert.equal(body.ok, true);

    const [rows] = await pool.query("SELECT token, monto FROM slots_excepcion WHERE conversacion_id = ? ORDER BY id DESC LIMIT 1", [conversacionId]);
    const fila = (rows as { token: string; monto: number }[])[0]!;
    tokenExcepcion = fila.token;
    assert.equal(fila.monto, 15000, "el monto debe salir del precio publicado del servicio, no de un monto negociado");
  } finally {
    await close();
  }
});

test("GET slot de excepción: otro usuario (no el alumno del slot) no tiene acceso", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/contratos/slots-excepcion/${tokenExcepcion}`, { headers: { Cookie: `PHPSESSID=${SESSION_VENDEDOR}` } });
    assert.equal(res.status, 403);
  } finally {
    await close();
  }
});

test("POST pagar slot de excepción: CARRERA REAL — 2 requests simultáneos sobre el mismo token, solo 1 contrato creado", async () => {
  const { url, close } = listen();
  try {
    const hacerRequest = () =>
      fetch(`${url}/api/me/contratos/slots-excepcion/${tokenExcepcion}/pagar`, {
        method: "POST",
        headers: { Cookie: `PHPSESSID=${SESSION_COMPRADOR}` },
      });

    const [resA, resB] = await Promise.all([hacerRequest(), hacerRequest()]);
    const [bodyA, bodyB] = (await Promise.all([resA.json(), resB.json()])) as [{ ok: boolean; contratoId: number }, { ok: boolean; contratoId: number }];

    assert.equal(resA.status, 200);
    assert.equal(resB.status, 200);
    assert.equal(bodyA.ok, true);
    assert.equal(bodyB.ok, true);
    assert.equal(bodyA.contratoId, bodyB.contratoId, "ambos requests deben converger en el MISMO contrato — nunca 2 contratos para 1 sola reserva");

    const [contratoRows] = await pool.query("SELECT COUNT(*) as n FROM contratos WHERE id = ?", [bodyA.contratoId]);
    assert.equal((contratoRows as { n: number }[])[0]!.n, 1);

    const [slotRows] = await pool.query("SELECT contrato_id FROM slots_excepcion WHERE token = ?", [tokenExcepcion]);
    assert.equal((slotRows as { contrato_id: number }[])[0]!.contrato_id, bodyA.contratoId);
  } finally {
    await close();
  }
});
