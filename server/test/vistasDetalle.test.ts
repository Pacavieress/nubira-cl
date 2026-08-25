import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";

// Puerto de track_vista.php — fire-and-forget, solo analítica. Servicio de prueba real
// necesario solo como publicacion_id válido (la tabla vistas_detalle no tiene FK real hacia
// servicios, pero se usa un id real de todas formas para no ensuciar con ids inventados).
let servicioId: number;
let alumnoId: number;
const SESSION_ID_A = "test-track-session-aaaaaaaaaa";
const SESSION_ID_B = "test-track-session-bbbbbbbbbb";

before(async () => {
  const [insAlumno] = await pool.query("INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, rol) VALUES (?, ?, 'x', 1, 0, 'alumno')", [
    "Test Alumno Track Vista",
    `test-alumno-track-vista-${Date.now()}@example.invalid`,
  ]);
  alumnoId = (insAlumno as { insertId: number }).insertId;

  const [insServicio] = await pool.query(
    "INSERT INTO servicios (alumno_id, titulo, categoria, precio, estado, visible) VALUES (?, 'Test servicio track vista', 'Otros', 5000, 'aprobado', 1)",
    [alumnoId],
  );
  servicioId = (insServicio as { insertId: number }).insertId;
});

after(async () => {
  await pool.query("DELETE FROM vistas_detalle WHERE session_id IN (?, ?)", [SESSION_ID_A, SESSION_ID_B]);
  await pool.query("DELETE FROM servicios WHERE id = ?", [servicioId]);
  await pool.query("DELETE FROM alumnos WHERE id = ?", [alumnoId]);
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

test("POST /api/vistas con payload inválido devuelve ok:false sin insertar (sesión < 10 chars)", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/vistas`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ tipo: "servicio", publicacion_id: servicioId, session_id: "corta" }),
    });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.equal(body.ok, false);
  } finally {
    await close();
  }
});

test("POST /api/vistas primer ping inserta la fila con los datos del payload", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/vistas`, {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-Forwarded-For": "127.0.0.1" },
      body: JSON.stringify({
        tipo: "servicio",
        publicacion_id: servicioId,
        session_id: SESSION_ID_A,
        tiempo_segundos: 12,
        scroll_max_pct: 45,
        leyo_completo: false,
        dispositivo: "desktop",
        origen: "https://google.com/",
      }),
    });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.equal(body.ok, true);

    const [rows] = await pool.query(
      "SELECT tipo, publicacion_id, tiempo_segundos, scroll_max_pct, leyo_completo, dispositivo, origen, user_id FROM vistas_detalle WHERE session_id = ?",
      [SESSION_ID_A],
    );
    const fila = (rows as Record<string, unknown>[])[0];
    assert.ok(fila, "debe existir la fila insertada");
    assert.equal(fila.tipo, "servicio");
    assert.equal(fila.publicacion_id, servicioId);
    assert.equal(fila.tiempo_segundos, 12);
    assert.equal(fila.scroll_max_pct, 45);
    assert.equal(fila.leyo_completo, 0);
    assert.equal(fila.dispositivo, "desktop");
    assert.equal(fila.origen, "https://google.com/");
    assert.equal(fila.user_id, null);
  } finally {
    await close();
  }
});

test("POST /api/vistas segundo ping actualiza con GREATEST (nunca retrocede tiempo/scroll)", async () => {
  const { url, close } = listen();
  try {
    // Primer ping con valores altos.
    await fetch(`${url}/api/vistas`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ tipo: "servicio", publicacion_id: servicioId, session_id: SESSION_ID_B, tiempo_segundos: 50, scroll_max_pct: 80 }),
    });
    // Segundo ping con valores más bajos (ej. el usuario subió scroll y luego bajó) — no debe retroceder.
    const res2 = await fetch(`${url}/api/vistas`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ tipo: "servicio", publicacion_id: servicioId, session_id: SESSION_ID_B, tiempo_segundos: 30, scroll_max_pct: 40 }),
    });
    assert.equal(res2.status, 200);

    const [rows] = await pool.query("SELECT tiempo_segundos, scroll_max_pct FROM vistas_detalle WHERE session_id = ?", [SESSION_ID_B]);
    const fila = (rows as { tiempo_segundos: number; scroll_max_pct: number }[])[0];
    assert.equal(fila.tiempo_segundos, 50, "no debe retroceder tiempo_segundos");
    assert.equal(fila.scroll_max_pct, 80, "no debe retroceder scroll_max_pct");
  } finally {
    await close();
  }
});

test("POST /api/vistas con usuario_id autenticado lo guarda en user_id", async () => {
  const SESSION_LOGIN = "test-track-vista-login-session";
  const { url, close } = listen();
  try {
    await pool.query("INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)", [SESSION_LOGIN, alumnoId]);

    const res = await fetch(`${url}/api/vistas`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_LOGIN}` },
      body: JSON.stringify({ tipo: "servicio", publicacion_id: servicioId, session_id: "test-track-session-cccccccccc" }),
    });
    assert.equal(res.status, 200);

    const [rows] = await pool.query("SELECT user_id FROM vistas_detalle WHERE session_id = ?", ["test-track-session-cccccccccc"]);
    assert.equal((rows as { user_id: number }[])[0].user_id, alumnoId);

    await pool.query("DELETE FROM vistas_detalle WHERE session_id = ?", ["test-track-session-cccccccccc"]);
    await pool.query("DELETE FROM sesiones_api WHERE session_id = ?", [SESSION_LOGIN]);
  } finally {
    await close();
  }
});
