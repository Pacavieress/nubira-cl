import { redirect } from "next/navigation";
import { getAdminVideos, type EstadoVideo } from "@/lib/api";
import { getSesion } from "@/lib/sesion";
import { Header } from "@/components/Header";

interface VideosPageProps {
  searchParams: Promise<{ filtro?: string }>;
}

function normalizarFiltro(v: string | undefined): EstadoVideo {
  return v === "aprobado" || v === "rechazado" || v === "todos" ? v : "pendiente";
}

function perfilUrl(alumnoId: number): string {
  const b64 = Buffer.from(`${alumnoId}-nubira_secreto`).toString("base64").replace(/=+$/, "");
  return `/perfil/${b64}`;
}

const BADGES: Record<string, string> = {
  pendiente: "bg-amber-50 text-amber-700 border-amber-200",
  aprobado: "bg-green-50 text-green-700 border-green-200",
  rechazado: "bg-red-50 text-red-700 border-red-200",
};

// Puerto de admin_videos.php ("Videos de presentación") — 100% lectura. Ver la nota de
// alcance en server/src/modules/adminVideos/adminVideos.types.ts (aprobar/rechazar envían
// correo + push real, excluidos). Sin Client Component: tabs vía <a href>, <video controls>
// nativo, sin ninguna interacción que requiera JS.
export default async function AdminVideosPage({ searchParams }: VideosPageProps) {
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const sesion = await getSesion();

  if (!sesion || !sesion.esAdmin) {
    redirect(`${phpSiteUrl}/login`);
  }

  const { filtro: filtroParam } = await searchParams;
  const filtro = normalizarFiltro(filtroParam);
  const { videos, totalPendientes } = await getAdminVideos(filtro);

  const tabs: { key: EstadoVideo; label: string }[] = [
    { key: "pendiente", label: "Pendientes" },
    { key: "aprobado", label: "Aprobados" },
    { key: "rechazado", label: "Rechazados" },
    { key: "todos", label: "Todos" },
  ];

  return (
    <>
      <Header titulo="Videos" />
      <main className="pt-20 pb-28 md:pb-16 px-4 md:px-10 lg:ml-64 max-w-[1600px] mx-auto space-y-6">
        <div className="flex flex-col md:flex-row md:items-end justify-between gap-4">
          <div>
            <h1 className="text-xl md:text-2xl font-medium text-[#222222] tracking-[-0.01em] flex items-center gap-3">
              Videos de presentación
              {totalPendientes > 0 && (
                <span className="bg-amber-100 text-amber-700 text-sm font-bold px-2.5 py-0.5 rounded-full border border-amber-200">
                  {totalPendientes} pendiente{totalPendientes !== 1 ? "s" : ""}
                </span>
              )}
            </h1>
            <p className="text-sm text-gray-500 mt-1">Modera los videos antes de que sean visibles en los perfiles.</p>
          </div>
          <div className="bg-white p-1 rounded-xl border border-gray-200 flex gap-1 flex-wrap">
            {tabs.map((t) => (
              <a
                key={t.key}
                href={`?filtro=${t.key}`}
                className={`px-4 py-2 rounded-lg text-sm font-bold transition-all ${filtro === t.key ? "bg-blue-50 text-[#54A6D8]" : "text-gray-500 hover:bg-gray-50"}`}
              >
                {t.label}
              </a>
            ))}
          </div>
        </div>

        {videos.length === 0 ? (
          <div className="bg-white border border-dashed border-gray-200 rounded-2xl p-16 text-center">
            <p className="text-gray-400 text-sm font-medium">No hay videos en este estado.</p>
          </div>
        ) : (
          <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
            {videos.map((v) => {
              const videoUrl = `${phpSiteUrl}/upload/videos_servicios/${encodeURIComponent(v.videoPath)}`;
              const fotoTutor = v.tutorFotoPerfil
                ? `${phpSiteUrl}/app/perfil/fotos/${encodeURIComponent(v.tutorFotoPerfil)}`
                : `https://ui-avatars.com/api/?name=${encodeURIComponent(v.tutorNombre)}&background=f1f5f9&color=64748b&size=128`;

              return (
                <div key={v.id} className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden flex flex-col">
                  <div className="flex gap-4 p-4">
                    <div className="shrink-0 w-[110px]">
                      <div className="aspect-[9/16] bg-black rounded-xl overflow-hidden">
                        {/* eslint-disable-next-line jsx-a11y/media-has-caption */}
                        <video src={videoUrl} className="w-full h-full object-cover" controls preload="metadata" playsInline />
                      </div>
                    </div>

                    <div className="flex-1 min-w-0 space-y-2 pt-1">
                      <span className={`inline-block text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full border ${BADGES[v.videoEstado]}`}>
                        {v.videoEstado === "pendiente" ? "Pendiente" : v.videoEstado === "aprobado" ? "Aprobado" : "Rechazado"}
                      </span>

                      <div>
                        <p className="font-bold text-gray-900 text-sm leading-tight line-clamp-2">{v.titulo}</p>
                        <p className="text-xs text-gray-400 mt-0.5">{v.materia || v.categoria || ""}</p>
                        <p className="text-xs font-bold text-[#54A6D8] mt-1">{v.precio.toLocaleString("es-CL")} CLP</p>
                      </div>

                      <a href={`${phpSiteUrl}${perfilUrl(v.alumnoId)}`} target="_blank" rel="noopener noreferrer" className="flex items-center gap-2 group">
                        {/* eslint-disable-next-line @next/next/no-img-element */}
                        <img src={fotoTutor} alt="" className="w-7 h-7 rounded-full object-cover border border-gray-100 shrink-0" />
                        <div className="min-w-0">
                          <p className="text-xs font-bold text-gray-700 truncate group-hover:text-[#54A6D8] transition">{v.tutorNombre}</p>
                          <p className="text-[10px] text-gray-400 truncate">{v.tutorCorreo}</p>
                        </div>
                      </a>

                      {v.videoSubidoEn && (
                        <p className="text-[10px] text-gray-400">
                          Subido el {new Date(v.videoSubidoEn).toLocaleDateString("es-CL", { day: "2-digit", month: "2-digit", year: "numeric" })}{" "}
                          {new Date(v.videoSubidoEn).toLocaleTimeString("es-CL", { hour: "2-digit", minute: "2-digit" })}
                        </p>
                      )}

                      {v.videoEstado === "rechazado" && v.videoMotivoRechazo && (
                        <p className="text-[11px] text-red-600 bg-red-50 rounded-lg px-2 py-1 border border-red-100 line-clamp-2">{v.videoMotivoRechazo}</p>
                      )}
                    </div>
                  </div>

                  <div className="border-t border-gray-50 px-4 py-3 flex gap-2">
                    <a
                      href={videoUrl}
                      download
                      target="_blank"
                      rel="noopener noreferrer"
                      className="flex items-center gap-1.5 px-3 py-2 bg-gray-50 hover:bg-gray-100 text-gray-600 rounded-lg text-xs font-bold border border-gray-200 transition"
                    >
                      Descargar
                    </a>
                    <a
                      href={`${phpSiteUrl}/admin/videos?filtro=pendiente`}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="flex-1 text-center px-3 py-2 bg-gray-100 text-gray-500 rounded-lg text-xs font-bold border border-gray-200"
                      title="Aprobar/rechazar (envía correo real) en el sitio real"
                    >
                      Aprobar / Rechazar en el sitio real
                    </a>
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </main>
    </>
  );
}
