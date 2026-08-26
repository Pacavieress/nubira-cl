import { Router } from "express";
import { getDatosBancarios } from "../miBilletera/miBilletera.repository.js";
import { esFotoValida } from "../perfil/perfil.repository.js";
import { getUsuarioConRol, getUsuarioParaHeader } from "./auth.repository.js";
import { requireAuth } from "./auth.middleware.js";

export const authRouter = Router();

// Puerto de header.php:133-134 (mb_strtoupper de la 1ra letra de las 2 primeras palabras).
function computeIniciales(nombre: string | null): string {
  const partes = (nombre ?? "U").trim().split(/\s+/);
  return ((partes[0]?.[0] ?? "U") + (partes[1]?.[0] ?? "")).toUpperCase();
}

// req.usuarioId siempre está poblado acá (requireAuth ya lo garantizó o cortó con 401
// antes de llegar). rol/esAdmin se agregan para que web/ pueda decidir acceso a /admin
// sin necesitar un segundo roundtrip — mismo criterio "fresco, no cacheado" que
// requireAdmin (ver auth.repository.ts::getUsuarioConRol).
//
// [26/08/2026] Se agregaron nombre/iniciales/fotoPerfil/mostrarBotonesPublicar/
// perfilIncompleto — puerto de lo que header.php arma para el avatar/botones de publicar/
// punto de alerta (líneas 53,68-85,133-151,222-224). perfilIncompleto usa el mismo
// criterio que perfil.mapper.ts::mapPerfilPropio (falta foto válida O bio vacía O datos
// bancarios incompletos) en vez de portar la caché de archivo de nav_cache.php — acá cada
// request re-consulta (3 queries lean en paralelo), sin caché: es una carga aceptable para
// una API interna server-to-server, y evita portar todo un sistema de caché de archivo
// solo para esto.
authRouter.get("/me", requireAuth, async (req, res) => {
  const usuarioId = req.usuarioId!;
  const [usuario, usuarioHeader, datosBancarios] = await Promise.all([
    getUsuarioConRol(usuarioId),
    getUsuarioParaHeader(usuarioId),
    getDatosBancarios(usuarioId),
  ]);
  const esAdmin = usuario !== null && usuario.rol === "admin" && usuario.visible === 1 && usuario.bloqueado === 0;

  const faltaFoto = !esFotoValida(usuarioHeader?.foto_perfil ?? null);
  const faltaBio = (usuarioHeader?.bio ?? "").trim() === "";
  const faltaBanco = !datosBancarios || !datosBancarios.banco || !datosBancarios.numero_cuenta;

  res.status(200).json({
    usuarioId,
    rol: usuario?.rol ?? null,
    esAdmin,
    nombre: usuarioHeader?.nombre ?? null,
    iniciales: computeIniciales(usuarioHeader?.nombre ?? null),
    fotoPerfil: usuarioHeader?.foto_perfil ?? null,
    mostrarBotonesPublicar: usuarioHeader?.intencion_uso !== "comprar",
    perfilIncompleto: faltaFoto || faltaBio || faltaBanco,
  });
});
