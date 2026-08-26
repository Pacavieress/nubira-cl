import { test, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";

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

interface LandingBody {
  categoria: string;
  seo: { titulo: string; descripcion: string; h1: string; intro: string | null; noindex: boolean };
  total: number;
  servicios: { id: number; categoria: string }[];
  faqs: { pregunta: string; respuesta: string }[];
}

test("GET /api/landings/clases/:slug inexistente devuelve 404", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/landings/clases/no-existe-esta-categoria`);
    assert.equal(res.status, 404);
  } finally {
    await close();
  }
});

test("GET /api/landings/clases/matematicas: servicios reales, todos con categoria='Matemáticas'", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/landings/clases/matematicas`);
    const body = (await res.json()) as LandingBody;
    assert.equal(res.status, 200);
    assert.equal(body.categoria, "Matemáticas");
    assert.ok(body.total > 0, "debería haber servicios reales de Matemáticas en la BD local");
    assert.equal(body.servicios.length, body.total);
    for (const s of body.servicios) assert.equal(s.categoria, "Matemáticas");
  } finally {
    await close();
  }
});

// Puerto de landing_categoria.php:81-89: "Cálculo" no es un valor real de
// servicios.categoria (no está en CATEGORIAS_VALIDAS) — el match es por filtro_titulo
// (LIKE '%calcul%' desde seo_categorias_contenido), no por categoria exacta.
test("GET /api/landings/clases/calculo: usa filtro_titulo (LIKE), no categoria exacta", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/landings/clases/calculo`);
    const body = (await res.json()) as LandingBody;
    assert.equal(res.status, 200);
    assert.equal(body.categoria, "Cálculo");
    assert.ok(body.total > 0, "debería haber servicios reales con 'calcul' en el título");
    for (const s of body.servicios) assert.ok(s.categoria !== "Cálculo", "no existe la categoría literal 'Cálculo' en servicios.categoria");
  } finally {
    await close();
  }
});

test("GET /api/landings/clases/paes: refuerzo amplio (LIKE + es_paes=1), no categoria exacta", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/landings/clases/paes`);
    const body = (await res.json()) as LandingBody;
    assert.equal(res.status, 200);
    assert.equal(body.categoria, "PAES");
    assert.ok(body.total > 0, "debería haber servicios reales relacionados con PAES en la BD local");
  } finally {
    await close();
  }
});

test("GET /api/landings/clases/tesis: FAQs solo pobladas para Tesis (único caso real hoy)", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/landings/clases/tesis`);
    const body = (await res.json()) as LandingBody;
    assert.equal(res.status, 200);
    assert.equal(body.faqs.length, 4);
  } finally {
    await close();
  }
});

test("GET /api/landings/clases/matematicas: sin FAQs (no está en el catálogo curado)", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/landings/clases/matematicas`);
    const body = (await res.json()) as LandingBody;
    assert.equal(res.status, 200);
    assert.equal(body.faqs.length, 0);
  } finally {
    await close();
  }
});

// Puerto de landing_categoria.php:122: noindex = (total < 3 || !indexable) — verificado
// con una categoría real que tiene fila indexable=1 en seo_categorias_contenido pero
// menos de 3 servicios reales (Física, confirmado 1 servicio en la BD local hoy).
test("GET /api/landings/clases/fisica: noindex=true por total<3 aunque indexable=1", async (t) => {
  const { url, close } = listen();
  const res = await fetch(`${url}/api/landings/clases/fisica`);
  const body = (await res.json()) as LandingBody;
  try {
    assert.equal(res.status, 200);
    if (body.total >= 3) {
      t.skip("la BD local ya tiene >=3 servicios de Física — este caso límite ya no aplica hoy");
      return;
    }
    assert.equal(body.seo.noindex, true);
  } finally {
    await close();
  }
});

// Puerto del default opt-in: sin fila en seo_categorias_contenido, indexable=false
// siempre, sin importar cuántos resultados reales haya.
test("GET /api/landings/clases/lenguaje: sin fila en seo_categorias_contenido -> noindex=true, copy genérico", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/landings/clases/lenguaje`);
    const body = (await res.json()) as LandingBody;
    assert.equal(res.status, 200);
    assert.equal(body.seo.noindex, true);
    assert.equal(body.seo.h1, "Clases de Lenguaje en Chile");
  } finally {
    await close();
  }
});

// ============================================================================
// Grupo C (26/08/2026) — tipo=apuntes, cerrando la asimetría con tipo=clases (mismo
// archivo PHP real, landing_categoria.php, ambas ramas). apuntes.categoria en la BD local
// real está en minúsculas ('derecho', 'paes') a diferencia de servicios.categoria
// ('Matemáticas') — confirmado que igual matchea contra el nombre canónico ('Derecho') por
// collation case-insensitive, mismo comportamiento que tendría la query real en MySQL.
// ============================================================================

interface LandingApuntesBody {
  categoria: string;
  seo: { titulo: string; descripcion: string; h1: string; intro: string | null; noindex: boolean };
  total: number;
  apuntes: { id: number; titulo: string }[];
  faqs: { pregunta: string; respuesta: string }[];
}

test("GET /api/landings/apuntes/:slug inexistente devuelve 404", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/landings/apuntes/no-existe-esta-categoria`);
    assert.equal(res.status, 404);
  } finally {
    await close();
  }
});

test("GET /api/landings/apuntes/derecho: apunte real, categoria en minúscula en BD matchea igual (Derecho)", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/landings/apuntes/derecho`);
    const body = (await res.json()) as LandingApuntesBody;
    assert.equal(res.status, 200);
    assert.equal(body.categoria, "Derecho");
    assert.ok(body.total >= 1, "debería haber al menos 1 apunte real de Derecho en la BD local");
    assert.equal(body.apuntes.length, body.total);
  } finally {
    await close();
  }
});

test("GET /api/landings/apuntes/paes: refuerzo amplio (LIKE + nivel_academico='paes'), no categoria exacta", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/landings/apuntes/paes`);
    const body = (await res.json()) as LandingApuntesBody;
    assert.equal(res.status, 200);
    assert.equal(body.categoria, "PAES");
    assert.ok(body.total > 0, "debería haber apuntes reales relacionados con PAES en la BD local");
  } finally {
    await close();
  }
});

test("GET /api/landings/apuntes/matematicas: sin apuntes reales en esa categoría -> total=0, estado vacío coherente", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/landings/apuntes/matematicas`);
    const body = (await res.json()) as LandingApuntesBody;
    assert.equal(res.status, 200);
    assert.equal(body.categoria, "Matemáticas");
    assert.equal(body.total, 0);
    assert.equal(body.apuntes.length, 0);
    assert.equal(body.seo.noindex, true, "total<3 -> siempre noindex");
  } finally {
    await close();
  }
});

test("GET /api/landings/apuntes/derecho: copy usa 'Apuntes'/'apuntes y resúmenes', no el texto de 'Clases'", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/landings/apuntes/derecho`);
    const body = (await res.json()) as LandingApuntesBody;
    assert.equal(res.status, 200);
    assert.equal(body.seo.h1, "Apuntes de Derecho en Chile");
    assert.equal(body.seo.titulo, "Apuntes de Derecho universidad Chile | Nubira");
    assert.ok(body.seo.descripcion.includes("apuntes y resúmenes"));
    assert.ok(!body.seo.descripcion.includes("clases particulares"));
  } finally {
    await close();
  }
});
