import type { PerfilCuenta, PerfilCuentaRow } from "./configurarCuenta.types.js";

export function mapPerfilCuentaRow(row: PerfilCuentaRow): PerfilCuenta {
  return {
    nombre: row.nombre,
    correo: row.correo,
    carrera: row.carrera,
    tipo: row.tipo,
    bio: row.bio,
    universidad: row.universidad,
    anioEgreso: row.anio_egreso,
    aniosExperiencia: row.anios_experiencia,
  };
}

// Equivalente de strip_tags() de PHP (editar_datos.php:84/86/87: carrera/bio/universidad
// pasan por strip_tags antes de guardarse). No es un reemplazo de sanitización XSS real —
// React ya escapa todo al renderizar — es para igualar qué queda guardado en la BD frente
// al mismo dato visto desde perfil.php/ver_apunte.php (que si son vulnerables a HTML crudo
// sin escapar en algún punto, dependen de que esto ya venga limpio, igual que hoy).
export function stripTags(valor: string): string {
  return valor.replace(/<[^>]*>/g, "");
}
