"use client";

// Puerto exacto del onerror de overlay_card_servicio.php:37-38 (el partial real que
// arma el avatar sobre la card). Bug real encontrado en vivo: cuando `foto_perfil` está
// poblado en la BD pero el archivo no existe en disco (gap real de datos, confirmado
// contra el filesystem — pasa incluso en el sitio PHP real si el archivo faltara ahí),
// <img src> sin fallback muestra el ícono de imagen rota. PHP degrada con gracia a un
// avatar generado; este componente replica exactamente ese comportamiento. Requiere
// "use client" (onError es un handler de JS) — por eso es su propio componente chico en
// vez de vivir inline en ServicioCard.tsx (Server Component), mismo criterio ya usado
// para Carrusel.tsx/Sidebar.tsx/BottomNav.tsx.
export function AvatarTutor({ src, nombre, className }: { src: string; nombre: string; className: string }) {
  return (
    <img
      src={src}
      alt={nombre}
      className={className}
      loading="lazy"
      onError={(e) => {
        const img = e.currentTarget;
        img.onerror = null;
        img.src = `https://ui-avatars.com/api/?name=${encodeURIComponent(nombre)}&background=54A6D8&color=fff&size=128`;
      }}
    />
  );
}
