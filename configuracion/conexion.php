<?php
declare(strict_types=1);


function app_database(): PDO
{
    $localConfigPath = __DIR__ . DIRECTORY_SEPARATOR . 'hosting.local.php';
    $localConfig = [];
    if (is_file($localConfigPath)) {
        $loadedConfig = require $localConfigPath;
        if (!is_array($loadedConfig)) {
            throw new RuntimeException('The private database configuration must return an array.');
        }
        $localConfig = $loadedConfig;
        foreach (['host', 'name', 'username', 'password'] as $requiredKey) {
            if (!isset($localConfig[$requiredKey])
                || !is_string($localConfig[$requiredKey])
                || trim($localConfig[$requiredKey]) === ''
            ) {
                throw new RuntimeException('The private database configuration is missing ' . $requiredKey . '.');
            }
        }
    }

    $environmentValue = static function (string $variable, string $key, string $default) use ($localConfig): string {
        $value = getenv($variable);
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return is_string($localConfig[$key] ?? null) && $localConfig[$key] !== ''
            ? $localConfig[$key]
            : $default;
    };

    $host = $environmentValue('SIVA_DB_HOST', 'host', '127.0.0.1');
    $port = $environmentValue('SIVA_DB_PORT', 'port', '3306');
    $name = $environmentValue('SIVA_DB_NAME', 'name', 'siva_inventario');
    $username = $environmentValue('SIVA_DB_USER', 'username', 'root');
    $password = $environmentValue('SIVA_DB_PASSWORD', 'password', '');

    $database = new PDO(
        "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => true,
        ]
    );

    app_migrate_optional_sale_customer($database);
    app_migrate_historical_sales($database);
    app_migrate_historical_sales_origin($database);
    app_migrate_historical_sales_files($database);
    app_migrate_manual_sales_forecast_history($database);
    app_migrate_excel_forecast_storage($database);
    app_migrate_warehouses($database);
    app_migrate_remove_legacy_product_stock($database);
    app_migrate_b4x_inventory($database);
    app_migrate_b4x_manual_products($database);
    app_migrate_remove_b4x_excel_scan_cache($database);
    app_migrate_remove_b4x_legacy_scans($database);
    app_migrate_valorized_sales($database);
    app_migrate_purchases($database);
    app_migrate_purchase_cancellations($database);
    app_migrate_inventory_import_metadata($database);
    app_migrate_remove_b4x_archive_source($database);
    app_migrate_operational_inventory_units($database);
    app_migrate_sales_review($database);

    return $database;
}

// Define las migraciones que mantienen actualizado el esquema de la base de datos.
function app_migrate_historical_sales_files(PDO $database): void
{
    $database->exec(
        'CREATE TABLE IF NOT EXISTS historico_ventas_archivos (
          archivo_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
          nombre_archivo VARCHAR(255) NOT NULL,
          contenido MEDIUMBLOB NOT NULL,
          mes_ventas DATE NOT NULL,
          fecha_corte DATE NOT NULL,
          formato VARCHAR(20) NOT NULL,
          almacen_origen VARCHAR(32) NULL,
          filas_importadas INT UNSIGNED NOT NULL,
          importado_por BIGINT UNSIGNED NULL,
          importado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_historico_archivos_mes (mes_ventas, importado_en)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function app_migrate_excel_forecast_storage(PDO $database): void
{
    $database->exec(
        'CREATE TABLE IF NOT EXISTS ventas_excel_importadas (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          archivo_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          hoja VARCHAR(64) NOT NULL,
          fila_origen INT UNSIGNED NOT NULL,
          codigo VARCHAR(191) NOT NULL,
          marca VARCHAR(120) NOT NULL,
          medida VARCHAR(120) NOT NULL,
          modelo VARCHAR(120) NOT NULL DEFAULT \'\',
          almacen_codigo VARCHAR(32) NOT NULL,
          fecha_venta DATE NOT NULL,
          cantidad INT NOT NULL,
          requiere_revision TINYINT(1) NOT NULL DEFAULT 0,
          importado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uq_ventas_excel_origen (archivo_hash, hoja, fila_origen),
          INDEX idx_ventas_excel_archivo_fecha (archivo_hash, fecha_venta),
          INDEX idx_ventas_excel_mes_fecha (fecha_venta, almacen_codigo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $modelColumn = $database->prepare(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'ventas_excel_importadas'
           AND COLUMN_NAME = 'modelo'"
    );
    $modelColumn->execute();
    if ($modelColumn->fetchColumn() === false) {
        $database->exec(
            "ALTER TABLE ventas_excel_importadas
             ADD COLUMN modelo VARCHAR(120) NOT NULL DEFAULT '' AFTER medida"
        );
    }
    $database->exec(
        'CREATE TABLE IF NOT EXISTS predicciones_ventas_marcas_diarias (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          archivo_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          marca VARCHAR(120) NOT NULL,
          fecha_corte DATE NOT NULL,
          fecha_objetivo DATE NOT NULL,
          unidades_estimadas INT UNSIGNED NOT NULL,
          calculado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uq_prediccion_marca_archivo_dia (archivo_hash, marca, fecha_objetivo),
          INDEX idx_prediccion_marca_fecha (fecha_objetivo, marca),
          INDEX idx_prediccion_marca_archivo (archivo_hash, calculado_en)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $database->exec(
        'CREATE TABLE IF NOT EXISTS predicciones_ventas_productos_diarias (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          archivo_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          producto_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          codigo VARCHAR(191) NOT NULL DEFAULT \'\',
          marca VARCHAR(120) NOT NULL,
          medida VARCHAR(120) NOT NULL,
          modelo VARCHAR(120) NOT NULL DEFAULT \'\',
          fecha_corte DATE NOT NULL,
          fecha_objetivo DATE NOT NULL,
          unidades_estimadas INT UNSIGNED NOT NULL,
          calculado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uq_prediccion_producto_archivo_dia (archivo_hash, producto_hash, fecha_objetivo),
          INDEX idx_prediccion_producto_fecha (fecha_objetivo, marca, medida),
          INDEX idx_prediccion_producto_archivo (archivo_hash, calculado_en)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $archiveUserColumn = $database->prepare(
        "SELECT IS_NULLABLE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'historico_ventas_archivos'
           AND COLUMN_NAME = 'importado_por'"
    );
    $archiveUserColumn->execute();
    if ($archiveUserColumn->fetchColumn() === 'NO') {
        $database->exec(
            'ALTER TABLE historico_ventas_archivos
             MODIFY importado_por BIGINT UNSIGNED NULL'
        );
    }

    $version = '2026_10_28_excel_forecast_storage';
    $migrationCheck = $database->prepare('SELECT 1 FROM migraciones_esquema WHERE version = :version');
    $migrationCheck->execute(['version' => $version]);
    if ($migrationCheck->fetchColumn() !== false) {
        return;
    }

    $database->beginTransaction();
    try {
        // Preserve the workbook already imported before the original-file archive was connected.
        $legacyWorkbookPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Ventas_Llantas_01-26_Oct_2026.xlsx';
        $legacyWorkbookHash = '1344cea2594fbb1e6323f3d8628e606be0827b3277a795941908b3748760285c';
        if (is_file($legacyWorkbookPath)) {
            $actualLegacyHash = hash_file('sha256', $legacyWorkbookPath);
            if (is_string($actualLegacyHash) && hash_equals($legacyWorkbookHash, $actualLegacyHash)) {
                $legacyArchiveCheck = $database->prepare(
                    'SELECT 1 FROM historico_ventas_archivos WHERE archivo_hash = :file_hash'
                );
                $legacyArchiveCheck->execute(['file_hash' => $legacyWorkbookHash]);
                if ($legacyArchiveCheck->fetchColumn() === false) {
                    $legacyRows = $database->prepare(
                        "SELECT COUNT(*) AS row_count, MAX(fecha_venta) AS cutoff_date
                         FROM historico_ventas_excel
                         WHERE archivo_hash = :file_hash AND tipo_origen = 'excel'"
                    );
                    $legacyRows->execute(['file_hash' => $legacyWorkbookHash]);
                    $legacyMetadata = $legacyRows->fetch();
                    if ((int) $legacyMetadata['row_count'] > 0) {
                        $legacyContent = file_get_contents($legacyWorkbookPath);
                        if (!is_string($legacyContent)) {
                            throw new RuntimeException('No se pudo leer el Excel existente para separarlo del histórico.');
                        }
                        $legacyArchive = $database->prepare(
                            'INSERT INTO historico_ventas_archivos
                             (archivo_hash, nombre_archivo, contenido, mes_ventas, fecha_corte, formato, almacen_origen, filas_importadas, importado_por)
                             VALUES (:file_hash, :file_name, :content, :sales_month, :cutoff_date, :format, NULL, :row_count, NULL)'
                        );
                        $legacyArchive->bindValue(':file_hash', $legacyWorkbookHash);
                        $legacyArchive->bindValue(':file_name', basename($legacyWorkbookPath));
                        $legacyArchive->bindValue(':content', $legacyContent, PDO::PARAM_LOB);
                        $legacyArchive->bindValue(
                            ':sales_month',
                            (new DateTimeImmutable((string) $legacyMetadata['cutoff_date']))
                                ->modify('first day of this month')->format('Y-m-d')
                        );
                        $legacyArchive->bindValue(':cutoff_date', $legacyMetadata['cutoff_date']);
                        $legacyArchive->bindValue(':format', 'brand_report');
                        $legacyArchive->bindValue(':row_count', (int) $legacyMetadata['row_count'], PDO::PARAM_INT);
                        $legacyArchive->execute();
                    }
                }
            }
        }

        $database->exec(
            "INSERT IGNORE INTO ventas_excel_importadas
              (archivo_hash, hoja, fila_origen, codigo, marca, medida, almacen_codigo, fecha_venta, cantidad, requiere_revision, importado_en)
             SELECT h.archivo_hash, h.hoja, h.fila_origen, h.codigo, h.marca, h.medida,
                    h.almacen_codigo, h.fecha_venta, h.cantidad, h.requiere_revision, h.importado_en
             FROM historico_ventas_excel h
             INNER JOIN historico_ventas_archivos a ON a.archivo_hash = h.archivo_hash
             WHERE h.tipo_origen = 'excel'"
        );
        $database->exec(
            "DELETE h FROM historico_ventas_excel h
             INNER JOIN historico_ventas_archivos a ON a.archivo_hash = h.archivo_hash
             WHERE h.tipo_origen = 'excel'"
        );
        $database->exec(
            "UPDATE historico_ventas_excel
             SET tipo_origen = 'manual'
             WHERE tipo_origen = 'operational' AND hoja = 'MANUAL'"
        );
        $migrationInsert = $database->prepare('INSERT IGNORE INTO migraciones_esquema (version) VALUES (:version)');
        $migrationInsert->execute(['version' => $version]);
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }
}

function app_migrate_inventory_import_metadata(PDO $database): void
{
    $database->exec(
        'CREATE TABLE IF NOT EXISTS configuracion_app (
          clave VARCHAR(191) NOT NULL PRIMARY KEY,
          valor TEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function app_migrate_remove_b4x_archive_source(PDO $database): void
{
    $version = '2026_11_18_remove_b4x_archive_source';
    $migrationCheck = $database->prepare('SELECT 1 FROM migraciones_esquema WHERE version = :version');
    $migrationCheck->execute(['version' => $version]);
    if ($migrationCheck->fetchColumn() !== false) {
        return;
    }

    $database->exec(
        'DROP TABLE IF EXISTS inventario_b4x01_archivo_fuente, inventario_b4x01_archivos'
    );

    $migrationInsert = $database->prepare('INSERT IGNORE INTO migraciones_esquema (version) VALUES (:version)');
    $migrationInsert->execute(['version' => $version]);
}

function app_migrate_operational_inventory_units(PDO $database): void
{
    $database->exec(
        'CREATE TABLE IF NOT EXISTS inventario_unidades (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          codigo_barras VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          codigo_fabricante VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
          producto_id BIGINT UNSIGNED NOT NULL,
          almacen_codigo VARCHAR(32) NOT NULL,
          compra_id BIGINT UNSIGNED NULL,
          venta_id BIGINT UNSIGNED NULL,
          estado VARCHAR(20) NOT NULL DEFAULT \'disponible\',
          creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uq_inventario_unidades_codigo (codigo_barras),
          KEY idx_inventario_unidades_stock (producto_id, almacen_codigo, estado, id),
          KEY idx_inventario_unidades_compra (compra_id, estado),
          KEY idx_inventario_unidades_venta (venta_id),
          CONSTRAINT fk_inventario_unidades_producto FOREIGN KEY (producto_id) REFERENCES productos(id),
          CONSTRAINT fk_inventario_unidades_almacen FOREIGN KEY (almacen_codigo) REFERENCES almacenes(codigo),
          CONSTRAINT fk_inventario_unidades_compra FOREIGN KEY (compra_id) REFERENCES compras(id),
          CONSTRAINT fk_inventario_unidades_venta FOREIGN KEY (venta_id) REFERENCES ventas(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $version = '2026_11_15_operational_inventory_units';
    $migrationCheck = $database->prepare('SELECT 1 FROM migraciones_esquema WHERE version = :version');
    $migrationCheck->execute(['version' => $version]);
    if ($migrationCheck->fetchColumn() !== false) {
        return;
    }

    $database->beginTransaction();
    try {
        $existencias = $database->query(
            'SELECT i.producto_id, i.almacen_codigo, i.cantidad
             FROM inventario i
             JOIN productos p ON p.id = i.producto_id
             WHERE i.cantidad > 0 AND p.categoria = \'Llantas\'
             ORDER BY i.producto_id, i.almacen_codigo'
        )->fetchAll();
        $insertarUnidad = $database->prepare(
            'INSERT INTO inventario_unidades (codigo_barras, producto_id, almacen_codigo)
             VALUES (:barcode, :product, :warehouse)'
        );
        foreach ($existencias as $existencia) {
            for ($unidad = 0, $cantidad = (int) $existencia['cantidad']; $unidad < $cantidad; $unidad++) {
                $insertarUnidad->execute([
                    'barcode' => 'LLA' . strtoupper(bin2hex(random_bytes(6))),
                    'product' => (int) $existencia['producto_id'],
                    'warehouse' => (string) $existencia['almacen_codigo'],
                ]);
            }
        }

        $migrationInsert = $database->prepare('INSERT IGNORE INTO migraciones_esquema (version) VALUES (:version)');
        $migrationInsert->execute(['version' => $version]);
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }
}

function app_migrate_purchases(PDO $database): void
{
    $version = '2026_10_26_purchases_module';
    $migrationCheck = $database->prepare('SELECT 1 FROM migraciones_esquema WHERE version = :version');
    $migrationCheck->execute(['version' => $version]);
    if ($migrationCheck->fetchColumn() !== false) {
        return;
    }

    $database->exec(
        'CREATE TABLE IF NOT EXISTS compras (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          numero_compra VARCHAR(64) NOT NULL UNIQUE,
          almacen_codigo VARCHAR(32) NOT NULL,
          proveedor VARCHAR(255) NOT NULL,
          documento_proveedor VARCHAR(120) NOT NULL DEFAULT \'\',
          fecha_compra DATE NOT NULL,
          creado_por BIGINT UNSIGNED NOT NULL,
          notas TEXT NOT NULL,
          creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_compras_fecha (fecha_compra),
          CONSTRAINT fk_compras_almacen FOREIGN KEY (almacen_codigo) REFERENCES almacenes(codigo),
          CONSTRAINT fk_compras_usuario FOREIGN KEY (creado_por) REFERENCES usuarios(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $database->exec(
        'CREATE TABLE IF NOT EXISTS detalle_compras (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          compra_id BIGINT UNSIGNED NOT NULL,
          producto_id BIGINT UNSIGNED NOT NULL,
          cantidad INT UNSIGNED NOT NULL,
          costo_unitario_centavos BIGINT UNSIGNED NOT NULL,
          total_linea_centavos BIGINT UNSIGNED NOT NULL,
          CONSTRAINT fk_detalle_compra FOREIGN KEY (compra_id) REFERENCES compras(id) ON DELETE CASCADE,
          CONSTRAINT fk_detalle_compra_producto FOREIGN KEY (producto_id) REFERENCES productos(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $migrationInsert = $database->prepare('INSERT IGNORE INTO migraciones_esquema (version) VALUES (:version)');
    $migrationInsert->execute(['version' => $version]);
}

function app_migrate_purchase_cancellations(PDO $database): void
{
    $version = '2026_11_10_purchase_edit_and_cancellation';
    $columns = [
        'estado' => "ALTER TABLE compras ADD COLUMN estado VARCHAR(20) NOT NULL DEFAULT 'activa'",
        'anulada_en' => 'ALTER TABLE compras ADD COLUMN anulada_en DATETIME NULL',
        'anulada_por' => 'ALTER TABLE compras ADD COLUMN anulada_por BIGINT UNSIGNED NULL',
        'motivo_anulacion' => "ALTER TABLE compras ADD COLUMN motivo_anulacion VARCHAR(500) NOT NULL DEFAULT ''",
    ];
    foreach ($columns as $column => $sql) {
        $checkColumn = $database->prepare(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'compras\' AND COLUMN_NAME = :column_name'
        );
        $checkColumn->execute(['column_name' => $column]);
        if ($checkColumn->fetchColumn() === false) {
            $database->exec($sql);
        }
    }

    $indexCheck = $database->prepare(
        'SELECT 1 FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'compras\' AND INDEX_NAME = \'idx_compras_estado_fecha\''
    );
    $indexCheck->execute();
    if ($indexCheck->fetchColumn() === false) {
        $database->exec('ALTER TABLE compras ADD INDEX idx_compras_estado_fecha (estado, fecha_compra)');
    }
    $database->exec(
        'CREATE TABLE IF NOT EXISTS compras_auditoria (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          compra_id BIGINT UNSIGNED NOT NULL,
          evento VARCHAR(20) NOT NULL,
          datos_anteriores LONGTEXT NOT NULL,
          datos_nuevos LONGTEXT NOT NULL,
          motivo VARCHAR(500) NOT NULL DEFAULT \'\',
          creado_por BIGINT UNSIGNED NOT NULL,
          creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          KEY idx_compras_auditoria_compra_fecha (compra_id, creado_en)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $migrationInsert = $database->prepare('INSERT IGNORE INTO migraciones_esquema (version) VALUES (:version)');
    $migrationInsert->execute(['version' => $version]);
}

function app_migrate_optional_sale_customer(PDO $database): void
{
    $database->exec(
        'CREATE TABLE IF NOT EXISTS migraciones_esquema (
          version VARCHAR(191) NOT NULL PRIMARY KEY,
          aplicado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $version = '2026_10_19_cliente_venta_opcional';
    $migrationCheck = $database->prepare('SELECT 1 FROM migraciones_esquema WHERE version = :version');
    $migrationCheck->execute(['version' => $version]);
    if ($migrationCheck->fetchColumn() !== false) {
        return;
    }

    $customerColumn = $database->query(
        "SELECT IS_NULLABLE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ventas' AND COLUMN_NAME = 'cliente_id'"
    )->fetchColumn();
    if ($customerColumn === 'NO') {
        $database->exec('ALTER TABLE ventas MODIFY cliente_id BIGINT UNSIGNED NULL');
    }

    $migrationInsert = $database->prepare('INSERT IGNORE INTO migraciones_esquema (version) VALUES (:version)');
    $migrationInsert->execute(['version' => $version]);
}

function app_migrate_historical_sales(PDO $database): void
{
    $version = '2026_10_19_historical_sales';
    $migrationCheck = $database->prepare('SELECT 1 FROM migraciones_esquema WHERE version = :version');
    $migrationCheck->execute(['version' => $version]);
    if ($migrationCheck->fetchColumn() !== false) {
        return;
    }

    $database->exec(
        'CREATE TABLE IF NOT EXISTS historico_ventas_excel (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          archivo_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          hoja VARCHAR(64) NOT NULL,
          fila_origen INT UNSIGNED NOT NULL,
          codigo VARCHAR(191) NOT NULL,
          marca VARCHAR(120) NOT NULL,
          medida VARCHAR(120) NOT NULL,
          almacen_codigo VARCHAR(32) NOT NULL,
          fecha_venta DATE NOT NULL,
          cantidad INT NOT NULL,
          tipo_origen VARCHAR(20) NOT NULL DEFAULT \'excel\',
          requiere_revision TINYINT(1) NOT NULL DEFAULT 0,
          importado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uq_historico_ventas_origen (archivo_hash, hoja, fila_origen),
          INDEX idx_historico_ventas_demanda (codigo, almacen_codigo, fecha_venta),
          INDEX idx_historico_origen_mes (tipo_origen, almacen_codigo, fecha_venta)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $migrationInsert = $database->prepare('INSERT IGNORE INTO migraciones_esquema (version) VALUES (:version)');
    $migrationInsert->execute(['version' => $version]);
}

function app_migrate_manual_sales_forecast_history(PDO $database): void
{
    $version = '2026_10_26_manual_sales_forecast_history';
    $migrationCheck = $database->prepare('SELECT 1 FROM migraciones_esquema WHERE version = :version');
    $migrationCheck->execute(['version' => $version]);
    if ($migrationCheck->fetchColumn() !== false) {
        return;
    }

    $database->exec(
        "INSERT IGNORE INTO historico_ventas_excel
          (archivo_hash, hoja, fila_origen, codigo, marca, medida, almacen_codigo, fecha_venta, cantidad, tipo_origen)
         SELECT
          SHA2(CONCAT('manual-sale-detail:', d.id), 256),
          'MANUAL',
          d.id,
          p.codigo_sku,
          p.marca,
          p.medida,
          s.almacen_codigo,
          s.fecha_venta,
          d.cantidad,
          'operational'
         FROM ventas s
         JOIN detalle_ventas d ON d.venta_id = s.id
         JOIN productos p ON p.id = d.producto_id
         WHERE s.almacen_codigo IN ('A0101', 'A0102')
           AND NOT EXISTS (
             SELECT 1 FROM historico_ventas_excel h
             WHERE h.codigo = p.codigo_sku
               AND h.almacen_codigo = s.almacen_codigo
               AND h.fecha_venta = s.fecha_venta
               AND h.cantidad = d.cantidad
           )"
    );
    $migrationInsert = $database->prepare('INSERT IGNORE INTO migraciones_esquema (version) VALUES (:version)');
    $migrationInsert->execute(['version' => $version]);
}

function app_migrate_historical_sales_origin(PDO $database): void
{
    $version = '2026_10_27_historical_sales_origin';
    $migrationCheck = $database->prepare('SELECT 1 FROM migraciones_esquema WHERE version = :version');
    $migrationCheck->execute(['version' => $version]);
    if ($migrationCheck->fetchColumn() !== false) {
        return;
    }

    $columnCheck = $database->prepare(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'historico_ventas_excel'
           AND COLUMN_NAME = 'tipo_origen'"
    );
    $columnCheck->execute();
    if ($columnCheck->fetchColumn() === false) {
        $database->exec(
            "ALTER TABLE historico_ventas_excel
             ADD COLUMN tipo_origen VARCHAR(20) NOT NULL DEFAULT 'excel' AFTER cantidad"
        );
    }

    $indexCheck = $database->prepare(
        "SELECT 1 FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'historico_ventas_excel'
           AND INDEX_NAME = 'idx_historico_origen_mes'"
    );
    $indexCheck->execute();
    if ($indexCheck->fetchColumn() === false) {
        $database->exec(
            'ALTER TABLE historico_ventas_excel
             ADD INDEX idx_historico_origen_mes (tipo_origen, almacen_codigo, fecha_venta)'
        );
    }

    $database->exec(
        "UPDATE historico_ventas_excel h
         JOIN ventas s ON s.almacen_codigo = h.almacen_codigo
                     AND s.fecha_venta = h.fecha_venta
         JOIN detalle_ventas d ON d.venta_id = s.id AND d.cantidad = h.cantidad
         JOIN productos p ON p.id = d.producto_id AND p.codigo_sku = h.codigo
         SET h.tipo_origen = 'operational'
         WHERE h.archivo_hash = CONVERT(SHA2(CONCAT('manual-sale-detail:', d.id), 256) USING ascii)"
    );
    $migrationInsert = $database->prepare('INSERT IGNORE INTO migraciones_esquema (version) VALUES (:version)');
    $migrationInsert->execute(['version' => $version]);
}

function app_migrate_warehouses(PDO $database): void
{
    $version = '2026_10_19_warehouses_a0101_a0103';
    $migrationCheck = $database->prepare('SELECT 1 FROM migraciones_esquema WHERE version = :version');
    $migrationCheck->execute(['version' => $version]);
    if ($migrationCheck->fetchColumn() !== false) {
        return;
    }

    $warehouseInsert = $database->prepare(
        'INSERT INTO almacenes (codigo, nombre, activo) VALUES (:code, :name, 1)
         ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), activo = 1'
    );
    foreach ([
        'A0101' => 'Castilla',
        'A0102' => 'Jr. Arequipa / Huancayo',
        'A0103' => 'Atalaya',
    ] as $code => $name) {
        $warehouseInsert->execute(['code' => $code, 'name' => $name]);
    }

    $legacyReferences = 0;
    foreach (['inventario', 'ventas', 'movimientos_inventario', 'historico_ventas_excel', 'compras'] as $table) {
        $tableCheck = $database->prepare(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
        );
        $tableCheck->execute(['table' => $table]);
        if ($tableCheck->fetchColumn() !== false) {
            $legacyReferences += (int) $database->query(
                "SELECT COUNT(*) FROM `{$table}` WHERE almacen_codigo = 'A0198'"
            )->fetchColumn();
        }
    }
    $legacyStockCheck = $database->query(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos' AND COLUMN_NAME = 'stock_huancayo'"
    );
    if ($legacyStockCheck->fetchColumn() !== false) {
        $legacyReferences += (int) $database->query(
            'SELECT COUNT(*) FROM productos WHERE stock_huancayo > 0'
        )->fetchColumn();
    }
    if ($legacyReferences === 0) {
        $database->exec("DELETE FROM almacenes WHERE codigo = 'A0198'");
    } else {
        $database->exec("UPDATE almacenes SET nombre = 'A0198 (legado)', activo = 0 WHERE codigo = 'A0198'");
    }

    $migrationInsert = $database->prepare('INSERT IGNORE INTO migraciones_esquema (version) VALUES (:version)');
    $migrationInsert->execute(['version' => $version]);
}

function app_migrate_remove_legacy_product_stock(PDO $database): void
{
    $version = '2026_11_11_remove_legacy_product_stock';
    $migrationCheck = $database->prepare('SELECT 1 FROM migraciones_esquema WHERE version = :version');
    $migrationCheck->execute(['version' => $version]);
    if ($migrationCheck->fetchColumn() !== false) {
        return;
    }

    $columnsToRemove = ['stock_lima', 'stock_huancayo', 'stock_a0101', 'stock_a0102', 'stock_a0103'];
    $existingColumns = [];
    $columnCheck = $database->prepare(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos' AND COLUMN_NAME = :column"
    );
    foreach ($columnsToRemove as $column) {
        $columnCheck->execute(['column' => $column]);
        if ($columnCheck->fetchColumn() !== false) {
            $existingColumns[] = $column;
        }
    }

    foreach (['stock_lima', 'stock_huancayo'] as $legacyColumn) {
        if (!in_array($legacyColumn, $existingColumns, true)) {
            continue;
        }
        $nonZeroValues = (int) $database->query(
            "SELECT COUNT(*) FROM productos WHERE `{$legacyColumn}` <> 0"
        )->fetchColumn();
        if ($nonZeroValues > 0) {
            throw new RuntimeException(
                "No se puede retirar {$legacyColumn}: contiene existencias que deben revisarse primero."
            );
        }
    }

    $productsWithoutWarehouseInventory = (int) $database->query(
        "SELECT COUNT(*) FROM productos p
         WHERE (
             SELECT COUNT(*) FROM inventario i
             WHERE i.producto_id = p.id AND i.almacen_codigo IN ('A0101', 'A0102', 'A0103')
         ) <> 3"
    )->fetchColumn();
    if ($productsWithoutWarehouseInventory > 0) {
        throw new RuntimeException(
            'No se pueden retirar las columnas duplicadas de stock: hay productos sin inventario completo por almacén.'
        );
    }

    if ($existingColumns !== []) {
        $dropClauses = array_map(
            static fn (string $column): string => "DROP COLUMN `{$column}`",
            $existingColumns
        );
        $database->exec('ALTER TABLE productos ' . implode(', ', $dropClauses));
    }

    $migrationInsert = $database->prepare('INSERT IGNORE INTO migraciones_esquema (version) VALUES (:version)');
    $migrationInsert->execute(['version' => $version]);
}

function app_migrate_b4x_inventory(PDO $database): void
{
    $version = '2026_10_30_b4x_inventory';
    $migrationCheck = $database->prepare('SELECT 1 FROM migraciones_esquema WHERE version = :version');
    $migrationCheck->execute(['version' => $version]);
    if ($migrationCheck->fetchColumn() !== false) {
        return;
    }

    $database->exec(
        'CREATE TABLE IF NOT EXISTS inventario_b4x01 (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          clave_producto CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          codigo_excel VARCHAR(191) NOT NULL,
          codigo_barras VARCHAR(80) NOT NULL,
          nombre VARCHAR(255) NOT NULL,
          categoria VARCHAR(120) NOT NULL,
          marca VARCHAR(120) NOT NULL,
          medida VARCHAR(120) NOT NULL,
          almacen_codigo VARCHAR(32) NOT NULL,
          cantidad INT NOT NULL DEFAULT 0,
          es_manual TINYINT(1) NOT NULL DEFAULT 0,
          stock_minimo INT NOT NULL DEFAULT 6,
          stock_seguridad INT NOT NULL DEFAULT 2,
          stock_en_transito INT NOT NULL DEFAULT 0,
          UNIQUE KEY uq_inventario_b4x01_barcode_warehouse (codigo_barras, almacen_codigo),
          UNIQUE KEY uq_inventario_b4x01_item_warehouse (clave_producto, almacen_codigo),
          INDEX idx_inventario_b4x01_busqueda (codigo_barras, almacen_codigo),
          INDEX idx_inventario_b4x01_nombre (nombre)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $migrationInsert = $database->prepare('INSERT IGNORE INTO migraciones_esquema (version) VALUES (:version)');
    $migrationInsert->execute(['version' => $version]);
}

function app_migrate_b4x_manual_products(PDO $database): void
{
    $version = '2026_11_12_b4x_manual_products';
    $manualColumn = $database->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'inventario_b4x01'
           AND COLUMN_NAME = 'es_manual'"
    )->fetchColumn();
    if ($manualColumn === false) {
        $database->exec(
            'ALTER TABLE inventario_b4x01
             ADD COLUMN es_manual TINYINT(1) NOT NULL DEFAULT 0 AFTER cantidad'
        );
    }

    $migrationCheck = $database->prepare('SELECT 1 FROM migraciones_esquema WHERE version = :version');
    $migrationCheck->execute(['version' => $version]);
    if ($migrationCheck->fetchColumn() !== false) {
        return;
    }

    $products = [
        [
            'barcode' => 'B4X01-WES-60013-01',
            'name' => 'WESTLAKE 6.00 13 POST',
            'brand' => 'WESTLAKE',
            'measure' => '6.00-13',
        ],
        [
            'barcode' => 'B4X01-WON-23575R175-01',
            'name' => 'WONDERLAND 235/75R17.5 POST',
            'brand' => 'WONDERLAND',
            'measure' => '235/75R17.5',
        ],
        [
            'barcode' => 'B4X01-WON-23575R175-02',
            'name' => 'WONDERLAND 235/75R17.5 POST BY996',
            'brand' => 'WONDERLAND',
            'measure' => '235/75R17.5',
        ],
    ];
    $warehouses = ['A0101', 'A0102', 'A0103'];
    $normalize = static fn (string $value): string =>
        strtolower((string) preg_replace('/[^a-z0-9]/i', '', $value));
    $findBarcode = $database->prepare(
        'SELECT 1 FROM inventario_b4x01 WHERE codigo_barras = :barcode LIMIT 1'
    );
    $insertProduct = $database->prepare(
        'INSERT INTO inventario_b4x01
            (clave_producto, codigo_excel, codigo_barras, nombre, categoria, marca,
             medida, almacen_codigo, cantidad, es_manual)
         VALUES
            (:product_key, :excel_code, :barcode, :name, :category, :brand,
             :measure, :warehouse, 0, 1)'
    );

    $database->beginTransaction();
    try {
        foreach ($products as $product) {
            $findBarcode->execute(['barcode' => $product['barcode']]);
            if ($findBarcode->fetchColumn() !== false) {
                throw new RuntimeException(
                    'El código ' . $product['barcode'] . ' ya existe en B4X01 y no se puede reutilizar.'
                );
            }

            $productKey = hash('sha256', implode('|', [
                $normalize($product['barcode']),
                $normalize($product['name']),
                $normalize($product['brand']),
                $normalize($product['measure']),
            ]));

            foreach ($warehouses as $warehouse) {
                $insertProduct->execute([
                    'product_key' => $productKey,
                    'excel_code' => $product['barcode'],
                    'barcode' => $product['barcode'],
                    'name' => $product['name'],
                    'category' => 'Llantas',
                    'brand' => $product['brand'],
                    'measure' => $product['measure'],
                    'warehouse' => $warehouse,
                ]);
            }
        }

        $migrationInsert = $database->prepare(
            'INSERT IGNORE INTO migraciones_esquema (version) VALUES (:version)'
        );
        $migrationInsert->execute(['version' => $version]);
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }
}

function app_migrate_remove_b4x_excel_scan_cache(PDO $database): void
{
    $version = '2026_11_18_remove_b4x_excel_scan_cache';
    $migrationCheck = $database->prepare('SELECT 1 FROM migraciones_esquema WHERE version = :version');
    $migrationCheck->execute(['version' => $version]);
    if ($migrationCheck->fetchColumn() !== false) {
        return;
    }

    $database->exec('DROP TABLE IF EXISTS inventario_b4x01_excel_escaneos');
    $migrationInsert = $database->prepare('INSERT IGNORE INTO migraciones_esquema (version) VALUES (:version)');
    $migrationInsert->execute(['version' => $version]);
}

function app_migrate_remove_b4x_legacy_scans(PDO $database): void
{
    $version = '2026_11_18_remove_b4x_legacy_scans';
    $migrationCheck = $database->prepare('SELECT 1 FROM migraciones_esquema WHERE version = :version');
    $migrationCheck->execute(['version' => $version]);
    if ($migrationCheck->fetchColumn() !== false) {
        return;
    }

    $database->exec('DROP TABLE IF EXISTS inventario_b4x01_escaneos');
    $migrationInsert = $database->prepare('INSERT IGNORE INTO migraciones_esquema (version) VALUES (:version)');
    $migrationInsert->execute(['version' => $version]);
}

function app_migrate_valorized_sales(PDO $database): void
{
    $version = '2026_10_19_valorized_sales_source';
    $migrationCheck = $database->prepare('SELECT 1 FROM migraciones_esquema WHERE version = :version');
    $migrationCheck->execute(['version' => $version]);
    if ($migrationCheck->fetchColumn() !== false) {
        return;
    }

    $database->exec(
        'CREATE TABLE IF NOT EXISTS historico_valorizado_excel (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          archivo_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          hoja VARCHAR(64) NOT NULL,
          fila_origen INT UNSIGNED NOT NULL,
          codigo VARCHAR(191) NOT NULL,
          descripcion TEXT NOT NULL,
          medida_detectada VARCHAR(80) NOT NULL DEFAULT \'\',
          almacen_codigo VARCHAR(32) NOT NULL,
          fecha_venta DATE NOT NULL,
          tipo_movimiento CHAR(1) NOT NULL,
          cantidad INT NOT NULL,
          clasificacion VARCHAR(32) NOT NULL,
          motivo_clasificacion VARCHAR(255) NOT NULL DEFAULT \'\',
          requiere_revision TINYINT(1) NOT NULL DEFAULT 0,
          importado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uq_valorizado_origen (archivo_hash, hoja, fila_origen),
          INDEX idx_valorizado_modelo (clasificacion, almacen_codigo, fecha_venta, codigo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $migrationInsert = $database->prepare('INSERT IGNORE INTO migraciones_esquema (version) VALUES (:version)');
    $migrationInsert->execute(['version' => $version]);
}

function app_migrate_sales_review(PDO $database): void
{
    $version = '2026_11_17_sales_review';
    $migrationCheck = $database->prepare('SELECT 1 FROM migraciones_esquema WHERE version = :version');
    $migrationCheck->execute(['version' => $version]);
    if ($migrationCheck->fetchColumn() !== false) {
        return;
    }

    $columnas = [
        'estado' => "VARCHAR(24) NOT NULL DEFAULT 'activa'",
        'anulada_en' => 'DATETIME NULL',
        'anulada_por' => 'BIGINT UNSIGNED NULL',
        'motivo_anulacion' => "VARCHAR(500) NOT NULL DEFAULT ''",
    ];
    foreach ($columnas as $nombre => $definicion) {
        $consultaColumna = $database->prepare(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'detalle_ventas'
               AND COLUMN_NAME = :column_name"
        );
        $consultaColumna->execute(['column_name' => $nombre]);
        if ($consultaColumna->fetchColumn() === false) {
            $database->exec(
                'ALTER TABLE detalle_ventas ADD COLUMN `' . $nombre . '` ' . $definicion
            );
        }
    }

    $database->exec(
        'CREATE TABLE IF NOT EXISTS reportes_ventas (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          venta_id BIGINT UNSIGNED NOT NULL,
          detalle_venta_id BIGINT UNSIGNED NOT NULL,
          reportado_por BIGINT UNSIGNED NOT NULL,
          motivo VARCHAR(40) NOT NULL,
          detalle VARCHAR(500) NOT NULL,
          estado VARCHAR(24) NOT NULL DEFAULT \'pendiente\',
          creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          revisado_por BIGINT UNSIGNED NULL,
          revisado_en DATETIME NULL,
          notas_revision VARCHAR(500) NOT NULL DEFAULT \'\',
          INDEX idx_reportes_ventas_estado (estado, creado_en),
          INDEX idx_reportes_ventas_detalle_estado (detalle_venta_id, estado),
          CONSTRAINT fk_reportes_ventas_venta FOREIGN KEY (venta_id) REFERENCES ventas(id),
          CONSTRAINT fk_reportes_ventas_detalle FOREIGN KEY (detalle_venta_id) REFERENCES detalle_ventas(id),
          CONSTRAINT fk_reportes_ventas_reportante FOREIGN KEY (reportado_por) REFERENCES usuarios(id),
          CONSTRAINT fk_reportes_ventas_revisor FOREIGN KEY (revisado_por) REFERENCES usuarios(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $migrationInsert = $database->prepare(
        'INSERT IGNORE INTO migraciones_esquema (version) VALUES (:version)'
    );
    $migrationInsert->execute(['version' => $version]);
}
