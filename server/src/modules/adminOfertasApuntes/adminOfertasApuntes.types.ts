// Puerto de admin_ofertas_apuntes.php — panel "Centro de Promos (Apuntes)". A diferencia de
// otros paneles de esta ronda, acá SÍ se portan las 3 acciones de escritura completas: son
// UPDATE puros sobre `apuntes` (precio/promo_gratis/promo_limite/promo_contador), sin correo,
// sin push, sin DELETE — mismo nivel de riesgo que adminConfigPrecios (ya portado con sus
// mutaciones completas).
export interface OfertaApunte {
  id: number;
  titulo: string;
  tutorNombre: string;
  precio: number;
  promoGratis: boolean;
  promoLimite: number;
  promoContador: number;
}
