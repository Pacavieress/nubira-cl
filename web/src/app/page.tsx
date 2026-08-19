import { redirect } from "next/navigation";

// "/" queda reservado para un futuro port de app/vitrina.php (la home real del sitio,
// con banners y carruseles — no construida aún, ver web/src/app/servicios/page.tsx para
// el contexto de esta decisión). Mientras tanto, redirige a /servicios en vez de 404ear.
export default function Home() {
  redirect("/servicios");
}
