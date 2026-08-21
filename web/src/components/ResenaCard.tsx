import { inicial } from "@/lib/texto";

// Extraído de tutores/[id]/page.tsx (perfil.php:758-...) — reutilizado también en
// mi-perfil/page.tsx, ambas vistas muestran reseñas con el mismo diseño.
export function ResenaCard({
  resena,
}: {
  resena: { id: number; calificacion: number; comentario: string | null; fecha: string; evaluador: { nombre: string | null; fotoUrl: string } };
}) {
  return (
    <div className="bg-gray-50 border border-[#f0f0f0] p-4 rounded-xl">
      <div className="flex items-center gap-3 mb-2">
        <div className="w-8 h-8 rounded-full bg-white border border-[#f0f0f0] overflow-hidden">
          {resena.evaluador.fotoUrl.startsWith("https://ui-avatars.com") ? (
            <div className="w-full h-full flex items-center justify-center text-[#54A6D8] bg-blue-50 font-bold text-xs">
              {inicial(resena.evaluador.nombre)}
            </div>
          ) : (
            /* eslint-disable-next-line @next/next/no-img-element -- foto de usuario dinámica */
            <img src={resena.evaluador.fotoUrl} alt={resena.evaluador.nombre ?? "Usuario"} className="w-full h-full object-cover" />
          )}
        </div>
        <div>
          <p className="font-medium tracking-[-0.01em] text-xs text-[#222222]">{resena.evaluador.nombre ?? "Usuario"}</p>
          <p className="text-[10px] text-gray-400 font-normal">
            {new Date(resena.fecha).toLocaleDateString("es-CL", { day: "2-digit", month: "short", year: "numeric" })}
          </p>
        </div>
      </div>
      <div className="flex text-yellow-400 text-[10px] mb-2">
        {Array.from({ length: 5 }, (_, i) => (
          <span key={i}>{i < resena.calificacion ? "★" : "☆"}</span>
        ))}
      </div>
      {resena.comentario && <p className="text-gray-600 text-xs font-normal leading-relaxed">{resena.comentario}</p>}
    </div>
  );
}
