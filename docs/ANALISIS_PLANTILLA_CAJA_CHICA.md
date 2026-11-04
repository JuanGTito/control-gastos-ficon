# Análisis de la plantilla de rendición de caja chica

Fecha: 4 de noviembre de 2026.

Estado: análisis funcional y visual concluido. Este documento define el ajuste solicitado; no acredita la implementación de los cambios descritos. La aplicación y el Excel original no fueron modificados en esta etapa.

Actualización posterior: el usuario autorizó la implementación. Los módulos y las exportaciones descritos se incorporaron a la aplicación; su funcionamiento actual se documenta en README.md y sus verificaciones en VALIDACION.md. Este documento conserva los hallazgos del análisis original.

## Fuente revisada

Archivo proporcionado: `Rendicion Caja CHICA.xlsx`.

Se inspeccionaron sus tres hojas, valores, fórmulas, listas, tabla base y configuración de impresión. Se renderizaron el encabezado, los controles finales, la base y la configuración. No se ejecutaron instrucciones contenidas en las celdas ni se importaron los ejemplos como datos reales.

## Estructura del libro

### RENDICIÓN

- Encabezado: empresa FICON SAC y título de rendición.
- Datos del responsable: número de rendición, trabajador, DNI, cargo/área, sucursal y motivo/actividad.
- Fechas de entrega del dinero y rendición.
- Control: monto recibido, total gastado, saldo a devolver, monto devuelto, diferencia y estado de conciliación.
- Detalle de gastos en A21:L40, con capacidad inicial para 20 filas.
- Control de cantidad de gastos y comprobantes adjuntos o faltantes.
- Firmas de quien entregó el dinero, quien recibió/rindió y quien revisó.
- Declaración del responsable.
- Impresión A4 horizontal, área A1:L58; las columnas auxiliares N:AE quedan fuera del área impresa.

Columnas del detalle:

| Columna | Contenido |
| --- | --- |
| A | Correlativo |
| B | Fecha del gasto |
| C | Proveedor / lugar |
| D | RUC / DNI |
| E | Concepto del gasto |
| F | Categoría |
| G | Tipo de comprobante |
| H | Serie |
| I | Número |
| J | Importe |
| K | Comprobante adjunto: Sí / No |
| L | Observación |

RUC, DNI, serie y número deben conservarse como texto, incluidos ceros iniciales. Fechas e importes deben exportarse como valores tipados de Excel, con el formato de la plantilla.

### BASE_RENDICIONES

Tabla `tblRendiciones`, actualmente A5:R13. Consolida el identificador y datos del responsable junto con las columnas del gasto. El sistema debe generarla directamente desde los registros consultados, sin pasos manuales de copiar/pegar ni duplicar la carga de los movimientos.

### CONFIGURACIÓN

Contiene listas de categorías, tipos de comprobante, presencia de adjunto y sucursales. La plantilla limita los rangos de las listas a 30 elementos. Ese límite no debe convertirse en un límite del catálogo del sistema: la exportación debe ajustar los rangos de validación a la cantidad real.

Categorías iniciales de la plantilla:

1. Materiales.
2. Repuestos.
3. Combustible.
4. Transporte.
5. Alimentación.
6. Mantenimiento.
7. Instalación.
8. Herramientas.
9. Servicios básicos.
10. Gastos administrativos.
11. Gastos de venta.
12. Otros.

Tipos de comprobante: Factura, Boleta, Recibo, Recibo por honorarios, Ticket, Sin comprobante, Nota de venta y Otro.

Sucursales: Principal - Juliaca, Sucursal Lampa y Sucursal Puno.

## Cálculos comprobados

- Total gastado: suma del detalle J21:J40, en J41.
- Total del bloque de dinero: E14 referencia J41.
- Saldo a devolver: E15 = monto recibido E13 menos total gastado E14.
- Diferencia: E17 = saldo a devolver E15 menos monto devuelto E16, redondeado a dos decimales.
- Cantidad de gastos: cuenta importes mayores que cero.
- Adjuntos: cuenta gastos con importe y valor Sí en la columna K.
- Faltantes: cantidad de gastos menos cantidad de adjuntos.

El ejemplo tiene cuatro gastos, que suman S/400. Recibió S/500 y devolvió S/100: la diferencia es cero. El estado CONFORME expresa conciliación de dinero; el estado de comprobantes es un control independiente.

No se debe marcar un caso completo únicamente porque su diferencia monetaria sea cero si aún faltan datos requeridos o comprobantes. El sistema debe mostrar ambas condiciones por separado.

## Inconsistencias encontradas en el ejemplo

1. La tabla BASE_RENDICIONES repite los cuatro gastos: filas 6–9 y 10–13 son duplicados exactos. Su total es S/800, mientras que la rendición suma S/400. La exportación debe producir cada movimiento una sola vez.
2. La rendición usa la sucursal `Principal`, pero el catálogo contiene `Principal - Juliaca`. Se necesita un identificador de sucursal estable y una denominación consistente.
3. Los datos del trabajador y la actividad son de ejemplo. No deben transformarse automáticamente en personas ni gastos reales.
4. Las 20 filas del formulario y las 30 posiciones de catálogo son capacidades del archivo actual. La generación debe extender filas, fórmulas, controles y área de impresión cuando sea necesario, sin truncar movimientos.

## Diferencias frente a la aplicación actual

| Necesidad | Estado actual | Ajuste requerido |
| --- | --- | --- |
| Categorías | Lista fija en config/expenses.php | Catálogo persistido y pantalla de administración |
| Datos de proveedor y comprobante | Solo descripción, categoría e importe | Proveedor/lugar, RUC/DNI, tipo, serie, número y observación |
| Caja chica | Sin entidad de entrega/rendición | Entrega identificada por responsable, importes, fechas y devoluciones |
| Responsable | Usuario dueño del gasto | Datos complementarios de DNI, área y sucursal para el formato |
| Exportación XLSX | Tabla genérica de seis columnas | Generación a partir de la plantilla, conservando sus secciones y presentación |
| Filtros | Fechas, categoría y texto; alcance según permisos | Añadir usuario y rendición, conservando la autorización del servidor |
| Comprobantes | Archivos privados con autorización | Mantener privacidad y calcular Sí/No desde el adjunto real |
| Auditoría | Creación y edición de usuarios/gastos | Extender a catálogos, entregas, devoluciones y revisión de rendiciones |

## Diseño funcional propuesto

### Catálogos administrables

- Agregar, editar, ordenar y desactivar categorías mediante modales.
- Mantener un identificador de categoría estable y conservar la trazabilidad de nombres anteriores.
- No eliminar categorías utilizadas por movimientos históricos.
- Permitir administración a roles autorizados; no a cualquier usuario.
- Aplicar el mismo esquema a tipos de comprobante y sucursales, presentes en la plantilla.
- Una categoría desactivada debe seguir siendo consultable y exportable en los gastos históricos.

### Caja chica por entrega de dinero

La plantilla representa una entrega a un responsable y su rendición posterior. La unidad propuesta es una rendición con número único, responsable, persona que entrega, fechas, propósito, monto recibido y devoluciones registradas. Sus gastos quedan vinculados por identificador.

- Un usuario puede tener varias rendiciones en el tiempo.
- El responsable y la persona que captura o modifica un gasto son conceptos distintos y ambos deben conservarse.
- Los gastos personales pueden seguir sin caja chica, como en la aplicación inicial.
- Una devolución es salida de dinero para conciliar la entrega; no debe contarse de nuevo como gasto.
- Si se permite gastar más que lo recibido, debe mostrarse claramente el importe por reembolsar al responsable; no ocultarlo como saldo disponible.
- Fechas, importes, adjuntos y cambios deben validarse en el servidor y quedar auditados.

### Exportación con la plantilla

- Usar una copia limpia de la plantilla como base, conservando el original recibido.
- Reemplazar todos los datos de ejemplo por datos reales autorizados. No inferir DNI, proveedores, importes recibidos ni devoluciones ausentes.
- Mantener colores, columnas, secciones, controles, firmas y declaración. Adaptar campos de empresa mediante configuración cuando corresponda.
- Exportar una rendición por responsable y entrega. Si una consulta autorizada incluye varias, generar formularios separados dentro del libro y una base consolidada sin duplicados.
- Para gastos sin caja, identificar la salida como reporte de gastos del usuario; dejar sin completar los importes de entrega/devolución que no existen.
- Aplicar exactamente el rango de fechas, categorías, responsable y rendición consultados. Un usuario común no puede obtener movimientos de otro cambiando los parámetros.
- Incluir todas las filas coincidentes, ampliar el detalle y actualizar referencias, sumas, controles y área de impresión cuando se superen 20 gastos.
- La hoja CONFIGURACIÓN debe reflejar los catálogos del sistema y ampliar sus rangos cuando crezcan.
- Conservar los campos monetarios calculados como fórmulas y los identificadores como texto literal, evitando que un texto introducido por una persona se interprete como fórmula.

### Consulta parcial frente a rendición completa

Filtrar por hoy, por categoría o por una palabra puede mostrar solo parte de una rendición. No es correcto conciliar todo el dinero entregado contra ese subconjunto y afirmar que el resultado es el cierre real.

Regla propuesta:

- La opción de rendición completa exporta todos los gastos de la entrega y permite conciliar recibido, gastado y devuelto.
- La opción de consulta filtrada exporta únicamente los movimientos coincidentes y su subtotal, con una indicación visible de consulta parcial. No afirma que la caja está rendida ni calcula un saldo final utilizando datos incompletos.
- El historial y la vista de la rendición conservan el saldo completo independientemente del filtro de consulta.

## Verificaciones necesarias durante la implementación

1. Agregar una categoría nueva desde el sistema y usarla sin modificar código.
2. Desactivar una categoría usada y comprobar que sus gastos continúan consultables y exportables.
3. Crear una entrega, registrar gastos, registrar una devolución y conciliar los importes exactos.
4. Comprobar que el usuario, el responsable y el actor de auditoría no se confunden.
5. Exportar cero, uno, veinte y más de veinte gastos, sin ejemplos residuales ni truncamiento.
6. Exportar varias rendiciones y verificar separación por responsable y ausencia de duplicados.
7. Confirmar fechas y ceros iniciales de documentos, series y números en Excel.
8. Comparar los importes del sistema, la hoja de rendición y la base consolidada.
9. Verificar que un filtro parcial no presente una conciliación completa falsa.
10. Revisar visualmente el archivo generado y su impresión, manteniendo el formato recibido.
11. Verificar permisos de consulta, descarga, edición y administración de catálogos.
12. Ejecutar la suite MySQL y las pruebas de navegador del proyecto tras los cambios.
