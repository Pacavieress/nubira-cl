import { test, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";
import { computeSubtitulo, mapTiempoRespuestaPerfil } from "../src/modules/tutores/tutores.mapper.js";

after(async () => {
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

// ---- computeSubtitulo: puerto exacto de perfil.php:191-200 ----

test("computeSubtitulo: tipo='egresado' con institución -> 'Egresado · <institución>'", () => {
  assert.equal(computeSubtitulo("egresado", "USACH"), "Egresado · USACH");
});

test("computeSubtitulo: tipo='egresado' sin institución -> solo 'Egresado'", () => {
  assert.equal(computeSubtitulo("egresado", null), "Egresado");
  assert.equal(computeSubtitulo("egresado", ""), "Egresado");
});

test("computeSubtitulo: tipo='profesor' -> siempre 'Profesor', ignora institución", () => {
  assert.equal(computeSubtitulo("profesor", "PUC"), "Profesor");
});

test("computeSubtitulo: tipo='particular' -> siempre 'Tutor Particular'", () => {
  assert.equal(computeSubtitulo("particular", "PUC"), "Tutor Particular");
});

test("computeSubtitulo: tipo default (estudiante/alumno/null) -> institución cruda, sin abreviar", () => {
  assert.equal(computeSubtitulo("estudiante", "Universidad de Santiago de Chile"), "Universidad de Santiago de Chile");
  assert.equal(computeSubtitulo("alumno", "PUC"), "PUC");
  assert.equal(computeSubtitulo(null, "PUC"), "PUC");
});

test("computeSubtitulo: tipo default sin institución -> 'Particular' (fallback de institucion_tutor())", () => {
  assert.equal(computeSubtitulo("estudiante", null), "Particular");
  assert.equal(computeSubtitulo(null, ""), "Particular");
});

// ---- mapTiempoRespuestaPerfil: puerto exacto de perfil.php:316-324 ----

test("mapTiempoRespuestaPerfil: con métrica -> igual que formatearTiempoRespuesta", () => {
  assert.deepEqual(mapTiempoRespuestaPerfil(10, 0), { texto: "En minutos", tono: "verde" });
});

test("mapTiempoRespuestaPerfil: sin métrica y CON reseñas como tutor -> null (perfil.php lo oculta)", () => {
  assert.equal(mapTiempoRespuestaPerfil(null, 5), null);
});

test("mapTiempoRespuestaPerfil: sin métrica y SIN reseñas como tutor -> 'Tutor nuevo' / gris", () => {
  assert.deepEqual(mapTiempoRespuestaPerfil(null, 0), { texto: "Tutor nuevo", tono: "gris" });
});

// ---- GET /api/tutores/:id: campos nuevos del perfil completo, contra datos reales ----

test("GET /api/tutores/:id: un tutor con apuntes reales los trae en el array `apuntes`", async () => {
  const [rows] = await pool.query(
    "SELECT id_alumno, COUNT(*) c FROM apuntes WHERE estado = 'aprobado' AND visible = 1 AND bloqueado = 0 GROUP BY id_alumno ORDER BY c DESC LIMIT 1",
  );
  const fixture = (rows as unknown as Array<{ id_alumno: number; c: number }>)[0];
  if (!fixture) {
    return; // BD local sin apuntes aprobados hoy — nada que comparar.
  }

  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/tutores/${fixture.id_alumno}`);
    const body = (await res.json()) as { apuntes: { id: number }[] };
    assert.equal(res.status, 200);
    assert.ok(body.apuntes.length > 0, "el tutor fixture tiene apuntes aprobados en la BD, deberían aparecer");
  } finally {
    await close();
  }
});

test("GET /api/tutores/:id: reseñas como tutor y como alumno vienen en arrays separados", async () => {
  const [rows] = await pool.query(
    `SELECT v.id_evaluado FROM valoraciones v
     JOIN alumnos a ON a.id = v.id_evaluado
     WHERE v.rol_evaluado = 'vendedor' AND v.calificacion > 0 AND a.visible = 1 AND a.bloqueado = 0
     LIMIT 1`,
  );
  const fixture = (rows as unknown as Array<{ id_evaluado: number }>)[0];
  if (!fixture) {
    return; // BD local sin reseñas de vendedor hoy — nada que comparar.
  }

  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/tutores/${fixture.id_evaluado}`);
    const body = (await res.json()) as {
      resenasComoTutor: { calificacion: number; evaluador: { nombre: string | null } }[];
      resenasComoAlumno: unknown[];
    };
    assert.equal(res.status, 200);
    assert.ok(body.resenasComoTutor.length > 0);
    assert.ok(Array.isArray(body.resenasComoAlumno));
  } finally {
    await close();
  }
});

test("GET /api/tutores/:id: statsAcademicas expone universidad/anioEgreso/aniosExperiencia tal cual la BD", async () => {
  const [rows] = await pool.query(
    "SELECT id, universidad, anio_egreso, anios_experiencia FROM alumnos WHERE universidad IS NOT NULL AND universidad != '' LIMIT 1",
  );
  const fixture = (rows as unknown as Array<{
    id: number;
    universidad: string;
    anio_egreso: number | null;
    anios_experiencia: number | null;
  }>)[0];
  if (!fixture) {
    return; // BD local sin ningún alumno con universidad cargada hoy — nada que comparar.
  }

  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/tutores/${fixture.id}`);
    const body = (await res.json()) as {
      statsAcademicas: { universidad: string | null; anioEgreso: number | null; aniosExperiencia: number | null };
    };
    assert.equal(res.status, 200);
    assert.equal(body.statsAcademicas.universidad, fixture.universidad);
    assert.equal(body.statsAcademicas.anioEgreso, fixture.anio_egreso);
    assert.equal(body.statsAcademicas.aniosExperiencia, fixture.anios_experiencia);
  } finally {
    await close();
  }
});

test("GET /api/tutores/:id: el rating sigue siendo SOLO de valoraciones (no mezcla alumnos.calificacion_promedio)", async () => {
  const [rows] = await pool.query(
    "SELECT id FROM alumnos WHERE calificacion_promedio > 0 AND cantidad_votos > 0 AND visible = 1 AND bloqueado = 0 LIMIT 1",
  );
  const fixture = (rows as unknown as Array<{ id: number }>)[0];
  if (!fixture) {
    return; // BD local sin ningún alumno con calificacion_promedio legado hoy — nada que comparar.
  }

  const [valRows] = await pool.query(
    "SELECT COUNT(*) c, AVG(calificacion) avg FROM valoraciones WHERE id_evaluado = ? AND rol_evaluado = 'vendedor' AND calificacion > 0",
    [fixture.id],
  );
  const esperado = (valRows as unknown as Array<{ c: number; avg: number | null }>)[0]!;

  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/tutores/${fixture.id}`);
    const body = (await res.json()) as { rating: { promedio: number | null; votos: number } };
    assert.equal(res.status, 200);
    assert.equal(body.rating.votos, Number(esperado.c));
    if (esperado.avg === null) {
      assert.equal(body.rating.promedio, null);
    } else {
      assert.ok(Math.abs(body.rating.promedio! - Number(esperado.avg)) < 0.01);
    }
  } finally {
    await close();
  }
});
