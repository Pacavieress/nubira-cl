CREATE DATABASE IF NOT EXISTS plataforma_apuntes;
USE plataforma_apuntes;

-- Tabla de usuarios (alumnos y admin)
CREATE TABLE alumnos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100),
    correo VARCHAR(100) UNIQUE,
    password TEXT,
    confirmado TINYINT DEFAULT 1,
    rol VARCHAR(10) DEFAULT 'alumno'
);

-- Usuario admin precreado: admin@uc.cl / AdminUC2024!
INSERT INTO alumnos (nombre, correo, password, confirmado, rol)
VALUES (
    'Administrador',
    'admin@uc.cl',
    '$2y$10$DrzDhgTnqk3VtN0vVbYgIewhDD0Y2WSovkw05N8oCQqRuAlTkY1Em', -- hash de AdminUC2024!
    1,
    'admin'
);

-- Tabla de apuntes
CREATE TABLE apuntes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_alumno INT NOT NULL,
    titulo VARCHAR(255),
    asignatura VARCHAR(100),
    descripcion TEXT,
    archivo VARCHAR(255),
    fecha_subida TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_alumno) REFERENCES alumnos(id)
);

-- Tabla de likes
CREATE TABLE likes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_apunte INT NOT NULL,
    id_alumno INT NOT NULL,
    fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unico_like (id_apunte, id_alumno),
    FOREIGN KEY (id_apunte) REFERENCES apuntes(id) ON DELETE CASCADE,
    FOREIGN KEY (id_alumno) REFERENCES alumnos(id) ON DELETE CASCADE
);

-- Tabla de comentarios
CREATE TABLE comentarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_apunte INT NOT NULL,
    id_alumno INT NOT NULL,
    contenido TEXT NOT NULL,
    fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_apunte) REFERENCES apuntes(id) ON DELETE CASCADE,
    FOREIGN KEY (id_alumno) REFERENCES alumnos(id) ON DELETE CASCADE
);
