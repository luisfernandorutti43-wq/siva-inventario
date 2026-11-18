# BLACK4X4 LLANTAS

## Exposición del proyecto

### Guion para presentar

**Buenos días. Mi proyecto se llama BLACK4X4 LLANTAS. Es una aplicación web para organizar la información de una empresa que vende llantas y necesita consultar sus productos, registrar salidas, revisar existencias y analizar sus ventas históricas.**

El sistema está pensado para trabajar con tres almacenes: A0101, que corresponde a Castilla; A0102, Jr. Arequipa / Huancayo; y A0103, Atalaya. Una necesidad importante del proyecto es consultar las cantidades por almacén y mantener separado el histórico de ventas del inventario disponible.

La aplicación cuenta con los módulos **Panel general**, **Productos**, **Compras**, **Salidas / Ventas** e **Inventario**; este último incluye el **Pronóstico de ventas**. En el Panel general aparecen accesos a tareas frecuentes según el permiso de cada persona.

La página de pronóstico combina 75 % de Random Forest con 25 % del promedio de ventas del mismo día de las últimas ocho semanas para estimar ventas por llanta; la vista por marca suma esas mismas estimaciones. El Administrador importa el Excel más reciente para seleccionar el período y los productos que se pronostican. Ninguna estimación crea compras ni modifica existencias.

La importación mensual tiene una pantalla de revisión antes de guardar. Muestra el archivo, las filas, el periodo, el almacén si aplica y las fechas que se estimarán. Al confirmar, guarda en una transacción las filas en `ventas_excel_importadas` y el Excel original en `historico_ventas_archivos`; cancelar no guarda ninguno. El sistema identifica archivos por hash y advierte si se intenta importar el mismo archivo otra vez. Las importaciones históricas y las salidas operativas se conservan en `historico_ventas_excel` con su origen.

La aplicación se desarrolló en PHP y se conecta a MariaDB/MySQL con PDO. La interfaz usa HTML, CSS y JavaScript. La base de datos almacena las cuentas, los productos, las ventas del sistema, el inventario, los movimientos y, por separado, el histórico que usa el pronóstico. Esta separación permite analizar ventas pasadas sin confundirlas con el stock actual.

El proyecto separa sus responsabilidades siguiendo MVC. La organización está en español y cada carpeta tiene una función concreta:

| Carpeta o archivo | Para qué sirve |
|---|---|
| `aplicacion/Controladores/` | Recibe las solicitudes de la página y coordina qué hacer. |
| `aplicacion/Modelos/` | Consulta y guarda información en MySQL/MariaDB. |
| `aplicacion/Vistas/paginas/` | Contiene las pantallas HTML que ve la persona usuaria. |
| `aplicacion/Servicios/` | Reúne funciones compartidas, como pronósticos, lectura de Excel y generación de códigos de barras y QR. |
| `configuracion/` | Guarda la conexión y configuración local de la aplicación. |
| `base_datos/` | Contiene el esquema SQL y el diccionario de tablas. |
| `publico/recursos/` | Contiene estilos, scripts e imágenes que carga el navegador. |
| `publico/subidas/productos/` | Guarda imágenes de productos cargadas desde la aplicación. |
| `vistas/index.php` | Conserva la dirección antigua de entrada para que no cambie el acceso de XAMPP. |
| `publico/enrutador.php` | Revisa el parámetro `pagina` y llama al controlador correspondiente. |
| `pruebas/` | Contiene comprobaciones automáticas para funciones y módulos. |
| `analisis/` | Guarda notebooks y herramientas independientes de análisis. |
| `analisis/.venv/` | Entorno aislado de Python que usa el pronóstico de IA; evita instalar sus paquetes en todo el equipo. |
| `requirements.txt` | Lista los paquetes de Python que necesita el pronóstico. |
| `composer.json` y `composer.lock` | Indican las bibliotecas PHP que necesita el proyecto. |
| `vendor/` | Guarda las bibliotecas PHP instaladas por Composer; no se edita ni se renombra manualmente. |

El inventario, propósito y estado de revisión de cada tabla de la base de datos se documentan en [`base_datos/DICCIONARIO_TABLAS.md`](base_datos/DICCIONARIO_TABLAS.md).

Los módulos operativos usan diseño adaptable para celular y tablet: la navegación se reorganiza, los formularios se ajustan al ancho disponible y las tablas anchas se pueden desplazar dentro de su propio contenedor.

También se contemplan medidas básicas de seguridad: contraseñas protegidas con hash, consultas preparadas, sesiones protegidas, permisos por rol y tokens CSRF para formularios. El módulo **Usuarios y permisos** es exclusivo del administrador y permite crear cuentas, asignar roles, activar o desactivar cuentas y restablecer contraseñas. Administrador y Almacén pueden registrar compras; Administrador y Vendedor pueden registrar salidas; Inventario queda disponible para Administrador y Almacén. Las rutas validan los roles en el servidor además de ocultar los enlaces que no corresponden. El sistema impide desactivar o degradar la última cuenta administradora activa.

**SIVA mantiene dos inventarios distintos.** El inventario operativo (`inventario`) es el que consumen Ventas y Compras. La copia B4X01 (`inventario_b4x01`) se usa para consultar/etiquetar llantas y recibir escaneos aprobados; esos escaneos no cambian el stock operativo. No se deben sumar ni comparar ambos saldos como si fueran una sola existencia.

En **Inventario → Escaneo**, las etiquetas impresas conservan el QR y también incluyen un código de barras. Puedes abrir el QR con la cámara del celular o escanear las barras con el lector conectado a SIVA; cada lectura se registra como pendiente sin cambiar el stock. Al terminar, descarga y revisa el Excel antes de aprobarlo; solo al subirlo se actualiza B4X01. Cada ID de escaneo se aplica una sola vez. **Inventario → Conteo físico** corresponde al inventario operativo: sus etiquetas también incluyen QR y código de barras; permite reemplazar el saldo registrado por la cantidad contada y guarda la diferencia como ajuste en Movimientos, con usuario, fecha y motivo. La impresión directa en un lector térmico Bluetooth puede requerir permiso del navegador y compatibilidad con ESC/POS raster; prueba primero con una sola etiqueta.

El pronóstico utiliza ventas observadas. Si un producto estuvo agotado, el historial puede subestimar la demanda potencial. En **Pronóstico por llanta**, SIVA muestra un código B4X01 solo cuando la marca, la medida y la descripción permiten una coincidencia única; los casos ambiguos o sin producto en B4X01 quedan pendientes, sin inventar códigos. Estos códigos B4X01 no están vinculados con los SKU operativos y no deben usarse como recomendaciones de reposición por producto.

Los productos agregados manualmente a B4X01 se identifican como tales y se conservan al reemplazar el inventario con una nueva importación; el stock debe actualizarse solo después de comprobarlo físicamente.

**En conclusión, SIVA integra catálogo, compras, salidas, stock operativo, conteos y un inventario B4X01 separado. El pronóstico apoya el análisis, pero no compra automáticamente ni cambia existencias.**

### Acceso desde celulares para una demostración local

Para abrir una instalación local desde un celular, conecta ambos dispositivos a la misma red Wi-Fi y usa la dirección IP del equipo que ejecuta Apache. No uses `localhost` en el celular: esa dirección apunta al propio teléfono. Si cambia la red o la IP, consulta la configuración de red del equipo anfitrión y actualiza el enlace.

Las etiquetas de producto se imprimen desde **Productos → Imprimir etiquetas**. Su QR abre **Ventas** con el producto y almacén ya seleccionados; revisa la salida y pulsa **Registrar salida** para descontar existencias. El código de barras de las llantas sigue siendo el mismo SKU para todas las unidades iguales; en Ventas, escanéalo una vez por cada llanta. Las etiquetas de **Inventario → Escaneo** y **Conteo físico** conservan sus propios QR y no se confunden con el QR de salida de Ventas.

### Recorrido sugerido de la demostración

1. Iniciar sesión en el Panel general y revisar las alertas de existencias en la campana superior.
2. Abrir **Productos**: el catálogo superior muestra las existencias operativas actuales en A0101, A0102 y A0103, además del total. El administrador puede corregir las cantidades por almacén; cada variación se registra en Movimientos. La tabla inferior muestra cantidades vendidas en 2026, no existencias actuales.
3. Mostrar **Compras**: Administrador y Almacén registran recepciones; la compra, sus detalles y movimientos actualizan el stock de forma transaccional.
4. Señalar las columnas A0101, A0102 y A0103, y explicar que el total es la suma neta del histórico.
5. Abrir **Salidas / Ventas** para registrar una venta; las llantas se agregan escaneando el código de cada unidad con el lector y el stock se descuenta al confirmar. Para otros productos, selecciona el producto y la cantidad.
6. Abrir **Inventario** para consultar el stock operativo por almacén y **Alertas de faltantes** para revisar existencias bajo el mínimo y registrar una compra con producto/almacén sugeridos. **Escaneo B4X01** recibe lecturas QR o de barras; **Conteo físico** actualiza el inventario operativo. Abrir **Movimientos** desde el menú general para consultar compras, ventas, stock inicial y ajustes en un solo historial. Para mostrarlo en un celular, entra por la IP Wi-Fi de la laptop y usa una cuenta autorizada.
7. En **Inventario → Pronóstico de ventas**, consultar la estimación de IA por marca o por llanta. El Administrador puede importar y revisar otro Excel mensual. Ningún pronóstico modifica stock ni genera órdenes automáticamente.

### Preguntas que puede hacer el instructor

**¿El histórico representa el stock actual?**  
No. El histórico registra cantidades vendidas en fechas anteriores. La pantalla Inventario consulta la copia B4X01 guardada en su tabla independiente; el stock operativo que usan Ventas y Compras no se reemplaza con ese archivo.

**¿Por qué el precio aparece como no disponible?**  
El archivo por almacén contiene existencias, pero no incluye precios. Los precios deben completarse por separado.

**¿El sistema compra automáticamente cuando el pronóstico es alto?**  
No. El pronóstico es informativo; no genera órdenes ni actualiza existencias.

**¿Qué significa WAPE?**  
Es una medida del error porcentual absoluto ponderado que se usa en los análisis históricos para comparar estimaciones con ventas reales. Las métricas guardadas en los notebooks son resultados históricos y no garantizan la precisión de un pronóstico actual.

**¿El pronóstico usa Machine Learning o está conectado a Compras?**  
Sí. La pantalla web combina 75 % de Random Forest con 25 % del promedio del mismo día de la semana en las últimas ocho semanas. Usa ese cálculo para las vistas por marca y por llanta; no es un asistente generativo. El pronóstico es informativo y no genera órdenes automáticas.

## Descripción funcional

- **Panel general:** indicadores del catálogo, existencias, alertas y actividad reciente. Separa visualmente el inventario operativo del saldo B4X01 y muestra productos prioritarios para reposición por almacén.
- **Productos:** consulta códigos presentes en el histórico 2026, con ventas netas por almacén. Un precio solo estará disponible si el SKU también existe en el catálogo.
- **Compras:** registra proveedor, factura/documento obligatorio, almacén, fecha, productos y costos; aumenta el inventario y crea movimientos dentro de una transacción. Al recibir llantas, se puede escanear un código de fábrica por unidad; si falta o se repite, SIVA genera un código interno individual. El Administrador puede editar o anular; la anulación revierte stock solo si las unidades siguen disponibles, y conserva motivo, usuario, cambios anteriores y movimientos de ajuste para auditoría. Las compras anuladas se ocultan del historial habitual. Acceso para Administrador y Almacén.
- **Usuarios:** acceso exclusivo para Administrador, separado en la sección Administración al final del menú lateral. Permite crear cuentas, asignar uno de los roles del sistema, activar o desactivar usuarios y restablecer contraseñas; la matriz muestra el acceso de cada rol.
- **Salidas / Ventas:** registra productos, cantidades, almacén, fecha y notas. La salida, el detalle, el movimiento y el descuento de inventario se guardan juntos en una transacción.
- **Inventario:** muestra las existencias operativas por producto y almacén. **Alertas de faltantes** ayuda a identificar qué revisar o reponer; **Conteo físico** corrige el saldo con base en el conteo real y admite QR o código de barras. **Escaneo B4X01** también admite ambas etiquetas y actualiza solo la copia B4X01, separada del stock de Ventas y Compras.
- **Movimientos:** es una sección general del menú, separada de Inventario. Reúne las entradas por compra, las salidas por venta, el stock inicial y los ajustes de conteo, con fecha, almacén, variación, detalle y usuario. No es un Kardex duplicado: registra los cambios que realizan los módulos operativos.
- **Reportes por almacén:** permite filtrar el saldo operativo por almacén y los movimientos por fechas. El Excel descargable contiene las hojas **Existencias** y **Movimientos**, con niveles de reposición y el detalle de usuario, fecha, almacén y motivo. La hoja de existencias es una fotografía actual; las fechas se aplican al historial de movimientos.
- **Alertas de faltantes:** lista productos cuyo saldo operativo está en o debajo del mínimo, distingue stock crítico del nivel de reposición y muestra las unidades en tránsito registradas. El seguimiento es dinámico: los movimientos, recepciones y conteos actualizan el estado; no se descarta una alerta manualmente ni se alteran existencias desde esta pantalla.
- **Conteo físico:** permite comparar el saldo operativo con las unidades contadas. Al confirmar una diferencia, actualiza el saldo y guarda un movimiento de ajuste con usuario, fecha y motivo. No cambia B4X01.
- **Notificaciones:** la campana de la barra superior muestra productos bajo el stock mínimo y destaca los que llegaron al nivel de seguridad.
- **Pronóstico de ventas (dentro de Inventario):** el cálculo combina 75 % de Random Forest con 25 % del promedio de ventas del mismo día de las últimas ocho semanas. Pronostica por llanta; el pronóstico por marca suma los mismos resultados. Requiere Python y scikit-learn. No modifica existencias ni genera compras.

## Pruebas automáticas

Desde la carpeta del proyecto, ejecuta las pruebas de PHP y del modelo:

```powershell
php pruebas/PruebaEvaluadorPronosticoDiario.php
php pruebas/PruebaVistaPreviaEscaneoInventario.php
php pruebas/PruebaCodigoQRInventario.php
php pruebas/PruebaAislamientoInventarioAlmacenes.php
php pruebas/PruebaExportadorExcelReportesInventario.php
.\analisis\.venv\Scripts\python.exe -m unittest pruebas\prueba_pronostico_diario_ventas_ia.py
```

La prueba de aislamiento usa una base SQLite temporal en memoria; comprueba que las compras, ventas y conteos solo cambien el almacén seleccionado, que los códigos de llanta sean únicos y correspondan al almacén, y que las operaciones incompletas reviertan sus cambios.

## Arquitectura

```text
vistas/index.php -> publico/enrutador.php -> aplicacion/Controladores -> aplicacion/Modelos -> base de datos
                    |
                    v
               aplicacion/Vistas/paginas
```

**Controlador:** coordina. **Modelo:** maneja los datos. **Vista:** muestra la pantalla.

`aplicacion/Servicios/` contiene las herramientas compartidas para pronósticos, Excel y códigos de barras. Las pantallas están en `aplicacion/Vistas/paginas/`. La carpeta raíz `vistas/` solo conserva la ruta de entrada anterior; Apache envía sus rutas de recursos a `publico/recursos/`.

En `aplicacion/Vistas/paginas/`, cada archivo es la pantalla de un módulo. `panel_general.php` es la vista inicial: presenta accesos rápidos, resumen y actividad reciente.

### Cómo usar los pronósticos

1. Registra las salidas reales en **Salidas / Ventas**. La operación descuenta el inventario operativo y agrega la venta al histórico.
2. Abre **Inventario → Pronóstico de ventas** para consultar el pronóstico IA por llanta y por marca, que combina 75 % de Random Forest con 25 % del promedio del mismo día de las últimas ocho semanas. El Excel confirmado más recientemente define los productos; el sistema muestra la semana completa, de lunes a domingo (domingo en 0).
3. El Administrador puede importar un Excel mensual. Revisa el archivo y las fechas antes de confirmar. No importes ventas que ya registraste como salidas del mismo periodo.
4. Interpreta el resultado como ventas observadas estimadas, no como demanda total: si hubo falta de stock, las ventas pueden subestimar lo que se habría podido vender.

La página usa `aplicacion/Servicios/pronostico_diario_ventas_ia.py` con ventas diarias históricas hasta el domingo anterior. Combina 75 % de la estimación recursiva de Random Forest con 25 % del promedio de ventas del mismo día en las ocho semanas previas; si Random Forest estima cero y el promedio indica ventas, conserva ese promedio para no ocultar demanda intermitente. El domingo queda en 0 porque no se trabaja. Si varios modelos comparten marca y medida y el histórico no distingue sus SKU, el total del grupo se reparte entre ellos según sus ventas recientes, sin duplicar la estimación. Al terminar la semana, se actualiza el pronóstico para la siguiente. La vista por marca suma las mismas predicciones por llanta. Se requiere Python con los paquetes de `requirements.txt`. El modelo es aprendizaje automático local, no depende de una API externa. Los pronósticos no generan órdenes ni cambian el inventario; los notebooks de `analisis/` siguen siendo herramientas independientes.

**Códigos de llantas:** el SKU identifica el producto y se comparte entre sus unidades; si no tienes un SKU del proveedor, Productos permite generar uno o SIVA lo crea al guardar. Al registrar stock de una categoría Llantas, SIVA genera además un código interno por cada unidad que no tenga un código individual de fábrica. En Ventas, cada lectura del SKU suma una unidad al borrador; pulsa **Registrar salida** al terminar para guardar la venta y asignar las unidades. También acepta códigos individuales, que no pueden repetirse en la misma venta.

**Escaneo B4X01:** en **Inventario → Escaneo**, imprime las etiquetas del almacén correspondiente. Cada una conserva el QR que abre el flujo desde la cámara y agrega un código de barras para lectores; con el lector, selecciona el almacén y escanea en el campo de la página. Ambos formatos registran la misma lectura pendiente. Al terminar, descarga el Excel, revísalo y aplícalo una sola vez. El escaneo por sí solo no cambia el stock; el Excel aprobado actualiza B4X01, no el inventario operativo de Ventas/Compras.

**Conteo físico:** en **Inventario → Conteo físico**, selecciona el almacén, cuenta las unidades reales y escribe el total contado, no la diferencia. Confirma la corrección; SIVA guarda el ajuste y su motivo en Movimientos.

### Tecnologías

- PHP 8.1 o posterior.
- MariaDB o MySQL con PDO MySQL.
- Composer y la biblioteca `chillerlan/php-qrcode` para generar los QR.
- Extensiones PHP `pdo_mysql`, `mbstring`, `phar` y `simplexml`.
- HTML, CSS y JavaScript.
- XAMPP para el entorno local de desarrollo.

## Base de datos y datos de demostración

`base_datos/siva_inventario_esquema.sql` crea la estructura vacía de la base. No contiene cuentas, productos, ventas ni existencias reales. El volcado local completo puede contener datos operativos y se excluye deliberadamente del repositorio. Para probar el pronóstico, usa únicamente los archivos sintéticos de `demostracion/`.

Las tablas separan usuarios, catálogo, almacenes, inventario operativo, movimientos y el histórico importado. `inventario_b4x01` es una copia independiente para escaneo; no se debe confundir ni sumar con el inventario operativo de Ventas y Compras.

## Cómo funciona el pronóstico

El cálculo web está implementado en `aplicacion/Servicios/Servicios.php` y se presenta en `aplicacion/Vistas/paginas/prediccion_marcas.php`.

La página consulta el Excel confirmado más reciente para determinar los productos del pronóstico. El modelo local `aplicacion/Servicios/pronostico_diario_ventas_ia.py` combina 75 % de Random Forest con 25 % del promedio de ventas del mismo día en las últimas ocho semanas, usando el historial de `historico_ventas_excel` y `ventas_excel_importadas` hasta el domingo anterior. Estima, día por día, la semana completa de lunes a domingo; el domingo se fija en 0. Al comenzar la siguiente semana, el pronóstico se actualiza automáticamente. Si el histórico no distingue SKU de modelos con la misma marca y medida, distribuye el total según las ventas recientes para evitar duplicarlo. La vista por marca suma esas mismas estimaciones por llanta. Requiere el intérprete Python del entorno `analisis/.venv` y las dependencias de `requirements.txt`. El modelo no envía datos a servicios externos, no crea compras ni cambia inventario. Los notebooks `analisis/ia_llantas_colab.ipynb` y `analisis/pronostico_rapido_llantas.ipynb` son experimentos independientes y no alimentan el modelo web.

## Instalación local

1. Coloca la carpeta del proyecto (la que contiene `README.md`, `composer.json`, `aplicacion/` y `publico/`) dentro de la carpeta `htdocs` de XAMPP.
2. Inicia Apache y MySQL/MariaDB desde XAMPP.
3. En phpMyAdmin, crea una base vacía llamada `siva_inventario` con cotejamiento `utf8mb4_unicode_ci` e importa `base_datos/siva_inventario_esquema.sql`. Este archivo crea solo la estructura; al abrir SIVA se ejecutan las migraciones y se pueden registrar los datos de demostración.
4. Desde la carpeta del proyecto, ejecuta `composer install`. En el servidor de producción, ejecuta allí `composer install --no-dev --optimize-autoloader`.
5. Instala Python 3.10 o posterior y prepara el entorno del modelo de IA:
   ```powershell
   py -m venv analisis\.venv
   .\analisis\.venv\Scripts\python.exe -m pip install -r requirements.txt
   ```
   Si Apache no encuentra ese entorno, define `SIVA_PYTHON_EXECUTABLE` con la ruta completa a `python.exe`.
6. Si tu entorno no usa la configuración local por defecto, define para Apache:
   - `SIVA_DB_HOST`
   - `SIVA_DB_PORT`
   - `SIVA_DB_NAME`
   - `SIVA_DB_USER`
   - `SIVA_DB_PASSWORD`
   En un alojamiento compartido que no permita definir variables de entorno, copia `configuracion/hosting.local.example.php` como `configuracion/hosting.local.php` y completa los datos de MySQL del proveedor. No publiques ni compartas ese archivo; Apache bloquea el acceso web directo al directorio `configuracion/`.
7. La ruta compatible para la aplicación es `http://localhost/siva-inventario/vistas/index.php`. La dirección `http://localhost/siva-inventario/` también redirige a la entrada existente.

Para publicar una demostración en una VM Ubuntu de Oracle Cloud, sigue la [guía de despliegue](DESPLIEGUE_ORACLE.md). Incluye archivos de ventas sintéticos, no datos de la empresa.

La conexión local predeterminada es `127.0.0.1:3306`, base `siva_inventario`, usuario `root` y contraseña vacía. La creación inicial de administrador aparece cuando la base no tiene usuarios. No uses la configuración local sin protección en un servidor publicado.

## Roles y seguridad

- **Administrador:** puede registrar productos y acceder a los módulos permitidos.
- **Vendedor:** puede consultar productos, registrar salidas y consultar el pronóstico.
- **Almacén:** puede consultar productos e inventario.

La aplicación usa `password_hash` para contraseñas, consultas preparadas mediante PDO, sesiones protegidas, validación CSRF y límites temporales para intentos fallidos de inicio de sesión. Para publicar el sistema fuera de una red local, configura una contraseña para la base de datos y habilita HTTPS.
