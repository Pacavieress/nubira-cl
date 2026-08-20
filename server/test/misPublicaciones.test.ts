import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";

const SESSION_VALID = "test-mis-publicaciones-session";
// id=1 ("Soporte Nubira") — a diferencia de otros tests de esta migración, acá NO puede
// ser un id sintético: `apuntes.id_alumno` tiene FK real hacia `alumnos.id` (confirmado en
// vivo: el INSERT synthetic fallaba con ER_NO_REFERENCED_ROW_2). servicios/contratos/
// valoraciones no tienen esa restricción, pero se usa el mismo id real acá para no mezclar
// 2 criterios distintos en el mismo fixture.
const ALUMNO_ID = 1;
const OTRO_ALUMNO_ID = 888888895; // sintético — solo se usa para la fila de sesiones_api del "atacante", sin FK

let servicioId: number;
let servicioPausadoId: number;
let apunteId: number;

before(async () => {
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_VALID, ALUMNO_ID],
  );

  const [insServicio] = await pool.query(
    "INSERT INTO servicios (alumno_id, titulo, descripcion, estado, modalidad, visible) VALUES (?, 'Clase de prueba', 'desc', 'aprobado', 'Online', 1)",
    [ALUMNO_ID],
  );
  servicioId = (insServicio as { insertId: number }).insertId;

  const [insServicioPausado] = await pool.query(
    "INSERT INTO servicios (alumno_id, titulo, descripcion, estado, modalidad, visible) VALUES (?, 'Clase pausada', 'desc', 'pendiente', 'Online', 1)",
    [ALUMNO_ID],
  );
  servicioPausadoId = (insServicioPausado as { insertId: number }).insertId;

  const [insApunte] = await pool.query(
    "INSERT INTO apuntes (id_alumno, titulo, publico, estado, visible) VALUES (?, 'Apunte de prueba', 1, 'aprobado', 1)",
    [ALUMNO_ID],
  );
  apunteId = (insApunte as { insertId: number }).insertId;
});

after(async () => {
  await pool.query("DELETE FROM sesiones_api WHERE session_id = ?", [SESSION_VALID]);
  await pool.query("DELETE FROM servicios WHERE id IN (?, ?)", [servicioId, servicioPausadoId]);
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

test("GET /api/me/mis-publicaciones sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/mis-publicaciones`);
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("GET /api/me/mis-publicaciones devuelve los 2 servicios y el apunte del fixture", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/mis-publicaciones`, {
      headers: { Cookie: `PHPSESSID=${SESSION_VALID}` },
    });
    assert.equal(res.status, 200);
    const body = (await res.json()) as {
      servicios: { id: number; titulo: string; estado: string }[];
      apuntes: { id: number; titulo: string; esPublico: boolean }[];
    };
    assert.ok(body.servicios.some((s) => s.id === servicioId && s.titulo === "Clase de prueba"));
    assert.ok(body.apuntes.some((a) => a.id === apunteId && a.esPublico === true));
  } finally {
    await close();
  }
});

test("DELETE /api/me/mis-publicaciones/servicios/:id (ocultar): otro usuario NO puede ocultar un servicio ajeno", async () => {
  const sessionAtacante = "test-mis-publicaciones-atacante";
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [sessionAtacante, OTRO_ALUMNO_ID],
  );

  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/mis-publicaciones/servicios/${servicioId}`, {
      method: "DELETE",
      headers: { Cookie: `PHPSESSID=${sessionAtacante}` },
    });
    assert.equal(res.status, 204); // mismo comportamiento silencioso que el PHP real: 204 sin efecto

    const [rows] = await pool.query<import("mysql2").RowDataPacket[]>("SELECT visible FROM servicios WHERE id = ?", [servicioId]);
    assert.equal(rows[0]!.visible, 1, "el servicio de OTRO usuario no debe haberse ocultado");
  } finally {
    await close();
    await pool.query("DELETE FROM sesiones_api WHERE session_id = ?", [sessionAtacante]);
  }
});

test("DELETE /api/me/mis-publicaciones/servicios/:id oculta (visible=0) el servicio del dueño real", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/mis-publicaciones/servicios/${servicioId}`, {
      method: "DELETE",
      headers: { Cookie: `PHPSESSID=${SESSION_VALID}` },
    });
    assert.equal(res.status, 204);

    const [rows] = await pool.query<import("mysql2").RowDataPacket[]>("SELECT visible FROM servicios WHERE id = ?", [servicioId]);
    assert.equal(rows[0]!.visible, 0);
  } finally {
    await close();
  }
});

test("POST /api/me/mis-publicaciones/servicios/:id/reactivar pone estado='aprobado'", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/mis-publicaciones/servicios/${servicioPausadoId}/reactivar`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_VALID}` },
    });
    assert.equal(res.status, 204);

    const [rows] = await pool.query<import("mysql2").RowDataPacket[]>("SELECT estado FROM servicios WHERE id = ?", [servicioPausadoId]);
    assert.equal(rows[0]!.estado, "aprobado");
  } finally {
    await close();
  }
});

test("DELETE /api/me/mis-publicaciones/apuntes/:id oculta (visible=0) el apunte del dueño real", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/mis-publicaciones/apuntes/${apunteId}`, {
      method: "DELETE",
      headers: { Cookie: `PHPSESSID=${SESSION_VALID}` },
    });
    assert.equal(res.status, 204);

    const [rows] = await pool.query<import("mysql2").RowDataPacket[]>("SELECT visible FROM apuntes WHERE id = ?", [apunteId]);
    assert.equal(rows[0]!.visible, 0);
  } finally {
    await close();
  }
});
