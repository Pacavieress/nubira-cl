// Puerto exacto de abreviar_institucion() en app/helpers/institucion.php:7-33 — mismo
// diccionario, mismo orden (foreach de un objeto JS preserva orden de inserción de claves
// string, igual que el array asociativo de PHP), mismo truncado final por ancho.
const DICCIONARIO_INSTITUCIONES: Array<[string, string]> = [
  ["Economía y Negocios", "FEN U. Chile"],
  ["ECONOMíA Y NEGOCIOS", "FEN U. Chile"],
  ["Servicio Local de Educ", "SLEP"],
  ["SERVICIO LOCAL DE EDUC", "SLEP"],
  ["Santísima Concepci", "UCSC"],
  ["SANTíSIMA CONCEPCI", "UCSC"],
  ["Santisima Concepci", "UCSC"],
  ["Konrad Lorenz", "Konrad Lorenz"],
  ["Universidad Andr", "UNAB"],
  ["Universidad Nac", "UNAB"],
  ["Católica de Valpara", "PUCV"],
  ["CATóLICA DE VALPARA", "PUCV"],
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

function strimwidth(texto: string, maxLen: number): string {
  if (texto.length <= maxLen) return texto;
  return texto.slice(0, Math.max(0, maxLen - 3)) + "...";
}

// escapar (parámetro `$escapar` de PHP) no se porta: acá el valor viaja directo a una
// columna de BD (mismo caso que publicar_servicio.php:86, que ya llama con escapar=false),
// nunca a HTML — no hay superficie de XSS que mitigar en este punto.
export function abreviarInstitucion(instRaw: string, maxLen = 22): string {
  if (!instRaw) return "";
  let institucionLimpia = instRaw;

  for (const [clave, valor] of DICCIONARIO_INSTITUCIONES) {
    if (institucionLimpia.toLowerCase().includes(clave.toLowerCase())) {
      if (valor.length <= 6) {
        institucionLimpia = valor;
      } else {
        const re = new RegExp(clave.replace(/[.*+?^${}()|[\]\\]/g, "\\$&"), "i");
        institucionLimpia = institucionLimpia.replace(re, valor);
      }
      break;
    }
  }

  if (institucionLimpia.toLowerCase().startsWith("universidad ")) {
    institucionLimpia = "U. " + institucionLimpia.slice(12);
  }

  return strimwidth(institucionLimpia, maxLen);
}
