import type { Metadata } from "next";
import { Header } from "@/components/Header";
import { LegalPageLayout, LegalSection } from "@/components/LegalPageLayout";

export const metadata: Metadata = {
  title: "Términos y Condiciones | Nubira",
  description: "Términos de uso de Nubira: reglas de la plataforma, derechos y responsabilidades de usuarios, tutores y compradores.",
};

// Puerto de terminos.php — 100% contenido estático, cero query a BD. "Modo lectura"
// (ocultarBuscador/ocultarBotonesPublicar en el Header), igual que el resto de las páginas
// de este barrido.
export default function TerminosPage() {
  return (
    <>
      <Header titulo="Términos y Condiciones" ocultarBuscador ocultarBotonesPublicar />
      <LegalPageLayout badge="Documento Legal" titulo="Términos y Condiciones" ultimaActualizacion="05/08/2026">
        <LegalSection n={1} titulo="¿Qué es Nubira?">
          <p>
            Nubira es una plataforma universitaria digital donde estudiantes y tutores publican servicios de clases particulares
            (por categoría académica y preparación PAES), apuntes y material de estudio, y oportunidades académicas. Al
            registrarte o utilizar nuestros servicios, aceptas cumplir con estos términos.
          </p>
        </LegalSection>

        <LegalSection n={2} titulo="Usuarios Permitidos">
          <ul className="list-disc pl-4 md:pl-3 space-y-2 marker:text-[#54A6D8]">
            <li>Cualquier persona con un correo electrónico válido puede registrarse en Nubira.</li>
            <li>
              Los usuarios que se registran con correo de una institución educativa reconocida por Nubira obtienen
              automáticamente el sello &quot;Verificado&quot; en su perfil. Registrarse con otro correo no impide usar la
              plataforma, solo no otorga ese sello.
            </li>
            <li>Mayores de 14 años o con autorización de apoderados.</li>
            <li>Las cuentas son personales e intransferibles.</li>
          </ul>
        </LegalSection>

        <LegalSection n={3} titulo="Uso Correcto">
          <p className="mb-3">Nos tomamos muy en serio la calidad de la comunidad. Está estrictamente prohibido:</p>
          <ul className="list-disc pl-4 md:pl-3 space-y-2 marker:text-[#54A6D8]">
            <li>Publicar material ilegal, ofensivo, spam o información falsa.</li>
            <li>Vulnerar derechos de autor o propiedad intelectual.</li>
            <li>Utilizar la plataforma para fines comerciales ajenos a la educación sin permiso.</li>
          </ul>
        </LegalSection>

        <LegalSection n={4} titulo="Transacciones y clases">
          <p className="mb-3">
            Los pagos se procesan a través de MercadoPago. Nubira retiene el monto pagado hasta que el servicio se entrega — el
            apunte se descarga o la clase se realiza — antes de liberarlo al tutor o vendedor, descontando la comisión
            correspondiente de la plataforma.
          </p>
          <p className="mb-3">
            Las clases particulares se realizan dentro de la misma plataforma, mediante videollamada integrada — no es necesario
            coordinar ni moverse a otra aplicación externa.
          </p>
          <p>
            Nubira actúa como intermediario para asegurar la entrega del servicio o archivo, pero la responsabilidad final de la
            calidad de la clase o el apunte recae en el usuario vendedor.
          </p>
        </LegalSection>

        <LegalSection n={5} titulo="Incumplimiento y suspensión">
          <p>
            Nubira puede suspender temporalmente una cuenta, con motivo y plazo definidos, o bloquearla de forma permanente, ante
            incumplimiento de estos términos. La suspensión cierra la sesión activa del usuario de inmediato.
          </p>
        </LegalSection>

        <LegalSection n={6} titulo="Contacto">
          <p>
            Si tienes dudas sobre estos términos, escríbenos directamente a{" "}
            <a href="mailto:contacto@nubira.cl" className="text-[#54A6D8] font-semibold hover:underline">
              contacto@nubira.cl
            </a>
            .
          </p>
        </LegalSection>
      </LegalPageLayout>
    </>
  );
}
