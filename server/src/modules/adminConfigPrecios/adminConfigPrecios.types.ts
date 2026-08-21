// Puerto de admin_config_precios.php — configuración global (tabla config, key-value),
// solo 2 claves: precio base de desbloqueo de contacto, y una promo "todo gratis hasta
// fecha X". Sin acciones destructivas (solo UPDATE, nunca DELETE).
export interface ConfigPrecios {
  precioDesbloqueoContacto: number;
  ofertaGratisHasta: string | null;
  ofertaVigente: boolean;
}
