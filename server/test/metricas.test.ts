import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";

const SESSION_VALID = "test-metricas-session";
const ALUMNO_ID = 1; // "Soporte Nubira" — apuntes.id_alumno tiene FK real, ver notas de tests previos

let servicioId: number;
let apunteId: number;

before(async () => {
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_VALID, ALUMNO_ID],
  );

  const [insServicio] = await pool.query(
    "INSERT INTO servicios (alumno_id, titulo, descripcion, estado, modalidad, visible, precio, fecha_publicacion) VALUES (?, 'Servicio Metricas Test', 'desc', 'aprobado', 'Online', 1, 15000, NOW())",
    [ALUMNO_ID],
  );
  servicioId = (insServicio as { insertId: number }).insertId;

  const [insApunte] = await pool.query(
    "INSERT INTO apuntes (id_alumno, titulo, publico, estado, visible, precio, fecha_subida) VALUES (?, 'Apunte Metricas Test', 1, 'aprobado', 1, 5000, NOW())",
    [ALUMNO_ID],
  );
  apunteId = (insApunte as { insertId: number }).insertId;

  // 2 vistas dentro de los últimos 30 días para el servicio (visitas30d=2), 1 vista en el
  // rango 30-60 días atrás (visitasPrev=1) -> tendencia esperada 'up' (2 > 1).
  await pool.query(
    `INSERT INTO vistas_detalle (tipo, publicacion_id, user_id, session_id, fecha_inicio) VALUES
     ('servicio', ?, 0, 'test-a', NOW()),
     ('servicio', ?, 0, 'test-b', NOW()),
     ('servicio', ?, 0, 'test-c', DATE_SUB(NOW(), INTERVAL 45 DAY))`,
    [servicioId, servicioId, servicioId],
  );
});

after(async () => {
  await pool.query("DELETE FROM sesiones_api WHERE session_id = ?", [SESSION_VALID]);
  await pool.query("DELETE FROM vistas_detalle WHERE publicacion_id = ? AND tipo = 'servicio'", [servicioId]);
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

test("GET /api/me/metricas sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/metricas`);
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("GET /api/me/metricas: servicio con 2 visitas en 30d y 1 en el período previo -> tendencia 'up'; apunte sin vistas -> 0 y sin tendencia", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/metricas`, {
      headers: { Cookie: `PHPSESSID=${SESSION_VALID}` },
    });
    assert.equal(res.status, 200);

    const body = (await res.json()) as {
      data: { id: number; tipo: string; titulo: string; precio: number | null; visitas30d: number; tendencia: string | null }[];
    };

    const servicio = body.data.find((p) => p.tipo === "servicio" && p.id === servicioId);
    assert.ok(servicio, "el servicio del fixture debe aparecer en data[]");
    assert.equal(servicio!.visitas30d, 2);
    assert.equal(servicio!.tendencia, "up");
    assert.equal(servicio!.precio, 15000);

    const apunte = body.data.find((p) => p.tipo === "apunte" && p.id === apunteId);
    assert.ok(apunte, "el apunte del fixture debe aparecer en data[]");
    assert.equal(apunte!.visitas30d, 0);
    assert.equal(apunte!.tendencia, null);
    assert.equal(apunte!.precio, 5000);
  } finally {
    await close();
  }
});
