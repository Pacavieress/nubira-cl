import type { Metadata } from "next";
import { Header } from "@/components/Header";
import { LegalPageLayout, LegalSection } from "@/components/LegalPageLayout";

export const metadata: Metadata = {
  title: "Política de Privacidad | Nubira",
  description: "Cómo Nubira recopila, usa y protege tus datos personales. Cumplimiento de normativa chilena de protección de datos.",
};

// Puerto de privacidad.php — 100% contenido estático, cero query a BD. "Modo lectura"
// (ocultarBuscador/ocultarBotonesPublicar en el Header), igual que el resto de las páginas
// de este barrido.
export default function PrivacidadPage() {
  return (
    <>
      <Header titulo="Política de Privacidad" ocultarBuscador ocultarBotonesPublicar />
      <LegalPageLayout badge="Privacidad y Datos" titulo="Política de Privacidad" ultimaActualizacion="08/03/2026">
        <LegalSection n={1} titulo="Protección de tu Identidad">
          <p>
            En Nubira valoramos profundamente la privacidad de nuestros estudiantes. La información que proporcionas al
            registrarte, como tu correo institucional y nombre, se utiliza exclusivamente para validar tu identidad dentro del
            ecosistema universitario y mantener una comunidad segura. No vendemos ni compartimos tu información personal con
            terceros.
          </p>
        </LegalSection>

        <LegalSection n={2} titulo="Datos que Recopilamos">
          <ul className="list-disc pl-4 md:pl-3 space-y-2 marker:text-[#54A6D8]">
            <li>
              <strong>Datos de cuenta:</strong> Nombre, correo institucional, universidad y foto de perfil.
            </li>
            <li>
              <strong>Actividad en la plataforma:</strong> Historial de compras, apuntes subidos y búsquedas, utilizados para
              mejorar nuestras recomendaciones mediante IA.
            </li>
            <li>
              <strong>Mensajes:</strong> Los chats internos están encriptados y solo son accesibles por los participantes
              involucrados o por soporte en caso de denuncias explícitas.
            </li>
          </ul>
        </LegalSection>

        <LegalSection n={3} titulo="Uso de Cookies">
          <p>
            Utilizamos cookies esenciales para mantener tu sesión activa (Auto-Login) y cookies analíticas para entender cómo
            navegas por la plataforma, lo que nos permite mejorar la velocidad y la interfaz. Puedes gestionar tus preferencias
            de cookies desde la configuración de tu navegador.
          </p>
        </LegalSection>

        <LegalSection n={4} titulo="Pasarelas de Pago">
          <p>
            Toda la información financiera (tarjetas de crédito, cuentas bancarias) es procesada por plataformas de pago
            certificadas externas. Nubira <strong>no almacena</strong> números de tarjeta ni claves bancarias en sus servidores.
          </p>
        </LegalSection>

        <LegalSection n={5} titulo="Tus Derechos">
          <p>
            Tienes el derecho a solicitar una copia de tus datos o exigir la eliminación permanente de tu cuenta (derecho al
            olvido). Para ejercer este derecho, escríbenos a{" "}
            <a href="mailto:privacidad@nubira.cl" className="text-[#54A6D8] font-semibold hover:underline">
              privacidad@nubira.cl
            </a>
            .
          </p>
        </LegalSection>
      </LegalPageLayout>
    </>
  );
}
