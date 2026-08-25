// Puerto exacto de app/helpers/geoip.php::geoip_lookup() — mismo servicio externo
// (ip-api.com, gratuito, sin API key), mismos campos, mismo timeout corto (2s) y el mismo
// criterio de "no llamar al API" para loopback/rangos privados.
export interface GeoInfo {
  pais: string | null;
  ciudad: string | null;
}

const NULO: GeoInfo = { pais: null, ciudad: null };
const RANGO_PRIVADO = /^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)/;

export async function geoipLookup(ip: string): Promise<GeoInfo> {
  if (ip === "127.0.0.1" || ip === "::1") return NULO;
  if (RANGO_PRIVADO.test(ip)) return NULO;

  try {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 2000);
    const res = await fetch(`http://ip-api.com/json/${encodeURIComponent(ip)}?fields=status,country,city`, { signal: controller.signal });
    clearTimeout(timeout);
    if (!res.ok) return NULO;

    const data = (await res.json()) as { status?: string; country?: string; city?: string };
    if (data.status !== "success") return NULO;

    return {
      pais: data.country ? data.country.slice(0, 60) : null,
      ciudad: data.city ? data.city.slice(0, 80) : null,
    };
  } catch {
    return NULO;
  }
}
