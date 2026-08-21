"use client";

import { useRef, useState } from "react";
import { useRouter } from "next/navigation";

// Puerto de app/formulario_subir_apunte.php — SOLO el camino real de subida y creación
// (archivo + metadatos). Deliberadamente SIN: generador de descripción/categorización por
// IA (depende de app/datos/ia_nubira.php, desactivado en producción — CLAUDE.md ya lo
// documenta como tarea aparte), sin compra de créditos IA (MercadoPago, dinero real), sin
// el escáner de cámara ni el selector de página de portada para PDF (pdf.js) — un PDF
// subido acá queda sin preview/portada hasta una pieza aparte, ver publicar.controller.ts.

const MATERIAS = [
  { value: "calculo", label: "Cálculo" },
  { value: "fisica", label: "Física" },
  { value: "algebra", label: "Álgebra" },
  { value: "programacion", label: "Programación" },
  { value: "quimica", label: "Química" },
  { value: "biologia", label: "Biología y Anatomía" },
  { value: "contabilidad", label: "Contabilidad y Finanzas" },
  { value: "economia", label: "Economía" },
  { value: "derecho", label: "Derecho" },
  { value: "psicologia", label: "Psicología y Estadística" },
  { value: "idiomas", label: "Idiomas" },
  { value: "redaccion", label: "Redacción y Tesis" },
];

const EXTENSIONES_VALIDAS = new Set(["pdf", "jpg", "jpeg", "png", "webp"]);
const MAX_BYTES = 40 * 1024 * 1024;

function anioDefault() {
  return new Date().getFullYear();
}
function semestreDefault() {
  return new Date().getMonth() + 1 <= 7 ? 1 : 2;
}

export function PublicarApunteForm() {
  const router = useRouter();
  const inputRef = useRef<HTMLInputElement>(null);

  const [archivo, setArchivo] = useState<File | null>(null);
  const [titulo, setTitulo] = useState("");
  const [descripcion, setDescripcion] = useState("");
  const [anio, setAnio] = useState(anioDefault());
  const [semestre, setSemestre] = useState(semestreDefault());
  const [precio, setPrecio] = useState(0);
  const [asignatura, setAsignatura] = useState("");
  const [materia, setMateria] = useState("");
  const [nivelAcademico, setNivelAcademico] = useState<"universitario" | "paes" | "escolar">("universitario");
  const [subtema, setSubtema] = useState("");

  const [arrastrando, setArrastrando] = useState(false);
  const [enviando, setEnviando] = useState(false);
  const [progreso, setProgreso] = useState(0);
  const [error, setError] = useState<string | null>(null);

  function elegirArchivo(file: File) {
    const ext = file.name.split(".").pop()?.toLowerCase() ?? "";
    if (!EXTENSIONES_VALIDAS.has(ext)) {
      setError(`Solo se aceptan PDF o imágenes (.jpg, .jpeg, .png, .webp). El archivo ".${ext}" no es compatible.`);
      return;
    }
    if (file.size > MAX_BYTES) {
      setError("El archivo supera los 40MB permitidos. Usa uno más liviano.");
      return;
    }
    setError(null);
    setArchivo(file);
  }

  function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (enviando) return;
    if (!archivo) {
      setError("Selecciona un archivo primero");
      return;
    }
    if (!materia) {
      setError("Selecciona una materia");
      return;
    }

    setEnviando(true);
    setProgreso(0);
    setError(null);

    const fd = new FormData();
    fd.append("archivo", archivo, archivo.name);
    fd.append("titulo", titulo);
    fd.append("descripcion", descripcion);
    fd.append("anio", String(anio));
    fd.append("semestre", String(semestre));
    fd.append("precio", String(precio));
    fd.append("asignatura", asignatura || "General");
    fd.append("materia", materia);
    fd.append("nivelAcademico", nivelAcademico);
    fd.append("subtema", subtema);

    const xhr = new XMLHttpRequest();
    xhr.upload.addEventListener("progress", (ev) => {
      if (!ev.lengthComputable) return;
      setProgreso(Math.round((ev.loaded / ev.total) * 100));
    });
    xhr.addEventListener("load", () => {
      let respuesta: { success?: boolean; error?: string; id?: number } = {};
      try {
        respuesta = JSON.parse(xhr.responseText);
      } catch {
        respuesta = {};
      }
      if (xhr.status >= 200 && xhr.status < 300 && respuesta.success) {
        setProgreso(100);
        setTimeout(() => router.push("/mis-publicaciones"), 700);
      } else {
        setError(respuesta.error ?? "Error al publicar el apunte. Intenta de nuevo.");
        setEnviando(false);
      }
    });
    xhr.addEventListener("error", () => {
      setError("Error de red. Verifica tu conexión e intenta de nuevo.");
      setEnviando(false);
    });
    xhr.open("POST", "/api/publicar/apuntes");
    xhr.send(fd);
  }

  return (
    <form onSubmit={onSubmit} className="bg-white border border-gray-100 rounded-3xl p-6 md:p-8 shadow-sm">
      {error && (
        <div className="mb-6 px-4 py-3 rounded-xl flex items-center gap-3 shadow-sm bg-red-50 text-red-700 border border-red-200">
          <span className="text-sm font-bold flex-1">{error}</span>
        </div>
      )}

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-8 items-start">
        <div className="flex flex-col gap-4">
          <label
            onClick={() => inputRef.current?.click()}
            onDragOver={(e) => {
              e.preventDefault();
              setArrastrando(true);
            }}
            onDragLeave={() => setArrastrando(false)}
            onDrop={(e) => {
              e.preventDefault();
              setArrastrando(false);
              const file = e.dataTransfer.files[0];
              if (file) elegirArchivo(file);
            }}
            className={`relative border-2 border-dashed rounded-2xl h-48 md:h-64 flex flex-col justify-center items-center text-center cursor-pointer transition-all bg-gray-50/50 hover:bg-white ${
              arrastrando ? "border-[#54A6D8] bg-blue-50/20 scale-[1.01]" : "border-gray-200"
            }`}
          >
            {archivo ? (
              <div className="flex flex-col items-center gap-2 px-4">
                <p className="text-sm font-bold text-gray-800 truncate max-w-full">{archivo.name}</p>
                <p className="text-xs text-gray-400">{(archivo.size / 1024 / 1024).toFixed(1)} MB</p>
                <button
                  type="button"
                  onClick={(e) => {
                    e.stopPropagation();
                    setArchivo(null);
                    if (inputRef.current) inputRef.current.value = "";
                  }}
                  className="mt-1 text-xs font-bold text-red-400 hover:text-red-600"
                >
                  Quitar
                </button>
              </div>
            ) : (
              <>
                <p className="text-sm font-bold text-gray-800">Sube tu apunte</p>
                <p className="text-[11px] text-gray-400 mt-1">PDF o imágenes, máx. 40MB</p>
              </>
            )}
          </label>
          <input
            ref={inputRef}
            type="file"
            className="hidden"
            accept=".pdf,.jpg,.jpeg,.png,.webp"
            onChange={(e) => {
              const file = e.target.files?.[0];
              if (file) elegirArchivo(file);
            }}
          />

          {enviando && (
            <div className="mt-2">
              <div className="h-1.5 rounded-full bg-gray-200 overflow-hidden">
                <div className="h-full rounded-full bg-gradient-to-r from-sky-400 to-[#54A6D8] transition-all" style={{ width: `${progreso}%` }} />
              </div>
              <p className="text-[10px] font-bold text-gray-500 mt-1">{progreso < 100 ? `Subiendo... ${progreso}%` : "Procesando..."}</p>
            </div>
          )}
        </div>

        <div className="flex flex-col gap-5">
          <div>
            <label className="block text-xs font-bold text-gray-900 mb-2 uppercase tracking-wide">
              Título <span className="text-red-400">*</span>
            </label>
            <input
              type="text"
              required
              maxLength={80}
              value={titulo}
              onChange={(e) => setTitulo(e.target.value)}
              placeholder="Ej: Resumen Completo Anatomía"
              className="w-full bg-gray-50 border border-gray-200 text-gray-900 text-sm rounded-xl p-3.5 outline-none focus:ring-2 focus:ring-[#54A6D8] font-medium"
            />
          </div>

          <div className="grid grid-cols-2 md:grid-cols-3 gap-4">
            <div>
              <label className="block text-xs font-bold text-gray-900 mb-2 uppercase">Año</label>
              <input
                type="number"
                value={anio}
                onChange={(e) => setAnio(Number(e.target.value))}
                className="w-full bg-gray-50 border border-gray-200 rounded-xl p-3.5 text-sm font-bold outline-none focus:ring-2 focus:ring-[#54A6D8]"
              />
            </div>
            <div>
              <label className="block text-xs font-bold text-gray-900 mb-2 uppercase">Semestre</label>
              <select
                value={semestre}
                onChange={(e) => setSemestre(Number(e.target.value))}
                className="w-full bg-gray-50 border border-gray-200 rounded-xl p-3.5 text-sm font-bold outline-none focus:ring-2 focus:ring-[#54A6D8]"
              >
                <option value={1}>1º Semestre</option>
                <option value={2}>2º Semestre</option>
              </select>
            </div>
            <div className="col-span-2 md:col-span-1">
              <label className="block text-xs font-bold text-gray-900 mb-2 uppercase">Precio</label>
              <div className="relative">
                <span className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 font-bold">$</span>
                <input
                  type="number"
                  min={0}
                  value={precio}
                  onChange={(e) => setPrecio(Math.max(0, Number(e.target.value)))}
                  className="w-full bg-gray-50 border border-gray-200 rounded-xl pl-6 p-3.5 text-sm font-bold outline-none focus:ring-2 focus:ring-[#54A6D8]"
                />
              </div>
            </div>
          </div>

          <div>
            <label className="block text-xs font-bold text-gray-900 mb-2 uppercase tracking-wide">Asignatura</label>
            <input
              type="text"
              value={asignatura}
              onChange={(e) => setAsignatura(e.target.value)}
              placeholder="Ej: Biología Celular"
              className="w-full bg-gray-50 border border-gray-200 text-gray-900 text-sm rounded-xl p-3.5 outline-none font-bold focus:ring-2 focus:ring-[#54A6D8]"
            />
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-bold text-gray-900 mb-2 uppercase tracking-wide">
                Materia <span className="text-red-400">*</span>
              </label>
              <select
                required
                value={materia}
                onChange={(e) => setMateria(e.target.value)}
                className="w-full bg-gray-50 border border-gray-200 rounded-xl p-3.5 text-sm font-bold outline-none focus:ring-2 focus:ring-[#54A6D8]"
              >
                <option value="">Selecciona...</option>
                {MATERIAS.map((m) => (
                  <option key={m.value} value={m.value}>
                    {m.label}
                  </option>
                ))}
              </select>
            </div>
            <div>
              <label className="block text-xs font-bold text-gray-900 mb-2 uppercase tracking-wide">
                Nivel <span className="text-red-400">*</span>
              </label>
              <select
                required
                value={nivelAcademico}
                onChange={(e) => setNivelAcademico(e.target.value as "universitario" | "paes" | "escolar")}
                className="w-full bg-gray-50 border border-gray-200 rounded-xl p-3.5 text-sm font-bold outline-none focus:ring-2 focus:ring-[#54A6D8]"
              >
                <option value="universitario">Universitario</option>
                <option value="paes">PAES</option>
                <option value="escolar">Escolar</option>
              </select>
            </div>
          </div>

          <div>
            <label className="block text-xs font-bold text-gray-900 mb-2 uppercase tracking-wide">
              Subtema <span className="text-gray-400 normal-case font-medium">(opcional)</span>
            </label>
            <input
              type="text"
              maxLength={60}
              value={subtema}
              onChange={(e) => setSubtema(e.target.value)}
              placeholder="Ej: Derivadas, PEP1, Examen final"
              className="w-full bg-gray-50 border border-gray-200 text-gray-900 text-sm rounded-xl p-3.5 outline-none font-medium focus:ring-2 focus:ring-[#54A6D8]"
            />
          </div>

          <div>
            <label className="block text-xs font-bold text-gray-900 mb-2 uppercase tracking-wide">
              Descripción <span className="text-red-400">*</span>
            </label>
            <textarea
              required
              maxLength={1500}
              value={descripcion}
              onChange={(e) => setDescripcion(e.target.value)}
              placeholder="Escribe una descripción de tu apunte..."
              className="w-full bg-gray-50 border border-gray-200 text-gray-900 text-sm rounded-xl p-4 resize-none outline-none h-32 leading-relaxed focus:ring-2 focus:ring-[#54A6D8]"
            />
          </div>

          <button
            type="submit"
            disabled={enviando || !archivo || !titulo || !descripcion || !materia}
            className="w-full text-white bg-[#54A6D8] hover:bg-sky-500 font-bold rounded-xl text-base px-5 py-4 shadow-sm transition-all disabled:bg-gray-300 disabled:cursor-not-allowed"
          >
            {enviando ? "Subiendo..." : "Publicar Apunte"}
          </button>
        </div>
      </div>
    </form>
  );
}
