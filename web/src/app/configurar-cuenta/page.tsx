import { redirect } from "next/navigation";
import { getMiPerfilCuenta } from "@/lib/api";
import { getSesion } from "@/lib/sesion";
import { Header } from "@/components/Header";
import { ConfigurarCuentaForm } from "@/components/ConfigurarCuentaForm";

// Puerto de app/editar_datos.php — mismo gate (línea 9: sin sesión -> /login). SOLO la
// card "Información Básica" es interactiva acá — "Cambiar Contraseña" y "Eliminar Cuenta"
// enlazan a la página PHP real (misma pestaña) en vez de reconstruirse: tocan credenciales
// (requeriría replicar bcrypt de PHP en Node) y borrado de cuenta (irreversible desde la
// perspectiva del usuario) — decisión explícita de dejarlas para otra sesión.
export default async function ConfigurarCuentaPage() {
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const sesion = await getSesion();

  if (!sesion) {
    redirect(`${phpSiteUrl}/login`);
  }

  const perfil = await getMiPerfilCuenta();
  if (!perfil) {
    redirect(`${phpSiteUrl}/login`);
  }

  return (
    <>
      <Header titulo="Configurar Cuenta" />
      <main className="pt-20 pb-28 md:pb-16 px-4 md:px-8 lg:ml-64 max-w-3xl mx-auto space-y-8">
        <header className="mb-2">
          <h1 className="text-xl md:text-2xl font-medium tracking-[-0.01em] text-[#222222]">Configuración de Cuenta</h1>
          <p className="text-gray-400 text-xs font-medium mt-0.5">Gestiona tu información personal y seguridad.</p>
        </header>

        <ConfigurarCuentaForm perfilInicial={perfil} />

        <div className="bg-white border border-gray-100 rounded-2xl shadow-sm p-6 md:p-8">
          <h2 className="text-lg font-bold text-gray-900 mb-2">Seguridad</h2>
          <p className="text-sm text-gray-500 mb-4">Cambia tu contraseña desde la página segura de Nubira.</p>
          <a href={`${phpSiteUrl}/configurar-cuenta`} className="inline-flex bg-gray-900 hover:bg-black text-white font-bold py-2.5 px-6 rounded-xl text-sm transition">
            Cambiar Contraseña
          </a>
        </div>

        <div className="bg-red-50 border border-red-100 rounded-2xl shadow-sm p-6 md:p-8">
          <h2 className="text-lg font-bold text-red-700 mb-2">Eliminar Cuenta</h2>
          <p className="text-sm text-red-600/80 mb-4">Esta acción es irreversible. Gestiónala desde la página segura de Nubira.</p>
          <a href={`${phpSiteUrl}/configurar-cuenta`} className="inline-flex bg-white border border-red-200 text-red-600 hover:bg-red-100 font-bold py-2.5 px-6 rounded-xl text-sm transition">
            Ir a Eliminar Cuenta
          </a>
        </div>
      </main>
    </>
  );
}
