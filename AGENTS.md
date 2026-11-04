# Control de gastos

- Trabajar exclusivamente en este proyecto; no modificar otros proyectos vecinos.
- Servidor web local únicamente en `http://localhost:8000`; no arrancar puertos alternativos.
- Arquitectura Laravel de monolito modular: Identidad, Catálogos, Caja chica, Gastos, Auditoría y Reportes.
- MySQL es la base de uso local y de verificación de integración. SQLite en memoria solo para la suite rápida.
- Mantener separados `control_gastos`, `control_gastos_test` y `control_gastos_e2e`.
- No ejecutar fixtures ni migraciones destructivas en la base de uso local.
- Al detener el servidor para pruebas, detener únicamente sus procesos PHP; no finalizar árboles de procesos que puedan incluir MySQL.
- Mantener la plantilla storage/app/templates/caja-chica.xlsx fuera de Git. Limpiar sus ejemplos en cada exportación, ampliar filas y conservar identificadores como texto.
- Las consultas filtradas no representan una conciliación completa de caja chica. Solo la exportación completa muestra recibido/devuelto y diferencia.
- Mantener datos, credenciales, adjuntos privados, herramientas portables y capturas de pruebas fuera de Git.
- No publicar ni desplegar sin instrucción del usuario.
- Solo admin/superadmin entregan dinero. Accountant fiscaliza y exporta sin modificar gastos ni dinero; technician y user consultan sus propios movimientos.
- Las cajas nuevas son permanentes, una por persona. Cada reposición reembolsa primero el saldo negativo del responsable. Nunca crear un segundo gasto por ese reembolso.
- Preservar el historial de entregas y el reparto reembolso/fondo. Los importes/fechas/caja de gastos conciliados en una entrega posterior no se sobrescriben; sus comprobantes y descripciones sí se pueden completar.
- Para cambios de permisos, importes, exportación o auditoría, ejecutar la suite MySQL. Para cambios de interacción o responsive, ejecutar las pruebas de navegador en el mismo puerto 8000, deteniendo antes la instancia normal.
