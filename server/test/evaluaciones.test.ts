import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";
import { searchServiciosAprobados } from "../src/modules/servicios/servicios.repository.js";

const SESSION_VALID = "test-evaluaciones-session";
const USUARIO_ID = 888888890; // sintético (id_evaluado), no corresponde a ningún usuario real
const EVALUADOR_ID = 1; // "Soporte Nubira" — único admin real, id estable, sirve como evaluador real

let servicioId: number;
let evaluacionVendedorId: number;
let evaluacionCompradorId: number;

before(async () => {
  const { rows } = await searchServiciosAprobados({ page: 1, limit: 1 });
  const servicio = rows[0];
  if (!servicio) throw new Error("Se necesita al menos un servicio aprobado en la BD local para correr este test.");
  servicioId = servicio.id;

  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_VALID, USUARIO_ID],
  );

  const [insVendedor] = await pool.query(
    `INSERT INTO valoraciones (contrato_id, servicio_id, id_evaluado, id_evaluador, rol_evaluado, id_item, tipo_item, calificacion, comentario, fecha)
     VALUES (0, ?, ?, ?, 'vendedor', ?, 'servicio', 5, 'Excelente tutor', NOW())`,
    [servicioId, USUARIO_ID, EVALUADOR_ID, servicioId],
  );
  evaluacionVendedorId = (insVendedor as { insertId: number }).insertId;

  const [insComprador] = await pool.query(
    `INSERT INTO valoraciones (contrato_id, servicio_id, id_evaluado, id_evaluador, rol_evaluado, id_item, tipo_item, calificacion, comentario, fecha)
     VALUES (0, ?, ?, ?, 'comprador', ?, 'servicio', 4, 'Buen estudiante', NOW())`,
    [servicioId, USUARIO_ID, EVALUADOR_ID, servicioId],
  );
  evaluacionCompradorId = (insComprador as { insertId: number }).insertId;
});

after(async () => {
  await pool.query("DELETE FROM sesiones_api WHERE session_id = ?", [SESSION_VALID]);
  await pool.query("DELETE FROM valoraciones WHERE id IN (?, ?)", [evaluacionVendedorId, evaluacionCompradorId]);
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

test("GET /api/me/evaluaciones sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/evaluaciones`);
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("GET /api/me/evaluaciones separa reseñas por rol (vendedor -> resenasComoTutor, comprador -> resenasComoAlumno)", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/evaluaciones`, {
      headers: { Cookie: `PHPSESSID=${SESSION_VALID}` },
    });
    assert.equal(res.status, 200);

    const body = (await res.json()) as {
      resenasComoTutor: { id: number; calificacion: number; comentario: string | null; nombreEvaluador: string }[];
      resenasComoAlumno: { id: number; calificacion: number; comentario: string | null; nombreEvaluador: string }[];
    };

    const comoTutor = body.resenasComoTutor.find((r) => r.id === evaluacionVendedorId);
    assert.ok(comoTutor, "la reseña 'vendedor' del fixture debe aparecer en resenasComoTutor");
    assert.equal(comoTutor!.calificacion, 5);
    assert.equal(comoTutor!.comentario, "Excelente tutor");
    assert.equal(comoTutor!.nombreEvaluador, "Soporte Nubira");

    const comoAlumno = body.resenasComoAlumno.find((r) => r.id === evaluacionCompradorId);
    assert.ok(comoAlumno, "la reseña 'comprador' del fixture debe aparecer en resenasComoAlumno");
    assert.equal(comoAlumno!.calificacion, 4);

    assert.ok(
      !body.resenasComoTutor.some((r) => r.id === evaluacionCompradorId),
      "la reseña de comprador NO debe filtrarse a resenasComoTutor",
    );
  } finally {
    await close();
  }
});

test("GET /api/me/evaluaciones: usuario sin reseñas devuelve arrays vacíos, no error", async () => {
  const sessionVacia = "test-evaluaciones-session-vacia";
  const usuarioVacio = 888888891;

  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [sessionVacia, usuarioVacio],
  );

  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/evaluaciones`, {
      headers: { Cookie: `PHPSESSID=${sessionVacia}` },
    });
    const body = (await res.json()) as { resenasComoTutor: unknown[]; resenasComoAlumno: unknown[] };
    assert.equal(res.status, 200);
    assert.deepEqual(body.resenasComoTutor, []);
    assert.deepEqual(body.resenasComoAlumno, []);
  } finally {
    await close();
    await pool.query("DELETE FROM sesiones_api WHERE session_id = ?", [sessionVacia]);
  }
});
