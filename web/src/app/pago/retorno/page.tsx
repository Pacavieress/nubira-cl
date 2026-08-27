import { redirect } from "next/navigation";
import Link from "next/link";
import { getConfirmarRetornoPago } from "@/lib/api";
import { getSesion } from "@/lib/sesion";
import { formatoCLP } from "@/lib/formato";
import { Header } from "@/components/Header";

interface RetornoPageProps {
  searchParams: Promise<{ payment_id?: string; collection_id?: string; contratoId?: string }>;
}

// Puerto fusionado de pago_exitoso_contrato.php + pago_error_contrato.php +
// pago_pendiente_contrato.php (Checkpoint 2 — Pago, 26/08/2026). Una sola página de
// retorno para las 3 back_urls (success/pending/failure de la preferencia, ver
// server/src/lib/mercadoPago.ts): a diferencia del PHP real, NUNCA confía en
// collection_status del query string — pide el resultado real al servidor
// (getConfirmarRetornoPago), que re-verifica el pago contra la API de MercadoPago antes de
// decidir el estado del contrato. Lo que esta página muestra es SIEMPRE la verdad
// verificada, sin importar a cuál de las 3 back_urls te haya mandado MercadoPago.
export default async function PagoRetornoPage({ searchParams }: RetornoPageProps) {
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const sesion = await getSesion();
  if (!sesion) {
    redirect(`${phpSiteUrl}/login`);
  }

  const { payment_id, collection_id } = await searchParams;
  const paymentId = payment_id || collection_id || "";

  if (!paymentId) {
    return <EstadoError phpSiteUrl={phpSiteUrl} mensaje="No encontramos información de tu pago en el retorno de MercadoPago." />;
  }

  const resultado = await getConfirmarRetornoPago(paymentId);

  if (!resultado || !resultado.ok || !resultado.contrato) {
    const mensaje =
      resultado?.error === "sin_acceso"
        ? "Este pago no corresponde a tu cuenta."
        : resultado?.status === "rejected"
          ? "Tu pago fue rechazado por la pasarela. No se realizó ningún cargo."
          : "No pudimos confirmar tu pago con MercadoPago en este momento.";
    return <EstadoError phpSiteUrl={phpSiteUrl} mensaje={mensaje} />;
  }

  const { contrato, accion } = resultado;

  if (accion === "pendiente") {
    return <EstadoPendiente phpSiteUrl={phpSiteUrl} />;
  }
  if (accion === "rechazado") {
    return <EstadoError phpSiteUrl={phpSiteUrl} mensaje="Tu pago fue rechazado por la pasarela. No se realizó ningún cargo." contratoId={contrato.id} />;
  }

  // "aprobado" o "aprobado_ya_procesado" — mismo criterio que pago_exitoso_contrato.php
  // (yaProcesado no cambia lo que se le muestra al comprador, solo evita reenviar correos).
  return <EstadoExitoso contrato={contrato} />;
}

function Layout({ children }: { children: React.ReactNode }) {
  return (
    <>
      <Header titulo="Resultado del pago" />
      <main className="pt-20 pb-16 px-4 flex items-center justify-center min-h-screen">
        <div className="bg-white rounded-3xl shadow-xl shadow-gray-200/50 p-8 text-center max-w-[480px] w-full border border-gray-100">{children}</div>
      </main>
    </>
  );
}

function EstadoExitoso({ contrato }: { contrato: { id: number; monto: number; servicioTitulo: string } }) {
  return (
    <Layout>
      <div className="w-20 h-20 bg-green-50 rounded-full flex items-center justify-center mx-auto mb-6">
        <svg className="w-10 h-10 text-green-500" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
          <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" />
        </svg>
      </div>
      <h1 className="text-2xl font-bold text-gray-900 mb-3">¡Pago asegurado!</h1>
      <p className="text-gray-500 text-sm leading-relaxed mb-8">
        Tu pago en custodia por <span className="font-semibold text-gray-900">{contrato.servicioTitulo}</span> ({formatoCLP(contrato.monto)}) se procesó con éxito. El tutor ya fue notificado.
      </p>
      <div className="bg-gray-50 rounded-2xl p-4 mb-8 text-left flex items-start gap-3 border border-gray-100">
        <p className="text-[11px] text-gray-500">El pago no se entregará al tutor hasta que el servicio sea realizado por completo y tú estés conforme.</p>
      </div>
      <Link href={`/aula/${contrato.id}`} className="block w-full bg-[#54A6D8] hover:bg-blue-600 text-white px-6 py-4 rounded-xl font-bold transition-colors shadow-lg shadow-blue-200">
        Ir al Aula Virtual
      </Link>
    </Layout>
  );
}

function EstadoPendiente({ phpSiteUrl }: { phpSiteUrl: string }) {
  return (
    <Layout>
      <div className="w-20 h-20 bg-yellow-50 rounded-full flex items-center justify-center mx-auto mb-6">
        <svg className="w-10 h-10 text-yellow-500" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
          <path strokeLinecap="round" strokeLinejoin="round" d="M12 6v6l4 2" />
          <circle cx="12" cy="12" r="9" strokeWidth={2} />
        </svg>
      </div>
      <h1 className="text-2xl font-bold text-gray-900 mb-3">Pago pendiente</h1>
      <p className="text-gray-500 text-sm leading-relaxed mb-8">Tu pago se está procesando. MercadoPago puede tardar unos minutos en confirmarlo.</p>
      <a href="/mis-contratos" className="block w-full bg-[#54A6D8] hover:bg-blue-600 text-white px-6 py-4 rounded-xl font-bold transition-colors shadow-lg shadow-blue-200">
        Ver mis contratos
      </a>
      <a href={`${phpSiteUrl}/vitrina`} className="block w-full text-gray-400 hover:text-gray-600 font-medium text-sm mt-4">
        Volver a la vitrina
      </a>
    </Layout>
  );
}

function EstadoError({ phpSiteUrl, mensaje, contratoId }: { phpSiteUrl: string; mensaje: string; contratoId?: number }) {
  return (
    <Layout>
      <div className="w-20 h-20 bg-orange-50 text-orange-400 rounded-full flex items-center justify-center mx-auto mb-6">
        <svg className="w-10 h-10" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
          <path strokeLinecap="round" strokeLinejoin="round" d="M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
      </div>
      <h1 className="text-2xl font-bold text-gray-900 mb-2">Pago no completado</h1>
      <p className="text-gray-500 text-sm mb-8 leading-relaxed">
        {mensaje} <br />
        <strong className="text-gray-700">No se ha realizado ningún cargo a tu cuenta.</strong>
      </p>
      <div className="space-y-3">
        {contratoId ? (
          <a
            href={`/mis-contratos`}
            className="block w-full bg-[#54A6D8] hover:bg-sky-500 text-white font-bold py-3.5 rounded-xl shadow-md transition-all"
          >
            Reintentar pago
          </a>
        ) : null}
        <a href={`${phpSiteUrl}/vitrina`} className="block w-full bg-white border border-gray-200 hover:bg-gray-50 text-gray-600 font-bold py-3.5 rounded-xl transition-all">
          Explorar otros servicios
        </a>
      </div>
    </Layout>
  );
}
