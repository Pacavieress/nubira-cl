// Puerto de admin_cuentas.php — revisión de cuentas bancarias configuradas por los
// usuarios (100% lectura en el PHP real: sin ninguna acción POST, solo búsqueda/filtro/
// orden client-side y un toggle de query param). El número de cuenta y RUT completos SÍ
// se exponen sin enmascarar acá — mismo comportamiento que el PHP real (el admin ya tiene
// ese acceso hoy para poder procesar retiros/reclamos), no una exposición nueva.
export interface CuentaBancariaAdmin {
  idUsuario: number;
  nombre: string;
  correo: string;
  bloqueado: boolean;
  visible: boolean;
  banco: string;
  tipoCuenta: string;
  numeroCuenta: string;
  titularNombre: string;
  rut: string;
  fechaConfiguracion: string;
}
