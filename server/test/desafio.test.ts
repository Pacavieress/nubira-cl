import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";

const SESSION_VALID = "test-desafio-session";
// id=1 ("Soporte Nubira") — desafio_progreso/desafio_preguntas_vistas/desafio_intentos
// tienen FK real hacia alumnos.id (confirmado con SHOW CREATE TABLE). Verificado antes de
// escribir este test que id=1 arranca sin filas en las 3 tablas — se limpia todo en after().
const USUARIO_ID = 1;
const MATERIA = "calculo";

before(async () => {
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_VALID, USUARIO_ID],
  );
});

after(async () => {
  await pool.query("DELETE FROM sesiones_api WHERE session_id = ?", [SESSION_VALID]);
  await pool.query("DELETE FROM desafio_progreso WHERE usuario_id = ?", [USUARIO_ID]);
  await pool.query("DELETE FROM desafio_preguntas_vistas WHERE usuario_id = ?", [USUARIO_ID]);
  await pool.query("DELETE FROM desafio_intentos WHERE usuario_id = ?", [USUARIO_ID]);
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

test("GET /api/desafio/materias sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/desafio/materias`);
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("GET /api/desafio/materias devuelve las 12 materias reales activas, incluida 'calculo'", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/desafio/materias`, { headers: { Cookie: `PHPSESSID=${SESSION_VALID}` } });
    assert.equal(res.status, 200);
    const body = (await res.json()) as { data: { slug: string; nombre: string }[] };
    assert.ok(body.data.length >= 12);
    assert.ok(body.data.some((m) => m.slug === "calculo" && m.nombre === "Cálculo"));
  } finally {
    await close();
  }
});

test("GET /api/desafio/preguntas sin materia devuelve 400", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/desafio/preguntas`, { headers: { Cookie: `PHPSESSID=${SESSION_VALID}` } });
    assert.equal(res.status, 400);
  } finally {
    await close();
  }
});

test("GET /api/desafio/preguntas?materia=no-existe devuelve 400 materia_invalida", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/desafio/preguntas?materia=no-existe`, { headers: { Cookie: `PHPSESSID=${SESSION_VALID}` } });
    assert.equal(res.status, 400);
    const body = (await res.json()) as { error: string };
    assert.equal(body.error, "materia_invalida");
  } finally {
    await close();
  }
});

test("GET /api/desafio/preguntas?materia=calculo: 3 preguntas reales, SIN respuesta_correcta expuesta en ningún lado del JSON", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/desafio/preguntas?materia=calculo`, { headers: { Cookie: `PHPSESSID=${SESSION_VALID}` } });
    assert.equal(res.status, 200);
    const textoCrudo = await res.text();
    assert.ok(!textoCrudo.includes("respuesta_correcta"), "la respuesta correcta NUNCA debe viajar hacia el cliente");

    const body = JSON.parse(textoCrudo) as {
      ok: boolean;
      materia: string;
      preguntas: { id: number; tipo: string; enunciado: string; opciones: Record<string, string> }[];
    };
    assert.equal(body.ok, true);
    assert.equal(body.materia, "calculo");
    assert.equal(body.preguntas.length, 3);
    for (const p of body.preguntas) {
      assert.ok(p.enunciado.length > 0);
      assert.ok(Object.keys(p.opciones).length >= 2, "cada pregunta debe traer al menos 2 opciones");
    }
  } finally {
    await close();
  }
});

test("POST /api/desafio/responder: con las 3 respuestas correctas reales -> aciertos=3, resultado='bien', nivel sube a 3, sin categoriaServicio", async () => {
  // Trae las respuestas correctas reales directo de la BD (server-only, nunca se expone
  // por la API) para poder construir un envío 100% acertado de forma determinística.
  const [rows] = await pool.query<import("mysql2").RowDataPacket[]>(
    "SELECT id, respuesta_correcta FROM desafio_preguntas WHERE materia_slug = ? AND activa = 1 AND revisado_por_admin = 1 LIMIT 3",
    [MATERIA],
  );
  assert.equal(rows.length, 3, "se necesitan 3 preguntas reales de calculo en la BD local para este test");
  const respuestas = (rows as { id: number; respuesta_correcta: string }[]).map((r) => ({ preguntaId: r.id, opcion: r.respuesta_correcta }));

  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/desafio/responder`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_VALID}` },
      body: JSON.stringify({ materia: MATERIA, respuestas }),
    });
    assert.equal(res.status, 200);
    const body = (await res.json()) as { ok: boolean; aciertos: number; resultado: string; categoriaServicio: string | null };
    assert.equal(body.ok, true);
    assert.equal(body.aciertos, 3);
    assert.equal(body.resultado, "bien");
    assert.equal(body.categoriaServicio, null, "categoriaServicio solo se calcula cuando resultado='mal'");

    const [prog] = await pool.query<import("mysql2").RowDataPacket[]>(
      "SELECT nivel_actual FROM desafio_progreso WHERE usuario_id = ? AND materia_slug = ?",
      [USUARIO_ID, MATERIA],
    );
    assert.equal(prog[0]!.nivel_actual, 3, "acertar debe subir el nivel: baseline 2 + 1 = 3");

    const [vistas] = await pool.query<import("mysql2").RowDataPacket[]>(
      "SELECT COUNT(*) n FROM desafio_preguntas_vistas WHERE usuario_id = ? AND pregunta_id IN (?, ?, ?)",
      [USUARIO_ID, respuestas[0]!.preguntaId, respuestas[1]!.preguntaId, respuestas[2]!.preguntaId],
    );
    assert.equal((vistas[0] as { n: number }).n, 3, "las 3 preguntas respondidas deben quedar marcadas como vistas");
  } finally {
    await close();
  }
});

test("POST /api/desafio/responder: con las 3 respuestas incorrectas -> aciertos=0, resultado='mal', trae categoriaServicio real ('Matemáticas' para calculo)", async () => {
  // Nuevas 3 preguntas (las 3 anteriores ya quedaron "vistas" en el test previo).
  const [rows] = await pool.query<import("mysql2").RowDataPacket[]>(
    `SELECT id, respuesta_correcta FROM desafio_preguntas
     WHERE materia_slug = ? AND activa = 1 AND revisado_por_admin = 1
       AND id NOT IN (SELECT pregunta_id FROM desafio_preguntas_vistas WHERE usuario_id = ?)
     LIMIT 3`,
    [MATERIA, USUARIO_ID],
  );
  assert.equal(rows.length, 3);
  const OPUESTA: Record<string, string> = { a: "b", b: "a", c: "a", d: "a" };
  const respuestas = (rows as { id: number; respuesta_correcta: string }[]).map((r) => ({
    preguntaId: r.id,
    opcion: OPUESTA[r.respuesta_correcta] ?? "b",
  }));

  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/desafio/responder`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_VALID}` },
      body: JSON.stringify({ materia: MATERIA, respuestas }),
    });
    assert.equal(res.status, 200);
    const body = (await res.json()) as { aciertos: number; resultado: string; categoriaServicio: string | null };
    assert.equal(body.aciertos, 0);
    assert.equal(body.resultado, "mal");
    assert.equal(body.categoriaServicio, "Matemáticas");
  } finally {
    await close();
  }
});

test("POST /api/desafio/responder con menos de 3 respuestas devuelve 400", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/desafio/responder`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_VALID}` },
      body: JSON.stringify({ materia: MATERIA, respuestas: [{ preguntaId: 1, opcion: "a" }] }),
    });
    assert.equal(res.status, 400);
  } finally {
    await close();
  }
});

test("POST /api/desafio/responder con una pregunta que NO pertenece a la materia declarada devuelve 400 preguntas_invalidas", async () => {
  const [otra] = await pool.query<import("mysql2").RowDataPacket[]>(
    "SELECT id FROM desafio_preguntas WHERE materia_slug != ? AND activa = 1 AND revisado_por_admin = 1 LIMIT 1",
    [MATERIA],
  );
  const idAjeno = (otra[0] as { id: number }).id;

  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/desafio/responder`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_VALID}` },
      body: JSON.stringify({
        materia: MATERIA,
        respuestas: [
          { preguntaId: idAjeno, opcion: "a" },
          { preguntaId: 999999997, opcion: "a" },
          { preguntaId: 999999998, opcion: "a" },
        ],
      }),
    });
    assert.equal(res.status, 400);
    const body = (await res.json()) as { error: string };
    assert.equal(body.error, "preguntas_invalidas");
  } finally {
    await close();
  }
});
