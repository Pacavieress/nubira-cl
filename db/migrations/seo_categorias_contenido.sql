-- Tabla de contenido SEO único por categoría (Fase 1 pSEO).
-- Ejecutar manualmente en local Y en producción (phpMyAdmin). NO se auto-migra.
-- Collation utf8mb4_unicode_ci (requerido por Pablo).
CREATE TABLE seo_categorias_contenido (
  id INT AUTO_INCREMENT PRIMARY KEY,
  categoria VARCHAR(50) NOT NULL,
  tipo ENUM('apuntes','clases','ambos') NOT NULL DEFAULT 'ambos',
  titulo_h1 VARCHAR(200) DEFAULT NULL,
  parrafo_intro TEXT DEFAULT NULL,
  meta_description VARCHAR(200) DEFAULT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_cat_tipo (categoria, tipo)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
