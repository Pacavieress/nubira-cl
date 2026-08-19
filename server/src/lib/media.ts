// Helpers de resolución de URLs de media, compartidos entre el módulo servicios y tutores
// (extraído de servicios.mapper.ts en Fase 6 para no duplicarlo en tutores.mapper.ts).

const NB_BANCO_WEB = "/upload/banco/";
const NB_SERVICIOS_WEB = "/upload/servicios/";
const NB_PLACEHOLDER = "placeholder";
const NB_PERFIL_FOTOS_WEB = "/app/perfil/fotos/";

// Mismo criterio que app/componentes/render_card.php:85-87: si el tutor no tiene
// foto_perfil, cae a un avatar generado externamente con su nombre (no un placeholder
// local) — se replica tal cual, no una decisión nueva de esta fase.
export function resolverFotoTutor(fotoPerfil: string | null, nombreTutor: string | null): string {
  if (fotoPerfil) return `${NB_PERFIL_FOTOS_WEB}${fotoPerfil}`;
  const nombre = encodeURIComponent(nombreTutor ?? "");
  return `https://ui-avatars.com/api/?name=${nombre}&background=54A6D8&color=fff&size=128&bold=true`;
}

function baseName(archivo: string): string {
  const idx = archivo.lastIndexOf(".");
  return idx === -1 ? archivo : archivo.slice(0, idx);
}

export interface PortadaVariantes {
  thumb: string;
  card: string;
  main: string;
}

// Simplificación consciente (Fase 4, decisión 2d ya aprobada): construye la URL esperada
// del pipeline de 3 tamaños SIN verificar existencia en filesystem, a diferencia de
// app/helpers/imagen_servicio.php (que sí valida is_file() con fallback en cascada).
// Node no debe asumir que comparte filesystem con PHP — hoy es cierto por coincidencia
// del entorno local (mismo repo/máquina), no por diseño; esa asunción se rompería si
// Node y PHP terminan en servidores distintos (decisión de hosting todavía pendiente).
// Riesgo aceptado: alguna imagen legacy previa al pipeline podría devolver una URL rota.
export function resolverPortada(bancoArchivo: string | null, imagenLegacy: string | null): PortadaVariantes {
  let webDir = NB_BANCO_WEB;
  let archivo = bancoArchivo;

  if (!archivo) {
    if (imagenLegacy) {
      webDir = NB_SERVICIOS_WEB;
      archivo = imagenLegacy;
    } else {
      archivo = NB_PLACEHOLDER;
    }
  }

  const base = baseName(archivo);
  return {
    thumb: `${webDir}${base}_thumb.webp`,
    card: `${webDir}${base}_card.webp`,
    main: `${webDir}${base}.webp`,
  };
}
