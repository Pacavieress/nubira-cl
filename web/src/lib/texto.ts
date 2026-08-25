// Puerto de la lógica de abreviación de nombre repetida en varios lugares de PHP
// (card_servicio_grid.php:87-95, detalle_servicio.php:792-798) — "Sofía Valentina C."
// se abrevia a "Sofía C.". Se extrae acá porque web/ la necesita en 2 lugares
// (ServicioCard y la página de detalle) y no tiene sentido triplicarla.
export function abreviarNombre(nombreCompleto: string | null): string {
  const partes = (nombreCompleto ?? "").trim().split(/\s+/).filter(Boolean);
  if (partes.length === 0) return "Profesor";
  const primero = partes[0]!.charAt(0).toUpperCase() + partes[0]!.slice(1).toLowerCase();
  if (partes.length >= 2) {
    const ultimo = partes[partes.length - 1]!;
    return `${primero} ${ultimo.charAt(0).toUpperCase()}.`;
  }
  return primero;
}

export function inicial(nombreCompleto: string | null): string {
  const texto = (nombreCompleto ?? "").trim();
  return texto ? texto.charAt(0).toUpperCase() : "U";
}

// Puerto acotado de html_entity_decode($s, ENT_QUOTES, 'UTF-8') — solo entidades numéricas
// (&#39; &#x27;) y las 5 básicas HTML/XML (&amp; &lt; &gt; &quot; &apos;), NO la tabla
// completa de ~250 entidades nombradas de HTML4 que soporta PHP. Cubre el caso real
// encontrado en producción (apóstrofes guardados como &#039;, ver servicio id 8893) sin
// traer una librería nueva solo para esto — documentado como gap si algún texto real usara
// una entidad nombrada fuera de esas 5 (ej. &eacute;), que hoy quedaría sin decodificar.
function decodeEntidadesHtml(texto: string): string {
  return texto
    .replace(/&#x([0-9a-f]+);/gi, (_, hex: string) => String.fromCodePoint(parseInt(hex, 16)))
    .replace(/&#(\d+);/g, (_, dec: string) => String.fromCodePoint(parseInt(dec, 10)))
    .replace(/&amp;/g, "&")
    .replace(/&lt;/g, "<")
    .replace(/&gt;/g, ">")
    .replace(/&quot;/g, '"')
    .replace(/&apos;/g, "'");
}

export interface DescripcionProcesada {
  corta: string;
  completa: string;
  esLarga: boolean;
}

// Puerto exacto de detalle_servicio.php:549-557: decodifica entidades, resuelve las
// alternativas aleatorias "(opción a|opción b)" que algunos tutores usan en su descripción
// (una elección distinta cada vez que se renderiza la página — confirmado con datos reales
// en producción, servicio id 8893), y trunca a 150 caracteres para la versión corta.
export function procesarDescripcionServicio(descripcionRaw: string | null): DescripcionProcesada {
  let texto = decodeEntidadesHtml((descripcionRaw ?? "").trim());
  texto = texto.replace(/\(([^)]+\|[^)]+)\)/g, (_, grupo: string) => {
    const opciones = grupo.split("|");
    return opciones[Math.floor(Math.random() * opciones.length)]!;
  });

  const esLarga = Array.from(texto).length > 150;
  const corta = esLarga ? Array.from(texto).slice(0, 150).join("") + "…" : texto;
  return { corta, completa: texto, esLarga };
}
