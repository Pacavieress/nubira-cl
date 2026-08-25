import { redirect } from "next/navigation";
import { getAdminUsuarios, type FiltroRolUsuarios, type FiltroVerificadoUsuarios } from "@/lib/api";
import { getSesion } from "@/lib/sesion";
import { Header } from "@/components/Header";

interface UsuariosPageProps {
  searchParams: Promise<{ q?: string; rol?: string; verificado?: string; page?: string }>;
}

function normalizarRol(v: string | undefined): FiltroRolUsuarios {
  return v === "admin" || v === "alumno" ? v : "";
}
function normalizarVerificado(v: string | undefined): FiltroVerificadoUsuarios {
  return v === "si" || v === "no" ? v : "";
}

// Puerto de admin_usuarios.php ("Gestión de Usuarios") — deliberadamente 100% lectura (ver
// nota de alcance completa en server/src/modules/adminUsuarios/adminUsuarios.types.ts): las 6
// mutaciones del PHP real (banear, cambiar rol, editar datos, eliminar en cascada, suspender,
// levantar suspensión) actúan sobre cuentas reales y quedan fuera de este puerto — se portarán
// en una sesión aparte, con aprobación explícita por acción. Cada fila enlaza al perfil público
// y al panel PHP real (con el mismo filtro aplicado) para ejecutar cualquier acción hoy.
export default async function AdminUsuariosPage({ searchParams }: UsuariosPageProps) {
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const sesion = await getSesion();

  if (!sesion || !sesion.esAdmin) {
    redirect(`${phpSiteUrl}/login`);
  }

  const { q: qParam, rol: rolParam, verificado: verificadoParam, page: pageParam } = await searchParams;
  const q = (qParam ?? "").trim();
  const rol = normalizarRol(rolParam);
  const verificado = normalizarVerificado(verificadoParam);
  const page = Math.max(1, Number(pageParam) || 1);

  const { usuarios, totalPages, totalUsersGlobal } = await getAdminUsuarios({ q, rol, verificado, page });

  const qsBase = new URLSearchParams();
  if (q) qsBase.set("q", q);
  if (rol) qsBase.set("rol", rol);
  if (verificado) qsBase.set("verificado", verificado);

  return (
    <>
      <Header titulo="Usuarios" />
      <main className="pt-20 pb-28 md:pb-16 px-4 md:px-10 lg:ml-64 max-w-[1400px] mx-auto space-y-6">
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
          <div>
            <h1 className="text-xl md:text-2xl font-medium text-[#222222] tracking-[-0.01em]">Usuarios</h1>
            <p className="text-sm text-gray-500 mt-1">Gestión, bloqueo y auditoría de cuentas.</p>
          </div>
          <div className="bg-white px-4 py-2 rounded-xl border border-gray-200 shadow-sm">
            <p className="text-[10px] uppercase font-bold text-gray-400">Total</p>
            <p className="text-lg font-black text-gray-900 leading-none">{totalUsersGlobal.toLocaleString("es-CL")}</p>
          </div>
        </div>

        <form method="get" className="flex flex-col md:flex-row gap-2">
          <select
            name="rol"
            defaultValue={rol}
            className="bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm font-medium text-gray-700 focus:outline-none focus:ring-2 focus:ring-[#54A6D8]"
          >
            <option value="">Todos los roles</option>
            <option value="admin">Administradores</option>
            <option value="alumno">Alumnos</option>
          </select>
          <select
            name="verificado"
            defaultValue={verificado}
            className="bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm font-medium text-gray-700 focus:outline-none focus:ring-2 focus:ring-[#54A6D8]"
          >
            <option value="">Verificado: todos</option>
            <option value="si">Verificado: sí</option>
            <option value="no">Verificado: no</option>
          </select>
          <input
            type="text"
            name="q"
            defaultValue={q}
            placeholder="Buscar usuario..."
            className="flex-1 bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm font-medium text-gray-900 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-[#54A6D8]"
          />
          <button type="submit" className="bg-gradient-to-r from-sky-400 to-[#54A6D8] text-white px-4 py-2.5 rounded-xl text-sm font-bold shrink-0">
            Buscar
          </button>
        </form>

        <div className="bg-white border border-gray-100 rounded-3xl overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full min-w-[900px] text-left text-sm">
              <thead className="bg-gray-50 text-[11px] text-gray-400 uppercase tracking-widest font-bold">
                <tr>
                  <th className="px-4 py-4 text-center w-16">ID</th>
                  <th className="px-4 py-4">Usuario</th>
                  <th className="px-4 py-4 w-28">Registrado</th>
                  <th className="px-4 py-4 w-32">Estado</th>
                  <th className="px-4 py-4 text-center w-36">Estadísticas</th>
                  <th className="px-4 py-4 text-center w-24">Rol</th>
                  <th className="px-4 py-4 text-right w-32">Acciones</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-50">
                {usuarios.length === 0 ? (
                  <tr>
                    <td colSpan={7} className="px-4 py-16 text-center text-gray-400 font-medium">
                      No se encontraron usuarios activos.
                    </td>
                  </tr>
                ) : (
                  usuarios.map((u) => {
                    const fotoUrl = u.fotoPerfil ? `${phpSiteUrl}/app/perfil/fotos/${encodeURIComponent(u.fotoPerfil)}` : null;
                    return (
                      <tr key={u.id} className={u.bloqueado ? "bg-red-50/50" : "hover:bg-gray-50/50"}>
                        <td className="px-4 py-4 text-center text-gray-400 font-mono text-xs">#{u.id}</td>
                        <td className="px-4 py-4">
                          <div className="flex items-center gap-3">
                            {fotoUrl ? (
                              // eslint-disable-next-line @next/next/no-img-element
                              <img src={fotoUrl} alt="" className="w-10 h-10 rounded-xl object-cover border border-gray-200" />
                            ) : (
                              <div className="w-10 h-10 rounded-xl bg-gray-100 flex items-center justify-center font-bold text-gray-400 text-sm">
                                {(u.nombre ?? "U").charAt(0).toUpperCase()}
                              </div>
                            )}
                            <div className="min-w-0">
                              <a
                                href={`${phpSiteUrl}/perfil/${u.id}`}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="font-bold text-gray-900 text-sm hover:text-[#54A6D8] transition-colors truncate max-w-[160px] inline-block"
                              >
                                {u.nombre || "Sin Nombre"}
                              </a>
                              <p className="text-xs text-gray-500 font-medium truncate max-w-[160px]">{u.correo}</p>
                            </div>
                          </div>
                        </td>
                        <td className="px-4 py-4 text-xs text-gray-500 font-medium whitespace-nowrap">
                          {u.fechaRegistro ? new Date(u.fechaRegistro).toLocaleDateString("es-CL", { day: "2-digit", month: "2-digit", year: "numeric" }) : "-"}
                        </td>
                        <td className="px-4 py-4">
                          {u.bloqueado ? (
                            <div className="flex flex-col items-start gap-1">
                              <span className="inline-flex items-center gap-1 bg-red-50 text-red-600 px-2.5 py-1 rounded-md text-[9px] font-bold uppercase tracking-widest">
                                Suspendido
                              </span>
                              {u.suspendidoHasta && (
                                <span className="text-[9px] font-medium text-red-400">
                                  hasta {new Date(u.suspendidoHasta).toLocaleDateString("es-CL")}
                                </span>
                              )}
                            </div>
                          ) : u.confirmado ? (
                            <span className="inline-flex items-center gap-1 bg-emerald-50 text-emerald-600 px-2.5 py-1 rounded-md text-[9px] font-bold uppercase tracking-widest">
                              Activo
                            </span>
                          ) : (
                            <span className="inline-flex items-center gap-1 bg-amber-50 text-amber-600 px-2.5 py-1 rounded-md text-[9px] font-bold uppercase tracking-widest">
                              Pendiente
                            </span>
                          )}
                        </td>
                        <td className="px-4 py-4 text-center">
                          <div className="flex justify-center gap-3 text-xs">
                            <div className="text-center">
                              <span className="block font-black text-blue-600">{u.totalServicios}</span>
                              <span className="text-gray-400 text-[9px] font-bold uppercase">Pubs</span>
                            </div>
                            <div className="text-center">
                              <span className="block font-black text-emerald-600">{u.totalApuntes}</span>
                              <span className="text-gray-400 text-[9px] font-bold uppercase">Apus</span>
                            </div>
                            <div className="text-center">
                              <span className={`block font-black ${u.totalReclamos > 0 ? "text-red-500" : "text-gray-400"}`}>{u.totalReclamos}</span>
                              <span className="text-gray-400 text-[9px] font-bold uppercase">Rep</span>
                            </div>
                          </div>
                        </td>
                        <td className="px-4 py-4 text-center">
                          <span
                            className={`px-2.5 py-1 rounded-md text-[9px] font-black uppercase tracking-widest ${u.rol === "admin" ? "bg-purple-50 text-purple-600" : "bg-gray-100 text-gray-500"}`}
                          >
                            {u.rol}
                          </span>
                        </td>
                        <td className="px-4 py-4 text-right">
                          <a
                            href={`${phpSiteUrl}/admin/usuarios?q=${encodeURIComponent(u.correo ?? "")}`}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="inline-flex items-center px-3 py-2 bg-gray-100 text-gray-500 rounded-lg text-xs font-bold border border-gray-200 whitespace-nowrap"
                            title="Banear/editar/suspender/eliminar en el sitio real"
                          >
                            Gestionar en el sitio real
                          </a>
                        </td>
                      </tr>
                    );
                  })
                )}
              </tbody>
            </table>
          </div>

          {totalPages > 1 && (
            <div className="flex justify-center flex-wrap p-4 gap-2 border-t border-gray-100">
              {Array.from({ length: totalPages }, (_, i) => i + 1).map((p) => {
                const qs = new URLSearchParams(qsBase);
                qs.set("page", String(p));
                return (
                  <a
                    key={p}
                    href={`?${qs.toString()}`}
                    className={`w-8 h-8 flex items-center justify-center rounded-xl text-xs font-bold transition-all ${p === page ? "bg-[#54A6D8] text-white" : "bg-gray-50 text-gray-600 hover:bg-gray-100"}`}
                  >
                    {p}
                  </a>
                );
              })}
            </div>
          )}
        </div>
      </main>
    </>
  );
}
