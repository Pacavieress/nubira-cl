import { AvatarTutor } from "./AvatarTutor";

// Puerto exacto de app/componentes/overlay_card_servicio.php (tamaño 'lg') — compartido
// entre ServicioCard.tsx (grid) y ServicioCardCarrusel.tsx (home), igual que el partial
// real es compartido por card_servicio_grid.php Y vitrina.php.
export function OverlayServicio({
  prefijo,
  categoria,
  fotoUrl,
  nombre,
}: {
  prefijo: string;
  categoria: string;
  fotoUrl: string | null;
  nombre: string;
}) {
  return (
    <>
      <div
        className="absolute inset-0 z-[5] pointer-events-none"
        style={{
          background:
            "linear-gradient(to bottom, rgba(0,0,0,0.42) 0%, rgba(0,0,0,0.08) 32%, rgba(0,0,0,0) 52%, rgba(0,0,0,0.10) 70%, rgba(0,0,0,0.48) 100%)",
        }}
      />
      <div className="absolute top-3 left-3 z-10 pr-2 leading-tight" style={{ maxWidth: "70%" }}>
        {prefijo && (
          <div
            className="text-white text-xs md:text-sm font-medium opacity-90"
            style={{ textShadow: "0 1px 2px rgba(0,0,0,0.5)" }}
          >
            {prefijo}
          </div>
        )}
        <div className="text-white text-base md:text-lg font-bold" style={{ textShadow: "0 1px 3px rgba(0,0,0,0.6)" }}>
          {categoria}
        </div>
      </div>
      <div
        className="absolute bottom-3 left-3 z-10 pr-2 flex items-center gap-2 text-white text-base md:text-lg font-bold"
        style={{ maxWidth: "80%", textShadow: "0 1px 3px rgba(0,0,0,0.6)" }}
      >
        {fotoUrl && (
          <AvatarTutor
            src={fotoUrl}
            nombre={nombre}
            className="w-10 h-10 md:w-12 md:h-12 rounded-full object-cover ring-1 ring-white/40 shadow-[0_1px_3px_rgba(0,0,0,0.15)]"
          />
        )}
        <span className="truncate min-w-0">{nombre}</span>
      </div>
    </>
  );
}
