import type { Request, Response } from "express";
import { mapPerfilCuentaRow, stripTags } from "./configurarCuenta.mapper.js";
import { actualizarPerfilCuenta, getPerfilCuenta } from "./configurarCuenta.repository.js";
import type { ActualizarPerfilInput, TipoCuenta } from "./configurarCuenta.types.js";

const TIPOS_VALIDOS: TipoCuenta[] = ["estudiante", "egresado", "profesor", "particular"];

export async function getMiPerfilCuenta(req: Request, res: Response): Promise<void> {
  const usuarioId = req.usuarioId as number;
  const row = await getPerfilCuenta(usuarioId);
  if (!row) {
    res.status(404).json({ error: "not_found" });
    return;
  }
  res.status(200).json(mapPerfilCuentaRow(row));
}

function aString(v: unknown): string {
  return typeof v === "string" ? v.trim() : "";
}

function aNumeroOpcional(v: unknown): number | null {
  if (v === null || v === undefined || v === "") return null;
  const n = Number(v);
  return Number.isFinite(n) ? Math.trunc(n) : null;
}

// Puerto exacto de la validación de editar_datos.php:82-115 (mismos 2 campos
// obligatorios, mismo whitelist de tipo, mismo strip_tags en carrera/bio/universidad).
export async function actualizarMiPerfilCuenta(req: Request, res: Response): Promise<void> {
  const usuarioId = req.usuarioId as number;
  const body = req.body as Record<string, unknown>;

  const nombre = aString(body.nombre);
  const carrera = stripTags(aString(body.carrera));
  const tipo = aString(body.tipo);
  const bio = stripTags(aString(body.bio));
  const universidad = stripTags(aString(body.universidad));
  const anioEgreso = aNumeroOpcional(body.anioEgreso);
  const aniosExperiencia = aNumeroOpcional(body.aniosExperiencia);

  if (nombre === "" || carrera === "") {
    res.status(400).json({ error: "campos_obligatorios", mensaje: "El nombre y la carrera o área son obligatorios." });
    return;
  }
  if (tipo !== "" && !TIPOS_VALIDOS.includes(tipo as TipoCuenta)) {
    res.status(400).json({ error: "tipo_invalido", mensaje: "Tipo de cuenta inválido." });
    return;
  }

  const input: ActualizarPerfilInput = {
    nombre,
    carrera,
    tipo: tipo === "" ? null : (tipo as TipoCuenta),
    bio: bio === "" ? null : bio,
    universidad: universidad === "" ? null : universidad,
    anioEgreso,
    aniosExperiencia,
  };

  await actualizarPerfilCuenta(usuarioId, input);
  res.status(204).send();
}
