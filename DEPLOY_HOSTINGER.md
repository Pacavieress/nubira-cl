# Checklist — Subir cambios a Hostinger

## ANTES DE EMPEZAR
- [ ] Descargar copia de respaldo de los 28 archivos actuales de produccion a carpeta backup_hostinger_2026-05
- [ ] Conectarse por FTP (FileZilla) o Administrador de archivos hPanel
- [ ] NO subir nunca: .env, app/config.php, /upload, /db, /vendor

## GRUPO 1 — Base (subir PRIMERO)
- [ ] app/iconos.php (critico: 9 iconos nuevos)
- [ ] .htaccess
- [ ] app/logs/.htaccess
- [ ] Verificar: abrir nubira.cl, navegar

## GRUPO 2 — Componentes
- [ ] app/componentes/header.php
- [ ] app/componentes/header_aula.php
- [ ] app/componentes/nav_bottom.php
- [ ] app/componentes/panel_gestion.php
- [ ] app/componentes/render_card.php
- [ ] app/componentes/modal_publicar.php
- [ ] Verificar: recargar home

## GRUPO 3 — Fix sesion
- [ ] app/admin_accesos_vitrina.php
- [ ] app/api/geolocalizar_ip.php
- [ ] app/cargar_fila_inteligente.php
- [ ] app/cargar_mensajes_chat_mini_aula.php
- [ ] app/mis_chats.php
- [ ] app/middleware/antibot.php
- [ ] app/contar_alertas_sistema.php

## GRUPO 4 — Paginas principales
- [ ] app/vitrina.php
- [ ] app/detalle_servicio.php
- [ ] app/ver_apunte.php
- [ ] app/busqueda.php
- [ ] app/cargar_apuntes.php
- [ ] app/cargar_servicios.php
- [ ] app/cargar_vistos.php
- [ ] app/bandeja_entrada.php
- [ ] app/chat_previo_contrato.php
- [ ] app/publicar_servicio.php
- [ ] app/render_mensajes.php
- [ ] app/api/motor_ia.php
- [ ] app/render_card.php

## CASO ESPECIAL — conexion.php
- [ ] NO subir desde local (tiene credenciales locales)
- [ ] Editar directo en Hostinger: abrir app/conexion.php, borrar la linea ?> del final y lineas en blanco posteriores, guardar

## DESPUES DE SUBIR
- [ ] Resetear OPCache (o esperar 5-10 min)
- [ ] Probar: home, detalle servicio, ver apunte, busqueda
- [ ] Probar en movil: ver apunte (barra flotante), chat
- [ ] Probar publicar un servicio (verificar filtro de contacto)
- [ ] Verificar que /bandeja-entrada cargue (fix del bucle)
