// Puerto exacto de busqueda.php:262-301 — tokenizador multi-palabra con recorte de
// plurales en español, documentado como "Key Pattern" del proyecto en CLAUDE.md
// ("Spanish plural-stripping for search"). Antes de esta pieza, /api/servicios?q= y
// /api/apuntes?q= (y sus contrapartes PHP reales, cargar_servicios.php/cargar_apuntes.php)
// solo hacían un LIKE de substring simple — la búsqueda tokenizada era exclusiva de
// busqueda.php. Decisión de producto: unificar en una sola implementación (la más
// completa) en vez de mantener 2 motores de "buscar por texto" en el codebase.
export function raicesBusqueda(q: string): string[] {
  const palabras = q.trim().split(/\s+/u).filter(Boolean);
  const palabrasValidas = palabras.filter((p) => Array.from(p).length >= 3);
  const base = palabrasValidas.length > 0 ? palabrasValidas : palabras;

  return base.map((palabra) => {
    const chars = Array.from(palabra);
    const largo = chars.length;
    let raiz = palabra;

    if (largo > 4 && chars.slice(-2).join("") === "es") {
      raiz = chars.slice(0, -2).join(""); // clases -> clas, peces -> pec
    } else if (largo > 3 && chars.slice(-1).join("") === "s") {
      raiz = chars.slice(0, -1).join(""); // matemáticas -> matemática
    }

    if (Array.from(raiz).length < 3) raiz = palabra;
    return raiz;
  });
}

// Puerto exacto de busqueda.php:252 — activa el refuerzo especial de PAES (coincidencia
// exacta de "paes", no depende de la raíz recortada del loop de arriba, que para "paes"
// cortaría a "pae" — fragil e implícito).
export function esBusquedaPaes(q: string): boolean {
  return q.toLowerCase().includes("paes");
}

// Arma el bloque "(raíz1 en campoA OR campoB...) AND (raíz2 en campoA OR campoB...) AND ..."
// — puerto de busqueda.php:288-296/299 (el AND entre bloques, el OR dentro de cada bloque).
// Push-ea los valores de los placeholders a `params` en el mismo orden en que aparecen en
// el SQL devuelto — el caller debe pasar el MISMO array que usará en la query final.
export function construirCondicionTexto(
  q: string,
  campos: string[],
  params: Array<string | number>,
): string {
  const bloques = raicesBusqueda(q).map((raiz) => {
    const like = `%${raiz}%`;
    campos.forEach(() => params.push(like));
    return `(${campos.map((campo) => `${campo} LIKE ?`).join(" OR ")})`;
  });
  return bloques.join(" AND ");
}
