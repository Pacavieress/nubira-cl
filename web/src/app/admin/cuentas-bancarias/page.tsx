import { redirect } from "next/navigation";
import { getAdminCuentas } from "@/lib/api";
import { getSesion } from "@/lib/sesion";
import { Header } from "@/components/Header";
import { AdminCuentasPanel } from "@/components/AdminCuentasPanel";

interface CuentasBancariasPageProps {
  searchParams: Promise<{ mostrar_todos?: string }>;
}

// Puerto de admin_cuentas.php — revisión de cuentas bancarias configuradas, 100% lectura
// (el PHP real no tiene ningún POST). El toggle "mostrar_todos" navega server-side (mismo
// query param que el PHP real); búsqueda/filtro/orden/expandir/copiar son interacciones
// puramente client-side, sin round-trip, igual que el JS vanilla del PHP — ver
// AdminCuentasPanel.tsx.
export default async function AdminCuentasBancariasPage({ searchParams }: CuentasBancariasPageProps) {
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const sesion = await getSesion();

  if (!sesion || !sesion.esAdmin) {
    redirect(`${phpSiteUrl}/login`);
  }

  const { mostrar_todos } = await searchParams;
  const mostrarTodos = mostrar_todos === "1";
  const cuentas = await getAdminCuentas(mostrarTodos);

  return (
    <>
      <Header titulo="Cuentas Bancarias Configuradas" />
      <main className="pt-20 pb-28 md:pb-16 px-4 md:px-10 lg:ml-64 max-w-[1200px] mx-auto space-y-6">
        <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
          <div>
            <span className="inline-block py-1 px-3 rounded-full bg-blue-50 text-[#54A6D8] text-xs font-bold mb-2 border border-blue-100">
              Panel de Administración
            </span>
            <h1 className="text-xl md:text-2xl font-medium text-[#222222] tracking-[-0.01em]">Cuentas Bancarias Configuradas</h1>
            <p className="text-gray-500 text-sm mt-1">
              {mostrarTodos ? (
                <>
                  Total de cuentas registradas: <strong>{cuentas.length}</strong>{" "}
                  <span className="text-xs text-gray-400">(incluye suspendidos y eliminados)</span>
                </>
              ) : (
                <>
                  Total de usuarios listos para recibir pagos: <strong>{cuentas.length}</strong>
                </>
              )}
            </p>
          </div>
          <a
            href={mostrarTodos ? "/admin/cuentas-bancarias" : "/admin/cuentas-bancarias?mostrar_todos=1"}
            className={`inline-flex items-center gap-2.5 px-4 py-2 rounded-xl border text-xs font-bold transition-colors shrink-0 ${
              mostrarTodos ? "bg-gray-800 text-white border-gray-800 hover:bg-gray-700" : "bg-white text-gray-600 border-gray-200 hover:bg-gray-50"
            }`}
          >
            <span className={`w-8 h-4 rounded-full relative flex items-center transition-colors ${mostrarTodos ? "bg-[#54A6D8]" : "bg-gray-200"}`}>
              <span className={`w-3 h-3 rounded-full bg-white absolute shadow-sm transition-all ${mostrarTodos ? "left-[18px]" : "left-[2px]"}`} />
            </span>
            Mostrar suspendidos y eliminados
          </a>
        </div>

        <AdminCuentasPanel cuentas={cuentas} />
      </main>
    </>
  );
}
