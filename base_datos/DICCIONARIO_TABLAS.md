# Diccionario de tablas de SIVA

Este documento conserva la fotografía de las **22 tablas activas** de
`siva_inventario`, confirmada directamente en MariaDB el 11 de noviembre de
2026. La migración de `inventario_unidades` añade el seguimiento de llantas
individuales al inventario operativo. La migración `2026_11_18_remove_b4x_excel_scan_cache`
retira `inventario_b4x01_excel_escaneos`, una copia Excel regenerable que ya no
forma parte del flujo directo de conteo. La migración
`2026_11_18_remove_b4x_archive_source` retira las tablas de archivo
`inventario_b4x01_archivos` e `inventario_b4x01_archivo_fuente`; el Excel ya no se
guarda en la base. La migración `2026_11_18_remove_b4x_legacy_scans` retira
`inventario_b4x01_escaneos` y su flujo antiguo de exportación/aplicación por
Excel. El volcado SQL del 6 de noviembre es anterior a estos cambios y conserva
los nombres históricos.

## Mapa general

SIVA mantiene dos inventarios separados:

- **Inventario operativo:** `productos`, `inventario`, `inventario_unidades`,
  compras, ventas y movimientos. `inventario` conserva el saldo por
  producto/almacén; `inventario_unidades` identifica cada llanta física para
  imprimir su código de barras y escanearla al venderla.
- **Inventario B4X01:** `inventario_b4x01` y sus tablas auxiliares. Se usa para
  consultar etiquetas y recibir los conteos de escaneo aprobados; no reemplaza
  el stock operativo.

## Fichas visuales

Hay fichas PNG para las 22 tablas incluidas en la fotografía. Cada imagen
muestra el propósito, el número de registros observado, sus campos y tipos
reales, nulabilidad, valores predeterminados, claves e interrelaciones. La nueva
tabla `inventario_unidades` todavía no tiene ficha PNG. Las fichas tienen fondo
blanco y no reproducen datos personales ni registros de negocio. Su fuente es
el esquema activo de MariaDB de la fecha indicada, no el volcado SQL antiguo.

## Revisión de los flujos y del esquema activo

Se rastrearon las consultas, escrituras y migraciones del código PHP para las
22 tablas de esa fotografía y la migración nueva de `inventario_unidades`.
**Activa** significa que existe en el esquema y tiene una función actual en la
aplicación; no significa que todas sean pantallas ni que todas guarden una fila
por producto. Se agrupan por el tipo de función:

- **Acceso:** `usuarios` conserva cuentas, roles y estado; `intentos_inicio_sesion`
  limita intentos fallidos mediante un identificador con hash.
- **Catálogo e inventario operativo:** `productos` identifica el artículo;
  `almacenes` define ubicaciones y `inventario` conserva el saldo vigente por
  producto/almacén; `inventario_unidades` conserva un registro por llanta
  disponible, vendida o retirada. `movimientos_inventario` registra aperturas,
  compras, salidas y ajustes. Las compras y ventas se separan en cabecera
  (`compras`, `ventas`) y renglones (`detalle_compras`, `detalle_ventas`);
  `compras_auditoria` guarda cambios y anulaciones.
- **B4X01:** `inventario_b4x01` conserva los renglones importados y es un
  inventario independiente. El Excel fuente se procesa al importarlo, pero ya
  no se guarda en una tabla aparte. El QR de conteo actual usa el inventario
  operativo y no persiste eventos en una tabla B4X01 separada.
- **Pronóstico e historial:** `historico_ventas_archivos` guarda el Excel fuente;
  `ventas_excel_importadas` conserva sus filas confirmadas; `historico_ventas_excel`
  unifica las filas históricas y las salidas operativas. Los resultados diarios
  se almacenan en `predicciones_ventas_marcas_diarias` y
  `predicciones_ventas_productos_diarias`.
- **Soporte:** `configuracion_app` guarda metadatos de importación y
  `migraciones_esquema` registra cambios de esquema aplicados.

Se comprobó el esquema activo y el código PHP. Las 22 tablas tienen registros;
Las 16 claves foráneas declaradas y los vínculos lógicos restantes se distinguen
en las fichas de esa fotografía. Los conteos de abajo son una fotografía de la
base al revisar y cambian cuando la aplicación registra nuevas operaciones.
La fila de `inventario_unidades` no tiene un conteo en esta fotografía:
la migración crea una unidad por cada llanta que ya figure en el stock
operativo y luego mantiene las unidades junto con compras, ventas y ajustes.

### Explicación breve para una exposición

> “No todas las tablas son pantallas. Las operativas guardan productos,
> existencias, compras y ventas; las de B4X01 llevan un inventario separado;
> y las de historial, auditoría y soporte permiten importar, consultar
> pronósticos, rastrear cambios y controlar la aplicación. Se revisó que las
> 22 tablas tengan un propósito y referencias en los flujos o migraciones del
> proyecto. El stock está en una sola fuente: `inventario`, separado por
> producto y almacén. Se retiraron las columnas duplicadas y legadas de
> `productos` para que no se confundan con las existencias reales.”

Eso distingue una tabla que **se usa indirectamente** (por ejemplo, el
`detalle_ventas` se consulta junto con `ventas`) de una columna **legada** que
permanece en el esquema, pero no es fuente de información actual. Los listados
debajo y las fichas identifican la función de cada tabla; una cuenta pequeña no
significa que una tabla sea inútil.

### Limpieza de columnas duplicadas

La migración `2026_11_11_remove_legacy_product_stock` retiró de `productos`
`stock_lima`, `stock_huancayo` y las copias
`stock_a0101`, `stock_a0102`, `stock_a0103`. Los dos campos por ciudad tenían
cero en los 1.479 productos. Las copias por almacén diferían del saldo vigente
en seis productos, así que no se usaron para corregir existencias. El saldo
oficial ya estaba en `inventario`; el formulario conserva el ingreso inicial y
la aplicación lo guarda allí. La migración verifica que ningún producto quede
sin sus tres filas de inventario y se detiene si encuentra stock legado
positivo.

El inventario operativo, los 1.479 productos y sus 4.437 saldos por almacén se
conservaron durante la limpieza. `stock_a0101`, `stock_a0102` y `stock_a0103`
siguen siendo nombres de campos de formulario/salida calculados desde
`inventario`; ya no son columnas físicas de `productos`.

- `historico_valorizado_excel`: **no es una de las 22 tablas activas**. El
  código aún conserva el DDL de una migración antigua, pero la tabla no existe
  en MariaDB ni se consulta en las rutas de negocio actuales. No debe contarse
  como funcionalidad activa sin reactivar y probar ese flujo.

## Conteo y estado observados

| Tabla | Registros | Qué confirma el conteo |
|---|---:|---|
| `almacenes` | 3 | Ubicaciones operativas activas. |
| `compras` | 4 | Cabeceras de compras registradas. |
| `compras_auditoria` | 4 | Eventos de edición/anulación conservados. |
| `configuracion_app` | 1 | Una clave de configuración de importación. |
| `detalle_compras` | 4 | Renglones de las compras. |
| `detalle_ventas` | 7 | Renglones de las ventas. |
| `historico_ventas_archivos` | 2 | Archivos fuente conservados. |
| `historico_ventas_excel` | 16.155 | 16.148 filas de origen Excel y 7 de origen manual. |
| `intentos_inicio_sesion` | 1 | Ventana de intentos almacenada por identificador con hash. |
| `inventario` | 4.437 | Saldo por cada uno de 1.479 productos en 3 almacenes. |
| `inventario_unidades` | Sin conteo en esta fotografía | La migración crea una fila por cada llanta del inventario operativo. |
| `inventario_b4x01` | 4.383 | Inventario B4X01 independiente; 1.461 filas por almacén. |
| `inventario_b4x01_archivos` | 1 | Nombre anterior de la tabla del archivo fuente B4X01. |
| `inventario_b4x01_escaneos` | 1 | Lectura en esta fotografía histórica; tabla retirada el 18/11/2026. |
| `migraciones_esquema` | 13 | Versiones de migración registradas como aplicadas. |
| `movimientos_inventario` | 1.119 | Eventos de cambios al inventario operativo. |
| `predicciones_ventas_marcas_diarias` | 357 | Resultados de pronóstico por marca y día. |
| `predicciones_ventas_productos_diarias` | 3.059 | Resultados de pronóstico por producto y día. |
| `productos` | 1.479 | Artículos del catálogo operativo. |
| `usuarios` | 1 | Una cuenta activa con rol administrador. |
| `ventas` | 7 | Cabeceras de salidas registradas. |
| `ventas_excel_importadas` | 1.009 | Filas de ventas importadas para pronóstico. |

No se encontró una tabla activa vacía. Que una tabla tenga pocas filas no la
vuelve innecesaria: por ejemplo, auditoría, configuración, migraciones y archivos
guardan un evento, una clave o un archivo por registro, no una fila por producto.

Las 16.148 filas con origen Excel de `historico_ventas_excel` comparten un
`archivo_hash` que no coincide con los dos archivos binarios de
`historico_ventas_archivos`. No hay una clave foránea que obligue a conservar
esa relación. Las filas siguen alimentando el historial y los pronósticos, pero
el archivo original de ese lote histórico no está entre los dos archivos
guardados; no se deben borrar ni volver a importar esas filas por este motivo.

## Operación diaria

| Tabla | Para qué sirve | Ficha |
|---|---|---|
| `almacenes` | Catálogo de almacenes y su estado activo. Incluye A0101, A0102 y A0103. | [PNG](diagramas_tablas/almacenes.png) |
| `usuarios` | Cuentas del personal, credenciales protegidas, rol y estado de acceso. | [PNG](diagramas_tablas/usuarios.png) |
| `productos` | Catálogo operativo: SKU, nombre, categoría, marca, medida, precio, proveedor e imagen. | [PNG](diagramas_tablas/productos.png) |
| `inventario` | Cantidad operativa de cada producto por almacén, mínimos y fecha de actualización. | [PNG](diagramas_tablas/inventario.png) |
| `inventario_unidades` | Código de barras y estado de cada llanta del inventario operativo; permite escanear la unidad exacta al venderla. | Sin ficha en la fotografía anterior |
| `ventas` | Encabezado de cada salida: número, almacén, fecha, usuario y notas. | [PNG](diagramas_tablas/ventas.png) |
| `detalle_ventas` | Productos, cantidades y precios registrados en cada venta. | [PNG](diagramas_tablas/detalle_ventas.png) |
| `compras` | Encabezado de cada recepción: proveedor, documento obligatorio, almacén, fecha, usuario y estado activo/anulado. | [PNG](diagramas_tablas/compras.png) |
| `detalle_compras` | Productos, cantidades y costos recibidos en cada compra. | [PNG](diagramas_tablas/detalle_compras.png) |
| `compras_auditoria` | Valores anteriores/nuevos y responsable de cada edición o anulación de compra. | [PNG](diagramas_tablas/compras_auditoria.png) |
| `movimientos_inventario` | Auditoría de cambios del stock operativo: variación, almacén, usuario, referencia y notas. | [PNG](diagramas_tablas/movimientos_inventario.png) |

Las pantallas **Inventario → Comparar almacenes** y **Alertas de faltantes** usan
estas tablas operativas. La comparación presenta las cantidades por almacén sin
mezclarlas con B4X01. Las alertas se calculan en cada consulta según `cantidad`,
`stock_minimo`, `stock_seguridad` y `stock_en_transito`; no se guardan como
registros independientes y cambian al registrar una compra, salida o ajuste.

## Inventario B4X01 y escaneo

| Tabla | Para qué sirve | Ficha |
|---|---|---|
| `inventario_b4x01` | Copia independiente del inventario B4X01, con códigos de barras, datos de llanta y cantidades por almacén. Incluye productos manuales con `es_manual = 1`. | [PNG](diagramas_tablas/inventario_b4x01.png) |

### Estado consultado en MariaDB · 18/11/2026

| Tabla | Filas | Función y decisión |
|---|---:|---|
| `inventario` | 4.452 | Stock operativo usado por Ventas, Compras, alertas y conteo físico. Conservar. |
| `inventario_unidades` | 73 | Identifica llantas individuales para sus códigos y estados. Conservar. |
| `inventario_b4x01` | 4.383 | Inventario B4X01 independiente, consultado por la pantalla y las búsquedas de etiquetas. No es solo el archivo Excel. Conservar. |

Las tablas `inventario_b4x01_excel_escaneos`, `inventario_b4x01_archivos`,
`inventario_b4x01_archivo_fuente` e `inventario_b4x01_escaneos` fueron retiradas.
Las tres lecturas pendientes del flujo antiguo se descartaron al retirar su
tabla. El importador guarda las filas en `inventario_b4x01` y los metadatos en
`configuracion_app`, pero ya no conserva el XLSX en MariaDB. El módulo operativo
usa `inventario` e `inventario_unidades`; no tenía una tabla aparte para guardar
el archivo del inventario.

## Ventas históricas e importaciones para pronóstico

| Tabla | Para qué sirve | Ficha |
|---|---|---|
| `ventas_excel_importadas` | Filas del Excel mensual confirmado que alimenta el pronóstico actual de ventas. | [PNG](diagramas_tablas/ventas_excel_importadas.png) |
| `historico_ventas_archivos` | Conserva los Excel originales confirmados, identificados por hash, junto con periodo y datos de importación. | [PNG](diagramas_tablas/historico_ventas_archivos.png) |
| `historico_ventas_excel` | Historial consultado por los pronósticos: 16.148 filas Excel y 7 manuales. Su hash no enlaza con los dos archivos binarios actualmente guardados. | [PNG](diagramas_tablas/historico_ventas_excel.png) |
| `predicciones_ventas_marcas_diarias` | Resultados diarios del pronóstico por marca, guardados por archivo y fecha objetivo. | [PNG](diagramas_tablas/predicciones_ventas_marcas_diarias.png) |
| `predicciones_ventas_productos_diarias` | Resultados diarios del pronóstico por producto/llanta, guardados por archivo y fecha objetivo. | [PNG](diagramas_tablas/predicciones_ventas_productos_diarias.png) |

## Sistema y control

| Tabla | Para qué sirve | Ficha |
|---|---|---|
| `configuracion_app` | Valores de configuración y metadatos de importación usados por la aplicación. | [PNG](diagramas_tablas/configuracion_app.png) |
| `migraciones_esquema` | Registro de las migraciones ya aplicadas; evita repetir cambios de estructura o cargas de compatibilidad. | [PNG](diagramas_tablas/migraciones_esquema.png) |
| `intentos_inicio_sesion` | Ventana temporal de intentos fallidos para limitar intentos de acceso; no es historial de actividad del usuario. | [PNG](diagramas_tablas/intentos_inicio_sesion.png) |

## Tablas retiradas

| Tabla retirada | Motivo | Acción realizada |
|---|---|---|
| `equivalencias_codigos_ventas` | Estaba vacía y no tenía uso detectado en el código actual. | Retirada con autorización. Estructura respaldada en `C:\Users\usuario\Documents\Respaldo_SIVA\tablas_retiradas_20261109.sql`. |
| `predicciones_ventas_mensuales` | Tenía 12.039 filas y 2.166 códigos; no se encontró una pantalla o proceso activo que la consultara o actualizara. | Retirada con autorización. Estructura y datos respaldados en `C:\Users\usuario\Documents\Respaldo_SIVA\tablas_retiradas_20261109.sql`. |
| `inventario_b4x01_excel_escaneos` | Guardaba una copia Excel regenerable del flujo antiguo. | Retirada por la migración `2026_11_18_remove_b4x_excel_scan_cache`. |
| `inventario_b4x01_escaneos` | Guardaba lecturas pendientes del flujo Excel antiguo, no del QR de conteo actual. | Retirada por `2026_11_18_remove_b4x_legacy_scans`; se descartaron sus 3 lecturas pendientes. |

El registro histórico de migración mensual se conserva en `migraciones_esquema`
como auditoría. Se retiró del código la migración que creaba la tabla, para que
no vuelva a aparecer en una instalación nueva.

## Nota de consistencia del esquema

El código conserva una migración para crear `historico_valorizado_excel`, pero
esa tabla no aparece en la base activa y la migración figura como aplicada. La
tabla no se utiliza en las rutas actuales. No se recreó durante la revisión;
antes de hacerlo hay que confirmar si se necesita ese historial valorizado.

La limpieza retiró las columnas de stock por ciudad y las copias guardadas en
`productos`. Antes de retirarlas se comprobó que `stock_lima` y `stock_huancayo`
valían cero en todos los artículos; las copias por almacén diferían del saldo
vigente en seis casos. No se usaron esas copias para modificar existencias. Los
nombres `stock_a0101`, `stock_a0102` y `stock_a0103` que quedan en PHP son
campos de entrada o alias de consulta calculados desde `inventario`, no
columnas físicas del catálogo.

`inventario` tiene exactamente 4.437 filas: las 1.479 del catálogo combinadas
con los tres almacenes activos. No se encontraron productos ni almacenes
huérfanos en esas filas. `inventario_b4x01`, en cambio, es una base independiente
importada y escaneada; no suma ni reemplaza las existencias operativas.

`ventas.cliente_id` no existe en el esquema activo; por eso no está en la ficha.
La migración antigua solo modifica ese campo si encuentra una instalación que
ya lo tenga.

La tabla `historico_valorizado_excel` tampoco forma parte de las 22 tablas
activas: no existe en MariaDB y no hay consultas de negocio que la usen. El
código aún conserva una migración de creación, pero su versión figura aplicada,
por lo que no se ejecuta en esta instalación. No se contó como tabla activa ni
se recreó.

MariaDB se verificó en modo normal (`innodb_force_recovery=0`). Las tablas de
SIVA pasan la comprobación de integridad de `mysqlcheck`; las reparaciones
anteriores correspondieron a tablas internas de MariaDB, no a estas tablas.

## Precauciones

- No confundir `inventario` con `inventario_b4x01`: representan saldos distintos.
- No borrar tablas de historial, importación o auditoría solo porque no aparezcan
  en una pantalla.
- Antes de retirar una tabla, guardar una copia de la base y comprobar sus
  referencias, dependencias y necesidad de conservación.
- `siva_inventario.sql` es un volcado anterior y puede contener
  estructuras que ya no están activas; las fichas reflejan el esquema local
  comprobado.
- MySQL/MariaDB no agrupa físicamente tablas por categoría en phpMyAdmin. Este
  diccionario las organiza por función; los nombres de las tablas permanecen
  intactos.
