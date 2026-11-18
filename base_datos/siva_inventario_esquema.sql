-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1
-- Tiempo de generación: 06-11-2026 a las 23:07:46
-- Versión del servidor: 10.4.32-MariaDB
-- Versión de PHP: 8.2.12


SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `siva_inventario`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `almacenes`
--

CREATE TABLE `almacenes` (
  `codigo` varchar(32) NOT NULL,
  `nombre` varchar(120) NOT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `almacenes`
--


-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `compras`
--

CREATE TABLE `compras` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `numero_compra` varchar(64) NOT NULL,
  `almacen_codigo` varchar(32) NOT NULL,
  `proveedor` varchar(255) NOT NULL,
  `documento_proveedor` varchar(120) NOT NULL DEFAULT '',
  `fecha_compra` date NOT NULL,
  `creado_por` bigint(20) UNSIGNED NOT NULL,
  `notas` text NOT NULL,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `estado` varchar(20) NOT NULL DEFAULT 'activa',
  `anulada_en` datetime DEFAULT NULL,
  `anulada_por` bigint(20) UNSIGNED DEFAULT NULL,
  `motivo_anulacion` varchar(500) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `compras_auditoria`
--

CREATE TABLE `compras_auditoria` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `compra_id` bigint(20) UNSIGNED NOT NULL,
  `evento` varchar(20) NOT NULL,
  `datos_anteriores` longtext NOT NULL,
  `datos_nuevos` longtext NOT NULL,
  `motivo` varchar(500) NOT NULL DEFAULT '',
  `creado_por` bigint(20) UNSIGNED NOT NULL,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `configuracion_app`
--

CREATE TABLE `configuracion_app` (
  `clave` varchar(191) NOT NULL,
  `valor` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `configuracion_app`
--


-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `detalle_compras`
--

CREATE TABLE `detalle_compras` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `compra_id` bigint(20) UNSIGNED NOT NULL,
  `producto_id` bigint(20) UNSIGNED NOT NULL,
  `cantidad` int(10) UNSIGNED NOT NULL,
  `costo_unitario_centavos` bigint(20) UNSIGNED NOT NULL,
  `total_linea_centavos` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `detalle_ventas`
--

CREATE TABLE `detalle_ventas` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `venta_id` bigint(20) UNSIGNED NOT NULL,
  `producto_id` bigint(20) UNSIGNED NOT NULL,
  `cantidad` int(11) NOT NULL,
  `precio_unitario_centavos` bigint(20) NOT NULL,
  `total_linea_centavos` bigint(20) NOT NULL,
  `estado` varchar(24) NOT NULL DEFAULT 'activa',
  `anulada_en` datetime DEFAULT NULL,
  `anulada_por` bigint(20) UNSIGNED DEFAULT NULL,
  `motivo_anulacion` varchar(500) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `detalle_ventas`
--


-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `historico_ventas_archivos`
--

CREATE TABLE `historico_ventas_archivos` (
  `archivo_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `nombre_archivo` varchar(255) NOT NULL,
  `contenido` mediumblob NOT NULL,
  `mes_ventas` date NOT NULL,
  `fecha_corte` date NOT NULL,
  `formato` varchar(20) NOT NULL,
  `almacen_origen` varchar(32) DEFAULT NULL,
  `filas_importadas` int(10) UNSIGNED NOT NULL,
  `importado_por` bigint(20) UNSIGNED DEFAULT NULL,
  `importado_en` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `historico_ventas_archivos`
--


-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `historico_ventas_excel`
--

CREATE TABLE `historico_ventas_excel` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `archivo_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `hoja` varchar(64) NOT NULL,
  `fila_origen` int(10) UNSIGNED NOT NULL,
  `codigo` varchar(191) NOT NULL,
  `marca` varchar(120) NOT NULL,
  `medida` varchar(120) NOT NULL,
  `almacen_codigo` varchar(32) NOT NULL,
  `fecha_venta` date NOT NULL,
  `cantidad` int(11) NOT NULL,
  `tipo_origen` varchar(20) NOT NULL DEFAULT 'excel',
  `requiere_revision` tinyint(1) NOT NULL DEFAULT 0,
  `importado_en` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `historico_ventas_excel`
--


-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `intentos_inicio_sesion`
--

CREATE TABLE `intentos_inicio_sesion` (
  `hash_identificador` char(64) NOT NULL,
  `intentos` int(11) NOT NULL,
  `inicio_ventana` bigint(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `intentos_inicio_sesion`
--


-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `inventario`
--

CREATE TABLE `inventario` (
  `producto_id` bigint(20) UNSIGNED NOT NULL,
  `almacen_codigo` varchar(32) NOT NULL,
  `cantidad` int(11) NOT NULL DEFAULT 0,
  `stock_minimo` int(11) NOT NULL DEFAULT 6,
  `stock_seguridad` int(11) NOT NULL DEFAULT 2,
  `stock_en_transito` int(11) NOT NULL DEFAULT 0,
  `actualizado_en` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `inventario`
--


-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `inventario_b4x01`
--

CREATE TABLE `inventario_b4x01` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `clave_producto` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `codigo_excel` varchar(191) NOT NULL,
  `codigo_barras` varchar(80) NOT NULL,
  `nombre` varchar(255) NOT NULL,
  `categoria` varchar(120) NOT NULL,
  `marca` varchar(120) NOT NULL,
  `medida` varchar(120) NOT NULL,
  `almacen_codigo` varchar(32) NOT NULL,
  `cantidad` int(11) NOT NULL DEFAULT 0,
  `stock_minimo` int(11) NOT NULL DEFAULT 6,
  `stock_seguridad` int(11) NOT NULL DEFAULT 2,
  `stock_en_transito` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `inventario_b4x01`
--


-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `inventario_b4x01_archivos`
--

CREATE TABLE `inventario_b4x01_archivos` (
  `id` tinyint(3) UNSIGNED NOT NULL,
  `nombre_archivo` varchar(255) NOT NULL,
  `archivo_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `contenido` mediumblob NOT NULL,
  `metadata` longtext NOT NULL,
  `importado_en` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `inventario_b4x01_archivos`
--


-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `inventario_b4x01_escaneos`
--

CREATE TABLE `inventario_b4x01_escaneos` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `codigo_barras` varchar(80) NOT NULL,
  `codigo_excel` varchar(191) NOT NULL,
  `nombre` varchar(255) NOT NULL,
  `marca` varchar(120) NOT NULL,
  `medida` varchar(120) NOT NULL,
  `almacen_codigo` varchar(32) NOT NULL,
  `escaneado_por` bigint(20) UNSIGNED DEFAULT NULL,
  `escaneado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `aplicado_en` timestamp NULL DEFAULT NULL,
  `aplicado_por` bigint(20) UNSIGNED DEFAULT NULL,
  `descargado_en` timestamp NULL DEFAULT NULL,
  `lote_descarga` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `inventario_b4x01_excel_escaneos`
--

CREATE TABLE `inventario_b4x01_excel_escaneos` (
  `id` tinyint(3) UNSIGNED NOT NULL,
  `contenido` longblob NOT NULL,
  `actualizado_en` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `migraciones_esquema`
--

CREATE TABLE `migraciones_esquema` (
  `version` varchar(191) NOT NULL,
  `aplicado_en` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `migraciones_esquema`
--


-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `movimientos_inventario`
--

CREATE TABLE `movimientos_inventario` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `producto_id` bigint(20) UNSIGNED NOT NULL,
  `almacen_codigo` varchar(32) NOT NULL,
  `tipo_movimiento` varchar(32) NOT NULL,
  `cambio_cantidad` int(11) NOT NULL,
  `tipo_referencia` varchar(64) NOT NULL DEFAULT '',
  `referencia_id` bigint(20) UNSIGNED DEFAULT NULL,
  `creado_por` bigint(20) UNSIGNED DEFAULT NULL,
  `notas` text NOT NULL,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `movimientos_inventario`
--


-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `predicciones_ventas_marcas_diarias`
--

CREATE TABLE `predicciones_ventas_marcas_diarias` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `archivo_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `marca` varchar(120) NOT NULL,
  `fecha_corte` date NOT NULL,
  `fecha_objetivo` date NOT NULL,
  `unidades_estimadas` int(10) UNSIGNED NOT NULL,
  `calculado_en` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `predicciones_ventas_marcas_diarias`
--


-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `predicciones_ventas_mensuales`
--

CREATE TABLE `predicciones_ventas_mensuales` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `calculo_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `codigo` varchar(191) NOT NULL,
  `almacen_codigo` varchar(32) NOT NULL,
  `mes_objetivo` date NOT NULL,
  `prediccion` decimal(12,2) NOT NULL,
  `prediccion_unidades` int(10) UNSIGNED NOT NULL,
  `fecha_calculo` datetime NOT NULL DEFAULT current_timestamp(),
  `venta_real` int(11) DEFAULT NULL,
  `error_unidades` int(11) DEFAULT NULL,
  `error_absoluto` int(10) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `predicciones_ventas_mensuales`
--


-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `predicciones_ventas_productos_diarias`
--

CREATE TABLE `predicciones_ventas_productos_diarias` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `archivo_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `producto_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `codigo` varchar(191) NOT NULL DEFAULT '',
  `marca` varchar(120) NOT NULL,
  `medida` varchar(120) NOT NULL,
  `modelo` varchar(120) NOT NULL DEFAULT '',
  `fecha_corte` date NOT NULL,
  `fecha_objetivo` date NOT NULL,
  `unidades_estimadas` int(10) UNSIGNED NOT NULL,
  `calculado_en` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `predicciones_ventas_productos_diarias`
--


-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `productos`
--

CREATE TABLE `productos` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `codigo_sku` varchar(191) NOT NULL,
  `nombre` varchar(255) NOT NULL,
  `categoria` varchar(120) NOT NULL,
  `marca` varchar(120) NOT NULL,
  `medida` varchar(120) NOT NULL,
  `descripcion` text NOT NULL,
  `precio_unitario_centavos` bigint(20) NOT NULL DEFAULT 0,
  `proveedor` varchar(255) NOT NULL DEFAULT '',
  `ruta_imagen` varchar(512) DEFAULT NULL,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `productos`
--


-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `usuarios`
--

CREATE TABLE `usuarios` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `nombre_completo` varchar(120) NOT NULL,
  `correo` varchar(254) NOT NULL,
  `hash_contrasena` varchar(255) NOT NULL,
  `rol` varchar(32) NOT NULL DEFAULT 'administrador',
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `usuarios`
--


-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `ventas`
--

CREATE TABLE `ventas` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `numero_venta` varchar(64) NOT NULL,
  `almacen_codigo` varchar(32) NOT NULL,
  `fecha_venta` date NOT NULL,
  `creado_por` bigint(20) UNSIGNED NOT NULL,
  `notas` text NOT NULL,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `ventas`
--


-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `ventas_excel_importadas`
--

CREATE TABLE `ventas_excel_importadas` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `archivo_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `hoja` varchar(64) NOT NULL,
  `fila_origen` int(10) UNSIGNED NOT NULL,
  `codigo` varchar(191) NOT NULL,
  `marca` varchar(120) NOT NULL,
  `medida` varchar(120) NOT NULL,
  `modelo` varchar(120) NOT NULL DEFAULT '',
  `almacen_codigo` varchar(32) NOT NULL,
  `fecha_venta` date NOT NULL,
  `cantidad` int(11) NOT NULL,
  `requiere_revision` tinyint(1) NOT NULL DEFAULT 0,
  `importado_en` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `ventas_excel_importadas`
--


--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `almacenes`
--
ALTER TABLE `almacenes`
  ADD PRIMARY KEY (`codigo`);

--
-- Indices de la tabla `compras`
--
ALTER TABLE `compras`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `numero_compra` (`numero_compra`),
  ADD KEY `idx_compras_fecha` (`fecha_compra`),
  ADD KEY `idx_compras_estado_fecha` (`estado`,`fecha_compra`),
  ADD KEY `fk_compras_almacen` (`almacen_codigo`),
  ADD KEY `fk_compras_usuario` (`creado_por`);

--
-- Indices de la tabla `compras_auditoria`
--
ALTER TABLE `compras_auditoria`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_compras_auditoria_compra_fecha` (`compra_id`,`creado_en`);

--
-- Indices de la tabla `configuracion_app`
--
ALTER TABLE `configuracion_app`
  ADD PRIMARY KEY (`clave`);

--
-- Indices de la tabla `detalle_compras`
--
ALTER TABLE `detalle_compras`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_detalle_compra` (`compra_id`),
  ADD KEY `fk_detalle_compra_producto` (`producto_id`);

--
-- Indices de la tabla `detalle_ventas`
--
ALTER TABLE `detalle_ventas`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_detalle_venta` (`venta_id`),
  ADD KEY `fk_detalle_producto` (`producto_id`);

--
-- Indices de la tabla `historico_ventas_archivos`
--
ALTER TABLE `historico_ventas_archivos`
  ADD PRIMARY KEY (`archivo_hash`),
  ADD KEY `idx_historico_archivos_mes` (`mes_ventas`,`importado_en`);

--
-- Indices de la tabla `historico_ventas_excel`
--
ALTER TABLE `historico_ventas_excel`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_historico_ventas_origen` (`archivo_hash`,`hoja`,`fila_origen`),
  ADD KEY `idx_historico_ventas_demanda` (`codigo`,`almacen_codigo`,`fecha_venta`),
  ADD KEY `idx_historico_origen_mes` (`tipo_origen`,`almacen_codigo`,`fecha_venta`);

--
-- Indices de la tabla `intentos_inicio_sesion`
--
ALTER TABLE `intentos_inicio_sesion`
  ADD PRIMARY KEY (`hash_identificador`);

--
-- Indices de la tabla `inventario`
--
ALTER TABLE `inventario`
  ADD PRIMARY KEY (`producto_id`,`almacen_codigo`),
  ADD KEY `fk_inventario_almacen` (`almacen_codigo`);

--
-- Indices de la tabla `inventario_b4x01`
--
ALTER TABLE `inventario_b4x01`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_inventario_b4x01_barcode_warehouse` (`codigo_barras`,`almacen_codigo`),
  ADD UNIQUE KEY `uq_inventario_b4x01_item_warehouse` (`clave_producto`,`almacen_codigo`),
  ADD KEY `idx_inventario_b4x01_busqueda` (`codigo_barras`,`almacen_codigo`),
  ADD KEY `idx_inventario_b4x01_nombre` (`nombre`);

--
-- Indices de la tabla `inventario_b4x01_archivos`
--
ALTER TABLE `inventario_b4x01_archivos`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `inventario_b4x01_escaneos`
--
ALTER TABLE `inventario_b4x01_escaneos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_b4x_escaneos_almacen_fecha` (`almacen_codigo`,`escaneado_en`),
  ADD KEY `idx_b4x_escaneos_codigo_fecha` (`codigo_barras`,`escaneado_en`),
  ADD KEY `fk_b4x_escaneos_usuario` (`escaneado_por`),
  ADD KEY `idx_b4x_escaneos_pendientes` (`aplicado_en`),
  ADD KEY `fk_b4x_escaneos_aplicado_por` (`aplicado_por`),
  ADD KEY `idx_b4x_escaneos_lote` (`descargado_en`,`aplicado_en`),
  ADD KEY `idx_b4x_escaneos_lote_id` (`lote_descarga`);

--
-- Indices de la tabla `inventario_b4x01_excel_escaneos`
--
ALTER TABLE `inventario_b4x01_excel_escaneos`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `migraciones_esquema`
--
ALTER TABLE `migraciones_esquema`
  ADD PRIMARY KEY (`version`);

--
-- Indices de la tabla `movimientos_inventario`
--
ALTER TABLE `movimientos_inventario`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_movimientos_producto_fecha` (`producto_id`,`creado_en`),
  ADD KEY `fk_movimientos_almacen` (`almacen_codigo`),
  ADD KEY `fk_movimientos_usuario` (`creado_por`);

--
-- Indices de la tabla `predicciones_ventas_marcas_diarias`
--
ALTER TABLE `predicciones_ventas_marcas_diarias`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_prediccion_marca_archivo_dia` (`archivo_hash`,`marca`,`fecha_objetivo`),
  ADD KEY `idx_prediccion_marca_fecha` (`fecha_objetivo`,`marca`),
  ADD KEY `idx_prediccion_marca_archivo` (`archivo_hash`,`calculado_en`);

--
-- Indices de la tabla `predicciones_ventas_mensuales`
--
ALTER TABLE `predicciones_ventas_mensuales`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_predicciones_calculo_producto_almacen` (`calculo_id`,`codigo`,`almacen_codigo`),
  ADD KEY `idx_predicciones_codigo_almacen_mes` (`codigo`,`almacen_codigo`,`mes_objetivo`,`fecha_calculo`),
  ADD KEY `idx_predicciones_mes_calculo` (`mes_objetivo`,`calculo_id`);

--
-- Indices de la tabla `predicciones_ventas_productos_diarias`
--
ALTER TABLE `predicciones_ventas_productos_diarias`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_prediccion_producto_archivo_dia` (`archivo_hash`,`producto_hash`,`fecha_objetivo`),
  ADD KEY `idx_prediccion_producto_fecha` (`fecha_objetivo`,`marca`,`medida`),
  ADD KEY `idx_prediccion_producto_archivo` (`archivo_hash`,`calculado_en`);

--
-- Indices de la tabla `productos`
--
ALTER TABLE `productos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `codigo_sku` (`codigo_sku`),
  ADD KEY `idx_productos_nombre` (`nombre`),
  ADD KEY `idx_productos_marca` (`marca`);

--
-- Indices de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `correo` (`correo`);

--
-- Indices de la tabla `ventas`
--
ALTER TABLE `ventas`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `numero_venta` (`numero_venta`),
  ADD KEY `idx_ventas_fecha` (`fecha_venta`),
  ADD KEY `fk_ventas_almacen` (`almacen_codigo`),
  ADD KEY `fk_ventas_usuario` (`creado_por`);

--
-- Indices de la tabla `ventas_excel_importadas`
--
ALTER TABLE `ventas_excel_importadas`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_ventas_excel_origen` (`archivo_hash`,`hoja`,`fila_origen`),
  ADD KEY `idx_ventas_excel_archivo_fecha` (`archivo_hash`,`fecha_venta`),
  ADD KEY `idx_ventas_excel_mes_fecha` (`fecha_venta`,`almacen_codigo`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `compras`
--
ALTER TABLE `compras`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `compras_auditoria`
--
ALTER TABLE `compras_auditoria`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `detalle_compras`
--
ALTER TABLE `detalle_compras`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `detalle_ventas`
--
ALTER TABLE `detalle_ventas`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de la tabla `historico_ventas_excel`
--
ALTER TABLE `historico_ventas_excel`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=98411;

--
-- AUTO_INCREMENT de la tabla `inventario_b4x01`
--
ALTER TABLE `inventario_b4x01`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4375;

--
-- AUTO_INCREMENT de la tabla `inventario_b4x01_escaneos`
--
ALTER TABLE `inventario_b4x01_escaneos`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT de la tabla `movimientos_inventario`
--
ALTER TABLE `movimientos_inventario`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2512;

--
-- AUTO_INCREMENT de la tabla `predicciones_ventas_marcas_diarias`
--
ALTER TABLE `predicciones_ventas_marcas_diarias`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19441;

--
-- AUTO_INCREMENT de la tabla `predicciones_ventas_mensuales`
--
ALTER TABLE `predicciones_ventas_mensuales`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13560;

--
-- AUTO_INCREMENT de la tabla `predicciones_ventas_productos_diarias`
--
ALTER TABLE `predicciones_ventas_productos_diarias`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=96730;

--
-- AUTO_INCREMENT de la tabla `productos`
--
ALTER TABLE `productos`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3419;

--
-- AUTO_INCREMENT de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de la tabla `ventas`
--
ALTER TABLE `ventas`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de la tabla `ventas_excel_importadas`
--
ALTER TABLE `ventas_excel_importadas`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1218;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `compras`
--
ALTER TABLE `compras`
  ADD CONSTRAINT `fk_compras_almacen` FOREIGN KEY (`almacen_codigo`) REFERENCES `almacenes` (`codigo`),
  ADD CONSTRAINT `fk_compras_usuario` FOREIGN KEY (`creado_por`) REFERENCES `usuarios` (`id`);

--
-- Filtros para la tabla `detalle_compras`
--
ALTER TABLE `detalle_compras`
  ADD CONSTRAINT `fk_detalle_compra` FOREIGN KEY (`compra_id`) REFERENCES `compras` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_detalle_compra_producto` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`);

--
-- Filtros para la tabla `detalle_ventas`
--
ALTER TABLE `detalle_ventas`
  ADD CONSTRAINT `fk_detalle_producto` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`),
  ADD CONSTRAINT `fk_detalle_venta` FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `inventario`
--
ALTER TABLE `inventario`
  ADD CONSTRAINT `fk_inventario_almacen` FOREIGN KEY (`almacen_codigo`) REFERENCES `almacenes` (`codigo`),
  ADD CONSTRAINT `fk_inventario_producto` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `inventario_b4x01_escaneos`
--
ALTER TABLE `inventario_b4x01_escaneos`
  ADD CONSTRAINT `fk_b4x_escaneos_almacen` FOREIGN KEY (`almacen_codigo`) REFERENCES `almacenes` (`codigo`),
  ADD CONSTRAINT `fk_b4x_escaneos_aplicado_por` FOREIGN KEY (`aplicado_por`) REFERENCES `usuarios` (`id`),
  ADD CONSTRAINT `fk_b4x_escaneos_usuario` FOREIGN KEY (`escaneado_por`) REFERENCES `usuarios` (`id`);

--
-- Filtros para la tabla `movimientos_inventario`
--
ALTER TABLE `movimientos_inventario`
  ADD CONSTRAINT `fk_movimientos_almacen` FOREIGN KEY (`almacen_codigo`) REFERENCES `almacenes` (`codigo`),
  ADD CONSTRAINT `fk_movimientos_producto` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`),
  ADD CONSTRAINT `fk_movimientos_usuario` FOREIGN KEY (`creado_por`) REFERENCES `usuarios` (`id`);

--
-- Filtros para la tabla `ventas`
--
ALTER TABLE `ventas`
  ADD CONSTRAINT `fk_ventas_almacen` FOREIGN KEY (`almacen_codigo`) REFERENCES `almacenes` (`codigo`),
  ADD CONSTRAINT `fk_ventas_usuario` FOREIGN KEY (`creado_por`) REFERENCES `usuarios` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
