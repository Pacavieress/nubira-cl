// Puerto de admin_dominios.php — gestor de instituciones/dominios de correo permitidos
// (tabla dominios_permitidos, ya usada como dependencia de lectura en TODO el resto del
// sitio: compartir, servicios, apuntes, tutores, perfil — ver LEFT JOIN dominios_permitidos
// en esos módulos). Esta es la primera pieza que la ESCRIBE.
export interface DominioPermitido {
  id: number;
  dominio: string;
  institucion: string;
  totalUsuarios: number;
}
