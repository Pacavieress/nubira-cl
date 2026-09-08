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

// Puerto exacto de app/helpers/institucion.php — mismo diccionario (colapsando las
// variantes de casing del original, redundantes porque el match acá también es
// case-insensitive), misma regla de reemplazo total-vs-parcial (un valor de reemplazo de
// <=6 caracteres sustituye TODA la cadena, uno más largo solo la porción matcheada) y el
// mismo recorte final (mb_strimwidth: el total, INCLUYENDO "...", nunca supera maxLen).
// Encontrado auditando /busqueda y /servicios contra el PHP real: institucion_tutor()
// nunca se había portado, así que toda card de servicio mostraba el nombre completo sin
// abreviar (p. ej. "UNIVERSIDAD DE SANTIAGO DE CH..." en vez de "USACH").
const DICCIONARIO_INSTITUCION: Array<[string, string]> = [
  ["Economía y Negocios", "FEN U. Chile"],
  ["Servicio Local de Educ", "SLEP"],
  ["Santísima Concepci", "UCSC"],
  ["Santisima Concepci", "UCSC"],
  ["Konrad Lorenz", "Konrad Lorenz"],
  ["Universidad Andr", "UNAB"],
  ["Universidad Nac", "UNAB"],
  ["Católica de Valpara", "PUCV"],
  ["Catolica de Valpara", "PUCV"],
  ["Pontificia Universidad Cat", "PUC"],
  ["Universidad de Santiago", "USACH"],
  ["Universidad de Concepci", "UdeC"],
  ["Universidad T", "USM"],
  ["Federico Santa Mar", "USM"],
  ["Adolfo Ib", "UAI"],
  ["Universidad de Chile", "U. de Chile"],
  ["Universidad del B", "UBB"],
  ["Bío Bío", "UBB"],
  ["Bio Bio", "UBB"],
  ["Instituto Profesional", "IP"],
  ["Centro de Formación Técnica", "CFT"],
  ["iacc", "IACC"],
];

// Puerto de mb_strimwidth($s, 0, $maxLen, '...') — el total devuelto (marcador incluido)
// nunca supera maxLen, a diferencia de un slice+concat ingenuo.
function recortarConMarcador(s: string, maxLen: number, marcador = "..."): string {
  if (s.length <= maxLen) return s;
  return s.slice(0, Math.max(0, maxLen - marcador.length)) + marcador;
}

// Puerto exacto de abreviar_institucion() — cadena vacía si no hay institución (a
// diferencia de institucionTutor(), que cae a "Particular").
export function abreviarInstitucion(instRaw: string | null | undefined, maxLen = 22): string {
  if (!instRaw) return "";
  let inst = instRaw;
  for (const [clave, valor] of DICCIONARIO_INSTITUCION) {
    const idx = inst.toLowerCase().indexOf(clave.toLowerCase());
    if (idx !== -1) {
      inst = valor.length <= 6 ? valor : inst.slice(0, idx) + valor + inst.slice(idx + clave.length);
      break;
    }
  }
  if (inst.toLowerCase().startsWith("universidad ")) {
    inst = `U. ${inst.slice(12)}`;
  }
  return recortarConMarcador(inst, maxLen);
}

// Puerto exacto de institucion_tutor() — usada en las cards de servicios (siempre
// abreviada, fallback "Particular" si no hay institución real).
export function institucionTutor(instRaw: string | null | undefined, maxLen = 22): string {
  const raw = (instRaw ?? "").trim();
  if (raw === "") return "Particular";
  return abreviarInstitucion(raw, maxLen);
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
