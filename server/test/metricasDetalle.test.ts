import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";
import { buildFunnel, buildVisitasPorDia, computeDeltaPct, computeDeltaPts, parseOrigen } from "../src/modules/metricas/metricas.mapper.js";

// Puerto de app/metricas_detalle.php (582 líneas) — página de detalle por publicación,
// completando /metricas/:tipo/:id. Fixture 100% propio (no reutiliza los publicaciones
// reales con más vistas_detalle que se usaron para exploración manual, ej. servicio 8930):
// necesito control total sobre quién chateó/contrató/compró para probar el funnel de
// forma determinística, algo que datos reales orgánicos no garantizan.
const SESSION_VALID = "test-metricas-detalle-session";
const SESSION_OTRO = "test-metricas-detalle-session-otro";
const ALUMNO_ID = 1; // "Soporte Nubira" — mismo criterio que metricas.test.ts (FK real hacia alumnos.id)
const OTRO_ALUMNO_ID = 59; // alumno real distinto, para probar que no puede ver métricas ajenas

// vistas_detalle.user_id / conversaciones.comprador_id / contratos.comprador_id /
// ventas_apuntes.comprador_id confirmados SIN FK real (information_schema.KEY_COLUMN_USAGE
// vacío para las 4 tablas) — enteros sintéticos fuera de rango son seguros acá.
const USER_CHATEO = 900101;
const USER_CONTRATO = 900102;
const USER_COMPRADOR_APUNTE = 900201;

let servicioId: number;
let apunteId: number;

before(async () => {
  await pool.query("INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)", [
    SESSION_VALID,
    ALUMNO_ID,
  ]);
  await pool.query("INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)", [
    SESSION_OTRO,
    OTRO_ALUMNO_ID,
  ]);

  const [insServicio] = await pool.query(
    "INSERT INTO servicios (alumno_id, titulo, descripcion, estado, modalidad, visible, precio, fecha_publicacion) VALUES (?, 'Servicio Metricas Detalle Test', 'desc', 'aprobado', 'Online', 1, 15000, NOW())",
    [ALUMNO_ID],
  );
  servicioId = (insServicio as { insertId: number }).insertId;

  const [insApunte] = await pool.query(
    "INSERT INTO apuntes (id_alumno, titulo, publico, estado, visible, precio, fecha_subida) VALUES (?, 'Apunte Metricas Detalle Test', 1, 'aprobado', 1, 5000, NOW())",
    [ALUMNO_ID],
  );
  apunteId = (insApunte as { insertId: number }).insertId;

  // Servicio: 2 vistas identificadas HOY (una leyó completo, la otra no; una móvil con
  // origen google, la otra desktop directo) + 1 vista anónima hace 45 días (período
  // anterior, para el delta). visitasTotal histórico = 3.
  await pool.query(
    `INSERT INTO vistas_detalle (tipo, publicacion_id, user_id, session_id, fecha_inicio, tiempo_segundos, leyo_completo, dispositivo, origen, pais, ciudad) VALUES
     ('servicio', ?, ?, 'sess-chateo', NOW(), 40, 1, 'movil', 'https://www.google.com/search?q=tutor', 'Chile', 'Santiago'),
     ('servicio', ?, ?, 'sess-contrato', NOW(), 20, 0, 'desktop', NULL, 'Chile', 'Santiago'),
     ('servicio', ?, 0, 'sess-previo', DATE_SUB(NOW(), INTERVAL 45 DAY), 10, 0, 'movil', NULL, NULL, NULL)`,
    [servicioId, USER_CHATEO, servicioId, USER_CONTRATO, servicioId],
  );
  // USER_CHATEO inició un chat sobre este servicio (cuenta para "Iniciaron chat").
  await pool.query("INSERT INTO conversaciones (servicio_id, comprador_id, vendedor_id) VALUES (?, ?, ?)", [
    servicioId,
    USER_CHATEO,
    ALUMNO_ID,
  ]);
  // USER_CONTRATO contrató (cuenta para "Contrataron") — nunca chateó, para que ambas
  // etapas del funnel tengan un valor distinto y verificable por separado.
  await pool.query("INSERT INTO contratos (servicio_id, comprador_id, vendedor_id, monto, estado) VALUES (?, ?, ?, 15000, 'pendiente')", [
    servicioId,
    USER_CONTRATO,
    ALUMNO_ID,
  ]);

  // Apunte: 1 vista identificada hoy, que además compró.
  await pool.query(
    "INSERT INTO vistas_detalle (tipo, publicacion_id, user_id, session_id, fecha_inicio) VALUES ('apunte', ?, ?, 'sess-compra', NOW())",
    [apunteId, USER_COMPRADOR_APUNTE],
  );
  await pool.query("INSERT INTO ventas_apuntes (apunte_id, comprador_id, vendedor_id, precio) VALUES (?, ?, ?, 5000)", [
    apunteId,
    USER_COMPRADOR_APUNTE,
    ALUMNO_ID,
  ]);
});

after(async () => {
  await pool.query("DELETE FROM sesiones_api WHERE session_id IN (?, ?)", [SESSION_VALID, SESSION_OTRO]);
  await pool.query("DELETE FROM vistas_detalle WHERE publicacion_id = ? AND tipo = 'servicio'", [servicioId]);
  await pool.query("DELETE FROM vistas_detalle WHERE publicacion_id = ? AND tipo = 'apunte'", [apunteId]);
  await pool.query("DELETE FROM conversaciones WHERE servicio_id = ?", [servicioId]);
  await pool.query("DELETE FROM contratos WHERE servicio_id = ?", [servicioId]);
  await pool.query("DELETE FROM ventas_apuntes WHERE apunte_id = ?", [apunteId]);
  await pool.query("DELETE FROM servicios WHERE id = ?", [servicioId]);
  await pool.query("DELETE FROM apuntes WHERE id = ?", [apunteId]);
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

interface DetalleBody {
  publicacion: { id: number; tipo: string; titulo: string; precio: number | null; editarHref: string };
  visitas30d: number;
  deltaVisitas: { dir: string; label: string } | null;
  tiempoPromedioSegundos: number;
  pctLeyo: number;
  deltaLeyo: { dir: string; label: string } | null;
  visitasTotal: number;
  funnel: { label: string; valor: number }[];
  visitasPorDia: number[];
  dispositivos: { movil: number; tablet: number; desktop: number };
  origenes: { origen: string; total: number }[];
  ubicaciones: { ciudad: string | null; pais: string | null; visitas: number }[];
}

test("GET /api/me/metricas/:tipo/:id sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/metricas/servicio/${servicioId}`);
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("GET /api/me/metricas/:tipo/:id con tipo inválido devuelve 400", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/metricas/proyecto/${servicioId}`, { headers: { Cookie: `PHPSESSID=${SESSION_VALID}` } });
    assert.equal(res.status, 400);
  } finally {
    await close();
  }
});

test("GET /api/me/metricas/:tipo/:id de una publicación de OTRO usuario devuelve 404 (ownership)", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/metricas/servicio/${servicioId}`, { headers: { Cookie: `PHPSESSID=${SESSION_OTRO}` } });
    assert.equal(res.status, 404);
  } finally {
    await close();
  }
});

test("GET /api/me/metricas/servicio/:id: stats, funnel de 3 etapas (chatearon != contrataron) y dispositivos correctos", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/metricas/servicio/${servicioId}`, { headers: { Cookie: `PHPSESSID=${SESSION_VALID}` } });
    const body = (await res.json()) as DetalleBody;
    assert.equal(res.status, 200);

    assert.equal(body.publicacion.id, servicioId);
    assert.equal(body.publicacion.tipo, "servicio");
    assert.equal(body.publicacion.editarHref, `/app/editar_servicio.php?id=${servicioId}`);

    assert.equal(body.visitas30d, 2);
    assert.equal(body.visitasTotal, 3);
    assert.deepEqual(body.deltaVisitas, { dir: "up", label: "+100%" }, "2 vs 1 anterior -> +100%");

    // tiempo promedio de las 2 vistas de hoy: (40+20)/2 = 30
    assert.equal(body.tiempoPromedioSegundos, 30);
    // 1 de 2 leyó completo -> 50%
    assert.equal(body.pctLeyo, 50);

    assert.equal(body.funnel.length, 3);
    assert.deepEqual(body.funnel[0], { label: "Visitas identificadas", valor: 2 });
    assert.deepEqual(body.funnel[1], { label: "Iniciaron chat", valor: 1 });
    assert.deepEqual(body.funnel[2], { label: "Contrataron", valor: 1 });

    assert.equal(body.dispositivos.movil, 1);
    assert.equal(body.dispositivos.desktop, 1);
    assert.equal(body.dispositivos.tablet, 0);

    assert.ok(body.origenes.some((o) => o.origen === "google.com"), "el origen de Google debe llegar parseado a solo el host, sin www");
    assert.ok(body.ubicaciones.some((u) => u.ciudad === "Santiago" && u.pais === "Chile" && u.visitas === 2));

    assert.equal(body.visitasPorDia.length, 30);
    assert.equal(body.visitasPorDia[29], 2, "hoy (último día del array) debe reflejar las 2 vistas de hoy");
  } finally {
    await close();
  }
});

test("GET /api/me/metricas/apunte/:id: funnel de 2 etapas (Visitas identificadas -> Compraron)", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/metricas/apunte/${apunteId}`, { headers: { Cookie: `PHPSESSID=${SESSION_VALID}` } });
    const body = (await res.json()) as DetalleBody;
    assert.equal(res.status, 200);

    assert.equal(body.publicacion.id, apunteId);
    assert.equal(body.publicacion.tipo, "apunte");
    assert.equal(body.publicacion.editarHref, `/app/editar_apunte.php?id=${apunteId}`);

    assert.equal(body.visitas30d, 1);
    assert.equal(body.funnel.length, 2);
    assert.deepEqual(body.funnel[0], { label: "Visitas identificadas", valor: 1 });
    assert.deepEqual(body.funnel[1], { label: "Compraron", valor: 1 });

    // Sin datos en el período anterior (30-60 días atrás) -> "Nuevo", no un % inventado.
    assert.deepEqual(body.deltaVisitas, { dir: "up", label: "Nuevo" });
    // pctLeyo sin ningún dato de leyo_completo real -> deltaLeyo null (huboAnterior=false).
    assert.equal(body.deltaLeyo, null);
  } finally {
    await close();
  }
});

// --- Funciones puras del mapper (server/src/modules/metricas/metricas.mapper.ts) ---

test("computeDeltaPct: anterior<=0 y actual>0 -> 'Nuevo', sin porcentaje inventado", () => {
  assert.deepEqual(computeDeltaPct(5, 0), { dir: "up", label: "Nuevo" });
});

test("computeDeltaPct: anterior<=0 y actual=0 -> null (nada que reportar)", () => {
  assert.equal(computeDeltaPct(0, 0), null);
});

test("computeDeltaPct: mismo valor -> 'flat'/'0%'", () => {
  assert.deepEqual(computeDeltaPct(10, 10), { dir: "flat", label: "0%" });
});

test("computeDeltaPts: huboAnterior=false -> null aunque los valores difieran", () => {
  assert.equal(computeDeltaPts(50, 0, false), null);
});

test("computeDeltaPts: 40% -> 50% es '+10 pts', no '+25%'", () => {
  assert.deepEqual(computeDeltaPts(50, 40, true), { dir: "up", label: "+10 pts" });
});

test("buildFunnel: visitasIdentificadas=0 -> array vacío, sin importar el resto", () => {
  assert.deepEqual(buildFunnel("servicio", 0, 5, 5, 5), []);
});

test("parseOrigen: URL completa con www -> solo el host sin www", () => {
  assert.equal(parseOrigen("https://www.instagram.com/p/xyz"), "instagram.com");
});

test("parseOrigen: string no-URL (ej. 'android-app://...') -> se devuelve tal cual, no 'Directo'", () => {
  assert.equal(parseOrigen("referencia-rara-sin-formato-url"), "referencia-rara-sin-formato-url");
});

test("buildVisitasPorDia: rellena 30 posiciones, ceros donde el mapa no tiene esa fecha", () => {
  const hoy = new Date();
  const claveHoy = `${hoy.getFullYear()}-${String(hoy.getMonth() + 1).padStart(2, "0")}-${String(hoy.getDate()).padStart(2, "0")}`;
  const mapa = new Map([[claveHoy, 7]]);
  const valores = buildVisitasPorDia(mapa);
  assert.equal(valores.length, 30);
  assert.equal(valores[29], 7);
  assert.equal(valores[0], 0);
});
