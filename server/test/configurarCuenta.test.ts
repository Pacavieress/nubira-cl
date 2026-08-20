import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";

const SESSION_VALID = "test-configurar-cuenta-session";
// id=1 ("Soporte Nubira") — usuario real. Se guardan sus valores originales en before() y
// se restauran en after(), mismo criterio que otras mutaciones sobre datos reales en esta
// migración (ej. la nota sobre oferta_termino en memoria de sesiones previas).
const USUARIO_ID = 1;

interface OriginalRow {
  nombre: string;
  carrera: string | null;
  tipo: string | null;
  bio: string | null;
  universidad: string | null;
  anio_egreso: number | null;
  anios_experiencia: number | null;
}
let original: OriginalRow;

before(async () => {
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_VALID, USUARIO_ID],
  );

  const [rows] = await pool.query<(OriginalRow & import("mysql2").RowDataPacket)[]>(
    "SELECT nombre, carrera, tipo, bio, universidad, anio_egreso, anios_experiencia FROM alumnos WHERE id = ?",
    [USUARIO_ID],
  );
  original = rows[0]!;
});

after(async () => {
  await pool.query(
    "UPDATE alumnos SET nombre=?, carrera=?, tipo=?, bio=?, universidad=?, anio_egreso=?, anios_experiencia=? WHERE id=?",
    [original.nombre, original.carrera, original.tipo, original.bio, original.universidad, original.anio_egreso, original.anios_experiencia, USUARIO_ID],
  );
  await pool.query("DELETE FROM sesiones_api WHERE session_id = ?", [SESSION_VALID]);
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

test("GET /api/me/configurar-cuenta sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/configurar-cuenta`);
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("GET /api/me/configurar-cuenta devuelve el perfil real del usuario", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/configurar-cuenta`, {
      headers: { Cookie: `PHPSESSID=${SESSION_VALID}` },
    });
    assert.equal(res.status, 200);
    const body = (await res.json()) as { nombre: string; correo: string };
    assert.equal(body.nombre, original.nombre);
    assert.ok(body.correo.length > 0);
  } finally {
    await close();
  }
});

test("PUT /api/me/configurar-cuenta con nombre vacío devuelve 400, no modifica nada", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/configurar-cuenta`, {
      method: "PUT",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_VALID}` },
      body: JSON.stringify({ nombre: "", carrera: "Ingeniería" }),
    });
    assert.equal(res.status, 400);
  } finally {
    await close();
  }
});

test("PUT /api/me/configurar-cuenta con tipo inválido devuelve 400", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/configurar-cuenta`, {
      method: "PUT",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_VALID}` },
      body: JSON.stringify({ nombre: "Test", carrera: "Test", tipo: "no_existe" }),
    });
    assert.equal(res.status, 400);
  } finally {
    await close();
  }
});

test("PUT /api/me/configurar-cuenta actualiza los campos y strip_tags limpia HTML de bio/carrera/universidad", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/configurar-cuenta`, {
      method: "PUT",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_VALID}` },
      body: JSON.stringify({
        nombre: "Test Nombre Actualizado",
        carrera: "<b>Ingeniería</b> Civil",
        tipo: "profesor",
        bio: "<script>alert(1)</script>Bio de prueba",
        universidad: "<i>USACH</i>",
        anioEgreso: 2020,
        aniosExperiencia: 5,
      }),
    });
    assert.equal(res.status, 204);

    const getRes = await fetch(`${url}/api/me/configurar-cuenta`, {
      headers: { Cookie: `PHPSESSID=${SESSION_VALID}` },
    });
    const body = (await getRes.json()) as {
      nombre: string;
      carrera: string | null;
      tipo: string | null;
      bio: string | null;
      universidad: string | null;
      anioEgreso: number | null;
      aniosExperiencia: number | null;
    };
    assert.equal(body.nombre, "Test Nombre Actualizado");
    assert.equal(body.carrera, "Ingeniería Civil");
    assert.equal(body.tipo, "profesor");
    assert.equal(body.bio, "alert(1)Bio de prueba");
    assert.equal(body.universidad, "USACH");
    assert.equal(body.anioEgreso, 2020);
    assert.equal(body.aniosExperiencia, 5);
  } finally {
    await close();
  }
});
