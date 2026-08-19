import { test, after } from "node:test";
import assert from "node:assert/strict";
import { formatearTiempoRespuesta } from "../src/modules/servicios/servicios.mapper.js";
import { getMinutosRespuestaTutor } from "../src/modules/servicios/servicios.repository.js";
import { pool } from "../src/db/pool.js";

const TUTOR_ID_TEST = 999888777; // sintético, no corresponde a ningún tutor real

after(async () => {
  await pool.query("DELETE FROM respuestas_tutor WHERE tutor_id = ?", [TUTOR_ID_TEST]);
  await pool.end();
});

// ---- formatearTiempoRespuesta: casos límite exactos de app/helpers/tiempo_respuesta.php ----

test("formatearTiempoRespuesta: null -> Tutor nuevo / gris", () => {
  assert.deepEqual(formatearTiempoRespuesta(null), { texto: "Tutor nuevo", tono: "gris" });
});

test("formatearTiempoRespuesta: 14 min -> En minutos / verde, 15 min -> En menos de 1 hora / verde", () => {
  assert.deepEqual(formatearTiempoRespuesta(14), { texto: "En minutos", tono: "verde" });
  assert.deepEqual(formatearTiempoRespuesta(15), { texto: "En menos de 1 hora", tono: "verde" });
});

test("formatearTiempoRespuesta: 59 min -> En menos de 1 hora / verde, 60 min -> En pocas horas / azul", () => {
  assert.deepEqual(formatearTiempoRespuesta(59), { texto: "En menos de 1 hora", tono: "verde" });
  assert.deepEqual(formatearTiempoRespuesta(60), { texto: "En pocas horas", tono: "azul" });
});

test("formatearTiempoRespuesta: 179 min -> En pocas horas / azul, 180 min -> En el día / azul", () => {
  assert.deepEqual(formatearTiempoRespuesta(179), { texto: "En pocas horas", tono: "azul" });
  assert.deepEqual(formatearTiempoRespuesta(180), { texto: "En el día", tono: "azul" });
});

test("formatearTiempoRespuesta: 719 min -> En el día / azul, 720 min -> En 1 día / naranjo", () => {
  assert.deepEqual(formatearTiempoRespuesta(719), { texto: "En el día", tono: "azul" });
  assert.deepEqual(formatearTiempoRespuesta(720), { texto: "En 1 día", tono: "naranjo" });
});

test("formatearTiempoRespuesta: valores grandes siguen siendo En 1 día / naranjo (nunca 'gris' salvo null)", () => {
  assert.deepEqual(formatearTiempoRespuesta(1440), { texto: "En 1 día", tono: "naranjo" });
});

// ---- getMinutosRespuestaTutor: mediana real contra la BD, cantidad par e impar ----

test("getMinutosRespuestaTutor: sin datos devuelve null", async () => {
  const resultado = await getMinutosRespuestaTutor(TUTOR_ID_TEST);
  assert.equal(resultado, null);
});

test("getMinutosRespuestaTutor: mediana con cantidad IMPAR de muestras (10, 20, 30 -> 20)", async () => {
  await pool.query(
    "INSERT INTO respuestas_tutor (tutor_id, conversacion_id, minutos_respuesta) VALUES (?, 1, 10), (?, 1, 20), (?, 1, 30)",
    [TUTOR_ID_TEST, TUTOR_ID_TEST, TUTOR_ID_TEST],
  );
  const resultado = await getMinutosRespuestaTutor(TUTOR_ID_TEST);
  assert.equal(resultado, 20);
  await pool.query("DELETE FROM respuestas_tutor WHERE tutor_id = ?", [TUTOR_ID_TEST]);
});

test("getMinutosRespuestaTutor: mediana con cantidad PAR de muestras (10, 20, 30, 40 -> 25)", async () => {
  await pool.query(
    "INSERT INTO respuestas_tutor (tutor_id, conversacion_id, minutos_respuesta) VALUES (?, 1, 10), (?, 1, 20), (?, 1, 30), (?, 1, 40)",
    [TUTOR_ID_TEST, TUTOR_ID_TEST, TUTOR_ID_TEST, TUTOR_ID_TEST],
  );
  const resultado = await getMinutosRespuestaTutor(TUTOR_ID_TEST);
  assert.equal(resultado, 25);
  await pool.query("DELETE FROM respuestas_tutor WHERE tutor_id = ?", [TUTOR_ID_TEST]);
});

test("getMinutosRespuestaTutor: descarta outliers >1440 min (24h)", async () => {
  await pool.query(
    "INSERT INTO respuestas_tutor (tutor_id, conversacion_id, minutos_respuesta) VALUES (?, 1, 10), (?, 1, 5000)",
    [TUTOR_ID_TEST, TUTOR_ID_TEST],
  );
  const resultado = await getMinutosRespuestaTutor(TUTOR_ID_TEST);
  assert.equal(resultado, 10, "el outlier de 5000 min no debería contar en la mediana");
});
