<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Servicios' . DIRECTORY_SEPARATOR . 'Servicios.php';

final class AccesoModelo
{
    public function __construct(private readonly PDO $baseDatos)
    {
    }

    public function contarUsuarios(): int
    {
        return (int) $this->baseDatos->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
    }

    public function obtenerUsuarioActivo(int $id): ?array
    {
        $consulta = $this->baseDatos->prepare(
            'SELECT id, nombre_completo AS full_name, correo AS email, rol AS role
             FROM usuarios WHERE id = :id AND activo = 1'
        );
        $consulta->execute(['id' => $id]);
        $usuario = $consulta->fetch();

        return $usuario === false ? null : $usuario;
    }

    public function crearAdministradorInicial(string $nombre, string $correo, string $contrasena): int
    {
        $this->baseDatos->beginTransaction();
        try {
            if ($this->contarUsuarios() !== 0) {
                throw new RuntimeException('La configuración inicial ya fue utilizada.');
            }
            $insertar = $this->baseDatos->prepare(
                'INSERT INTO usuarios (nombre_completo, correo, hash_contrasena, rol)
                 VALUES (:name, :email, :password, :role)'
            );
            $insertar->execute([
                'name' => $nombre,
                'email' => $correo,
                'password' => password_hash($contrasena, PASSWORD_DEFAULT),
                'role' => 'administrador',
            ]);
            $id = (int) $this->baseDatos->lastInsertId();
            $this->baseDatos->commit();

            return $id;
        } catch (Throwable $exception) {
            if ($this->baseDatos->inTransaction()) {
                $this->baseDatos->rollBack();
            }
            throw $exception;
        }
    }

    public function autenticar(string $correo, string $contrasena, string $ip): array
    {
        $ahora = time();
        $identificador = hash('sha256', $ip . "\0" . $correo);
        $consultaIntento = $this->baseDatos->prepare(
            'SELECT intentos AS attempts, inicio_ventana AS window_started_at
             FROM intentos_inicio_sesion WHERE hash_identificador = :identifier'
        );
        $consultaIntento->execute(['identifier' => $identificador]);
        $intento = $consultaIntento->fetch();
        if ($intento !== false
            && (int) $intento['window_started_at'] + 900 > $ahora
            && (int) $intento['attempts'] >= 5
        ) {
            return ['estado' => 'bloqueada'];
        }

        $consultaUsuario = $this->baseDatos->prepare(
            'SELECT id, hash_contrasena AS password_hash, rol AS role
             FROM usuarios WHERE correo = :email AND activo = 1'
        );
        $consultaUsuario->execute(['email' => $correo]);
        $usuario = $consultaUsuario->fetch();
        if ($usuario !== false && password_verify($contrasena, $usuario['password_hash'])) {
            $this->baseDatos->prepare(
                'DELETE FROM intentos_inicio_sesion WHERE hash_identificador = :identifier'
            )->execute(['identifier' => $identificador]);

            return ['estado' => 'correcta', 'id' => (int) $usuario['id'], 'rol' => (string) $usuario['role']];
        }

        $this->baseDatos->prepare(
            'INSERT INTO intentos_inicio_sesion (hash_identificador, intentos, inicio_ventana)
             VALUES (:identifier, 1, :started)
             ON DUPLICATE KEY UPDATE
                intentos = CASE WHEN inicio_ventana + 900 <= :now THEN 1 ELSE intentos + 1 END,
                inicio_ventana = CASE WHEN inicio_ventana + 900 <= :now THEN :started ELSE inicio_ventana END'
        )->execute(['identifier' => $identificador, 'started' => $ahora, 'now' => $ahora]);

        return ['estado' => 'incorrecta'];
    }
}

final class BarraSuperiorModelo
{
    public function __construct(private readonly PDO $baseDatos)
    {
    }

    public function obtenerAlertas(): array
    {
        $cantidad = (int) $this->baseDatos->query(
            'SELECT COUNT(*) FROM inventario WHERE cantidad <= stock_minimo'
        )->fetchColumn();
        $alertas = $this->baseDatos->query(
            'SELECT p.nombre AS product_name, p.codigo_sku AS sku, w.codigo AS warehouse_code,
                    i.cantidad AS quantity, i.stock_minimo AS minimum_stock,
                    i.stock_seguridad AS safety_stock
             FROM inventario i
             JOIN productos p ON p.id = i.producto_id
             JOIN almacenes w ON w.codigo = i.almacen_codigo
             WHERE i.cantidad <= i.stock_minimo
             ORDER BY (i.cantidad <= i.stock_seguridad) DESC, i.cantidad ASC, p.nombre
             LIMIT 6'
        )->fetchAll();

        return ['alertCount' => $cantidad, 'alerts' => $alertas];
    }
}

final class InventarioUnidades
{
    public static function esLlanta(array $producto): bool
    {
        return strcasecmp(trim((string) ($producto['category'] ?? '')), 'Llantas') === 0;
    }

    public static function esProductoLlanta(PDO $baseDatos, int $idProducto): bool
    {
        $consulta = $baseDatos->prepare('SELECT categoria FROM productos WHERE id = :id');
        $consulta->execute(['id' => $idProducto]);

        return strcasecmp(trim((string) $consulta->fetchColumn()), 'Llantas') === 0;
    }

    public static function generarCodigoInterno(): string
    {
        return 'LLA' . strtoupper(bin2hex(random_bytes(6)));
    }

    public static function normalizarCodigoEscaneado(string $codigo): string
    {
        $codigo = strtoupper(trim($codigo));
        if (strlen($codigo) > 2 && $codigo[0] === '*' && substr($codigo, -1) === '*') {
            $codigo = substr($codigo, 1, -1);
        }

        return $codigo;
    }

    public static function agregar(
        PDO $baseDatos,
        int $idProducto,
        string $almacen,
        int $cantidad,
        array $codigosFabricante = [],
        ?int $idCompra = null
    ): void {
        if ($cantidad < 0 || count($codigosFabricante) > $cantidad) {
            throw new RuntimeException('Los códigos de fábrica no coinciden con la cantidad recibida.');
        }

        $frecuenciasFabricante = [];
        foreach ($codigosFabricante as $codigoFabricante) {
            $codigoFabricante = self::normalizarCodigoEscaneado((string) $codigoFabricante);
            if ($codigoFabricante !== '') {
                $frecuenciasFabricante[$codigoFabricante] = ($frecuenciasFabricante[$codigoFabricante] ?? 0) + 1;
            }
        }
        $buscarCodigo = $baseDatos->prepare(
            'SELECT 1 FROM inventario_unidades
             WHERE codigo_barras = :barcode OR codigo_fabricante = :factory_code LIMIT 1'
        );
        $insertarUnidad = $baseDatos->prepare(
            'INSERT INTO inventario_unidades
                (codigo_barras, codigo_fabricante, producto_id, almacen_codigo, compra_id)
             VALUES (:barcode, :factory_code, :product, :warehouse, :purchase)'
        );

        for ($indice = 0; $indice < $cantidad; $indice++) {
            $codigoFabricante = self::normalizarCodigoEscaneado(
                (string) ($codigosFabricante[$indice] ?? '')
            );
            if ($codigoFabricante !== ''
                && (strlen($codigoFabricante) > 80
                    || preg_match('/\A[A-Z0-9 .\-$\/+%]+\z/D', $codigoFabricante) !== 1)
            ) {
                throw new RuntimeException('Un código de fábrica contiene caracteres no admitidos por Code 39.');
            }

            $codigoBarras = self::generarCodigoInterno();
            if ($codigoFabricante !== '' && ($frecuenciasFabricante[$codigoFabricante] ?? 0) === 1) {
                $buscarCodigo->execute([
                    'barcode' => $codigoFabricante,
                    'factory_code' => $codigoFabricante,
                ]);
                if ($buscarCodigo->fetchColumn() === false) {
                    $codigoBarras = $codigoFabricante;
                }
            }

            $insertarUnidad->execute([
                'barcode' => $codigoBarras,
                'factory_code' => $codigoFabricante === '' ? null : $codigoFabricante,
                'product' => $idProducto,
                'warehouse' => $almacen,
                'purchase' => $idCompra,
            ]);
        }
    }

    public static function retirar(
        PDO $baseDatos,
        int $idProducto,
        string $almacen,
        int $cantidad,
        string $estado = 'retirada',
        ?int $idCompra = null
    ): void {
        if ($cantidad < 0 || !in_array($estado, ['retirada', 'vendida'], true)) {
            throw new RuntimeException('No se pudo actualizar el estado de las llantas.');
        }
        if ($cantidad === 0) {
            return;
        }

        $condicionCompra = $idCompra === null ? '' : ' AND (compra_id = :purchase OR compra_id IS NULL)';
        $bloqueo = $baseDatos->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $seleccionar = $baseDatos->prepare(
            'SELECT id FROM inventario_unidades
             WHERE producto_id = :product AND almacen_codigo = :warehouse
               AND estado = \'disponible\'' . $condicionCompra
             . ' ORDER BY id DESC LIMIT ' . $cantidad . $bloqueo
        );
        $parametros = ['product' => $idProducto, 'warehouse' => $almacen];
        if ($idCompra !== null) {
            $parametros['purchase'] = $idCompra;
        }
        $seleccionar->execute($parametros);
        $ids = array_map('intval', $seleccionar->fetchAll(PDO::FETCH_COLUMN));
        if (count($ids) !== $cantidad) {
            throw new RuntimeException('No hay suficientes códigos individuales disponibles para ajustar este stock.');
        }

        $actualizar = $baseDatos->prepare(
            'UPDATE inventario_unidades SET estado = :status
             WHERE id = :id AND estado = \'disponible\''
        );
        foreach ($ids as $idUnidad) {
            $actualizar->execute(['status' => $estado, 'id' => $idUnidad]);
            if ($actualizar->rowCount() !== 1) {
                throw new RuntimeException('Una llanta cambió de estado mientras se procesaba el movimiento.');
            }
        }
    }

    public static function marcarVendidas(
        PDO $baseDatos,
        array $codigos,
        int $idProducto,
        string $almacen,
        int $idVenta
    ): void {
        $actualizar = $baseDatos->prepare(
            'UPDATE inventario_unidades
             SET estado = \'vendida\', venta_id = :sale
             WHERE codigo_barras = :barcode AND producto_id = :product
               AND almacen_codigo = :warehouse AND estado = \'disponible\''
        );
        foreach ($codigos as $codigo) {
            $actualizar->execute([
                'sale' => $idVenta,
                'barcode' => $codigo,
                'product' => $idProducto,
                'warehouse' => $almacen,
            ]);
            if ($actualizar->rowCount() !== 1) {
                throw new RuntimeException('Un código escaneado ya no está disponible en el almacén seleccionado.');
            }
        }
    }
}

final class ComprasModelo
{
    public function __construct(private readonly PDO $baseDatos)
    {
    }

    private function bloqueoFilas(): string
    {
        return $this->baseDatos->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }

    public function obtenerProductos(): array
    {
        return $this->baseDatos->query(
            'SELECT id, codigo_sku AS sku, nombre AS name, marca AS brand, medida AS size
             FROM productos ORDER BY nombre'
        )->fetchAll();
    }

    public function obtenerAlmacenes(): array
    {
        return $this->baseDatos->query(
            'SELECT codigo AS code, nombre AS name FROM almacenes WHERE activo = 1 ORDER BY codigo'
        )->fetchAll();
    }

    public function registrarCompra(array $lineas, string $proveedor, string $documento, string $almacen, string $fecha, int $idUsuario, string $notas): array
    {
        $this->baseDatos->beginTransaction();
        try {
            $comprobarProducto = $this->baseDatos->prepare(
                'SELECT p.id, p.codigo_sku AS sku, p.nombre AS name, p.categoria AS category,
                        i.cantidad AS current_quantity
                 FROM productos p
                 JOIN inventario i ON i.producto_id = p.id AND i.almacen_codigo = :warehouse
                 WHERE p.id = :product' . $this->bloqueoFilas()
            );
            $actualizarStock = $this->baseDatos->prepare(
                'UPDATE inventario SET cantidad = cantidad + :quantity, actualizado_en = CURRENT_TIMESTAMP
                 WHERE producto_id = :product AND almacen_codigo = :warehouse'
            );
            $numeroCompra = 'COM-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $insertarCompra = $this->baseDatos->prepare(
                'INSERT INTO compras
                   (numero_compra, almacen_codigo, proveedor, documento_proveedor, fecha_compra, creado_por, notas)
                 VALUES (:number, :warehouse, :supplier, :document, :date, :user, :notes)'
            );
            $insertarCompra->execute([
                'number' => $numeroCompra,
                'warehouse' => $almacen,
                'supplier' => $proveedor,
                'document' => $documento,
                'date' => $fecha,
                'user' => $idUsuario,
                'notes' => $notas,
            ]);
            $idCompra = (int) $this->baseDatos->lastInsertId();
            $insertarDetalle = $this->baseDatos->prepare(
                'INSERT INTO detalle_compras
                   (compra_id, producto_id, cantidad, costo_unitario_centavos, total_linea_centavos)
                 VALUES (:purchase, :product, :quantity, :unit_cost, :line_total)'
            );
            $insertarMovimiento = $this->baseDatos->prepare(
                'INSERT INTO movimientos_inventario
                   (producto_id, almacen_codigo, tipo_movimiento, cambio_cantidad, tipo_referencia, referencia_id, creado_por, notas)
                 VALUES (:product, :warehouse, :type, :quantity, :reference_type, :reference_id, :user, :notes)'
            );
            $unidadesTotales = 0;
            $cambiosStock = [];
            foreach ($lineas as $linea) {
                $comprobarProducto->execute([
                    'warehouse' => $almacen,
                    'product' => $linea['product_id'],
                ]);
                $producto = $comprobarProducto->fetch();
                if ($producto === false) {
                    throw new RuntimeException('Un producto no tiene inventario configurado en el almacén seleccionado.');
                }
                $idProducto = (int) $producto['id'];
                if (!isset($cambiosStock[$idProducto])) {
                    $cambiosStock[$idProducto] = [
                        'product_id' => $idProducto,
                        'sku' => (string) $producto['sku'],
                        'name' => (string) $producto['name'],
                        'previous_quantity' => (int) $producto['current_quantity'],
                        'received_quantity' => 0,
                        'current_quantity' => (int) $producto['current_quantity'],
                    ];
                }
                $totalLinea = $linea['unit_cost_cents'] * $linea['quantity'];
                $actualizarStock->execute([
                    'quantity' => $linea['quantity'],
                    'product' => $linea['product_id'],
                    'warehouse' => $almacen,
                ]);
                if ($actualizarStock->rowCount() !== 1) {
                    throw new RuntimeException('No se pudo actualizar el stock de un producto. No se registró la compra.');
                }
                if (InventarioUnidades::esLlanta($producto)) {
                    InventarioUnidades::agregar(
                        $this->baseDatos,
                        (int) $linea['product_id'],
                        $almacen,
                        (int) $linea['quantity'],
                        (array) ($linea['factory_barcodes'] ?? []),
                        $idCompra
                    );
                } elseif (($linea['factory_barcodes'] ?? []) !== []) {
                    throw new RuntimeException('Los códigos de fábrica individuales solo se registran para llantas.');
                }
                $cambiosStock[$idProducto]['received_quantity'] += (int) $linea['quantity'];
                $cambiosStock[$idProducto]['current_quantity'] += (int) $linea['quantity'];
                $insertarDetalle->execute([
                    'purchase' => $idCompra,
                    'product' => $linea['product_id'],
                    'quantity' => $linea['quantity'],
                    'unit_cost' => $linea['unit_cost_cents'],
                    'line_total' => $totalLinea,
                ]);
                $insertarMovimiento->execute([
                    'product' => $linea['product_id'],
                    'warehouse' => $almacen,
                    'type' => 'purchase',
                    'quantity' => $linea['quantity'],
                    'reference_type' => 'purchase',
                    'reference_id' => $idCompra,
                    'user' => $idUsuario,
                    'notes' => 'Compra ' . $numeroCompra,
                ]);
                $unidadesTotales += $linea['quantity'];
            }
            $this->baseDatos->commit();

            return [
                'numero' => $numeroCompra,
                'unidades' => $unidadesTotales,
                'stock_changes' => array_values($cambiosStock),
            ];
        } catch (Throwable $exception) {
            if ($this->baseDatos->inTransaction()) {
                $this->baseDatos->rollBack();
            }
            throw $exception;
        }
    }

    public function obtenerCompraParaEditar(int $idCompra): ?array
    {
        $consulta = $this->baseDatos->prepare(
            "SELECT id, numero_compra AS purchase_number, fecha_compra AS purchase_date,
                    proveedor AS supplier, documento_proveedor AS supplier_document,
                    almacen_codigo AS warehouse_code, notas AS notes
             FROM compras WHERE id = :id AND estado = 'activa'"
        );
        $consulta->execute(['id' => $idCompra]);
        $compra = $consulta->fetch();
        if ($compra === false) {
            return null;
        }

        $consultaDetalles = $this->baseDatos->prepare(
            'SELECT producto_id AS product_id, cantidad AS quantity,
                    costo_unitario_centavos AS unit_cost_cents
             FROM detalle_compras WHERE compra_id = :purchase ORDER BY id'
        );
        $consultaDetalles->execute(['purchase' => $idCompra]);
        $compra['items'] = $consultaDetalles->fetchAll();

        return $compra;
    }

    public function actualizarCompra(
        int $idCompra,
        array $lineas,
        string $proveedor,
        string $documento,
        string $fecha,
        string $notas,
        int $idUsuario
    ): void {
        $this->baseDatos->beginTransaction();
        try {
            $consultaCompra = $this->baseDatos->prepare(
                "SELECT numero_compra, almacen_codigo, proveedor, documento_proveedor, fecha_compra, notas
                 FROM compras
                 WHERE id = :id AND estado = 'activa' FOR UPDATE"
            );
            $consultaCompra->execute(['id' => $idCompra]);
            $compra = $consultaCompra->fetch();
            if ($compra === false) {
                throw new RuntimeException('La compra ya no existe o fue anulada.');
            }

            $consultaDetalles = $this->baseDatos->prepare(
                'SELECT producto_id, cantidad, costo_unitario_centavos
                 FROM detalle_compras WHERE compra_id = :purchase FOR UPDATE'
            );
            $consultaDetalles->execute(['purchase' => $idCompra]);
            $detallesAnteriores = $consultaDetalles->fetchAll();
            $cantidadesAnteriores = [];
            foreach ($detallesAnteriores as $detalle) {
                $idProducto = (int) $detalle['producto_id'];
                $cantidadesAnteriores[$idProducto] = ($cantidadesAnteriores[$idProducto] ?? 0)
                    + (int) $detalle['cantidad'];
            }

            $cantidadesNuevas = [];
            foreach ($lineas as $linea) {
                $idProducto = (int) ($linea['product_id'] ?? 0);
                $cantidad = (int) ($linea['quantity'] ?? 0);
                $costo = (int) ($linea['unit_cost_cents'] ?? 0);
                if ($idProducto < 1 || $cantidad < 1 || $costo < 1 || $costo > intdiv(PHP_INT_MAX, $cantidad)) {
                    throw new RuntimeException('Una línea de compra tiene datos inválidos.');
                }
                $cantidadesNuevas[$idProducto] = ($cantidadesNuevas[$idProducto] ?? 0) + $cantidad;
            }
            if ($lineas === []) {
                throw new RuntimeException('La compra debe contener al menos un producto.');
            }

            $consultarStock = $this->baseDatos->prepare(
                'SELECT cantidad FROM inventario
                 WHERE producto_id = :product AND almacen_codigo = :warehouse FOR UPDATE'
            );
            $actualizarStock = $this->baseDatos->prepare(
                'UPDATE inventario SET cantidad = cantidad + :change, actualizado_en = CURRENT_TIMESTAMP
                 WHERE producto_id = :product AND almacen_codigo = :warehouse AND cantidad + :guard_change >= 0'
            );
            $insertarMovimiento = $this->baseDatos->prepare(
                'INSERT INTO movimientos_inventario
                   (producto_id, almacen_codigo, tipo_movimiento, cambio_cantidad, tipo_referencia, referencia_id, creado_por, notas)
                 VALUES (:product, :warehouse, \'adjustment\', :change, \'purchase_edit\', :reference, :user, :notes)'
            );
            $idsProductos = array_values(array_unique(array_merge(
                array_keys($cantidadesAnteriores),
                array_keys($cantidadesNuevas)
            )));
            sort($idsProductos);
            foreach ($idsProductos as $idProducto) {
                $consultarStock->execute([
                    'product' => $idProducto,
                    'warehouse' => $compra['almacen_codigo'],
                ]);
                $stock = $consultarStock->fetchColumn();
                if ($stock === false) {
                    throw new RuntimeException('No existe una fila de inventario para uno de los productos de la compra.');
                }
                $cambio = ($cantidadesNuevas[$idProducto] ?? 0) - ($cantidadesAnteriores[$idProducto] ?? 0);
                if ($cambio === 0) {
                    continue;
                }
                if ((int) $stock + $cambio < 0) {
                    throw new RuntimeException(
                        'No se puede reducir la compra: el stock disponible ya fue utilizado. Revisa los movimientos antes de editar.'
                    );
                }
                $actualizarStock->execute([
                    'change' => $cambio,
                    'product' => $idProducto,
                    'warehouse' => $compra['almacen_codigo'],
                    'guard_change' => $cambio,
                ]);
                if ($actualizarStock->rowCount() !== 1) {
                    throw new RuntimeException('No se pudo ajustar el stock; la compra no fue modificada.');
                }
                if (InventarioUnidades::esProductoLlanta($this->baseDatos, $idProducto)) {
                    if ($cambio > 0) {
                        InventarioUnidades::agregar(
                            $this->baseDatos,
                            $idProducto,
                            (string) $compra['almacen_codigo'],
                            $cambio,
                            [],
                            $idCompra
                        );
                    } else {
                        InventarioUnidades::retirar(
                            $this->baseDatos,
                            $idProducto,
                            (string) $compra['almacen_codigo'],
                            -$cambio,
                            'retirada',
                            $idCompra
                        );
                    }
                }
                $insertarMovimiento->execute([
                    'product' => $idProducto,
                    'warehouse' => $compra['almacen_codigo'],
                    'change' => $cambio,
                    'reference' => $idCompra,
                    'user' => $idUsuario,
                    'notes' => 'Edición de compra ' . $compra['numero_compra'],
                ]);
            }

            $this->baseDatos->prepare(
                'UPDATE compras SET proveedor = :supplier, documento_proveedor = :document,
                    fecha_compra = :date, notas = :notes WHERE id = :id'
            )->execute([
                'supplier' => $proveedor,
                'document' => $documento,
                'date' => $fecha,
                'notes' => $notas,
                'id' => $idCompra,
            ]);
            $this->baseDatos->prepare('DELETE FROM detalle_compras WHERE compra_id = :purchase')
                ->execute(['purchase' => $idCompra]);
            $insertarDetalle = $this->baseDatos->prepare(
                'INSERT INTO detalle_compras
                   (compra_id, producto_id, cantidad, costo_unitario_centavos, total_linea_centavos)
                 VALUES (:purchase, :product, :quantity, :unit_cost, :line_total)'
            );
            foreach ($lineas as $linea) {
                $insertarDetalle->execute([
                    'purchase' => $idCompra,
                    'product' => $linea['product_id'],
                    'quantity' => $linea['quantity'],
                    'unit_cost' => $linea['unit_cost_cents'],
                    'line_total' => $linea['unit_cost_cents'] * $linea['quantity'],
                ]);
            }
            $auditoria = $this->baseDatos->prepare(
                'INSERT INTO compras_auditoria
                   (compra_id, evento, datos_anteriores, datos_nuevos, creado_por)
                 VALUES (:purchase, \'edicion\', :previous, :current, :user)'
            );
            $auditoria->execute([
                'purchase' => $idCompra,
                'previous' => json_encode([
                    'supplier' => $compra['proveedor'],
                    'supplier_document' => $compra['documento_proveedor'],
                    'purchase_date' => $compra['fecha_compra'],
                    'notes' => $compra['notas'],
                    'items' => array_map(static fn (array $item): array => [
                        'product_id' => (int) $item['producto_id'],
                        'quantity' => (int) $item['cantidad'],
                        'unit_cost_cents' => (int) $item['costo_unitario_centavos'],
                    ], $detallesAnteriores),
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'current' => json_encode([
                    'supplier' => $proveedor,
                    'supplier_document' => $documento,
                    'purchase_date' => $fecha,
                    'notes' => $notas,
                    'items' => $lineas,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'user' => $idUsuario,
            ]);
            $this->baseDatos->commit();
        } catch (Throwable $exception) {
            if ($this->baseDatos->inTransaction()) {
                $this->baseDatos->rollBack();
            }
            throw $exception;
        }
    }

    public function anularCompra(int $idCompra, int $idUsuario, string $motivo): array
    {
        $this->baseDatos->beginTransaction();
        try {
            $consultaCompra = $this->baseDatos->prepare(
                "SELECT numero_compra, almacen_codigo FROM compras
                 WHERE id = :id AND estado = 'activa' FOR UPDATE"
            );
            $consultaCompra->execute(['id' => $idCompra]);
            $compra = $consultaCompra->fetch();
            if ($compra === false) {
                throw new RuntimeException('La compra ya no existe o fue anulada.');
            }

            $consultaDetalles = $this->baseDatos->prepare(
                'SELECT producto_id, cantidad
                 FROM detalle_compras WHERE compra_id = :purchase ORDER BY producto_id, id FOR UPDATE'
            );
            $consultaDetalles->execute(['purchase' => $idCompra]);
            $detallesCompra = $consultaDetalles->fetchAll();
            $detalles = [];
            foreach ($detallesCompra as $detalleCompra) {
                $idProducto = (int) $detalleCompra['producto_id'];
                $detalles[$idProducto] = ($detalles[$idProducto] ?? 0) + (int) $detalleCompra['cantidad'];
            }
            if ($detalles === []) {
                throw new RuntimeException('La compra no tiene productos para revertir.');
            }

            $consultarStock = $this->baseDatos->prepare(
                'SELECT cantidad FROM inventario
                 WHERE producto_id = :product AND almacen_codigo = :warehouse FOR UPDATE'
            );
            $actualizarStock = $this->baseDatos->prepare(
                'UPDATE inventario SET cantidad = cantidad - :quantity, actualizado_en = CURRENT_TIMESTAMP
                 WHERE producto_id = :product AND almacen_codigo = :warehouse AND cantidad >= :guard_quantity'
            );
            $insertarMovimiento = $this->baseDatos->prepare(
                'INSERT INTO movimientos_inventario
                   (producto_id, almacen_codigo, tipo_movimiento, cambio_cantidad, tipo_referencia, referencia_id, creado_por, notas)
                 VALUES (:product, :warehouse, \'adjustment\', :change, \'purchase_cancel\', :reference, :user, :notes)'
            );
            foreach ($detalles as $idProducto => $cantidad) {
                $consultarStock->execute([
                    'product' => $idProducto,
                    'warehouse' => $compra['almacen_codigo'],
                ]);
                $stock = $consultarStock->fetchColumn();
                if ($stock === false || (int) $stock < $cantidad) {
                    throw new RuntimeException(
                        'No se puede anular esta compra porque parte de las unidades ya fueron utilizadas. Revisa los movimientos y ajusta el saldo primero.'
                    );
                }
                $actualizarStock->execute([
                    'quantity' => $cantidad,
                    'product' => $idProducto,
                    'warehouse' => $compra['almacen_codigo'],
                    'guard_quantity' => $cantidad,
                ]);
                if ($actualizarStock->rowCount() !== 1) {
                    throw new RuntimeException('No se pudo revertir el stock; la compra sigue activa.');
                }
                if (InventarioUnidades::esProductoLlanta($this->baseDatos, $idProducto)) {
                    InventarioUnidades::retirar(
                        $this->baseDatos,
                        $idProducto,
                        (string) $compra['almacen_codigo'],
                        $cantidad,
                        'retirada',
                        $idCompra
                    );
                }
                $insertarMovimiento->execute([
                    'product' => $idProducto,
                    'warehouse' => $compra['almacen_codigo'],
                    'change' => -$cantidad,
                    'reference' => $idCompra,
                    'user' => $idUsuario,
                    'notes' => 'Anulación de compra ' . $compra['numero_compra'] . ': ' . $motivo,
                ]);
            }

            $this->baseDatos->prepare(
                "UPDATE compras SET estado = 'anulada', anulada_en = CURRENT_TIMESTAMP,
                    anulada_por = :user, motivo_anulacion = :reason WHERE id = :id"
            )->execute(['user' => $idUsuario, 'reason' => $motivo, 'id' => $idCompra]);
            $this->baseDatos->prepare(
                'INSERT INTO compras_auditoria
                   (compra_id, evento, datos_anteriores, datos_nuevos, motivo, creado_por)
                 VALUES (:purchase, \'anulacion\', :previous, :current, :reason, :user)'
            )->execute([
                'purchase' => $idCompra,
                'previous' => json_encode([
                    'status' => 'activa',
                    'items' => array_map(static fn (int $idProducto, int $cantidad): array => [
                        'product_id' => $idProducto,
                        'quantity' => $cantidad,
                    ], array_keys($detalles), array_values($detalles)),
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'current' => json_encode(['status' => 'anulada'], JSON_THROW_ON_ERROR),
                'reason' => $motivo,
                'user' => $idUsuario,
            ]);
            $this->baseDatos->commit();

            return ['numero' => (string) $compra['numero_compra']];
        } catch (Throwable $exception) {
            if ($this->baseDatos->inTransaction()) {
                $this->baseDatos->rollBack();
            }
            throw $exception;
        }
    }

    public function obtenerHistorial(bool $incluirAnuladas = false, string $almacenCodigo = ''): array
    {
        $condicionAlmacen = $almacenCodigo === '' ? '' : ' AND c.almacen_codigo = :warehouse_code';
        $consultaCompras = $this->baseDatos->prepare(
            'SELECT c.id, c.numero_compra AS purchase_number, c.fecha_compra AS purchase_date,
                    c.proveedor AS supplier, c.documento_proveedor AS supplier_document,
                    c.almacen_codigo AS warehouse_code, w.nombre AS warehouse_name,
                    u.nombre_completo AS created_by, c.estado AS status,
                    c.motivo_anulacion AS cancellation_reason,
                    c.anulada_en AS cancelled_at,
                    COALESCE(ua.nombre_completo, \'\') AS cancelled_by,
                    COUNT(d.id) AS line_count,
                    COALESCE(SUM(d.total_linea_centavos), 0) AS total_cents
             FROM compras c
             JOIN almacenes w ON w.codigo = c.almacen_codigo
             JOIN usuarios u ON u.id = c.creado_por
             LEFT JOIN usuarios ua ON ua.id = c.anulada_por
             LEFT JOIN detalle_compras d ON d.compra_id = c.id
             WHERE (:include_cancelled = 1 OR c.estado = \'activa\')' . $condicionAlmacen . '
             GROUP BY c.id, c.numero_compra, c.fecha_compra, c.proveedor, c.documento_proveedor,
                      c.almacen_codigo, w.nombre, u.nombre_completo, c.estado,
                      c.motivo_anulacion, c.anulada_en, ua.nombre_completo
             ORDER BY c.fecha_compra DESC, c.id DESC LIMIT 50'
        );
        $parametros = ['include_cancelled' => $incluirAnuladas ? 1 : 0];
        if ($almacenCodigo !== '') {
            $parametros['warehouse_code'] = $almacenCodigo;
        }
        $consultaCompras->execute($parametros);
        $compras = $consultaCompras->fetchAll();
        $idsCompras = array_map(static fn (array $compra): int => (int) $compra['id'], $compras);
        $detallesPorCompra = [];
        if ($idsCompras !== []) {
            $marcadores = implode(', ', array_fill(0, count($idsCompras), '?'));
            $consultaDetalles = $this->baseDatos->prepare(
                'SELECT d.compra_id, p.codigo_sku AS sku, p.nombre AS product_name,
                        d.cantidad AS quantity, d.costo_unitario_centavos AS unit_cost_cents,
                        d.total_linea_centavos AS line_total_cents
                 FROM detalle_compras d
                 JOIN productos p ON p.id = d.producto_id
                 WHERE d.compra_id IN (' . $marcadores . ')
                 ORDER BY d.compra_id DESC, d.id'
            );
            $consultaDetalles->execute($idsCompras);
            foreach ($consultaDetalles->fetchAll() as $detalle) {
                $detallesPorCompra[(int) $detalle['compra_id']][] = $detalle;
            }
        }

        return ['compras' => $compras, 'detalles' => $detallesPorCompra];
    }

    public function obtenerAuditoria(array $idsCompras): array
    {
        if ($idsCompras === []) {
            return [];
        }

        $marcadores = implode(', ', array_fill(0, count($idsCompras), '?'));
        $consulta = $this->baseDatos->prepare(
            'SELECT a.compra_id, a.evento, a.datos_anteriores, a.datos_nuevos, a.motivo,
                    a.creado_en, u.nombre_completo AS created_by
             FROM compras_auditoria a
             JOIN usuarios u ON u.id = a.creado_por
             WHERE a.compra_id IN (' . $marcadores . ')
             ORDER BY a.creado_en DESC, a.id DESC'
        );
        $consulta->execute($idsCompras);
        $auditoriaPorCompra = [];
        foreach ($consulta->fetchAll() as $evento) {
            $auditoriaPorCompra[(int) $evento['compra_id']][] = $evento;
        }

        return $auditoriaPorCompra;
    }
}

final class EtiquetasInventarioModelo
{
    public function __construct(private readonly PDO $baseDatos)
    {
    }

    public function obtenerProducto(int $idProducto, string $codigoAlmacen): array|false
    {
        $consulta = $this->baseDatos->prepare(
            'SELECT i.codigo_barras AS barcode, i.nombre AS name, i.marca AS brand, i.medida AS measure,
                    i.cantidad AS quantity, w.codigo AS warehouse_code, w.nombre AS warehouse_name
             FROM inventario_b4x01 i
             JOIN almacenes w ON w.codigo = i.almacen_codigo AND w.activo = 1
             WHERE i.id = :product_id AND w.codigo = :warehouse'
        );
        $consulta->execute(['product_id' => $idProducto, 'warehouse' => $codigoAlmacen]);

        return $consulta->fetch();
    }

    public function obtenerProductoOperativo(int $idProducto, string $codigoAlmacen): array|false
    {
        $consulta = $this->baseDatos->prepare(
            'SELECT p.codigo_sku AS barcode, p.nombre AS name, p.marca AS brand, p.medida AS measure,
                    i.cantidad AS quantity, w.codigo AS warehouse_code, w.nombre AS warehouse_name
             FROM productos p
             JOIN inventario i ON i.producto_id = p.id
             JOIN almacenes w ON w.codigo = i.almacen_codigo AND w.activo = 1
             WHERE p.id = :product_id AND w.codigo = :warehouse'
        );
        $consulta->execute(['product_id' => $idProducto, 'warehouse' => $codigoAlmacen]);

        return $consulta->fetch();
    }
}

final class EscaneoSinStockException extends RuntimeException
{
}

final class InventarioModelo
{
    private const TAMANO_MAXIMO_ARCHIVO = 15_000_000;
    private const TAMANO_MAXIMO_XML = 30_000_000;
    private const FILAS_MAXIMAS = 50_000;
    private const ALMACENES = ['A0101', 'A0102', 'A0103'];
    private const DIAS_SIN_VENTA_PRIORIDAD = 30;

    private array $totalesAlmacenesExcluidos = [];

    public function __construct(private readonly PDO $baseDatos)
    {
    }

    private function bloqueoFilas(): string
    {
        return $this->baseDatos->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }

    public function obtenerConteoFisico(array $filtros): array
    {
        $almacen = strtoupper(trim((string) ($filtros['warehouse'] ?? '')));
        if (!in_array($almacen, self::ALMACENES, true)) {
            $almacen = '';
        }
        $busqueda = trim((string) ($filtros['q'] ?? ''));
        $filtroBusqueda = $this->filtroTextoConteoFisico($busqueda);
        $almacenes = $this->baseDatos->query(
            'SELECT codigo AS code, nombre AS name FROM almacenes WHERE activo = 1 ORDER BY codigo'
        )->fetchAll();

        $condiciones = [];
        $parametros = [];
        if ($almacen !== '') {
            $condiciones[] = 'i.almacen_codigo = :warehouse';
            $parametros['warehouse'] = $almacen;
        }
        if ($filtroBusqueda['sql'] !== '') {
            $condiciones[] = $filtroBusqueda['sql'];
            $parametros += $filtroBusqueda['parameters'];
        }

        $where = $condiciones === [] ? '' : 'WHERE ' . implode(' AND ', $condiciones);
        $conteo = $this->baseDatos->prepare(
            "SELECT COUNT(*) FROM inventario i JOIN productos p ON p.id = i.producto_id $where"
        );
        $conteo->execute($parametros);
        $totalFilas = (int) $conteo->fetchColumn();
        $tamanoPagina = 20;
        $totalPaginas = max(1, (int) ceil($totalFilas / $tamanoPagina));
        $pagina = filter_var($filtros['page'] ?? 1, FILTER_VALIDATE_INT);
        $pagina = $pagina === false || $pagina < 1 ? 1 : min($pagina, $totalPaginas);
        $desplazamiento = ($pagina - 1) * $tamanoPagina;
        $productosEscaneados = $filtros['scanned_product_ids'] ?? [];
        $productosEscaneados = is_array($productosEscaneados)
            ? array_values(array_unique(array_filter(
                array_map('intval', $productosEscaneados),
                static fn (int $productId): bool => $productId > 0
            )))
            : [];
        $productosEscaneados = array_slice($productosEscaneados, 0, $tamanoPagina);
        $prioridadEscaneados = '';
        foreach ($productosEscaneados as $index => $productId) {
            $parameter = 'scanned_product_' . $index;
            $prioridadEscaneados .= ' WHEN p.id = :' . $parameter . ' THEN ' . $index;
            $parametros[$parameter] = $productId;
        }
        if ($productosEscaneados !== []) {
            $prioridadEscaneados = 'CASE' . $prioridadEscaneados
                . ' ELSE ' . count($productosEscaneados) . ' END, ';
        }
        $prioridadSkuBusqueda = '';
        if ($busqueda !== '') {
            $parametros['count_sku_exact'] = strtoupper($busqueda);
            $parametros['count_sku_partial'] = '%' . strtoupper($busqueda) . '%';
            $prioridadSkuBusqueda = 'CASE WHEN UPPER(p.codigo_sku) = :count_sku_exact THEN 0'
                . ' WHEN UPPER(p.codigo_sku) LIKE :count_sku_partial THEN 1 ELSE 2 END, ';
        }
        $consulta = $this->baseDatos->prepare(
            "SELECT p.id AS product_id, p.codigo_sku AS sku, p.nombre AS name, p.marca AS brand,
                    p.medida AS size, i.almacen_codigo AS warehouse_code, w.nombre AS warehouse_name,
                    i.cantidad AS system_quantity, i.stock_minimo AS minimum_stock,
                    i.stock_seguridad AS safety_stock, i.stock_en_transito AS stock_in_transit
             FROM inventario i
             JOIN productos p ON p.id = i.producto_id
             JOIN almacenes w ON w.codigo = i.almacen_codigo
             $where
             ORDER BY $prioridadEscaneados $prioridadSkuBusqueda w.codigo, p.nombre, p.codigo_sku
             LIMIT :limit OFFSET :offset"
        );
        foreach ($parametros as $nombre => $valor) {
            $consulta->bindValue(
                ':' . $nombre,
                $valor,
                is_int($valor) ? PDO::PARAM_INT : PDO::PARAM_STR
            );
        }
        $consulta->bindValue(':limit', $tamanoPagina, PDO::PARAM_INT);
        $consulta->bindValue(':offset', $desplazamiento, PDO::PARAM_INT);
        $consulta->execute();

        return [
            'warehouses' => $almacenes,
            'warehouse' => $almacen,
            'search' => $busqueda,
            'rows' => $consulta->fetchAll(),
            'total_rows' => $totalFilas,
            'page' => $pagina,
            'page_size' => $tamanoPagina,
            'total_pages' => $totalPaginas,
            'page_offset' => $desplazamiento,
        ];
    }

    public function buscarSugerenciasConteoFisico(string $warehouse, string $search, int $limit = 12): array
    {
        $warehouse = strtoupper(trim($warehouse));
        $search = trim($search);
        if (!in_array($warehouse, self::ALMACENES, true)) {
            throw new RuntimeException('Selecciona un almacén válido para buscar llantas.');
        }
        if ($search === '') {
            return [];
        }

        $filtroBusqueda = $this->filtroTextoConteoFisico($search);
        if ($filtroBusqueda['sql'] === '') {
            return [];
        }

        $consulta = $this->baseDatos->prepare(
            'SELECT p.id AS product_id, p.codigo_sku AS sku, p.nombre AS name,
                    p.marca AS brand, p.medida AS measure, i.cantidad AS system_quantity
             FROM inventario i
             JOIN productos p ON p.id = i.producto_id
             WHERE i.almacen_codigo = :warehouse AND ' . $filtroBusqueda['sql'] . '
             ORDER BY p.nombre, p.codigo_sku
             LIMIT :limit'
        );
        $consulta->bindValue(':warehouse', $warehouse, PDO::PARAM_STR);
        foreach ($filtroBusqueda['parameters'] as $name => $value) {
            $consulta->bindValue(':' . $name, $value, PDO::PARAM_STR);
        }
        $consulta->bindValue(':limit', max(1, min($limit, 20)), PDO::PARAM_INT);
        $consulta->execute();

        return $consulta->fetchAll();
    }

    public function buscarSugerenciasInventarioOperativo(
        string $search,
        string $warehouse = '',
        int $limit = 12,
        bool $matchPrefix = false
    ): array {
        $search = trim($search);
        $warehouse = strtoupper(trim($warehouse));
        if ($warehouse !== '' && !in_array($warehouse, self::ALMACENES, true)) {
            throw new RuntimeException('Selecciona un almacén válido para buscar productos.');
        }
        if ($search === '') {
            return [];
        }

        $filtroBusqueda = $this->filtroTextoConteoFisico(
            $search,
            null,
            $matchPrefix,
            $matchPrefix
        );
        if ($filtroBusqueda['sql'] === '') {
            return [];
        }
        $conditions = ['w.activo = 1', $filtroBusqueda['sql']];
        $parameters = $filtroBusqueda['parameters'];
        if ($warehouse !== '') {
            $conditions[] = 'i.almacen_codigo = :warehouse';
            $parameters['warehouse'] = $warehouse;
        }

        $consulta = $this->baseDatos->prepare(
            'SELECT p.id AS product_id, p.codigo_sku AS sku, p.nombre AS name,
                    p.marca AS brand, p.medida AS measure, COALESCE(SUM(i.cantidad), 0) AS system_quantity,
                    COUNT(DISTINCT w.codigo) AS warehouse_count
             FROM inventario i
             JOIN productos p ON p.id = i.producto_id
             JOIN almacenes w ON w.codigo = i.almacen_codigo
             WHERE ' . implode(' AND ', $conditions) . '
             GROUP BY p.id, p.codigo_sku, p.nombre, p.marca, p.medida
             ORDER BY p.nombre, p.codigo_sku
             LIMIT :limit'
        );
        foreach ($parameters as $name => $value) {
            $consulta->bindValue(':' . $name, $value, PDO::PARAM_STR);
        }
        $consulta->bindValue(':limit', max(1, min($limit, 20)), PDO::PARAM_INT);
        $consulta->execute();

        return $consulta->fetchAll();
    }

    public function buscarSugerenciasInventarioB4x01(
        string $search,
        string $warehouse = '',
        int $limit = 12
    ): array {
        $search = trim($search);
        $warehouse = strtoupper(trim($warehouse));
        if ($warehouse !== '' && !in_array($warehouse, self::ALMACENES, true)) {
            throw new RuntimeException('Selecciona un almacén válido para buscar llantas.');
        }
        if ($search === '') {
            return [];
        }

        $filtroBusqueda = $this->filtroTextoConteoFisico($search, [
            'barcode' => 'i.codigo_barras',
            'name' => 'i.nombre',
            'brand' => 'i.marca',
            'measure' => 'i.medida',
            'category' => 'i.categoria',
        ]);
        if ($filtroBusqueda['sql'] === '') {
            return [];
        }
        $conditions = [$filtroBusqueda['sql']];
        $parameters = $filtroBusqueda['parameters'];
        if ($warehouse !== '') {
            $conditions[] = 'i.almacen_codigo = :warehouse';
            $parameters['warehouse'] = $warehouse;
        }

        $consulta = $this->baseDatos->prepare(
            'SELECT i.id AS product_id, i.codigo_barras AS barcode, i.nombre AS name,
                    i.marca AS brand, i.medida AS measure, i.cantidad AS system_quantity,
                    i.almacen_codigo AS warehouse_code, w.nombre AS warehouse_name
             FROM inventario_b4x01 i
             JOIN almacenes w ON w.codigo = i.almacen_codigo
             WHERE ' . implode(' AND ', $conditions) . '
             ORDER BY i.nombre, i.codigo_barras, i.almacen_codigo
             LIMIT :limit'
        );
        foreach ($parameters as $name => $value) {
            $consulta->bindValue(':' . $name, $value, PDO::PARAM_STR);
        }
        $consulta->bindValue(':limit', max(1, min($limit, 20)), PDO::PARAM_INT);
        $consulta->execute();

        return $consulta->fetchAll();
    }

    private function filtroTextoConteoFisico(
        string $search,
        ?array $columns = null,
        bool $matchPrefix = false,
        bool $matchSkuWithoutB4x01Prefix = false
    ): array
    {
        $tokens = preg_split('/[\s\/\-]+/u', trim($search), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($tokens)) {
            throw new RuntimeException('No se pudo interpretar la búsqueda del producto.');
        }
        if ($tokens === [] && trim($search) !== '') {
            $tokens = [trim($search)];
        }

        $columns ??= [
            'sku' => 'p.codigo_sku',
            'name' => 'p.nombre',
            'brand' => 'p.marca',
            'measure' => 'p.medida',
        ];
        $conditions = [];
        $parameters = [];
        foreach ($tokens as $index => $token) {
            $fieldConditions = [];
            foreach ($columns as $field => $column) {
                $parameter = 'search_' . $index . '_' . $field;
                $fieldConditions[] = $column . ' LIKE :' . $parameter;
                $parameters[$parameter] = $matchPrefix ? $token . '%' : '%' . $token . '%';
                if ($matchSkuWithoutB4x01Prefix && $field === 'sku') {
                    $hiddenPrefixParameter = $parameter . '_without_b4x01';
                    $fieldConditions[array_key_last($fieldConditions)] = '('
                        . $column . ' LIKE :' . $parameter
                        . ' OR UPPER(' . $column . ') LIKE :' . $hiddenPrefixParameter . ')';
                    $parameters[$hiddenPrefixParameter] = '%B4X01' . strtoupper($token) . '%';
                }
            }
            $conditions[] = '(' . implode(' OR ', $fieldConditions) . ')';
        }

        return [
            'sql' => implode(' AND ', $conditions),
            'parameters' => $parameters,
        ];
    }

    public function obtenerReporteConteoFisico(string $warehouse): array
    {
        $warehouse = strtoupper(trim($warehouse));
        if (!in_array($warehouse, self::ALMACENES, true)) {
            throw new RuntimeException('Selecciona un almacén válido para descargar el reporte.');
        }

        $consulta = $this->baseDatos->prepare(
            "SELECT m.creado_en AS counted_at, w.codigo AS warehouse_code, w.nombre AS warehouse_name,
                    p.codigo_sku AS sku, p.nombre AS product_name, p.marca AS brand, p.medida AS measure,
                    m.cambio_cantidad AS difference, m.notas AS notes,
                    COALESCE(u.nombre_completo, 'Usuario desactivado') AS user_name
             FROM movimientos_inventario m
             JOIN productos p ON p.id = m.producto_id
             JOIN almacenes w ON w.codigo = m.almacen_codigo
             LEFT JOIN usuarios u ON u.id = m.creado_por
             WHERE m.tipo_referencia = 'physical_count' AND m.almacen_codigo = :warehouse
             ORDER BY m.creado_en DESC, m.id DESC"
        );
        $consulta->execute(['warehouse' => $warehouse]);

        $rows = [];
        foreach ($consulta->fetchAll() as $row) {
            if (preg_match(
                '/\AConteo físico: saldo anterior (-?\d+), contado (-?\d+)\. Motivo: (.*)\z/su',
                (string) $row['notes'],
                $match
            ) !== 1) {
                throw new RuntimeException('Un registro guardado no tiene el detalle esperado para el reporte.');
            }

            $rows[] = [
                'counted_at' => (string) $row['counted_at'],
                'warehouse_code' => (string) $row['warehouse_code'],
                'warehouse_name' => (string) $row['warehouse_name'],
                'sku' => (string) $row['sku'],
                'product_name' => (string) $row['product_name'],
                'brand' => (string) $row['brand'],
                'measure' => (string) $row['measure'],
                'previous_quantity' => (int) $match[1],
                'physical_quantity' => (int) $match[2],
                'difference' => (int) $row['difference'],
                'reason' => (string) $match[3],
                'user_name' => (string) $row['user_name'],
            ];
        }

        return $rows;
    }

    public function obtenerUltimosConteosFisicos(string $warehouse): array
    {
        $warehouse = strtoupper(trim($warehouse));
        if (!in_array($warehouse, self::ALMACENES, true)) {
            throw new RuntimeException('Selecciona un almacén válido para consultar los últimos conteos.');
        }

        $consulta = $this->baseDatos->prepare(
            "SELECT movimiento.producto_id, movimiento.notas
             FROM movimientos_inventario movimiento
             JOIN (
                 SELECT producto_id, MAX(id) AS latest_id
                 FROM movimientos_inventario
                 WHERE tipo_referencia = 'physical_count' AND almacen_codigo = :warehouse
                 GROUP BY producto_id
             ) ultimo ON ultimo.latest_id = movimiento.id
             ORDER BY movimiento.creado_en DESC, movimiento.id DESC"
        );
        $consulta->execute(['warehouse' => $warehouse]);

        $counts = [];
        foreach ($consulta->fetchAll() as $row) {
            if (preg_match(
                '/\AConteo físico: saldo anterior (-?\d+), contado (-?\d+)\. Motivo: (.*)\z/su',
                (string) $row['notas'],
                $match
            ) !== 1) {
                throw new RuntimeException('Un conteo guardado no tiene el detalle esperado.');
            }
            $counts[(int) $row['producto_id']] = (int) $match[2];
        }

        return $counts;
    }

    public function analizarCoincidenciasConteoFisico(
        string $warehouse,
        ?int $targetProductId = null,
        int $maximumResults = 40
    ): array
    {
        $warehouse = strtoupper(trim($warehouse));
        if (!in_array($warehouse, self::ALMACENES, true)) {
            throw new RuntimeException('Selecciona un almacén válido para analizar productos similares.');
        }
        if ($targetProductId !== null && $targetProductId < 1) {
            throw new RuntimeException('Selecciona una llanta válida para el análisis.');
        }

        $consulta = $this->baseDatos->prepare(
            'SELECT p.id AS product_id, p.codigo_sku AS sku, p.nombre AS name,
                    p.marca AS brand, p.medida AS measure, i.cantidad AS system_quantity
             FROM inventario i
             JOIN productos p ON p.id = i.producto_id
             WHERE i.almacen_codigo = :warehouse
             ORDER BY p.nombre, p.codigo_sku'
        );
        $consulta->execute(['warehouse' => $warehouse]);

        $groups = [];
        $analyzedProducts = 0;
        $focusProduct = null;
        foreach ($consulta->fetchAll() as $row) {
            if ($targetProductId !== null && (int) $row['product_id'] === $targetProductId) {
                $focusProduct = [
                    'product_id' => (int) $row['product_id'],
                    'sku' => (string) $row['sku'],
                    'name' => (string) $row['name'],
                    'brand' => (string) $row['brand'],
                    'measure' => (string) $row['measure'],
                ];
            }
            $measure = $this->extraerMedidaParaCoincidencia((string) $row['measure'])
                ?? $this->extraerMedidaParaCoincidencia((string) $row['name']);
            if ($measure === null) {
                continue;
            }

            $nameWithoutMeasure = preg_replace(
                '/(?<!\d)\d{3}\s*[\/\-]\s*\d{2}\s*(?:[\/\-]|R)\s*\d{2}(?!\d)/iu',
                ' ',
                (string) $row['name']
            );
            if (!is_string($nameWithoutMeasure)) {
                throw new RuntimeException('No se pudo preparar el nombre del producto para el análisis.');
            }
            $row['size_key'] = $measure['key'];
            $row['size_display'] = $measure['display'];
            $row['brand_key'] = $this->normalizarClaveCoincidencia((string) $row['brand']);
            $row['name_key'] = $this->normalizarClaveCoincidencia($nameWithoutMeasure);
            if ($row['brand_key'] === '' || $row['name_key'] === '') {
                continue;
            }
            $groups[$measure['key']][] = $row;
            $analyzedProducts++;
        }

        $maximumResults = max(1, min($maximumResults, 100));
        $matches = [];
        $totalMatches = 0;
        foreach ($groups as $sizeKey => $products) {
            $sizeKey = (string) $sizeKey;
            $neighborKeys = [$sizeKey];
            for ($position = 0; $position < strlen($sizeKey); $position++) {
                for ($digit = 0; $digit <= 9; $digit++) {
                    if ((string) $digit === $sizeKey[$position]) {
                        continue;
                    }
                    $neighborKey = substr_replace($sizeKey, (string) $digit, $position, 1);
                    if (isset($groups[$neighborKey]) && strcmp($neighborKey, $sizeKey) >= 0) {
                        $neighborKeys[] = $neighborKey;
                    }
                }
            }

            foreach (array_unique($neighborKeys) as $neighborKey) {
                $neighborProducts = $groups[$neighborKey];
                $sizeDistance = levenshtein($sizeKey, $neighborKey);
                foreach ($products as $leftIndex => $left) {
                    $startIndex = $neighborKey === $sizeKey ? $leftIndex + 1 : 0;
                    for ($rightIndex = $startIndex, $rightCount = count($neighborProducts); $rightIndex < $rightCount; $rightIndex++) {
                        $right = $neighborProducts[$rightIndex];
                        if ($targetProductId !== null
                            && (int) $left['product_id'] !== $targetProductId
                            && (int) $right['product_id'] !== $targetProductId
                        ) {
                            continue;
                        }
                        $match = $this->compararProductosConteoFisico(
                            $left,
                            $right,
                            $sizeDistance
                        );
                        if ($match === null) {
                            continue;
                        }

                        $totalMatches++;
                        $matches[] = $match;
                        if (count($matches) > $maximumResults * 2) {
                            usort($matches, static fn (array $a, array $b): int => $b['similarity'] <=> $a['similarity']);
                            $matches = array_slice($matches, 0, $maximumResults);
                        }
                    }
                }
            }
        }

        if ($targetProductId !== null && $focusProduct === null) {
            throw new RuntimeException('No se encontró la llanta seleccionada en este almacén.');
        }
        usort($matches, static fn (array $a, array $b): int => $b['similarity'] <=> $a['similarity']);

        return [
            'products_analyzed' => $analyzedProducts,
            'total_matches' => $totalMatches,
            'matches' => array_slice($matches, 0, $maximumResults),
            'focus_product' => $focusProduct,
        ];
    }

    private function extraerMedidaParaCoincidencia(string $value): ?array
    {
        if (preg_match('/(?<!\d)(\d{3})\s*[\/\-]\s*(\d{2})\s*(?:[\/\-]|R)\s*(\d{2})(?!\d)/i', $value, $match) !== 1) {
            return null;
        }

        return [
            'key' => $match[1] . $match[2] . $match[3],
            'display' => $match[1] . '/' . $match[2] . '/' . $match[3],
        ];
    }

    private function normalizarClaveCoincidencia(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($ascii === false) {
            throw new RuntimeException('No se pudo normalizar el texto del producto para el análisis.');
        }
        $normalized = preg_replace('/[^a-z0-9]+/i', '', strtolower($ascii));
        if (!is_string($normalized)) {
            throw new RuntimeException('No se pudo normalizar el texto del producto para el análisis.');
        }

        return $normalized;
    }

    private function compararProductosConteoFisico(array $left, array $right, int $sizeDistance): ?array
    {
        $brandLength = max(strlen($left['brand_key']), strlen($right['brand_key']));
        $nameLength = max(strlen($left['name_key']), strlen($right['name_key']));
        if ($brandLength === 0 || $nameLength === 0) {
            return null;
        }
        $brandSimilarity = 1 - levenshtein($left['brand_key'], $right['brand_key']) / $brandLength;
        $nameSimilarity = 1 - levenshtein($left['name_key'], $right['name_key']) / $nameLength;
        if ($brandSimilarity < 0.72 || $nameSimilarity < 0.65) {
            return null;
        }

        $sizeLength = max(strlen($left['size_key']), strlen($right['size_key']));
        $sizeSimilarity = $sizeLength === 0 ? 0 : 1 - $sizeDistance / $sizeLength;
        $score = (int) round(
            ($brandSimilarity * 0.3 + $nameSimilarity * 0.5 + $sizeSimilarity * 0.2) * 100
        );
        $reasons = $sizeDistance === 0
            ? ['La medida coincide']
            : ['Medida muy parecida: ' . $left['size_display'] . ' / ' . $right['size_display']];
        if ($brandSimilarity < 1) {
            $reasons[] = 'marca con posibles diferencias';
        }
        if ($nameSimilarity < 1) {
            $reasons[] = 'nombre o modelo con posibles diferencias';
        }

        return [
            'first' => $left,
            'second' => $right,
            'similarity' => $score,
            'reason' => implode('; ', $reasons),
        ];
    }

    public function buscarProductoConteoFisicoPorCodigoBarras(string $codigoBarras, string $almacen): array
    {
        $codigoBarras = strtoupper(trim($codigoBarras));
        if (strlen($codigoBarras) > 2 && $codigoBarras[0] === '*' && substr($codigoBarras, -1) === '*') {
            $codigoBarras = substr($codigoBarras, 1, -1);
        }
        $almacen = strtoupper(trim($almacen));
        if ($codigoBarras === '' || strlen($codigoBarras) > 80
            || preg_match('/\A[\x20-\x7E]+\z/D', $codigoBarras) !== 1
        ) {
            throw new RuntimeException('El código escaneado no es válido.');
        }
        if (!in_array($almacen, self::ALMACENES, true)) {
            throw new RuntimeException('Selecciona un almacén válido para el conteo.');
        }

        $buscarSku = $this->baseDatos->prepare(
            'SELECT p.id AS product_id, p.codigo_sku AS sku, p.nombre AS name, p.marca AS brand,
                    p.medida AS size, i.almacen_codigo AS warehouse_code, w.nombre AS warehouse_name,
                    i.cantidad AS system_quantity
             FROM inventario i
             JOIN productos p ON p.id = i.producto_id
             JOIN almacenes w ON w.codigo = i.almacen_codigo
             WHERE p.codigo_sku = :sku AND i.almacen_codigo = :warehouse'
        );
        $buscarSku->execute(['sku' => $codigoBarras, 'warehouse' => $almacen]);
        $productoSku = $buscarSku->fetch();
        if ($productoSku !== false) {
            return $productoSku;
        }

        $buscarEtiqueta = $this->baseDatos->prepare(
            'SELECT nombre, marca FROM inventario_b4x01
             WHERE codigo_barras = :barcode AND almacen_codigo = :warehouse'
        );
        $buscarEtiqueta->execute(['barcode' => $codigoBarras, 'warehouse' => $almacen]);
        $etiqueta = $buscarEtiqueta->fetch();
        if ($etiqueta === false) {
            throw new RuntimeException('No se encontró ese código en el almacén seleccionado.');
        }

        $buscarProducto = $this->baseDatos->prepare(
            'SELECT p.id AS product_id, p.codigo_sku AS sku, p.nombre AS name, p.marca AS brand,
                    p.medida AS size, i.almacen_codigo AS warehouse_code, w.nombre AS warehouse_name,
                    i.cantidad AS system_quantity
             FROM inventario i
             JOIN productos p ON p.id = i.producto_id
             JOIN almacenes w ON w.codigo = i.almacen_codigo
             WHERE i.almacen_codigo = :warehouse
               AND p.nombre = :name AND p.marca = :brand
             ORDER BY p.id
             LIMIT 2'
        );
        $buscarProducto->execute([
            'warehouse' => $almacen,
            'name' => $etiqueta['nombre'],
            'brand' => $etiqueta['marca'],
        ]);
        $productos = $buscarProducto->fetchAll();
        if (count($productos) !== 1) {
            throw new RuntimeException(
                $productos === []
                    ? 'La etiqueta se reconoció, pero no coincide con un producto del inventario operativo. Captúralo manualmente.'
                    : 'La etiqueta coincide con varios productos operativos. Captúralo manualmente.'
            );
        }

        return $productos[0];
    }

    public function ajustarConteoFisico(
        int $productId,
        string $warehouse,
        int $physicalQuantity,
        string $reason,
        int $userId
    ): array {
        $warehouse = strtoupper(trim($warehouse));
        $reason = trim($reason);
        if (!in_array($warehouse, self::ALMACENES, true)) {
            throw new RuntimeException('Selecciona un almacén válido para el conteo.');
        }
        if ($productId < 1 || $physicalQuantity < 0 || $physicalQuantity > 2147483647) {
            throw new RuntimeException('La cantidad del conteo debe ser un entero no negativo.');
        }
        if ($reason === '' || text_length($reason) > 255) {
            throw new RuntimeException('Escribe el motivo del conteo (máximo 255 caracteres).');
        }

        $this->baseDatos->beginTransaction();
        try {
            $buscar = $this->baseDatos->prepare(
                'SELECT p.codigo_sku AS sku, p.nombre AS name, p.categoria AS category,
                        i.cantidad AS previous_quantity
                 FROM inventario i
                 JOIN productos p ON p.id = i.producto_id
                 WHERE i.producto_id = :product_id AND i.almacen_codigo = :warehouse
                 ' . $this->bloqueoFilas()
            );
            $buscar->execute(['product_id' => $productId, 'warehouse' => $warehouse]);
            $producto = $buscar->fetch();
            if ($producto === false) {
                throw new RuntimeException('No existe inventario operativo para ese producto y almacén.');
            }

            $saldoAnterior = (int) $producto['previous_quantity'];
            $diferencia = $physicalQuantity - $saldoAnterior;
            $actualizar = $this->baseDatos->prepare(
                'UPDATE inventario SET cantidad = :quantity, actualizado_en = CURRENT_TIMESTAMP
                 WHERE producto_id = :product_id AND almacen_codigo = :warehouse'
            );
            $actualizar->execute([
                'quantity' => $physicalQuantity,
                'product_id' => $productId,
                'warehouse' => $warehouse,
            ]);
            if (InventarioUnidades::esLlanta($producto)) {
                if ($diferencia > 0) {
                    InventarioUnidades::agregar($this->baseDatos, $productId, $warehouse, $diferencia);
                } elseif ($diferencia < 0) {
                    InventarioUnidades::retirar($this->baseDatos, $productId, $warehouse, -$diferencia);
                }
            }

            $movimiento = $this->baseDatos->prepare(
                'INSERT INTO movimientos_inventario
                    (producto_id, almacen_codigo, tipo_movimiento, cambio_cantidad,
                     tipo_referencia, referencia_id, creado_por, notas)
                 VALUES (:product_id, :warehouse, :movement_type, :quantity_change,
                         :reference_type, NULL, :user_id, :notes)'
            );
            $movimiento->execute([
                'product_id' => $productId,
                'warehouse' => $warehouse,
                'movement_type' => 'adjustment',
                'quantity_change' => $diferencia,
                'reference_type' => 'physical_count',
                'user_id' => $userId,
                'notes' => 'Conteo físico: saldo anterior ' . $saldoAnterior
                    . ', contado ' . $physicalQuantity . '. Motivo: ' . $reason,
            ]);
            $this->baseDatos->commit();

            return [
                'sku' => (string) $producto['sku'],
                'name' => (string) $producto['name'],
                'warehouse' => $warehouse,
                'previous_quantity' => $saldoAnterior,
                'physical_quantity' => $physicalQuantity,
                'difference' => $diferencia,
            ];
        } catch (Throwable $exception) {
            if ($this->baseDatos->inTransaction()) {
                $this->baseDatos->rollBack();
            }
            throw $exception;
        }
    }

    public function registrarEscaneo(string $codigoBarras, string $almacen, int $usuarioId): array
    {
        $codigoBarras = strtoupper(trim($codigoBarras));
        if (strlen($codigoBarras) > 2 && $codigoBarras[0] === '*' && substr($codigoBarras, -1) === '*') {
            $codigoBarras = substr($codigoBarras, 1, -1);
        }
        $almacen = strtoupper(trim($almacen));
        if ($codigoBarras === '' || strlen($codigoBarras) > 80
            || preg_match('/\A[\x20-\x7E]+\z/D', $codigoBarras) !== 1
        ) {
            throw new RuntimeException('El código escaneado no es válido.');
        }
        if (!in_array($almacen, self::ALMACENES, true)) {
            throw new RuntimeException('Selecciona un almacén válido.');
        }
        $this->baseDatos->beginTransaction();
        try {
            $buscarProducto = $this->baseDatos->prepare(
                'SELECT id, codigo_excel, codigo_barras, nombre, marca, medida, cantidad, stock_minimo
                 FROM inventario_b4x01
                 WHERE codigo_barras = :barcode AND almacen_codigo = :warehouse
                 FOR UPDATE'
            );
            $buscarProducto->execute(['barcode' => $codigoBarras, 'warehouse' => $almacen]);
            $producto = $buscarProducto->fetch();
            if ($producto === false) {
                throw new RuntimeException('No se encontró esa etiqueta en el inventario del almacén seleccionado.');
            }
            if ((int) $producto['cantidad'] <= 0) {
                throw new EscaneoSinStockException('No hay stock. No se puede registrar este escaneo.');
            }

            $guardarEscaneo = $this->baseDatos->prepare(
                'INSERT INTO inventario_b4x01_escaneos
                    (codigo_barras, codigo_excel, nombre, marca, medida, almacen_codigo, escaneado_por)
                 VALUES (:barcode, :excel_code, :name, :brand, :measure, :warehouse, :user_id)'
            );
            $guardarEscaneo->execute([
                'barcode' => $producto['codigo_barras'],
                'excel_code' => $producto['codigo_excel'],
                'name' => $producto['nombre'],
                'brand' => $producto['marca'],
                'measure' => $producto['medida'],
                'warehouse' => $almacen,
                'user_id' => $usuarioId,
            ]);

            $contarEscaneos = $this->baseDatos->prepare(
                'SELECT COUNT(*)
                 FROM inventario_b4x01_escaneos
                 WHERE codigo_barras = :barcode
                   AND almacen_codigo = :warehouse
                   AND descargado_en IS NULL
                   AND aplicado_en IS NULL'
            );
            $contarEscaneos->execute([
                'barcode' => $producto['codigo_barras'],
                'warehouse' => $almacen,
            ]);
            $cantidadEscaneos = (int) $contarEscaneos->fetchColumn();

            $this->baseDatos->commit();

            return [
                'barcode' => (string) $producto['codigo_barras'],
                'name' => (string) $producto['nombre'],
                'brand' => (string) $producto['marca'],
                'measure' => (string) $producto['medida'],
                'warehouse' => $almacen,
                'scan_count' => $cantidadEscaneos,
            ];
        } catch (Throwable $exception) {
            if ($this->baseDatos->inTransaction()) {
                $this->baseDatos->rollBack();
            }
            throw $exception;
        }
    }

    public function obtenerEscaneos(
        ?string $almacen = null,
        bool $soloLoteNuevo = false,
        ?string $loteDescarga = null
    ): array
    {
        if ($almacen !== null && !in_array($almacen, self::ALMACENES, true)) {
            throw new RuntimeException('El almacén seleccionado no es válido.');
        }

        $sql = 'SELECT e.id AS scan_id,
                       DATE_FORMAT(e.escaneado_en, "%Y-%m-%d %H:%i:%s") AS scanned_at,
                       e.codigo_barras AS barcode, e.codigo_excel AS excel_code,
                       e.nombre AS name, e.marca AS brand, e.medida AS measure,
                       e.almacen_codigo AS warehouse,
                       COALESCE(u.nombre_completo, "Usuario desactivado") AS user_name
                FROM inventario_b4x01_escaneos e
                LEFT JOIN usuarios u ON u.id = e.escaneado_por
                WHERE e.aplicado_en IS NULL';
        if ($soloLoteNuevo) {
            $sql .= ' AND e.descargado_en IS NULL';
        }
        if ($loteDescarga !== null) {
            $sql .= ' AND e.lote_descarga = :download_batch';
        }
        if ($almacen !== null) {
            $sql .= ' AND e.almacen_codigo = :warehouse';
        }
        $sql .= ' ORDER BY e.id';
        if (($soloLoteNuevo || $loteDescarga !== null) && $this->baseDatos->inTransaction()) {
            $sql .= ' FOR UPDATE';
        }

        $consulta = $this->baseDatos->prepare($sql);
        $parametros = [];
        if ($almacen !== null) {
            $parametros['warehouse'] = $almacen;
        }
        if ($loteDescarga !== null) {
            $parametros['download_batch'] = $loteDescarga;
        }
        $consulta->execute($parametros);

        return $consulta->fetchAll();
    }

    public function obtenerLotesEscaneosDescargados(): array
    {
        return $this->baseDatos->query(
            'SELECT e.lote_descarga AS batch_id,
                    DATE_FORMAT(MIN(e.descargado_en), "%Y-%m-%d %H:%i") AS downloaded_at,
                    COUNT(*) AS scan_count,
                    GROUP_CONCAT(DISTINCT e.almacen_codigo ORDER BY e.almacen_codigo SEPARATOR ", ") AS warehouses
             FROM inventario_b4x01_escaneos e
             WHERE e.lote_descarga IS NOT NULL AND e.aplicado_en IS NULL
             GROUP BY e.lote_descarga
             ORDER BY MIN(e.descargado_en) DESC'
        )->fetchAll();
    }

    public function obtenerConteoEscaneosPendientesPorAlmacen(): array
    {
        $conteos = array_fill_keys(self::ALMACENES, 0);
        $filas = $this->baseDatos->query(
            'SELECT almacen_codigo, COUNT(*) AS scan_count
             FROM inventario_b4x01_escaneos
             WHERE descargado_en IS NULL AND aplicado_en IS NULL
             GROUP BY almacen_codigo'
        )->fetchAll();
        foreach ($filas as $fila) {
            $almacen = (string) $fila['almacen_codigo'];
            if (array_key_exists($almacen, $conteos)) {
                $conteos[$almacen] = (int) $fila['scan_count'];
            }
        }

        return $conteos;
    }

    public function prepararDescargaEscaneos(
        ?string $almacen = null,
        ?string $loteDescarga = null
    ): array|false {
        if ($almacen !== null && !in_array($almacen, self::ALMACENES, true)) {
            throw new RuntimeException('El almacén seleccionado no es válido.');
        }
        if ($loteDescarga === null && $almacen === null) {
            throw new RuntimeException('Selecciona un almacén para descargar sus escaneos.');
        }
        if ($loteDescarga !== null
            && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD', $loteDescarga) !== 1
        ) {
            throw new RuntimeException('El lote de escaneos seleccionado no es válido.');
        }

        $this->baseDatos->beginTransaction();
        try {
            $escaneos = $loteDescarga === null
                ? $this->obtenerEscaneos($almacen, true)
                : $this->obtenerEscaneos(null, false, $loteDescarga);
            if ($escaneos === []) {
                $this->baseDatos->rollBack();
                return false;
            }
            $almacenesDelLote = array_unique(array_column($escaneos, 'warehouse'));
            if (count($almacenesDelLote) !== 1) {
                throw new RuntimeException('El Excel debe contener escaneos de un solo almacén. Descarga un archivo separado para cada almacén.');
            }

            $contenido = (new InventoryScanExcelExporter())->generate($escaneos);
            if ($loteDescarga === null) {
                $bytes = random_bytes(16);
                $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
                $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
                $loteDescarga = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
                foreach (array_chunk(array_column($escaneos, 'scan_id'), 500) as $ids) {
                    $marcadores = implode(',', array_fill(0, count($ids), '?'));
                    $marcarDescargados = $this->baseDatos->prepare(
                        'UPDATE inventario_b4x01_escaneos
                         SET descargado_en = CURRENT_TIMESTAMP, lote_descarga = ?
                         WHERE descargado_en IS NULL AND aplicado_en IS NULL
                           AND id IN (' . $marcadores . ')'
                    );
                    $marcarDescargados->execute(array_merge([$loteDescarga], $ids));
                }
            }

            $this->baseDatos->commit();

            return [
                'content' => $contenido,
                'batch_id' => $loteDescarga,
                'scan_count' => count($escaneos),
                'warehouse' => (string) $escaneos[0]['warehouse'],
            ];
        } catch (Throwable $exception) {
            if ($this->baseDatos->inTransaction()) {
                $this->baseDatos->rollBack();
            }
            throw $exception;
        }
    }

    public function eliminarLoteEscaneosDescargado(string $loteDescarga): int
    {
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD', $loteDescarga) !== 1) {
            throw new RuntimeException('El lote de escaneos seleccionado no es válido.');
        }

        $this->baseDatos->beginTransaction();
        try {
            $eliminar = $this->baseDatos->prepare(
                'DELETE FROM inventario_b4x01_escaneos
                 WHERE lote_descarga = :download_batch
                   AND descargado_en IS NOT NULL
                   AND aplicado_en IS NULL'
            );
            $eliminar->execute(['download_batch' => $loteDescarga]);
            $eliminados = $eliminar->rowCount();
            if ($eliminados === 0) {
                throw new RuntimeException('El lote ya no existe o ya fue aplicado al inventario.');
            }

            $this->baseDatos->commit();

            return $eliminados;
        } catch (Throwable $exception) {
            if ($this->baseDatos->inTransaction()) {
                $this->baseDatos->rollBack();
            }
            throw $exception;
        }
    }

    public function previsualizarEscaneosArchivo(string $ruta, string $nombreOriginal): array
    {
        $filas = $this->leerFilasEscaneosArchivo($ruta, $nombreOriginal);
        $escaneos = [];
        foreach (array_chunk($filas, 500) as $grupoFilas) {
            $ids = array_column($grupoFilas, 'scan_id');
            $marcadores = implode(',', array_fill(0, count($ids), '?'));
            $buscarEscaneos = $this->baseDatos->prepare(
                'SELECT id, codigo_barras, almacen_codigo
                 FROM inventario_b4x01_escaneos
                 WHERE aplicado_en IS NULL AND id IN (' . $marcadores . ')'
            );
            $buscarEscaneos->execute($ids);
            $escaneosPorId = [];
            foreach ($buscarEscaneos->fetchAll() as $escaneo) {
                $escaneosPorId[(int) $escaneo['id']] = $escaneo;
            }

            foreach ($grupoFilas as $fila) {
                $escaneo = $escaneosPorId[$fila['scan_id']] ?? null;
                if ($escaneo === null) {
                    throw new RuntimeException(
                        'El escaneo de la fila ' . $fila['row'] . ' ya fue aplicado o no existe. No se modificó el inventario.'
                    );
                }
                if (strtoupper(trim((string) $escaneo['codigo_barras'])) !== $fila['barcode']
                    || strtoupper(trim((string) $escaneo['almacen_codigo'])) !== $fila['warehouse']
                ) {
                    throw new RuntimeException(
                        'El ID de escaneo ' . $fila['scan_id']
                        . ' no corresponde al código y almacén de la fila ' . $fila['row']
                        . '. No se modificó el inventario.'
                    );
                }
                $escaneos[] = $fila;
            }
        }

        $almacen = (string) $escaneos[0]['warehouse'];
        $filasPorCodigo = [];
        foreach ($escaneos as $escaneo) {
            if (!isset($filasPorCodigo[$escaneo['barcode']])) {
                $filasPorCodigo[$escaneo['barcode']] = (int) $escaneo['row'];
            }
        }

        $products = [];
        foreach (array_chunk(array_keys($filasPorCodigo), 500) as $codigos) {
            $marcadores = implode(',', array_fill(0, count($codigos), '?'));
            $buscarProductos = $this->baseDatos->prepare(
                'SELECT codigo_excel, codigo_barras, nombre, marca, medida, cantidad, stock_minimo
                 FROM inventario_b4x01
                 WHERE almacen_codigo = ? AND codigo_barras IN (' . $marcadores . ')'
            );
            $buscarProductos->execute(array_merge([$almacen], $codigos));
            foreach ($buscarProductos->fetchAll() as $producto) {
                $key = (string) $producto['codigo_barras'] . "\0" . $almacen;
                $products[$key] = [
                    'sku' => (string) $producto['codigo_excel'],
                    'name' => (string) $producto['nombre'],
                    'brand' => (string) $producto['marca'],
                    'measure' => (string) $producto['medida'],
                    'current_stock' => (int) $producto['cantidad'],
                    'minimum_stock' => (int) $producto['stock_minimo'],
                ];
            }
        }

        foreach ($filasPorCodigo as $barcode => $numeroFila) {
            if (!isset($products[$barcode . "\0" . $almacen])) {
                throw new RuntimeException(
                    'No se encontró el código de la fila ' . $numeroFila
                    . ' en el almacén indicado. No se modificó el inventario.'
                );
            }
        }

        $summary = (new InventoryScanExcelExporter())->summarizePreview($escaneos, $products);
        foreach ($summary['products'] as $product) {
            if ($product['stock_after'] > 2147483647) {
                throw new RuntimeException('La cantidad del producto alcanzaría el límite permitido.');
            }
        }

        $summary['filename'] = basename(str_replace('\\', '/', $nombreOriginal));

        return $summary;
    }

    public function importarEscaneosArchivo(
        string $ruta,
        string $nombreOriginal,
        int $usuarioId,
        ?string $stockSnapshotEsperado = null
    ): array
    {
        $filas = $this->leerFilasEscaneosArchivo($ruta, $nombreOriginal);
        $conteoProductos = [];
        $stockInicialPorProducto = [];
        $this->baseDatos->beginTransaction();
        try {
            $buscarEscaneo = $this->baseDatos->prepare(
                'SELECT id, codigo_barras, almacen_codigo FROM inventario_b4x01_escaneos
                 WHERE id = :id AND aplicado_en IS NULL FOR UPDATE'
            );
            $buscarProducto = $this->baseDatos->prepare(
                'SELECT id, codigo_excel, codigo_barras, nombre, marca, medida, cantidad, stock_minimo
                 FROM inventario_b4x01
                 WHERE codigo_barras = :barcode AND almacen_codigo = :warehouse
                 FOR UPDATE'
            );
            $actualizarCantidad = $this->baseDatos->prepare(
                'UPDATE inventario_b4x01 SET cantidad = cantidad + 1 WHERE id = :id'
            );
            $actualizarEscaneo = $this->baseDatos->prepare(
                'UPDATE inventario_b4x01_escaneos
                 SET codigo_barras = :barcode, codigo_excel = :excel_code, nombre = :name,
                     marca = :brand, medida = :measure, almacen_codigo = :warehouse,
                     aplicado_en = CURRENT_TIMESTAMP, aplicado_por = :user_id
                 WHERE id = :id AND aplicado_en IS NULL'
            );

            foreach ($filas as $fila) {
                $buscarEscaneo->execute(['id' => $fila['scan_id']]);
                $escaneo = $buscarEscaneo->fetch();
                if ($escaneo === false) {
                    throw new RuntimeException(
                        'El escaneo de la fila ' . $fila['row'] . ' ya fue aplicado o no existe. No se modificó el inventario.'
                    );
                }
                if (strtoupper(trim((string) $escaneo['codigo_barras'])) !== $fila['barcode']
                    || strtoupper(trim((string) $escaneo['almacen_codigo'])) !== $fila['warehouse']
                ) {
                    throw new RuntimeException(
                        'El ID de escaneo ' . $fila['scan_id']
                        . ' no corresponde al código y almacén de la fila ' . $fila['row']
                        . '. No se modificó el inventario.'
                    );
                }

                $buscarProducto->execute([
                    'barcode' => $fila['barcode'],
                    'warehouse' => $fila['warehouse'],
                ]);
                $producto = $buscarProducto->fetch();
                if ($producto === false) {
                    throw new RuntimeException(
                        'No se encontró el código de la fila ' . $fila['row'] . ' en el almacén indicado. No se modificó el inventario.'
                    );
                }
                if ((int) $producto['cantidad'] >= 2147483647) {
                    throw new RuntimeException('La cantidad del producto alcanzó el límite permitido.');
                }
                $clave = (string) $fila['barcode'] . "\0" . (string) $fila['warehouse'];
                if (!array_key_exists($clave, $stockInicialPorProducto)) {
                    $stockInicialPorProducto[$clave] = [
                        'current_stock' => (int) $producto['cantidad'],
                    ];
                }

                $actualizarCantidad->execute(['id' => (int) $producto['id']]);
                if ($actualizarCantidad->rowCount() !== 1) {
                    throw new RuntimeException('No se pudo actualizar la cantidad del producto.');
                }
                $actualizarEscaneo->execute([
                    'barcode' => $producto['codigo_barras'],
                    'excel_code' => $producto['codigo_excel'],
                    'name' => $producto['nombre'],
                    'brand' => $producto['marca'],
                    'measure' => $producto['medida'],
                    'warehouse' => $fila['warehouse'],
                    'user_id' => $usuarioId,
                    'id' => $fila['scan_id'],
                ]);
                if ($actualizarEscaneo->rowCount() !== 1) {
                    throw new RuntimeException('No se pudo confirmar la fila ' . $fila['row'] . ' del Excel.');
                }
                $claveResumen = (string) $producto['codigo_excel'] . '|' . $fila['warehouse'];
                if (!isset($conteoProductos[$claveResumen])) {
                    $conteoProductos[$claveResumen] = [
                        'name' => (string) $producto['nombre'],
                        'sku' => (string) $producto['codigo_excel'],
                        'warehouse' => (string) $fila['warehouse'],
                        'units_added' => 0,
                        'stock_after' => (int) $producto['cantidad'],
                        'minimum_stock' => (int) $producto['stock_minimo'],
                        'low_stock' => false,
                    ];
                }
                $conteoProductos[$claveResumen]['units_added'] += 1;
                $conteoProductos[$claveResumen]['stock_after'] = (int) $producto['cantidad'] + 1;
                $conteoProductos[$claveResumen]['low_stock'] = $conteoProductos[$claveResumen]['stock_after']
                    <= (int) $producto['stock_minimo'];
            }

            if ($stockSnapshotEsperado !== null
                && !hash_equals(
                    $stockSnapshotEsperado,
                    (new InventoryScanExcelExporter())->stockSnapshotHash($stockInicialPorProducto)
                )
            ) {
                throw new RuntimeException('Las existencias cambiaron desde la vista previa. Vuelve a revisar el Excel antes de aplicarlo.');
            }

            $this->baseDatos->commit();
        } catch (Throwable $exception) {
            if ($this->baseDatos->inTransaction()) {
                $this->baseDatos->rollBack();
            }
            throw $exception;
        }

        return [
            'unidades' => count($filas),
            'productos' => count($conteoProductos),
            'archivo' => basename(str_replace('\\', '/', $nombreOriginal)),
            'stock_changes' => array_values($conteoProductos),
        ];
    }

    private function leerFilasEscaneosArchivo(string $ruta, string $nombreOriginal): array
    {
        if (!is_file($ruta) || filesize($ruta) > self::TAMANO_MAXIMO_ARCHIVO) {
            throw new RuntimeException('El archivo no existe o supera el límite de 15 MB.');
        }
        if (strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new RuntimeException('Selecciona el Excel revisado en formato .xlsx.');
        }

        try {
            $archivoExcel = new PharData($ruta, FilesystemIterator::SKIP_DOTS, null, Phar::ZIP);
            $matriz = $this->leerMatrizLibro($archivoExcel);
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new RuntimeException('No se pudo abrir el Excel. Verifica que sea un archivo .xlsx válido.', 0, $exception);
        }

        return $this->convertirFilasEscaneos($matriz);
    }

    public function importarArchivo(string $ruta, string $nombreOriginal): array
    {
        if (!is_file($ruta) || filesize($ruta) > self::TAMANO_MAXIMO_ARCHIVO) {
            throw new RuntimeException('El archivo no existe o supera el límite de 15 MB.');
        }
        if (strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new RuntimeException('Selecciona un archivo de Excel con formato .xlsx.');
        }

        try {
            $archivoExcel = new PharData($ruta, FilesystemIterator::SKIP_DOTS, null, Phar::ZIP);
            $filas = $this->leerLibro($archivoExcel);
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new RuntimeException('No se pudo abrir el Excel. Verifica que sea un archivo .xlsx válido.', 0, $exception);
        }

        $productos = $this->agruparProductos($filas);
        $huella = hash_file('sha256', $ruta);
        if (!is_string($huella)) {
            throw new RuntimeException('No se pudo calcular la huella del archivo.');
        }

        $nombreOriginal = basename(str_replace('\\', '/', $nombreOriginal));
        $totalesAlmacenes = array_fill_keys(self::ALMACENES, 0);
        foreach ($productos as $producto) {
            foreach (self::ALMACENES as $almacen) {
                $totalesAlmacenes[$almacen] += $producto['existencias'][$almacen];
            }
        }
        $codigosDiferenciados = count(array_filter(
            $productos,
            static fn (array $producto): bool => $producto['codigo_barras'] !== $producto['codigo']
        ));
        $resumen = [
            'archivo' => $nombreOriginal,
            'sha256' => $huella,
            'fecha_importacion' => date(DATE_ATOM),
            'productos' => count($productos),
            'omitidos' => 0,
            'almacenes' => $totalesAlmacenes,
            'unidades' => array_sum($totalesAlmacenes),
            'codigos_barras_diferenciados' => $codigosDiferenciados,
            'almacenes_excluidos' => $this->totalesAlmacenesExcluidos,
        ];
        $resumenJson = json_encode($resumen, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $this->baseDatos->beginTransaction();
        try {
            $productosManuales = $this->baseDatos->query(
                'SELECT clave_producto, codigo_excel, codigo_barras, nombre, categoria, marca,
                        medida, almacen_codigo, cantidad, stock_minimo, stock_seguridad,
                        stock_en_transito
                 FROM inventario_b4x01
                 WHERE es_manual = 1'
            )->fetchAll();
            $codigosImportados = [];
            $identidadesImportadas = [];
            foreach ($productos as $producto) {
                $codigosImportados[strtoupper($producto['codigo_barras'])] = true;
                $identidad = implode('|', [
                    $this->normalizar($producto['nombre']),
                    $this->normalizar($producto['marca']),
                    $this->normalizar($producto['medida']),
                ]);
                $identidadesImportadas[$identidad] = true;
            }

            $this->baseDatos->exec('DELETE FROM inventario_b4x01');
            $insertarProducto = $this->baseDatos->prepare(
                'INSERT INTO inventario_b4x01
                    (clave_producto, codigo_excel, codigo_barras, nombre, categoria, marca,
                     medida, almacen_codigo, cantidad)
                 VALUES
                    (:clave, :codigo_excel, :codigo_barras, :nombre, :categoria, :marca,
                     :medida, :almacen, :cantidad)'
            );
            foreach ($productos as $producto) {
                $clave = hash('sha256', implode('|', [
                    $this->normalizar($producto['codigo']),
                    $this->normalizar($producto['nombre']),
                    $this->normalizar($producto['marca']),
                    $this->normalizar($producto['medida']),
                ]));
                foreach (self::ALMACENES as $almacen) {
                    $insertarProducto->execute([
                        'clave' => $clave,
                        'codigo_excel' => $producto['codigo'],
                        'codigo_barras' => $producto['codigo_barras'],
                        'nombre' => $producto['nombre'],
                        'categoria' => $producto['categoria'],
                        'marca' => $producto['marca'],
                        'medida' => $producto['medida'],
                        'almacen' => $almacen,
                        'cantidad' => $producto['existencias'][$almacen],
                    ]);
                }
            }
            $insertarProductoManual = $this->baseDatos->prepare(
                'INSERT INTO inventario_b4x01
                    (clave_producto, codigo_excel, codigo_barras, nombre, categoria, marca,
                     medida, almacen_codigo, cantidad, stock_minimo, stock_seguridad,
                     stock_en_transito, es_manual)
                 VALUES
                    (:clave, :codigo_excel, :codigo_barras, :nombre, :categoria, :marca,
                     :medida, :almacen, :cantidad, :stock_minimo, :stock_seguridad,
                     :stock_en_transito, 1)'
            );
            foreach ($productosManuales as $producto) {
                $codigo = strtoupper((string) $producto['codigo_barras']);
                $identidad = implode('|', [
                    $this->normalizar((string) $producto['nombre']),
                    $this->normalizar((string) $producto['marca']),
                    $this->normalizar((string) $producto['medida']),
                ]);
                if (isset($codigosImportados[$codigo]) || isset($identidadesImportadas[$identidad])) {
                    continue;
                }

                $insertarProductoManual->execute([
                    'clave' => $producto['clave_producto'],
                    'codigo_excel' => $producto['codigo_excel'],
                    'codigo_barras' => $producto['codigo_barras'],
                    'nombre' => $producto['nombre'],
                    'categoria' => $producto['categoria'],
                    'marca' => $producto['marca'],
                    'medida' => $producto['medida'],
                    'almacen' => $producto['almacen_codigo'],
                    'cantidad' => $producto['cantidad'],
                    'stock_minimo' => $producto['stock_minimo'],
                    'stock_seguridad' => $producto['stock_seguridad'],
                    'stock_en_transito' => $producto['stock_en_transito'],
                ]);
            }

            $configuracion = $this->baseDatos->prepare(
                'INSERT INTO configuracion_app (clave, valor)
                 VALUES (:clave, :valor)
                 ON DUPLICATE KEY UPDATE valor = VALUES(valor)'
            );
            $configuracion->execute([
                'clave' => 'inventario_importacion_actual',
                'valor' => $resumenJson,
            ]);

            $this->baseDatos->commit();
        } catch (Throwable $exception) {
            if ($this->baseDatos->inTransaction()) {
                $this->baseDatos->rollBack();
            }
            throw $exception;
        }

        return $resumen;
    }

    public function obtenerDatosPagina(array $filtros): array
    {
        $filtroAlmacen = trim((string) ($filtros['warehouse'] ?? ''));
        $busqueda = trim((string) ($filtros['q'] ?? ''));
        $busquedaCodigo = strtoupper(trim((string) ($filtros['barcode'] ?? '')));
        if (strlen($busquedaCodigo) > 2 && $busquedaCodigo[0] === '*' && substr($busquedaCodigo, -1) === '*') {
            $busquedaCodigo = substr($busquedaCodigo, 1, -1);
        }
        $filtroCategoria = trim((string) ($filtros['category'] ?? ''));
        $filtroMarca = trim((string) ($filtros['brand'] ?? ''));
        $filtroEstado = trim((string) ($filtros['status'] ?? ''));
        $mostrarMovimientos = (string) ($filtros['view'] ?? '') === 'movements';
        $filtroTipoMovimiento = trim((string) ($filtros['movement_type'] ?? ''));
        if (!in_array($filtroTipoMovimiento, ['opening', 'outgoing', 'purchase', 'adjustment'], true)) {
            $filtroTipoMovimiento = '';
        }
        $fechaMovimientoDesde = trim((string) ($filtros['movement_from'] ?? ''));
        $fechaMovimientoHasta = trim((string) ($filtros['movement_to'] ?? ''));
        $validarFechaMovimiento = static fn (string $fecha): bool => preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $fecha) === 1
            && checkdate(
                (int) substr($fecha, 5, 2),
                (int) substr($fecha, 8, 2),
                (int) substr($fecha, 0, 4)
            );
        if (!$validarFechaMovimiento($fechaMovimientoDesde)) {
            $fechaMovimientoDesde = '';
        }
        if (!$validarFechaMovimiento($fechaMovimientoHasta)) {
            $fechaMovimientoHasta = '';
        }
        if ($fechaMovimientoDesde !== '' && $fechaMovimientoHasta !== '' && $fechaMovimientoDesde > $fechaMovimientoHasta) {
            $fechaMovimientoDesde = '';
            $fechaMovimientoHasta = '';
        }
        $almacenes = $this->baseDatos->query(
            'SELECT codigo AS code, nombre AS name FROM almacenes WHERE activo = 1 ORDER BY codigo'
        )->fetchAll();
        $importacion = $this->baseDatos->query(
            "SELECT valor FROM configuracion_app WHERE clave = 'inventario_importacion_actual'"
        )->fetchColumn();
        $datosImportacion = is_string($importacion) ? json_decode($importacion, true) : null;
        $almacenesExcluidos = is_array($datosImportacion)
            && is_array($datosImportacion['almacenes_excluidos'] ?? null)
            ? array_filter($datosImportacion['almacenes_excluidos'], static fn ($unidades): bool => (int) $unidades > 0)
            : [];
        $resumenAlmacenesExcluidos = [];
        foreach ($almacenesExcluidos as $codigo => $unidades) {
            $resumenAlmacenesExcluidos[] = $codigo . ': ' . (int) $unidades;
        }
        $categorias = $this->baseDatos->query(
            'SELECT DISTINCT categoria FROM inventario_b4x01 ORDER BY categoria'
        )->fetchAll(PDO::FETCH_COLUMN);
        $marcas = $this->baseDatos->query(
            'SELECT DISTINCT marca FROM inventario_b4x01 ORDER BY marca'
        )->fetchAll(PDO::FETCH_COLUMN);
        $almacenesConocidos = array_column($almacenes, 'code');
        if ($filtroAlmacen !== '' && !in_array($filtroAlmacen, $almacenesConocidos, true)) {
            $filtroAlmacen = '';
        }

        $condiciones = [];
        $parametros = [];
        $requiereAlmacenEscaneo = $busquedaCodigo !== ''
            && !in_array($filtroAlmacen, $almacenesConocidos, true);
        if ($busquedaCodigo !== '') {
            if ($requiereAlmacenEscaneo) {
                $condiciones[] = '1 = 0';
            } else {
                $condiciones[] = 'i.codigo_barras = :codigo_barras';
                $parametros['codigo_barras'] = $busquedaCodigo;
            }
        }
        if (!$mostrarMovimientos && (int) $this->baseDatos->query(
            'SELECT EXISTS (SELECT 1 FROM inventario_b4x01)'
        )->fetchColumn() === 1) {
            $condiciones[] = 'i.codigo_barras IS NOT NULL';
        }
        if ($filtroAlmacen !== '') {
            $condiciones[] = 'i.almacen_codigo = :almacen';
            $parametros['almacen'] = $filtroAlmacen;
        }
        if ($busqueda !== '') {
            $terminosBusqueda = preg_split('/\s+/u', $busqueda, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($terminosBusqueda as $indice => $termino) {
                $camposBusqueda = ['nombre', 'marca', 'medida', 'codigo_barras', 'categoria'];
                $condicionesTermino = [];
                foreach ($camposBusqueda as $campo) {
                    $parametro = 'busqueda_' . $indice . '_' . $campo;
                    $condicionesTermino[] = 'i.' . $campo . ' LIKE :' . $parametro;
                    $parametros[$parametro] = '%' . $termino . '%';
                }
                $condiciones[] = '(' . implode(' OR ', $condicionesTermino) . ')';
            }
        }
        if (!in_array($filtroCategoria, $categorias, true)) {
            $filtroCategoria = '';
        } else {
            $condiciones[] = 'i.categoria = :categoria';
            $parametros['categoria'] = $filtroCategoria;
        }
        if (!in_array($filtroMarca, $marcas, true)) {
            $filtroMarca = '';
        } else {
            $condiciones[] = 'i.marca = :marca';
            $parametros['marca'] = $filtroMarca;
        }
        if (in_array($filtroEstado, ['healthy', 'warning', 'critical'], true)) {
            $condiciones[] = match ($filtroEstado) {
                'critical' => 'i.cantidad <= i.stock_seguridad',
                'warning' => 'i.cantidad > i.stock_seguridad AND i.cantidad <= i.stock_minimo',
                default => 'i.cantidad > i.stock_minimo',
            };
        } else {
            $filtroEstado = '';
        }

        $sqlCondiciones = $condiciones === [] ? '' : 'WHERE ' . implode(' AND ', $condiciones);
        $consultaResumen = $this->baseDatos->prepare(
            "SELECT COUNT(*) AS total_rows, COALESCE(SUM(i.cantidad), 0) AS total_units,
                    COALESCE(SUM(i.cantidad <= i.stock_seguridad), 0) AS critical_count,
                    COALESCE(SUM(i.cantidad > i.stock_seguridad AND i.cantidad <= i.stock_minimo), 0) AS warning_count
             FROM inventario_b4x01 i
             JOIN almacenes w ON w.codigo = i.almacen_codigo
             $sqlCondiciones"
        );
        $consultaResumen->execute($parametros);
        $resumen = $consultaResumen->fetch();
        $totalFilas = (int) $resumen['total_rows'];
        $conteoCritico = (int) $resumen['critical_count'];
        $conteoAdvertencia = (int) $resumen['warning_count'];
        $totalUnidades = (int) $resumen['total_units'];
        $filasPorPagina = 20;
        $totalPaginas = max(1, (int) ceil($totalFilas / $filasPorPagina));
        $pagina = filter_var($filtros['page'] ?? 1, FILTER_VALIDATE_INT);
        $pagina = $pagina === false || $pagina < 1 ? 1 : min($pagina, $totalPaginas);
        $desplazamiento = ($pagina - 1) * $filasPorPagina;
        $filas = [];
        if (!$mostrarMovimientos) {
            $consultaFilas = $this->baseDatos->prepare(
                "SELECT i.id AS product_id, i.codigo_barras AS barcode, i.nombre AS name, i.categoria AS category, i.marca AS brand, i.medida AS size,
                        w.codigo AS warehouse_code, w.nombre AS warehouse_name,
                        i.cantidad AS quantity, i.stock_minimo AS minimum_stock, i.stock_seguridad AS safety_stock, i.stock_en_transito AS stock_in_transit,
                        CASE WHEN i.cantidad <= i.stock_seguridad THEN 'critical'
                             WHEN i.cantidad <= i.stock_minimo THEN 'warning'
                             ELSE 'healthy' END AS stock_status
                 FROM inventario_b4x01 i
                 JOIN almacenes w ON w.codigo = i.almacen_codigo
                 $sqlCondiciones
                 ORDER BY CASE WHEN i.cantidad > 0 THEN 0 ELSE 1 END,
                          CASE WHEN i.cantidad <= i.stock_seguridad THEN 0
                               WHEN i.cantidad <= i.stock_minimo THEN 1 ELSE 2 END,
                          i.nombre, w.codigo LIMIT :limite OFFSET :desplazamiento"
            );
            foreach ($parametros as $nombre => $valor) {
                $consultaFilas->bindValue(':' . $nombre, $valor, PDO::PARAM_STR);
            }
            $consultaFilas->bindValue(':limite', $filasPorPagina, PDO::PARAM_INT);
            $consultaFilas->bindValue(':desplazamiento', $desplazamiento, PDO::PARAM_INT);
            $consultaFilas->execute();
            $filas = $consultaFilas->fetchAll();
        }

        $movimientos = [];
        $cantidadMovimientos = 0;
        if ($mostrarMovimientos) {
            $condicionesMovimientos = [];
            $parametrosMovimientos = [];
            if ($filtroAlmacen !== '') {
                $condicionesMovimientos[] = 'm.almacen_codigo = :movement_warehouse';
                $parametrosMovimientos['movement_warehouse'] = $filtroAlmacen;
            }
            if ($filtroTipoMovimiento !== '') {
                $condicionesMovimientos[] = 'm.tipo_movimiento = :movement_type';
                $parametrosMovimientos['movement_type'] = $filtroTipoMovimiento;
            }
            if ($fechaMovimientoDesde !== '') {
                $condicionesMovimientos[] = 'm.creado_en >= :movement_from';
                $parametrosMovimientos['movement_from'] = $fechaMovimientoDesde . ' 00:00:00';
            }
            if ($fechaMovimientoHasta !== '') {
                $condicionesMovimientos[] = 'm.creado_en < :movement_to';
                $parametrosMovimientos['movement_to'] = (new DateTimeImmutable($fechaMovimientoHasta))
                    ->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
            }
            if ($busqueda !== '') {
                $condicionesMovimientos[] = "CONCAT_WS(' ', p.codigo_sku, p.nombre, p.marca, p.medida, m.notas, u.nombre_completo) LIKE :movement_search";
                $parametrosMovimientos['movement_search'] = '%' . $busqueda . '%';
            }
            $whereMovimientos = $condicionesMovimientos === []
                ? ''
                : ' WHERE ' . implode(' AND ', $condicionesMovimientos);
            $conteoMovimientos = $this->baseDatos->prepare(
                'SELECT COUNT(*) FROM movimientos_inventario m
                 JOIN productos p ON p.id = m.producto_id
                 LEFT JOIN usuarios u ON u.id = m.creado_por'
                . $whereMovimientos
            );
            $conteoMovimientos->execute($parametrosMovimientos);
            $cantidadMovimientos = (int) $conteoMovimientos->fetchColumn();
            $consultaMovimientos = $this->baseDatos->prepare(
                'SELECT m.creado_en AS created_at, p.codigo_sku AS product_sku,
                        p.nombre AS product_name, p.marca AS product_brand,
                        p.medida AS product_measure,
                        w.codigo AS warehouse_code, w.nombre AS warehouse_name,
                            m.tipo_movimiento AS movement_type, m.tipo_referencia AS reference_type,
                            m.cambio_cantidad AS quantity_change,
                            m.notas AS notes, COALESCE(u.nombre_completo, "Usuario desactivado") AS user_name
                 FROM movimientos_inventario m
                 JOIN productos p ON p.id = m.producto_id
                 JOIN almacenes w ON w.codigo = m.almacen_codigo
                 LEFT JOIN usuarios u ON u.id = m.creado_por'
                . $whereMovimientos
                . ' ORDER BY m.creado_en DESC, m.id DESC LIMIT 200'
            );
            $consultaMovimientos->execute($parametrosMovimientos);
            $movimientos = $consultaMovimientos->fetchAll();
        }

        return [
            'warehouseFilter' => $filtroAlmacen,
            'search' => $busqueda,
            'barcodeSearch' => $busquedaCodigo,
            'categoryFilter' => $filtroCategoria,
            'brandFilter' => $filtroMarca,
            'statusFilter' => $filtroEstado,
            'showMovements' => $mostrarMovimientos,
            'movementTypeFilter' => $filtroTipoMovimiento,
            'movementFromFilter' => $fechaMovimientoDesde,
            'movementToFilter' => $fechaMovimientoHasta,
            'warehouses' => $almacenes,
            'inventoryImportDetails' => $datosImportacion,
            'excludedWarehouseSummary' => $resumenAlmacenesExcluidos,
            'categories' => $categorias,
            'brands' => $marcas,
            'barcodeWarehouseRequired' => $requiereAlmacenEscaneo,
            'totalRows' => $totalFilas,
            'criticalCount' => $conteoCritico,
            'warningCount' => $conteoAdvertencia,
            'totalUnits' => $totalUnidades,
            'pageSize' => $filasPorPagina,
            'totalPages' => $totalPaginas,
            'page' => $pagina,
            'pageOffset' => $desplazamiento,
            'rows' => $filas,
            'movements' => $movimientos,
            'movementCount' => $cantidadMovimientos,
        ];
    }

    public function obtenerComparacionAlmacenesOperativos(array $filtros): array
    {
        $almacenes = $this->baseDatos->query(
            'SELECT codigo AS code, nombre AS name FROM almacenes WHERE activo = 1 ORDER BY codigo'
        )->fetchAll();
        $busqueda = trim((string) ($filtros['q'] ?? ''));
        $parametros = [];
        $columnasStock = [];
        foreach ($almacenes as $indice => $almacen) {
            $parametro = 'warehouse_' . $indice;
            $columnasStock[] = 'MAX(CASE WHEN w.codigo = :' . $parametro
                . ' THEN COALESCE(i.cantidad, 0) ELSE 0 END) AS stock_' . $indice;
            $parametros[$parametro] = (string) $almacen['code'];
        }
        $proyeccionStock = $columnasStock === [] ? '0 AS stock_0' : implode(', ', $columnasStock);
        $proyeccionProductos = $almacenes === []
            ? '0 AS warehouse_count, 0 AS stock_spread'
            : '(' . implode(' + ', array_map(
                static fn (int $indice): string => '(stock_' . $indice . ' > 0)',
                array_keys($almacenes)
            )) . ') AS warehouse_count, '
                . '(GREATEST(' . implode(', ', array_map(
                    static fn (int $indice): string => 'stock_' . $indice,
                    array_keys($almacenes)
                )) . ') - LEAST(' . implode(', ', array_map(
                    static fn (int $indice): string => 'stock_' . $indice,
                    array_keys($almacenes)
                )) . ')) AS stock_spread';
        $agrupado = "SELECT p.id AS product_id, p.codigo_sku AS sku, p.nombre AS name,
                            p.marca AS brand, p.medida AS size, $proyeccionStock,
                            SUM(COALESCE(i.cantidad, 0)) AS total_units
                     FROM productos p
                     CROSS JOIN almacenes w
                     LEFT JOIN inventario i ON i.producto_id = p.id AND i.almacen_codigo = w.codigo
                     WHERE w.activo = 1
                       AND (:search_filter = '' OR p.codigo_sku LIKE :search_filter_sku
                            OR p.nombre LIKE :search_filter_name OR p.marca LIKE :search_filter_brand
                            OR p.medida LIKE :search_filter_size)
                     GROUP BY p.id, p.codigo_sku, p.nombre, p.marca, p.medida";
        $parametrosAgrupado = $parametros;
        $parametrosAgrupado['search_filter'] = $busqueda;
        foreach (['search_filter_sku', 'search_filter_name', 'search_filter_brand', 'search_filter_size'] as $parametro) {
            $parametrosAgrupado[$parametro] = '%' . $busqueda . '%';
        }

        $consultaResumen = $this->baseDatos->prepare(
            "SELECT COUNT(*) AS product_count, COALESCE(SUM(total_units), 0) AS total_units,
                    COALESCE(SUM(warehouse_count > 0), 0) AS stocked_product_count,
                    COALESCE(SUM(stock_spread > 0), 0) AS differing_product_count
             FROM (SELECT compared.*, $proyeccionProductos
                   FROM ($agrupado) compared) summary"
        );
        $consultaResumen->execute($parametrosAgrupado);
        $resumen = $consultaResumen->fetch();
        $totalFilas = (int) $resumen['product_count'];
        $tamanoPagina = 20;
        $totalPaginas = max(1, (int) ceil($totalFilas / $tamanoPagina));
        $pagina = filter_var($filtros['page'] ?? 1, FILTER_VALIDATE_INT);
        $pagina = $pagina === false || $pagina < 1 ? 1 : min($pagina, $totalPaginas);
        $desplazamiento = ($pagina - 1) * $tamanoPagina;

        $consultaFilas = $this->baseDatos->prepare(
            "SELECT compared.*, $proyeccionProductos
             FROM ($agrupado) compared
             ORDER BY product_id DESC, name, sku
             LIMIT :limit OFFSET :offset"
        );
        foreach ($parametrosAgrupado as $nombre => $valor) {
            $consultaFilas->bindValue(':' . $nombre, $valor, PDO::PARAM_STR);
        }
        $consultaFilas->bindValue(':limit', $tamanoPagina, PDO::PARAM_INT);
        $consultaFilas->bindValue(':offset', $desplazamiento, PDO::PARAM_INT);
        $consultaFilas->execute();

        return [
            'warehouses' => $almacenes,
            'search' => $busqueda,
            'rows' => $consultaFilas->fetchAll(),
            'total_rows' => $totalFilas,
            'total_units' => (int) $resumen['total_units'],
            'stocked_product_count' => (int) $resumen['stocked_product_count'],
            'differing_product_count' => (int) $resumen['differing_product_count'],
            'page' => $pagina,
            'page_size' => $tamanoPagina,
            'total_pages' => $totalPaginas,
            'page_offset' => $desplazamiento,
        ];
    }

    public function obtenerAlertasRotacionLenta(array $filtros): array
    {
        $almacenes = $this->baseDatos->query(
            'SELECT codigo AS code, nombre AS name FROM almacenes WHERE activo = 1 ORDER BY codigo'
        )->fetchAll();
        $codigosAlmacenes = array_column($almacenes, 'code');
        $almacen = strtoupper(trim((string) ($filtros['slow_warehouse'] ?? '')));
        if ($almacen !== '' && !in_array($almacen, $codigosAlmacenes, true)) {
            $almacen = '';
        }
        $busqueda = trim((string) ($filtros['q'] ?? ''));
        $condiciones = ['i.cantidad > 0', 'w.activo = 1'];
        $parametros = [
            'search_filter' => $busqueda,
            'search_sku' => '%' . $busqueda . '%',
            'search_name' => '%' . $busqueda . '%',
            'search_brand' => '%' . $busqueda . '%',
            'search_size' => '%' . $busqueda . '%',
        ];
        if ($almacen !== '') {
            $condiciones[] = 'w.codigo = :slow_warehouse';
            $parametros['slow_warehouse'] = $almacen;
        }
        $condiciones[] = "(:search_filter = '' OR p.codigo_sku LIKE :search_sku
            OR p.nombre LIKE :search_name OR p.marca LIKE :search_brand OR p.medida LIKE :search_size)";

        $consulta = $this->baseDatos->prepare(
            'SELECT p.id AS product_id, p.codigo_sku AS sku, p.nombre AS name,
                    p.marca AS brand, p.medida AS size, p.creado_en AS created_at,
                    w.codigo AS warehouse_code, w.nombre AS warehouse_name,
                    i.cantidad AS stock,
                    imported.last_sale_date AS imported_sale_date,
                    manual.last_sale_date AS manual_sale_date
             FROM productos p
             JOIN inventario i ON i.producto_id = p.id
             JOIN almacenes w ON w.codigo = i.almacen_codigo
             LEFT JOIN (
                 SELECT h.codigo, h.almacen_codigo, MAX(h.fecha_venta) AS last_sale_date
                 FROM historico_ventas_excel h
                 JOIN almacenes historical_warehouse
                   ON historical_warehouse.codigo = h.almacen_codigo AND historical_warehouse.activo = 1
                 WHERE h.tipo_origen <> "manual"
                 GROUP BY h.codigo, h.almacen_codigo
             ) imported ON imported.codigo = p.codigo_sku AND imported.almacen_codigo = w.codigo
             LEFT JOIN (
                 SELECT d.producto_id, s.almacen_codigo, MAX(s.fecha_venta) AS last_sale_date
                 FROM ventas s
                 JOIN detalle_ventas d ON d.venta_id = s.id AND d.estado = "activa"
                 JOIN almacenes sale_warehouse
                   ON sale_warehouse.codigo = s.almacen_codigo AND sale_warehouse.activo = 1
                 GROUP BY d.producto_id, s.almacen_codigo
             ) manual ON manual.producto_id = p.id AND manual.almacen_codigo = w.codigo
             WHERE ' . implode(' AND ', $condiciones)
        );
        $consulta->execute($parametros);

        $hoy = new DateTimeImmutable('today');
        $alertas = [];
        foreach ($consulta->fetchAll() as $fila) {
            $fechasVenta = array_filter([
                (string) ($fila['imported_sale_date'] ?? ''),
                (string) ($fila['manual_sale_date'] ?? ''),
            ]);
            $ultimaVenta = $fechasVenta === [] ? '' : max($fechasVenta);
            $fechaReferencia = $ultimaVenta !== ''
                ? new DateTimeImmutable($ultimaVenta)
                : new DateTimeImmutable((string) $fila['created_at']);
            $diasSinVenta = $fechaReferencia > $hoy
                ? 0
                : (int) $fechaReferencia->diff($hoy)->days;
            if ($diasSinVenta < self::DIAS_SIN_VENTA_PRIORIDAD) {
                continue;
            }

            $fila['stock'] = (int) $fila['stock'];
            $fila['last_sale_date'] = $ultimaVenta;
            $fila['never_sold'] = $ultimaVenta === '';
            $fila['days_without_sale'] = $diasSinVenta;
            unset($fila['imported_sale_date'], $fila['manual_sale_date'], $fila['created_at']);
            $alertas[] = $fila;
        }

        usort($alertas, static function (array $a, array $b): int {
            return ($b['days_without_sale'] <=> $a['days_without_sale'])
                ?: ($b['stock'] <=> $a['stock'])
                ?: strcmp((string) $a['name'], (string) $b['name']);
        });

        return [
            'warehouses' => $almacenes,
            'warehouse' => $almacen,
            'search' => $busqueda,
            'threshold_days' => self::DIAS_SIN_VENTA_PRIORIDAD,
            'total_rows' => count($alertas),
            'rows' => array_slice($alertas, 0, 12),
        ];
    }

    public function obtenerAlertasStockOperativo(array $filtros): array
    {
        $almacen = trim((string) ($filtros['warehouse'] ?? ''));
        $busqueda = trim((string) ($filtros['q'] ?? ''));
        $estado = trim((string) ($filtros['status'] ?? ''));
        if (!in_array($estado, ['critical', 'warning'], true)) {
            $estado = '';
        }
        $almacenes = $this->baseDatos->query(
            'SELECT codigo AS code, nombre AS name FROM almacenes WHERE activo = 1 ORDER BY codigo'
        )->fetchAll();
        $codigosAlmacenes = array_column($almacenes, 'code');
        if ($almacen !== '' && !in_array($almacen, $codigosAlmacenes, true)) {
            $almacen = '';
        }

        $condiciones = ['i.cantidad <= i.stock_minimo', 'w.activo = 1'];
        $parametros = [];
        if ($almacen !== '') {
            $condiciones[] = 'i.almacen_codigo = :warehouse';
            $parametros['warehouse'] = $almacen;
        }
        if ($busqueda !== '') {
            $filtroBusqueda = $this->filtroTextoConteoFisico($busqueda, null, true, true);
            if ($filtroBusqueda['sql'] !== '') {
                $condiciones[] = $filtroBusqueda['sql'];
                $parametros += $filtroBusqueda['parameters'];
            }
        }
        if ($estado === 'critical') {
            $condiciones[] = 'i.cantidad <= i.stock_seguridad';
        } elseif ($estado === 'warning') {
            $condiciones[] = 'i.cantidad > i.stock_seguridad';
        }
        $where = 'WHERE ' . implode(' AND ', $condiciones);
        $consultaResumen = $this->baseDatos->prepare(
            "SELECT COUNT(*) AS alert_count,
                    COALESCE(SUM(i.cantidad <= i.stock_seguridad), 0) AS critical_count,
                    COALESCE(SUM(i.stock_en_transito > 0), 0) AS in_transit_count
             FROM inventario i
             JOIN productos p ON p.id = i.producto_id
             JOIN almacenes w ON w.codigo = i.almacen_codigo
             $where"
        );
        $consultaResumen->execute($parametros);
        $resumen = $consultaResumen->fetch();
        $totalFilas = (int) $resumen['alert_count'];
        $tamanoPagina = 20;
        $totalPaginas = max(1, (int) ceil($totalFilas / $tamanoPagina));
        $pagina = filter_var($filtros['page'] ?? 1, FILTER_VALIDATE_INT);
        $pagina = $pagina === false || $pagina < 1 ? 1 : min($pagina, $totalPaginas);
        $desplazamiento = ($pagina - 1) * $tamanoPagina;

        $consultaAlertas = $this->baseDatos->prepare(
            "SELECT p.id AS product_id, p.codigo_sku AS sku, p.nombre AS name,
                    p.marca AS brand, p.medida AS size, w.codigo AS warehouse_code,
                    w.nombre AS warehouse_name, i.cantidad AS quantity,
                    i.stock_minimo AS minimum_stock, i.stock_seguridad AS safety_stock,
                    i.stock_en_transito AS stock_in_transit,
                    GREATEST(i.stock_minimo - i.cantidad + 1, 0) AS shortage_quantity,
                    CASE WHEN i.cantidad <= i.stock_seguridad THEN 'critical' ELSE 'warning' END AS stock_status
             FROM inventario i
             JOIN productos p ON p.id = i.producto_id
             JOIN almacenes w ON w.codigo = i.almacen_codigo
             $where
             ORDER BY (i.cantidad <= i.stock_seguridad) DESC,
                      (i.stock_en_transito > 0) DESC, i.cantidad ASC, p.nombre, w.codigo
             LIMIT :limit OFFSET :offset"
        );
        foreach ($parametros as $nombre => $valor) {
            $consultaAlertas->bindValue(':' . $nombre, $valor, PDO::PARAM_STR);
        }
        $consultaAlertas->bindValue(':limit', $tamanoPagina, PDO::PARAM_INT);
        $consultaAlertas->bindValue(':offset', $desplazamiento, PDO::PARAM_INT);
        $consultaAlertas->execute();

        return [
            'warehouses' => $almacenes,
            'warehouse' => $almacen,
            'search' => $busqueda,
            'status' => $estado,
            'rows' => $consultaAlertas->fetchAll(),
            'total_rows' => $totalFilas,
            'critical_count' => (int) $resumen['critical_count'],
            'in_transit_count' => (int) $resumen['in_transit_count'],
            'page' => $pagina,
            'page_size' => $tamanoPagina,
            'total_pages' => $totalPaginas,
            'page_offset' => $desplazamiento,
        ];
    }

    public function obtenerReporteOperativo(array $filtros, bool $incluirTodosLosMovimientos = false): array
    {
        $almacenes = $this->baseDatos->query(
            'SELECT codigo AS code, nombre AS name FROM almacenes WHERE activo = 1 ORDER BY codigo'
        )->fetchAll();
        $almacen = strtoupper(trim((string) ($filtros['warehouse'] ?? '')));
        if ($almacen !== '' && !in_array($almacen, array_column($almacenes, 'code'), true)) {
            $almacen = '';
        }

        $validarFecha = static fn (string $fecha): bool => preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $fecha) === 1
            && checkdate((int) substr($fecha, 5, 2), (int) substr($fecha, 8, 2), (int) substr($fecha, 0, 4));
        $desde = trim((string) ($filtros['from'] ?? ''));
        $hasta = trim((string) ($filtros['to'] ?? ''));
        if (!$validarFecha($desde)) {
            $desde = '';
        }
        if (!$validarFecha($hasta)) {
            $hasta = '';
        }
        if ($desde !== '' && $hasta !== '' && $desde > $hasta) {
            $desde = '';
            $hasta = '';
        }

        $parametrosStock = [];
        $condicionesStock = ['w.activo = 1'];
        if ($almacen !== '') {
            $condicionesStock[] = 'i.almacen_codigo = :warehouse';
            $parametrosStock['warehouse'] = $almacen;
        }
        $consultaStock = $this->baseDatos->prepare(
            "SELECT p.id AS product_id, p.codigo_sku AS sku, p.nombre AS name, p.marca AS brand,
                    p.medida AS size, w.codigo AS warehouse_code, w.nombre AS warehouse_name,
                    i.cantidad AS quantity, i.stock_minimo AS minimum_stock,
                    i.stock_seguridad AS safety_stock, i.stock_en_transito AS stock_in_transit,
                    CASE WHEN i.stock_minimo > i.cantidad
                         THEN i.stock_minimo - i.cantidad ELSE 0 END AS shortage_quantity,
                    CASE WHEN i.cantidad <= i.stock_seguridad THEN 'critical'
                         WHEN i.cantidad <= i.stock_minimo THEN 'warning'
                         ELSE 'healthy' END AS stock_status
             FROM inventario i
             JOIN productos p ON p.id = i.producto_id
             JOIN almacenes w ON w.codigo = i.almacen_codigo
             WHERE " . implode(' AND ', $condicionesStock) . '
             ORDER BY w.codigo, (i.cantidad <= i.stock_minimo) DESC, p.nombre, p.codigo_sku'
        );
        $consultaStock->execute($parametrosStock);
        $stockRows = $consultaStock->fetchAll();
        $alertRows = array_values(array_filter(
            $stockRows,
            static fn (array $row): bool => (int) $row['quantity'] <= (int) $row['minimum_stock']
        ));
        usort($alertRows, static function (array $left, array $right): int {
            $criticalOrder = ((int) $right['quantity'] <= (int) $right['safety_stock'])
                <=> ((int) $left['quantity'] <= (int) $left['safety_stock']);

            return $criticalOrder
                ?: ((int) $left['quantity'] <=> (int) $right['quantity'])
                ?: strcmp((string) $left['name'], (string) $right['name']);
        });

        $parametrosMovimientos = [];
        $condicionesMovimientos = [];
        if ($almacen !== '') {
            $condicionesMovimientos[] = 'm.almacen_codigo = :warehouse';
            $parametrosMovimientos['warehouse'] = $almacen;
        }
        if ($desde !== '') {
            $condicionesMovimientos[] = 'm.creado_en >= :date_from';
            $parametrosMovimientos['date_from'] = $desde . ' 00:00:00';
        }
        if ($hasta !== '') {
            $condicionesMovimientos[] = 'm.creado_en < :date_to';
            $parametrosMovimientos['date_to'] = (new DateTimeImmutable($hasta))
                ->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
        }
        $whereMovimientos = $condicionesMovimientos === []
            ? ''
            : ' WHERE ' . implode(' AND ', $condicionesMovimientos);
        $conteoMovimientos = $this->baseDatos->prepare(
            'SELECT COUNT(*) FROM movimientos_inventario m' . $whereMovimientos
        );
        $conteoMovimientos->execute($parametrosMovimientos);
        $cantidadMovimientos = (int) $conteoMovimientos->fetchColumn();
        $consultaMovimientos = $this->baseDatos->prepare(
            'SELECT m.creado_en AS created_at, p.codigo_sku AS product_sku,
                    p.nombre AS product_name, p.marca AS product_brand, p.medida AS product_measure,
                    w.codigo AS warehouse_code, w.nombre AS warehouse_name,
                    m.tipo_movimiento AS movement_type, m.tipo_referencia AS reference_type,
                    m.cambio_cantidad AS quantity_change, m.notas AS notes,
                    COALESCE(u.nombre_completo, "Usuario desactivado") AS user_name
             FROM movimientos_inventario m
             JOIN productos p ON p.id = m.producto_id
             JOIN almacenes w ON w.codigo = m.almacen_codigo
             LEFT JOIN usuarios u ON u.id = m.creado_por'
            . $whereMovimientos
            . ' ORDER BY m.creado_en DESC, m.id DESC'
            . ($incluirTodosLosMovimientos ? '' : ' LIMIT 20')
        );
        $consultaMovimientos->execute($parametrosMovimientos);
        $unidadesTotales = 0;
        $criticos = 0;
        $porReponer = 0;
        foreach ($stockRows as $row) {
            $unidadesTotales += (int) $row['quantity'];
            if ((int) $row['quantity'] <= (int) $row['safety_stock']) {
                $criticos++;
            } elseif ((int) $row['quantity'] <= (int) $row['minimum_stock']) {
                $porReponer++;
            }
        }

        return [
            'warehouses' => $almacenes,
            'warehouse' => $almacen,
            'from' => $desde,
            'to' => $hasta,
            'stock_rows' => $stockRows,
            'alert_rows' => array_slice($alertRows, 0, 20),
            'total_products' => count($stockRows),
            'total_units' => $unidadesTotales,
            'critical_count' => $criticos,
            'replenishment_count' => $porReponer,
            'movements' => $consultaMovimientos->fetchAll(),
            'movement_count' => $cantidadMovimientos,
        ];
    }

    private function leerLibro(PharData $archivo): array
    {
        return $this->convertirFilas($this->leerMatrizLibro($archivo));
    }

    private function leerMatrizLibro(PharData $archivo): array
    {
        $libro = $this->leerXml($archivo, 'xl/workbook.xml');
        $espacios = $libro->getNamespaces(true);
        $espacioPrincipal = (string) ($espacios[''] ?? '');
        $espacioRelaciones = (string) ($espacios['r'] ?? '');
        if ($espacioPrincipal === '' || $espacioRelaciones === '') {
            throw new RuntimeException('El Excel no contiene una hoja válida.');
        }
        $libro->registerXPathNamespace('x', $espacioPrincipal);
        $hojas = $libro->xpath('/x:workbook/x:sheets/x:sheet') ?: [];
        $hojasVisibles = [];
        foreach ($hojas as $hoja) {
            if ((string) ($hoja->attributes()['state'] ?? 'visible') !== 'hidden') {
                $hojasVisibles[] = $hoja;
            }
        }
        if (count($hojasVisibles) !== 1) {
            throw new RuntimeException('El Excel debe tener una sola hoja visible.');
        }

        $idRelacion = (string) $hojasVisibles[0]->attributes($espacioRelaciones)['id'];
        $relaciones = $this->leerXml($archivo, 'xl/_rels/workbook.xml.rels');
        $espacioRelacionesPrincipal = (string) ($relaciones->getNamespaces(true)[''] ?? '');
        $relaciones->registerXPathNamespace('x', $espacioRelacionesPrincipal);
        $rutaObjetivo = '';
        foreach ($relaciones->xpath('/x:Relationships/x:Relationship') ?: [] as $relacion) {
            $atributos = $relacion->attributes();
            if ((string) ($atributos['Id'] ?? '') === $idRelacion) {
                $rutaObjetivo = (string) ($atributos['Target'] ?? '');
                break;
            }
        }
        $hoja = $this->leerXml($archivo, $this->resolverRutaHoja($rutaObjetivo));
        $hoja->registerXPathNamespace('x', $espacioPrincipal);

        $textos = [];
        if ($archivo->offsetExists('xl/sharedStrings.xml')) {
            $cadenas = $this->leerXml($archivo, 'xl/sharedStrings.xml');
            $cadenas->registerXPathNamespace('x', $espacioPrincipal);
            foreach ($cadenas->xpath('/x:sst/x:si') ?: [] as $cadena) {
                $textos[] = $this->obtenerTexto($cadena, $espacioPrincipal);
            }
        }

        $nodosFila = $hoja->xpath('/x:worksheet/x:sheetData/x:row') ?: [];
        if (count($nodosFila) < 2 || count($nodosFila) > self::FILAS_MAXIMAS + 1) {
            throw new RuntimeException('La hoja está vacía o supera el límite de 50.000 filas.');
        }
        $matriz = [];
        foreach ($nodosFila as $nodoFila) {
            $fila = [];
            foreach ($nodoFila->children($espacioPrincipal) as $celda) {
                if ($celda->getName() !== 'c') {
                    continue;
                }
                $atributos = $celda->attributes();
                $indiceColumna = $this->obtenerIndiceColumna((string) ($atributos['r'] ?? ''));
                $tipo = (string) ($atributos['t'] ?? '');
                $contenidoCelda = $celda->children($espacioPrincipal);
                if ($tipo === 'inlineStr') {
                    $valor = isset($contenidoCelda->is)
                        ? $this->obtenerTexto($contenidoCelda->is, $espacioPrincipal)
                        : '';
                } else {
                    $valor = isset($contenidoCelda->v) ? (string) $contenidoCelda->v : '';
                    if ($tipo === 's') {
                        $indiceTexto = filter_var($valor, FILTER_VALIDATE_INT);
                        if ($indiceTexto === false || !array_key_exists($indiceTexto, $textos)) {
                            throw new RuntimeException('El Excel contiene una referencia de texto inválida.');
                        }
                        $valor = $textos[$indiceTexto];
                    }
                }
                $fila[$indiceColumna] = $valor;
            }
            $matriz[] = $fila;
        }

        return $matriz;
    }

    private function leerXml(PharData $archivo, string $ruta): SimpleXMLElement
    {
        if (!$archivo->offsetExists($ruta)) {
            throw new RuntimeException('El Excel está incompleto; falta información de una hoja.');
        }
        $entrada = $archivo[$ruta];
        if (!$entrada instanceof PharFileInfo || $entrada->getSize() > self::TAMANO_MAXIMO_XML) {
            throw new RuntimeException('El Excel contiene una sección demasiado grande para procesar.');
        }
        $xml = @simplexml_load_string(
            $entrada->getContent(),
            SimpleXMLElement::class,
            LIBXML_NONET | LIBXML_COMPACT
        );
        if (!$xml instanceof SimpleXMLElement) {
            throw new RuntimeException('El Excel contiene datos XML inválidos.');
        }

        return $xml;
    }

    private function obtenerTexto(SimpleXMLElement $elemento, string $espacio): string
    {
        if ($elemento->getName() === 't') {
            return (string) $elemento;
        }
        $texto = '';
        foreach ($elemento->children($espacio) as $hijo) {
            $texto .= $this->obtenerTexto($hijo, $espacio);
        }

        return $texto;
    }

    private function resolverRutaHoja(string $objetivo): string
    {
        if ($objetivo === '') {
            throw new RuntimeException('No se encontró la hoja visible del Excel.');
        }
        $segmentos = [];
        $ruta = str_starts_with($objetivo, '/') ? ltrim($objetivo, '/') : 'xl/' . $objetivo;
        foreach (explode('/', $ruta) as $segmento) {
            if ($segmento === '' || $segmento === '.') {
                continue;
            }
            if ($segmento === '..') {
                array_pop($segmentos);
            } else {
                $segmentos[] = $segmento;
            }
        }
        $rutaHoja = implode('/', $segmentos);
        if (!str_starts_with($rutaHoja, 'xl/worksheets/') || !str_ends_with($rutaHoja, '.xml')) {
            throw new RuntimeException('La hoja del Excel apunta a una ruta no permitida.');
        }

        return $rutaHoja;
    }

    private function convertirFilas(array $matriz): array
    {
        $columnas = [];
        foreach ($matriz[0] as $indice => $valor) {
            $columnas[$this->normalizar((string) $valor)] = $indice;
        }
        $requeridas = ['codigo', 'descripcion', 'categoria', 'marca', 'medida', 'umedida'];
        $faltantes = array_values(array_diff($requeridas, array_keys($columnas)));
        if ($faltantes !== []) {
            throw new RuntimeException('Faltan columnas requeridas: ' . implode(', ', $faltantes) . '.');
        }

        $columnasAlmacen = [];
        $columnasExcluidas = [];
        foreach ($columnas as $nombre => $indice) {
            if (!str_starts_with($nombre, 'almacen') || preg_match('/(\d{4})$/', $nombre, $coincidencia) !== 1) {
                continue;
            }
            $almacen = 'A' . $coincidencia[1];
            if (in_array($almacen, self::ALMACENES, true)) {
                $columnasAlmacen[$almacen] = $indice;
            } else {
                $columnasExcluidas[$almacen] = $indice;
            }
        }
        if (array_diff(self::ALMACENES, array_keys($columnasAlmacen)) !== []) {
            throw new RuntimeException('El Excel debe incluir las columnas A0101, A0102 y A0103.');
        }
        $this->totalesAlmacenesExcluidos = array_fill_keys(array_keys($columnasExcluidas), 0);

        $filas = [];
        foreach (array_slice($matriz, 1, null, true) as $posicion => $fila) {
            if (count(array_filter($fila, static fn (string $valor): bool => trim($valor) !== '')) === 0) {
                continue;
            }
            $numeroFila = $posicion + 1;
            $obtener = static fn (string $columna): string => trim((string) ($fila[$columnas[$columna]] ?? ''));
            $codigo = strtoupper($obtener('codigo'));
            $nombre = $obtener('descripcion');
            $marca = $obtener('marca');
            $medida = $obtener('medida');
            if ($codigo === '' || $nombre === '' || $marca === '' || $medida === '') {
                throw new RuntimeException('La fila ' . $numeroFila . ' tiene datos de producto vacíos.');
            }
            $existencias = [];
            foreach (self::ALMACENES as $almacen) {
                $existencias[$almacen] = $this->validarCantidad(
                    $fila[$columnasAlmacen[$almacen]] ?? '',
                    $numeroFila,
                    $almacen
                );
            }
            foreach ($columnasExcluidas as $almacen => $indice) {
                $this->totalesAlmacenesExcluidos[$almacen] += $this->validarCantidad(
                    $fila[$indice] ?? '',
                    $numeroFila,
                    $almacen
                );
            }
            $filas[] = [
                'codigo' => $codigo,
                'nombre' => $nombre,
                'categoria' => $obtener('categoria') !== '' ? $obtener('categoria') : 'Llantas',
                'marca' => $marca,
                'medida' => $medida,
                'existencias' => $existencias,
            ];
        }
        if ($filas === []) {
            throw new RuntimeException('El Excel no contiene productos.');
        }

        return $filas;
    }

    private function convertirFilasEscaneos(array $matriz): array
    {
        $columnas = [];
        foreach ($matriz[0] ?? [] as $indice => $valor) {
            $columnas[$this->normalizar((string) $valor)] = $indice;
        }
        $requeridas = ['idsescaneo', 'codigodebarras', 'almacen', 'cantidad'];
        if (array_diff($requeridas, array_keys($columnas)) !== []) {
            throw new RuntimeException(
                'Este no es el Excel de escaneos descargado desde Inventario o faltan sus columnas requeridas.'
            );
        }
        if (count($matriz) > self::FILAS_MAXIMAS + 1) {
            throw new RuntimeException('El Excel supera el límite de 50.000 escaneos.');
        }

        $filas = [];
        $idsVistos = [];
        $almacenDelArchivo = null;
        foreach (array_slice($matriz, 1, null, true) as $posicion => $fila) {
            if (count(array_filter($fila, static fn (string $valor): bool => trim($valor) !== '')) === 0) {
                continue;
            }
            $numeroFila = $posicion + 1;
            $idsTexto = trim((string) ($fila[$columnas['idsescaneo']] ?? ''));
            $ids = $idsTexto === '' ? [] : preg_split('/\s*,\s*/', $idsTexto);
            $barcode = strtoupper(trim((string) ($fila[$columnas['codigodebarras']] ?? '')));
            if (strlen($barcode) > 2 && $barcode[0] === '*' && substr($barcode, -1) === '*') {
                $barcode = substr($barcode, 1, -1);
            }
            $warehouse = strtoupper(trim((string) ($fila[$columnas['almacen']] ?? '')));
            $cantidad = filter_var(trim((string) ($fila[$columnas['cantidad']] ?? '')), FILTER_VALIDATE_INT);
            if ($ids === false || $ids === []) {
                throw new RuntimeException('La fila ' . $numeroFila . ' no contiene IDs de escaneo.');
            }
            $idsValidos = [];
            foreach ($ids as $idTexto) {
                $id = filter_var($idTexto, FILTER_VALIDATE_INT);
                if ($id === false || $id < 1) {
                    throw new RuntimeException('Un ID de escaneo de la fila ' . $numeroFila . ' no es válido.');
                }
                if (isset($idsVistos[$id])) {
                    throw new RuntimeException('El ID de escaneo ' . $id . ' está repetido en el Excel.');
                }
                $idsVistos[$id] = true;
                $idsValidos[] = $id;
            }
            if ($barcode === '' || strlen($barcode) > 80
                || preg_match('/\A[\x20-\x7E]+\z/D', $barcode) !== 1
            ) {
                throw new RuntimeException('El código de barras de la fila ' . $numeroFila . ' no es válido.');
            }
            if (!in_array($warehouse, self::ALMACENES, true)) {
                throw new RuntimeException('El almacén de la fila ' . $numeroFila . ' no es válido.');
            }
            if ($almacenDelArchivo !== null && $almacenDelArchivo !== $warehouse) {
                throw new RuntimeException('El Excel contiene más de un almacén. Descarga y aplica un archivo separado para cada almacén.');
            }
            $almacenDelArchivo = $warehouse;
            if ($cantidad === false || $cantidad < 1 || $cantidad > count($idsValidos)) {
                throw new RuntimeException(
                    'La cantidad de la fila ' . $numeroFila . ' debe estar entre 1 y el total de IDs de escaneo.'
                );
            }
            foreach (array_slice($idsValidos, 0, $cantidad) as $id) {
                $filas[] = [
                    'scan_id' => $id,
                    'barcode' => $barcode,
                    'warehouse' => $warehouse,
                    'row' => $numeroFila,
                ];
            }
        }
        if ($filas === []) {
            throw new RuntimeException('El Excel no contiene escaneos para agregar al inventario.');
        }

        return $filas;
    }

    private function agruparProductos(array $filas): array
    {
        $productos = [];
        foreach ($filas as $fila) {
            $identidad = implode('|', [
                $this->normalizar($fila['codigo']),
                $this->normalizar($fila['nombre']),
                $this->normalizar($fila['marca']),
                $this->normalizar($fila['medida']),
            ]);
            if (!isset($productos[$identidad])) {
                $productos[$identidad] = $fila;
            } else {
                foreach (self::ALMACENES as $almacen) {
                    $productos[$identidad]['existencias'][$almacen] += $fila['existencias'][$almacen];
                }
            }
        }

        $porCodigo = [];
        foreach ($productos as $identidad => $producto) {
            $porCodigo[$producto['codigo']][] = $identidad;
        }
        foreach ($porCodigo as $identidades) {
            foreach ($identidades as $posicion => $identidad) {
                $productos[$identidad]['codigo_barras'] = count($identidades) > 1
                    ? $productos[$identidad]['codigo'] . '-' . str_pad((string) ($posicion + 1), 2, '0', STR_PAD_LEFT)
                    : $productos[$identidad]['codigo'];
            }
        }

        $codigosOrigen = array_fill_keys(array_map(
            static fn (array $producto): string => strtoupper($producto['codigo']),
            $productos
        ), true);
        $codigosUsados = [];
        foreach ($productos as &$producto) {
            $codigoBarras = $producto['codigo_barras'];
            $sufijo = 1;
            while (isset($codigosUsados[strtoupper($codigoBarras)])
                || (isset($codigosOrigen[strtoupper($codigoBarras)])
                    && strtoupper($codigoBarras) !== strtoupper($producto['codigo']))
            ) {
                $codigoBarras = $producto['codigo'] . '-B' . str_pad((string) $sufijo, 2, '0', STR_PAD_LEFT);
                $sufijo++;
            }
            if (strlen($codigoBarras) > 80 || preg_match('/^[A-Z0-9 .\-$\/+%]+$/D', $codigoBarras) !== 1) {
                throw new RuntimeException('El código de una llanta no se puede imprimir como etiqueta Code 39.');
            }
            $producto['codigo_barras'] = $codigoBarras;
            $codigosUsados[strtoupper($codigoBarras)] = true;
        }
        unset($producto);

        return array_values($productos);
    }

    private function validarCantidad(string $valor, int $fila, string $almacen): int
    {
        if (trim($valor) === '') {
            return 0;
        }
        if (!is_numeric($valor) || (float) $valor < 0 || floor((float) $valor) !== (float) $valor) {
            throw new RuntimeException('La cantidad de la fila ' . $fila . ' no es válida para ' . $almacen . '.');
        }

        return (int) $valor;
    }

    private function obtenerIndiceColumna(string $referencia): int
    {
        if (preg_match('/^([A-Z]+)/i', $referencia, $coincidencia) !== 1) {
            throw new RuntimeException('El Excel contiene una columna con referencia inválida.');
        }
        $indice = 0;
        foreach (str_split(strtoupper($coincidencia[1])) as $letra) {
            $indice = $indice * 26 + ord($letra) - ord('A') + 1;
        }

        return $indice - 1;
    }

    private function normalizar(string $valor): string
    {
        if (function_exists('iconv')) {
            $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $valor);
            if (is_string($ascii)) {
                $valor = $ascii;
            }
        }

        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $valor));
    }
}

final class PanelModelo
{
    public function __construct(private readonly PDO $baseDatos)
    {
    }

    public function obtenerResumen(): array
    {
        return $this->baseDatos->query(
            'SELECT (SELECT COUNT(*) FROM productos) AS product_count,
                    (SELECT COALESCE(SUM(cantidad), 0) FROM inventario) AS unit_count,
                    (SELECT COUNT(*) FROM inventario WHERE cantidad <= stock_minimo) AS low_stock_count,
                    (SELECT COALESCE(SUM(cantidad), 0) FROM inventario_b4x01) AS b4x01_unit_count,
                    (SELECT COUNT(DISTINCT clave_producto) FROM inventario_b4x01) AS b4x01_product_count,
                    (SELECT COUNT(DISTINCT s.id)
                     FROM ventas s
                     JOIN detalle_ventas d ON d.venta_id = s.id AND d.estado = \'activa\'
                     WHERE s.fecha_venta >= DATE_FORMAT(CURRENT_DATE, "%Y-%m-01")) AS monthly_sale_count'
        )->fetch();
    }

    public function obtenerAlertasPrioritarias(): array
    {
        return $this->baseDatos->query(
            "SELECT p.id AS product_id, p.codigo_sku AS sku, p.nombre AS name,
                    w.codigo AS warehouse_code, w.nombre AS warehouse_name,
                    i.cantidad AS quantity, i.stock_minimo AS minimum_stock,
                    i.stock_seguridad AS safety_stock,
                    GREATEST(i.stock_minimo - i.cantidad, 0) AS shortage_quantity,
                    CASE WHEN i.cantidad <= i.stock_seguridad THEN 'critical' ELSE 'warning' END AS stock_status
             FROM inventario i
             JOIN productos p ON p.id = i.producto_id
             JOIN almacenes w ON w.codigo = i.almacen_codigo
             WHERE w.activo = 1 AND i.cantidad <= i.stock_minimo
             ORDER BY (i.cantidad <= i.stock_seguridad) DESC, i.cantidad ASC, p.nombre, w.codigo
             LIMIT 5"
        )->fetchAll();
    }

    public function obtenerVentasRecientes(): array
    {
        return $this->baseDatos->query(
            'SELECT s.numero_venta AS sale_number, s.fecha_venta AS sale_date,
                    s.almacen_codigo AS warehouse_code,
                    COALESCE(SUM(d.total_linea_centavos), 0) AS total_cents
             FROM ventas s
             JOIN detalle_ventas d ON d.venta_id = s.id AND d.estado = \'activa\'
             GROUP BY s.id, s.numero_venta, s.fecha_venta, s.almacen_codigo
             ORDER BY s.fecha_venta DESC, s.id DESC LIMIT 8'
        )->fetchAll();
    }
}

final class ProductosModelo
{
    private const CATEGORIAS = ['Llantas', 'Aros', 'Suspensión', 'Accesorios'];

    public function __construct(private readonly PDO $baseDatos)
    {
    }

    public function obtenerCategorias(): array
    {
        return self::CATEGORIAS;
    }

    public function obtenerMarcas(): array
    {
        return ['Austone', 'Double Coin', 'Dunlop', 'Michelin', 'Nglongtir', 'Pirelli', 'Transmate', 'Otra'];
    }

    public function obtenerMarcasHistoricas(): array
    {
        return $this->baseDatos->query(
            "SELECT DISTINCT TRIM(marca)
             FROM historico_ventas_excel
             WHERE fecha_venta >= '2026-01-01' AND fecha_venta < '2027-01-01'
               AND TRIM(marca) <> ''
             ORDER BY TRIM(marca)"
        )->fetchAll(PDO::FETCH_COLUMN);
    }

    public function generarSkuAutomatico(): string
    {
        $consulta = $this->baseDatos->prepare(
            'SELECT 1 FROM productos WHERE UPPER(codigo_sku) = :sku LIMIT 1'
        );
        for ($intento = 0; $intento < 5; $intento++) {
            $sku = 'B4X01' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 9));
            $consulta->execute(['sku' => $sku]);
            if ($consulta->fetchColumn() === false) {
                return $sku;
            }
        }

        throw new RuntimeException('No se pudo generar un SKU único. Inténtalo otra vez.');
    }

    public function guardarProducto(array $datos, int $precioCentavos, ?string $rutaImagen, int $idUsuario): void
    {
        $this->baseDatos->beginTransaction();
        try {
            $insertarProducto = $this->baseDatos->prepare(
                'INSERT INTO productos (codigo_sku, nombre, categoria, marca, medida, descripcion, precio_unitario_centavos,
                  proveedor, ruta_imagen)
                 VALUES (:sku, :name, :category, :brand, :size, :description, :price, :supplier, :image_path)'
            );
            $insertarProducto->execute([
                'sku' => $datos['sku'],
                'name' => $datos['name'],
                'category' => $datos['category'],
                'brand' => $datos['brand'],
                'size' => $datos['size'],
                'description' => $datos['description'],
                'price' => $precioCentavos,
                'supplier' => $datos['supplier'],
                'image_path' => $rutaImagen,
            ]);
            $idProducto = (int) $this->baseDatos->lastInsertId();
            $existenciasIniciales = [
                'A0101' => (int) $datos['stock_a0101'],
                'A0102' => (int) $datos['stock_a0102'],
                'A0103' => (int) $datos['stock_a0103'],
            ];
            $insertarExistencia = $this->baseDatos->prepare(
                'INSERT INTO inventario (producto_id, almacen_codigo, cantidad, stock_minimo, stock_seguridad)
                 VALUES (:product_id, :warehouse_code, :quantity, :minimum_stock, :safety_stock)'
            );
            $insertarMovimiento = $this->baseDatos->prepare(
                'INSERT INTO movimientos_inventario
                  (producto_id, almacen_codigo, tipo_movimiento, cambio_cantidad, tipo_referencia, referencia_id, creado_por, notas)
                 VALUES (:product_id, :warehouse_code, :movement_type, :quantity_change, :reference_type, :reference_id, :created_by, :notes)'
            );
            foreach ($existenciasIniciales as $codigoAlmacen => $cantidad) {
                $insertarExistencia->execute([
                    'product_id' => $idProducto,
                    'warehouse_code' => $codigoAlmacen,
                    'quantity' => $cantidad,
                    'minimum_stock' => (int) $datos['minimum_stock'],
                    'safety_stock' => (int) $datos['safety_stock'],
                ]);
                if (strcasecmp(trim((string) $datos['category']), 'Llantas') === 0) {
                    InventarioUnidades::agregar(
                        $this->baseDatos,
                        $idProducto,
                        $codigoAlmacen,
                        $cantidad
                    );
                }
                if ($cantidad > 0) {
                    $insertarMovimiento->execute([
                        'product_id' => $idProducto,
                        'warehouse_code' => $codigoAlmacen,
                        'movement_type' => 'opening',
                        'quantity_change' => $cantidad,
                        'reference_type' => 'product',
                        'reference_id' => $idProducto,
                        'created_by' => $idUsuario,
                        'notes' => 'Stock inicial del producto',
                    ]);
                }
            }
            $this->baseDatos->commit();
        } catch (Throwable $exception) {
            if ($this->baseDatos->inTransaction()) {
                $this->baseDatos->rollBack();
            }
            throw $exception;
        }
    }

    public function obtenerProductoParaEditar(int $idProducto): array|false
    {
        $consulta = $this->baseDatos->prepare(
            'SELECT p.id, p.codigo_sku AS sku, p.nombre AS name, p.categoria AS category,
                    p.marca AS brand, p.medida AS size, p.descripcion AS description,
                    p.precio_unitario_centavos AS unit_price_cents, p.proveedor AS supplier,
                    p.ruta_imagen AS image_path
             FROM productos p WHERE p.id = :id'
        );
        $consulta->execute(['id' => $idProducto]);
        return $consulta->fetch();
    }

    public function actualizarProducto(int $idProducto, array $datos, int $precioCentavos): void
    {
        $this->baseDatos->beginTransaction();
        try {
            $actualizar = $this->baseDatos->prepare(
                'UPDATE productos
                 SET codigo_sku = :sku, nombre = :name, categoria = :category, marca = :brand,
                     medida = :size, descripcion = :description, precio_unitario_centavos = :price,
                     proveedor = :supplier
                 WHERE id = :id'
            );
            $actualizar->execute([
                'sku' => $datos['sku'],
                'name' => $datos['name'],
                'category' => $datos['category'],
                'brand' => $datos['brand'],
                'size' => $datos['size'],
                'description' => $datos['description'],
                'price' => $precioCentavos,
                'supplier' => $datos['supplier'],
                'id' => $idProducto,
            ]);
            if ($actualizar->rowCount() === 0) {
                $existe = $this->baseDatos->prepare('SELECT 1 FROM productos WHERE id = :id');
                $existe->execute(['id' => $idProducto]);
                if ($existe->fetchColumn() === false) {
                    throw new RuntimeException('No se encontró el producto seleccionado.');
                }
            }

            $this->baseDatos->commit();
        } catch (Throwable $exception) {
            if ($this->baseDatos->inTransaction()) {
                $this->baseDatos->rollBack();
            }
            throw $exception;
        }
    }

    public function eliminarProductoSinHistorial(int $idProducto): ?string
    {
        $this->baseDatos->beginTransaction();
        try {
            $producto = $this->baseDatos->prepare(
                'SELECT ruta_imagen FROM productos WHERE id = :id FOR UPDATE'
            );
            $producto->execute(['id' => $idProducto]);
            $rutaImagen = $producto->fetchColumn();
            if ($rutaImagen === false) {
                throw new RuntimeException('No se encontró el producto seleccionado.');
            }

            $stock = $this->baseDatos->prepare(
                'SELECT COALESCE(SUM(cantidad), 0) FROM inventario WHERE producto_id = :product_id'
            );
            $stock->execute(['product_id' => $idProducto]);
            if ((int) $stock->fetchColumn() > 0) {
                throw new RuntimeException('No se puede eliminar: el producto todavía tiene existencias. Ajusta el stock a cero primero.');
            }

            foreach (['movimientos_inventario', 'detalle_ventas', 'detalle_compras'] as $tabla) {
                $referencias = $this->baseDatos->prepare(
                    "SELECT 1 FROM $tabla WHERE producto_id = :product_id LIMIT 1"
                );
                $referencias->execute(['product_id' => $idProducto]);
                if ($referencias->fetchColumn() !== false) {
                    throw new RuntimeException('No se puede eliminar porque el producto tiene historial de movimientos, compras o ventas.');
                }
            }

            $eliminar = $this->baseDatos->prepare('DELETE FROM productos WHERE id = :id');
            $eliminar->execute(['id' => $idProducto]);
            $this->baseDatos->commit();
            return is_string($rutaImagen) ? $rutaImagen : null;
        } catch (Throwable $exception) {
            if ($this->baseDatos->inTransaction()) {
                $this->baseDatos->rollBack();
            }
            throw $exception;
        }
    }

    public function actualizarPrecio(int $idProducto, int $precioCentavos): void
    {
        $consulta = $this->baseDatos->prepare(
            'UPDATE productos SET precio_unitario_centavos = :price WHERE id = :id'
        );
        $consulta->execute(['price' => $precioCentavos, 'id' => $idProducto]);
        if ($consulta->rowCount() === 0) {
            $existe = $this->baseDatos->prepare('SELECT 1 FROM productos WHERE id = :id');
            $existe->execute(['id' => $idProducto]);
            if ($existe->fetchColumn() === false) {
                throw new RuntimeException('No se encontró el producto seleccionado.');
            }
        }
    }

    public function actualizarStockCatalogo(int $idProducto, array $cantidades, int $idUsuario): array
    {
        $almacenes = ['A0101', 'A0102', 'A0103'];
        foreach ($almacenes as $almacen) {
            $cantidad = $cantidades[$almacen] ?? null;
            if (!is_int($cantidad) || $cantidad < 0 || $cantidad > 2147483647) {
                throw new RuntimeException('Las existencias deben ser números enteros no negativos.');
            }
        }
        if ($idProducto < 1 || $idUsuario < 1) {
            throw new RuntimeException('Selecciona un producto y usuario válidos.');
        }

        $this->baseDatos->beginTransaction();
        try {
            $productoConsulta = $this->baseDatos->prepare(
                'SELECT codigo_sku AS sku, categoria AS category FROM productos WHERE id = :id FOR UPDATE'
            );
            $productoConsulta->execute(['id' => $idProducto]);
            $producto = $productoConsulta->fetch();
            if ($producto === false) {
                throw new RuntimeException('No se encontró el producto seleccionado.');
            }

            $existenciasConsulta = $this->baseDatos->prepare(
                'SELECT almacen_codigo, cantidad FROM inventario
                 WHERE producto_id = :product_id ORDER BY almacen_codigo FOR UPDATE'
            );
            $existenciasConsulta->execute(['product_id' => $idProducto]);
            $existencias = [];
            foreach ($existenciasConsulta->fetchAll() as $existencia) {
                $existencias[(string) $existencia['almacen_codigo']] = (int) $existencia['cantidad'];
            }
            foreach ($almacenes as $almacen) {
                if (!array_key_exists($almacen, $existencias)) {
                    throw new RuntimeException('No se encontró el inventario operativo completo del producto.');
                }
            }

            $actualizar = $this->baseDatos->prepare(
                'UPDATE inventario SET cantidad = :quantity, actualizado_en = CURRENT_TIMESTAMP
                 WHERE producto_id = :product_id AND almacen_codigo = :warehouse'
            );
            $movimiento = $this->baseDatos->prepare(
                'INSERT INTO movimientos_inventario
                    (producto_id, almacen_codigo, tipo_movimiento, cambio_cantidad,
                     tipo_referencia, referencia_id, creado_por, notas)
                 VALUES (:product_id, :warehouse, :movement_type, :quantity_change,
                         :reference_type, NULL, :user_id, :notes)'
            );
            $almacenesActualizados = 0;
            foreach ($almacenes as $almacen) {
                $anterior = $existencias[$almacen];
                $nuevo = $cantidades[$almacen];
                $diferencia = $nuevo - $anterior;
                if ($diferencia === 0) {
                    continue;
                }
                $actualizar->execute([
                    'quantity' => $nuevo,
                    'product_id' => $idProducto,
                    'warehouse' => $almacen,
                ]);
                if (InventarioUnidades::esLlanta($producto)) {
                    if ($diferencia > 0) {
                        InventarioUnidades::agregar($this->baseDatos, $idProducto, $almacen, $diferencia);
                    } else {
                        InventarioUnidades::retirar($this->baseDatos, $idProducto, $almacen, -$diferencia);
                    }
                }
                $movimiento->execute([
                    'product_id' => $idProducto,
                    'warehouse' => $almacen,
                    'movement_type' => 'adjustment',
                    'quantity_change' => $diferencia,
                    'reference_type' => 'catalog_stock_adjustment',
                    'user_id' => $idUsuario,
                    'notes' => 'Ajuste desde catálogo de Productos. SKU ' . $producto['sku']
                        . ': saldo anterior ' . $anterior . ', nuevo saldo ' . $nuevo . '.',
                ]);
                $almacenesActualizados++;
            }
            $this->baseDatos->commit();

            return ['warehouses_updated' => $almacenesActualizados];
        } catch (Throwable $exception) {
            if ($this->baseDatos->inTransaction()) {
                $this->baseDatos->rollBack();
            }
            throw $exception;
        }
    }

    public function obtenerParaEtiqueta(int $idProducto): array|false
    {
        $consulta = $this->baseDatos->prepare(
            'SELECT p.id, p.codigo_sku AS sku, p.nombre AS name, p.marca AS brand, p.medida AS size,
                    p.categoria AS category,
                    p.precio_unitario_centavos AS unit_price_cents,
                    COALESCE((SELECT SUM(i.cantidad) FROM inventario i
                              WHERE i.producto_id = p.id
                                AND i.almacen_codigo IN (\'A0101\', \'A0102\', \'A0103\')), 0) AS stock
             FROM productos p WHERE p.id = :id'
        );
        $consulta->execute(['id' => $idProducto]);

        return $consulta->fetch();
    }

    public function obtenerUnidadesParaEtiqueta(
        int $idProducto,
        string $almacen,
        int $limite = 500,
        int $desplazamiento = 0
    ): array {
        if ($limite < 1 || $limite > 500 || $desplazamiento < 0) {
            throw new RuntimeException('La cantidad de etiquetas solicitada no es válida.');
        }
        $consulta = $this->baseDatos->prepare(
            'SELECT id, codigo_barras AS barcode
             FROM inventario_unidades
             WHERE producto_id = :product AND almacen_codigo = :warehouse AND estado = \'disponible\'
             ORDER BY id
             LIMIT :limit OFFSET :offset'
        );
        $consulta->bindValue(':product', $idProducto, PDO::PARAM_INT);
        $consulta->bindValue(':warehouse', $almacen, PDO::PARAM_STR);
        $consulta->bindValue(':limit', $limite, PDO::PARAM_INT);
        $consulta->bindValue(':offset', $desplazamiento, PDO::PARAM_INT);
        $consulta->execute();

        return $consulta->fetchAll();
    }

    public function contarUnidadesDisponibles(int $idProducto, string $almacen): int
    {
        $consulta = $this->baseDatos->prepare(
            'SELECT COUNT(*) FROM inventario_unidades
             WHERE producto_id = :product AND almacen_codigo = :warehouse AND estado = \'disponible\''
        );
        $consulta->execute(['product' => $idProducto, 'warehouse' => $almacen]);

        return (int) $consulta->fetchColumn();
    }

    public function buscarCatalogo(string $busqueda, int $pagina): array
    {
        $condicion = '';
        $parametros = [];
        if ($busqueda !== '') {
            $condicion = 'WHERE p.codigo_sku LIKE :search_sku
                OR UPPER(p.codigo_sku) LIKE :search_sku_without_prefix
                OR p.nombre LIKE :search_name
                OR p.marca LIKE :search_brand
                OR p.medida LIKE :search_size';
            $termino = '%' . $busqueda . '%';
            $parametros = [
                'search_sku' => $termino,
                'search_sku_without_prefix' => '%B4X01' . strtoupper($busqueda) . '%',
                'search_name' => $termino,
                'search_brand' => $termino,
                'search_size' => $termino,
            ];
        }

        $totalConsulta = $this->baseDatos->prepare(
            "SELECT COUNT(*) FROM productos p $condicion"
        );
        $totalConsulta->execute($parametros);
        $total = (int) $totalConsulta->fetchColumn();
        $porPagina = 50;
        $paginas = max(1, (int) ceil($total / $porPagina));
        $pagina = max(1, min($pagina, $paginas));

        $consulta = $this->baseDatos->prepare(
            "SELECT p.id, p.codigo_sku AS sku, p.nombre AS name, p.marca AS brand,
                    CASE WHEN UPPER(p.codigo_sku) LIKE 'B4X01%'
                         THEN SUBSTR(p.codigo_sku, 6) ELSE p.codigo_sku END AS sku_display,
                    p.medida AS size, p.ruta_imagen AS image_path,
                    p.precio_unitario_centavos AS unit_price_cents,
                    COALESCE((SELECT i.cantidad FROM inventario i WHERE i.producto_id = p.id AND i.almacen_codigo = 'A0101'), 0) AS stock_a0101,
                    COALESCE((SELECT i.cantidad FROM inventario i WHERE i.producto_id = p.id AND i.almacen_codigo = 'A0102'), 0) AS stock_a0102,
                    COALESCE((SELECT i.cantidad FROM inventario i WHERE i.producto_id = p.id AND i.almacen_codigo = 'A0103'), 0) AS stock_a0103,
                    COALESCE((SELECT SUM(i.cantidad) FROM inventario i
                              WHERE i.producto_id = p.id
                                AND i.almacen_codigo IN ('A0101', 'A0102', 'A0103')), 0) AS stock
             FROM productos p $condicion
             ORDER BY p.id DESC
             LIMIT :limit OFFSET :offset"
        );
        foreach ($parametros as $nombre => $valor) {
            $consulta->bindValue(':' . $nombre, $valor, PDO::PARAM_STR);
        }
        $consulta->bindValue(':limit', $porPagina, PDO::PARAM_INT);
        $consulta->bindValue(':offset', ($pagina - 1) * $porPagina, PDO::PARAM_INT);
        $consulta->execute();

        return [
            'products' => $consulta->fetchAll(),
            'total' => $total,
            'page' => $pagina,
            'pages' => $paginas,
            'per_page' => $porPagina,
        ];
    }

    public function buscarProductos(
        string $busqueda,
        string $categoria,
        string $marca,
        array $marcasHistoricas,
        int $pagina
    ): array
    {
        $condiciones = ['h.codigo IS NOT NULL'];
        $parametros = [];
        if ($busqueda !== '') {
            $condiciones[] = '(COALESCE(p.nombre, h.codigo) LIKE :search
                OR h.codigo LIKE :search
                OR COALESCE(p.marca, h.brand) LIKE :search
                OR COALESCE(p.medida, h.size) LIKE :search)';
            $parametros['search'] = '%' . $busqueda . '%';
        }
        if (in_array($categoria, self::CATEGORIAS, true)) {
            $condiciones[] = 'p.categoria = :category';
            $parametros['category'] = $categoria;
        } else {
            $categoria = '';
        }
        if (in_array($marca, $marcasHistoricas, true)) {
            $condiciones[] = 'h.brand = :brand';
            $parametros['brand'] = $marca;
        } else {
            $marca = '';
        }
        $where = implode(' AND ', $condiciones);
        $from = "FROM (
                SELECT codigo, MAX(marca) AS brand, MAX(medida) AS size,
                    SUM(CASE WHEN almacen_codigo = 'A0101' THEN cantidad ELSE 0 END) AS sales_a0101,
                    SUM(CASE WHEN almacen_codigo = 'A0102' THEN cantidad ELSE 0 END) AS sales_a0102,
                    SUM(CASE WHEN almacen_codigo = 'A0103' THEN cantidad ELSE 0 END) AS sales_a0103,
                    SUM(cantidad) AS historical_units
                FROM historico_ventas_excel
                WHERE fecha_venta >= '2026-01-01' AND fecha_venta < '2027-01-01'
                  AND almacen_codigo IN ('A0101', 'A0102', 'A0103')
                GROUP BY codigo
             ) h
             LEFT JOIN productos p ON p.codigo_sku = h.codigo
             WHERE $where";
        $totalConsulta = $this->baseDatos->prepare("SELECT COUNT(*) $from");
        $totalConsulta->execute($parametros);
        $total = (int) $totalConsulta->fetchColumn();
        $porPagina = 50;
        $paginas = max(1, (int) ceil($total / $porPagina));
        $pagina = max(1, min($pagina, $paginas));
        $consulta = $this->baseDatos->prepare(
            "SELECT p.id, COALESCE(p.codigo_sku, h.codigo) AS sku,
                COALESCE(p.nombre, h.codigo) AS name, COALESCE(p.categoria, 'Histórico') AS category,
                COALESCE(p.marca, h.brand) AS brand, COALESCE(p.medida, h.size) AS size,
                h.sales_a0101, h.sales_a0102, h.sales_a0103, h.historical_units,
                p.precio_unitario_centavos AS unit_price_cents,
                p.proveedor AS supplier, p.ruta_imagen AS image_path
             $from
             ORDER BY COALESCE(p.nombre, h.codigo)
             LIMIT :limit OFFSET :offset"
        );
        foreach ($parametros as $nombre => $valor) {
            $consulta->bindValue(':' . $nombre, $valor, PDO::PARAM_STR);
        }
        $consulta->bindValue(':limit', $porPagina, PDO::PARAM_INT);
        $consulta->bindValue(':offset', ($pagina - 1) * $porPagina, PDO::PARAM_INT);
        $consulta->execute();

        return [
            'productos' => $consulta->fetchAll(),
            'categoria' => $categoria,
            'marca' => $marca,
            'total' => $total,
            'page' => $pagina,
            'pages' => $paginas,
            'per_page' => $porPagina,
        ];
    }
}


final class PronosticoModelo
{
    public function __construct(private readonly PDO $baseDatos)
    {
    }

    public function obtenerPronostico(): array
    {
        $resultado = (new BrandSalesForecastService($this->baseDatos))->generateDaily();
        $semana = $resultado['current_daily_forecast'];
        $marcasVisibles = [];
        $productosVisibles = [];
        $encabezadosDias = [];
        $totalesPorDia = [];
        $totalesMarcasPorDia = [];
        $totalVentasRecientes = 0;
        $totalVentasRecientesMarcas = 0;
        if (is_array($semana)) {
            $codigosInventario = $this->obtenerCodigosInventarioB4x01();
            $nombresDias = [1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb', 7 => 'Dom'];
            foreach ($semana['forecast_dates'] as $fechaTexto) {
                $fecha = new DateTimeImmutable($fechaTexto);
                $encabezadosDias[] = [
                    'name' => $nombresDias[(int) $fecha->format('N')],
                    'date' => $fecha->format('d/m'),
                ];
            }
            foreach ($semana['brand_forecast_rows'] as $fila) {
                $fila['logos'] = $this->obtenerLogosMarca($fila['marca']);
                $marcasVisibles[] = $fila;
                $totalVentasRecientesMarcas += (int) $fila['recent_units'];
            }
            foreach ($semana['product_forecast_rows'] as $fila) {
                $fila['logos'] = $this->obtenerLogosMarca($fila['marca']);
                $fila['codigo_inventario'] = $this->buscarCodigoInventario($fila, $codigosInventario);
                $productosVisibles[] = $fila;
                $totalVentasRecientes += (int) $fila['recent_units'];
                foreach ($fila['daily_units'] as $dia => $unidades) {
                    $totalesPorDia[$dia] = ($totalesPorDia[$dia] ?? 0) + (int) $unidades;
                }
            }
        }

        return [
            'resultado_pronostico' => $resultado,
            'mensaje_pronostico' => $resultado['daily_forecast_message'],
            'semana_seleccionada' => $semana,
            'marcas_visibles' => $marcasVisibles,
            'totales_marcas_por_dia' => $semana['brand_daily_totals'] ?? [],
            'total_ventas_recientes_marcas' => $totalVentasRecientesMarcas,
            'productos_visibles' => $productosVisibles,
            'encabezados_dias' => $encabezadosDias,
            'totales_por_dia' => $totalesPorDia,
            'total_ventas_recientes' => $totalVentasRecientes,
        ];
    }

    private function obtenerCodigosInventarioB4x01(): array
    {
        return $this->baseDatos->query(
            "SELECT DISTINCT codigo_barras AS barcode, codigo_excel AS excel_code,
                    marca AS brand, medida AS measure, nombre AS name,
                    es_manual AS manual
             FROM inventario_b4x01
             WHERE TRIM(codigo_barras) <> ''"
        )->fetchAll();
    }

    private function buscarCodigoInventario(array $producto, array $codigosInventario): string
    {
        $codigoHistorico = $this->normalizarCodigo((string) ($producto['codigo'] ?? ''));
        if ($codigoHistorico !== '') {
            foreach ($codigosInventario as $codigo) {
                if (
                    $codigoHistorico === $this->normalizarCodigo((string) $codigo['barcode'])
                    || $codigoHistorico === $this->normalizarCodigo((string) $codigo['excel_code'])
                ) {
                    return (string) $codigo['barcode'];
                }
            }
        }

        $marca = $this->normalizarMarca((string) ($producto['marca'] ?? ''));
        $medida = $this->normalizarMedida((string) ($producto['medida'] ?? ''));
        $modelo = $this->normalizarCodigo((string) ($producto['modelo'] ?? ''));
        $puntuaciones = [];
        foreach ($codigosInventario as $codigo) {
            $marcaInventario = $this->normalizarMarca((string) $codigo['brand']);
            if ($marca !== $marcaInventario && !($marca === 'DOUBLE' && $marcaInventario === 'DOUBLE')) {
                continue;
            }

            $medidaInventario = $this->normalizarMedida((string) $codigo['measure']);
            $nombreInventario = $this->normalizarCodigo((string) $codigo['name']);
            if ($medida === $medidaInventario) {
                $puntuaciones[$codigo['barcode']] = 100;
            } elseif ($medida !== '' && str_contains($nombreInventario, $medida)) {
                $puntuaciones[$codigo['barcode']] = 90;
            } elseif ($medidaInventario !== '' && str_contains($medida, $medidaInventario)) {
                $puntuaciones[$codigo['barcode']] = 75;
            } else {
                continue;
            }

            if ($modelo !== '' && str_contains($nombreInventario, $modelo)) {
                $coincidenciaManualExacta = (int) $codigo['manual'] === 1
                    && str_ends_with($nombreInventario, $modelo);
                $puntuaciones[$codigo['barcode']] += $coincidenciaManualExacta ? 100 : 60;
                continue;
            }

            foreach ($this->obtenerTokensModelo((string) ($producto['modelo'] ?? '')) as $token) {
                if (str_contains($nombreInventario, $token)) {
                    $puntuaciones[$codigo['barcode']] = ($puntuaciones[$codigo['barcode']] ?? 0) + 12;
                }
            }
        }

        if ($puntuaciones === []) {
            return '';
        }
        arsort($puntuaciones, SORT_NUMERIC);
        $codigoMejor = (string) array_key_first($puntuaciones);
        $puntuacionesOrdenadas = array_values($puntuaciones);

        return isset($puntuacionesOrdenadas[1]) && $puntuacionesOrdenadas[0] === $puntuacionesOrdenadas[1]
            ? ''
            : $codigoMejor;
    }

    private function normalizarCodigo(string $valor): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', strtoupper($valor));
    }

    private function normalizarMedida(string $medida): string
    {
        $medida = strtoupper($medida);
        $medida = (string) preg_replace('/(?<=\d)X(?=\d)/', '', $medida);

        return $this->normalizarCodigo($medida);
    }

    private function normalizarMarca(string $marca): string
    {
        $marca = $this->normalizarCodigo($marca);
        $alias = [
            'DOUBLECOIN' => 'DOUBLE',
            'DOUBLESTAR' => 'DOUBLE',
            'GOOD' => 'GOODYEAR',
            'GOODTYRE' => 'GOODYEAR',
            'JK' => 'JKTYRES',
            'JKTYRE' => 'JKTYRES',
            'JKTYRES' => 'JKTYRES',
            'CHAO' => 'CHAOYANG',
            'GT' => 'GTRADIAL',
            'LING' => 'LINGLONG',
            'LONG' => 'LONGMARCH',
            'TAITON' => 'TAITONG',
        ];

        return $alias[$marca] ?? $marca;
    }

    private function obtenerTokensModelo(string $modelo): array
    {
        $tokensIgnorados = ['AT', 'DEL', 'L', 'MINERA', 'MIXTA', 'MT', 'POST', 'RIB', 'SUPER', 'TCF', 'TL'];
        $tokens = preg_split('/[^A-Z0-9]+/', strtoupper($modelo), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (in_array('MINERA', $tokens, true)) {
            $tokens[] = 'MINING';
        }

        return array_values(array_filter(
            $tokens,
            static fn (string $token): bool => strlen($token) >= 3 && !in_array($token, $tokensIgnorados, true)
        ));
    }

    private function obtenerLogosMarca(string $marca): array
    {
        static $logos = null;
        if ($logos === null) {
            $logos = [];
            $directorio = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'publico' . DIRECTORY_SEPARATOR . 'recursos' . DIRECTORY_SEPARATOR . 'imagenes'
                . DIRECTORY_SEPARATOR . 'CARPETAS_MARCAS_LLANTAS';
            $extensiones = ['avif', 'gif', 'jpeg', 'jpg', 'png', 'svg', 'webp'];
            foreach (glob($directorio . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $carpeta) {
                $nombreMarca = (string) preg_replace('/^\d+_/', '', basename($carpeta));
                $clave = strtoupper((string) preg_replace('/[^A-Z0-9]/', '', $nombreMarca));
                foreach (glob($carpeta . DIRECTORY_SEPARATOR . '*') ?: [] as $ruta) {
                    if (!is_file($ruta) || !in_array(strtolower(pathinfo($ruta, PATHINFO_EXTENSION)), $extensiones, true)) {
                        continue;
                    }
                    if ($clave !== '') {
                        $logos[$clave] = '../publico/recursos/imagenes/CARPETAS_MARCAS_LLANTAS/'
                            . rawurlencode(basename($carpeta)) . '/' . rawurlencode(basename($ruta));
                    }
                    break;
                }
            }
        }

        $clave = strtoupper((string) preg_replace('/[^A-Z0-9]/', '', $marca));
        if ($clave === 'DOUBLE') {
            return array_values(array_filter([$logos['DOUBLECOIN'] ?? null, $logos['DOUBLESTAR'] ?? null]));
        }
        $alias = [
            'CHALLENDER' => 'CHALLENGER', 'CHAO' => 'CHAOYANG', 'GOODTYRE' => 'GOODYEAR',
            'GT' => 'GTRADIAL', 'HUNTER' => 'HUNTERROAD', 'JK' => 'JKTYRE', 'JKTYRES' => 'JKTYRE',
            'LIMA' => 'LIMACAUCHO', 'LING' => 'LINGLONG', 'LONG' => 'LONGMARCH', 'RED' => 'REDLION',
            'ROLLING' => 'ROLLINGMASTER', 'SUMAXX' => 'SUMAX', 'SUPER' => 'SUPERCARGO',
            'TAITON' => 'TAITONG', 'TECHSHILD' => 'TECHSHIELD',
        ];
        $clave = $alias[$clave] ?? $clave;

        return isset($logos[$clave]) ? [$logos[$clave]] : [];
    }
}

final class UsuariosModelo
{
    public function __construct(private readonly PDO $baseDatos)
    {
    }

    public function crearUsuario(string $nombre, string $correo, string $contrasena, string $rol): void
    {
        $consulta = $this->baseDatos->prepare(
            'INSERT INTO usuarios (nombre_completo, correo, hash_contrasena, rol)
             VALUES (:name, :email, :password, :role)'
        );
        $consulta->execute([
            'name' => $nombre,
            'email' => $correo,
            'password' => password_hash($contrasena, PASSWORD_DEFAULT),
            'role' => $rol,
        ]);
    }

    public function actualizarUsuario(int $id, string $nombre, string $correo, string $rol, bool $activo, int $idUsuarioActual): void
    {
        $this->baseDatos->beginTransaction();
        try {
            $consultaObjetivo = $this->baseDatos->prepare(
                'SELECT id, rol AS role, activo AS is_active FROM usuarios WHERE id = :id FOR UPDATE'
            );
            $consultaObjetivo->execute(['id' => $id]);
            $objetivo = $consultaObjetivo->fetch();
            if ($objetivo === false) {
                throw new RuntimeException('La cuenta seleccionada ya no existe.');
            }
            if ($id === $idUsuarioActual && ($rol !== 'administrador' || !$activo)) {
                throw new RuntimeException('No puedes quitarte el rol de administrador ni desactivar tu propia cuenta.');
            }
            if ((string) $objetivo['role'] === 'administrador' && (int) $objetivo['is_active'] === 1
                && ($rol !== 'administrador' || !$activo)
            ) {
                $administradoresActivos = $this->baseDatos->query(
                    "SELECT id FROM usuarios WHERE rol = 'administrador' AND activo = 1 FOR UPDATE"
                )->fetchAll(PDO::FETCH_COLUMN);
                if (count($administradoresActivos) <= 1) {
                    throw new RuntimeException('Debe quedar al menos una cuenta administradora activa.');
                }
            }

            $actualizar = $this->baseDatos->prepare(
                'UPDATE usuarios SET nombre_completo = :name, correo = :email, rol = :role, activo = :active
                 WHERE id = :id'
            );
            $actualizar->execute([
                'name' => $nombre,
                'email' => $correo,
                'role' => $rol,
                'active' => $activo ? 1 : 0,
                'id' => $id,
            ]);
            $this->baseDatos->commit();
        } catch (Throwable $exception) {
            if ($this->baseDatos->inTransaction()) {
                $this->baseDatos->rollBack();
            }
            throw $exception;
        }
    }

    public function restablecerContrasena(int $id, string $contrasena): bool
    {
        $consulta = $this->baseDatos->prepare(
            'UPDATE usuarios SET hash_contrasena = :password WHERE id = :id'
        );
        $consulta->execute([
            'password' => password_hash($contrasena, PASSWORD_DEFAULT),
            'id' => $id,
        ]);

        return $consulta->rowCount() === 1;
    }

    public function obtenerUsuarios(): array
    {
        return $this->baseDatos->query(
            'SELECT id, nombre_completo AS full_name, correo AS email, rol AS role,
                    activo AS is_active, creado_en AS created_at
             FROM usuarios ORDER BY (rol = "administrador") DESC, nombre_completo, id'
        )->fetchAll();
    }
}

final class VentasModelo
{
    public function __construct(private readonly PDO $baseDatos)
    {
    }

    private function bloqueoFilas(): string
    {
        return $this->baseDatos->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }

    public function obtenerProductos(): array
    {
        return $this->baseDatos->query(
            'SELECT p.id, p.codigo_sku AS sku, p.nombre AS name, p.categoria AS category,
                p.marca AS brand, p.medida AS size,
                p.precio_unitario_centavos AS unit_price_cents,
                COALESCE((SELECT cantidad FROM inventario WHERE producto_id = p.id AND almacen_codigo = "A0101"), 0) AS stock_a0101,
                COALESCE((SELECT cantidad FROM inventario WHERE producto_id = p.id AND almacen_codigo = "A0102"), 0) AS stock_a0102,
                COALESCE((SELECT cantidad FROM inventario WHERE producto_id = p.id AND almacen_codigo = "A0103"), 0) AS stock_a0103
             FROM productos p ORDER BY p.nombre'
        )->fetchAll();
    }

    public function buscarProductoDisponiblePorSku(string $codigo, string $almacen): array|false
    {
        $codigo = InventarioUnidades::normalizarCodigoEscaneado($codigo);
        $consulta = $this->baseDatos->prepare(
            'SELECT p.id AS product_id, p.codigo_sku AS sku, p.nombre AS name, p.categoria AS category
             FROM productos p
             JOIN inventario i ON i.producto_id = p.id AND i.almacen_codigo = :warehouse
             WHERE UPPER(p.codigo_sku) = :sku AND i.cantidad > 0
             LIMIT 1'
        );
        $consulta->execute(['sku' => $codigo, 'warehouse' => $almacen]);

        return $consulta->fetch();
    }

    public function buscarUnidadDisponible(string $codigo, string $almacen): array|false
    {
        $codigo = InventarioUnidades::normalizarCodigoEscaneado($codigo);
        $consulta = $this->baseDatos->prepare(
            'SELECT u.codigo_barras AS barcode, p.id, p.codigo_sku AS sku, p.nombre AS name,
                   p.categoria AS category
             FROM inventario_unidades u
             JOIN productos p ON p.id = u.producto_id
             JOIN inventario i ON i.producto_id = u.producto_id AND i.almacen_codigo = u.almacen_codigo
             WHERE u.codigo_barras = :barcode AND u.almacen_codigo = :warehouse
               AND u.estado = \'disponible\' AND p.categoria = \'Llantas\' AND i.cantidad > 0'
        );
        $consulta->execute(['barcode' => $codigo, 'warehouse' => $almacen]);

        return $consulta->fetch();
    }

    public function obtenerAlmacenes(): array
    {
        return $this->baseDatos->query(
            'SELECT codigo AS code, nombre AS name FROM almacenes WHERE activo = 1 ORDER BY codigo'
        )->fetchAll();
    }

    public function registrarSalida(
        array $lineas,
        string $almacen,
        string $fecha,
        string $notas,
        int $idUsuario,
        array $codigosEscaneados = []
    ): array
    {
        $this->baseDatos->beginTransaction();
        try {
            $codigosPorProducto = [];
            $escaneosSkuPorProducto = [];
            $buscarSku = $this->baseDatos->prepare(
                'SELECT p.id AS product_id
                 FROM productos p
                 JOIN inventario i ON i.producto_id = p.id AND i.almacen_codigo = :warehouse
                 WHERE UPPER(p.codigo_sku) = :sku AND UPPER(p.categoria) = \'LLANTAS\' AND i.cantidad > 0
                 LIMIT 1' . $this->bloqueoFilas()
            );
            $buscarUnidad = $this->baseDatos->prepare(
                'SELECT u.producto_id, p.categoria
                 FROM inventario_unidades u
                 JOIN productos p ON p.id = u.producto_id
                 WHERE u.codigo_barras = :barcode AND u.almacen_codigo = :warehouse
                   AND u.estado = \'disponible\'' . $this->bloqueoFilas()
            );
            $codigosUnicos = [];
            foreach ($codigosEscaneados as $codigoEntrada) {
                $codigo = InventarioUnidades::normalizarCodigoEscaneado((string) $codigoEntrada);
                if ($codigo === '' || strlen($codigo) > 80
                    || preg_match('/\A[\x20-\x7E]+\z/D', $codigo) !== 1
                ) {
                    throw new RuntimeException('Hay un código vacío o inválido en la venta.');
                }

                $buscarSku->execute(['sku' => $codigo, 'warehouse' => $almacen]);
                $productoSku = $buscarSku->fetch();
                if ($productoSku !== false) {
                    $idProducto = (int) $productoSku['product_id'];
                    $escaneosSkuPorProducto[$idProducto] = ($escaneosSkuPorProducto[$idProducto] ?? 0) + 1;
                    continue;
                }

                if (isset($codigosUnicos[$codigo])) {
                    throw new RuntimeException('Hay un código individual repetido en la venta.');
                }
                $buscarUnidad->execute(['barcode' => $codigo, 'warehouse' => $almacen]);
                $unidad = $buscarUnidad->fetch();
                if ($unidad === false) {
                    throw new RuntimeException('Una llanta escaneada no está disponible en el almacén seleccionado.');
                }
                if (!InventarioUnidades::esLlanta(['category' => $unidad['categoria']])) {
                    throw new RuntimeException('El código escaneado no corresponde a una llanta.');
                }
                $codigosUnicos[$codigo] = true;
                $codigosPorProducto[(int) $unidad['producto_id']][] = $codigo;
            }

            $consultaProducto = $this->baseDatos->prepare(
                'SELECT p.id, p.nombre AS name, p.codigo_sku AS sku, p.categoria AS category,
                    p.marca AS brand, p.medida AS size,
                    p.precio_unitario_centavos AS unit_price_cents, i.cantidad AS quantity,
                    i.stock_minimo AS minimum_stock
                 FROM productos p
                 JOIN inventario i ON i.producto_id = p.id AND i.almacen_codigo = :warehouse
                 WHERE p.id = :product' . $this->bloqueoFilas()
            );
            $actualizarStock = $this->baseDatos->prepare(
                'UPDATE inventario SET cantidad = cantidad - :quantity, actualizado_en = CURRENT_TIMESTAMP
                 WHERE producto_id = :product AND almacen_codigo = :warehouse AND cantidad >= :quantity'
            );
            $productosSalida = [];
            $cantidadesPorProducto = [];
            $productosPorId = [];
            $unidadesTotales = 0;
            foreach ($lineas as $indice => $linea) {
                $consultaProducto->execute(['warehouse' => $almacen, 'product' => $linea['product_id']]);
                $producto = $consultaProducto->fetch();
                if ($producto === false) {
                    throw new RuntimeException('Un producto no tiene inventario configurado en ese almacén.');
                }
                if ((int) $producto['quantity'] < $linea['quantity']) {
                    throw new RuntimeException('Stock insuficiente para uno de los productos. Disponible: ' . (int) $producto['quantity'] . '.');
                }
                $idProducto = (int) $producto['id'];
                $cantidadesPorProducto[$idProducto] = ($cantidadesPorProducto[$idProducto] ?? 0)
                    + (int) $linea['quantity'];
                $productosPorId[$idProducto] = $producto;
                $actualizarStock->execute([
                    'quantity' => $linea['quantity'],
                    'product' => $linea['product_id'],
                    'warehouse' => $almacen,
                ]);
                if ($actualizarStock->rowCount() !== 1) {
                    throw new RuntimeException('El stock cambió mientras registrabas la salida. Vuelve a intentarlo.');
                }
                $productosSalida[$indice] = $producto;
                $unidadesTotales += $linea['quantity'];
            }
            foreach ($cantidadesPorProducto as $idProducto => $cantidad) {
                $producto = $productosPorId[$idProducto];
                $codigos = $codigosPorProducto[$idProducto] ?? [];
                $escaneosSku = (int) ($escaneosSkuPorProducto[$idProducto] ?? 0);
                if (InventarioUnidades::esLlanta($producto) && count($codigos) + $escaneosSku > $cantidad) {
                    throw new RuntimeException('La cantidad no puede ser menor que las llantas escaneadas.');
                }
                if (!InventarioUnidades::esLlanta($producto) && $codigos !== []) {
                    throw new RuntimeException('Solo se pueden escanear códigos individuales de llantas en esta venta.');
                }
            }
            foreach ($escaneosSkuPorProducto as $idProducto => $cantidadEscaneada) {
                if (!array_key_exists($idProducto, $cantidadesPorProducto)) {
                    throw new RuntimeException('Agrega a la venta la llanta correspondiente a cada escaneo.');
                }
            }
            foreach ($codigosPorProducto as $idProducto => $codigos) {
                if (!array_key_exists($idProducto, $cantidadesPorProducto)) {
                    throw new RuntimeException('Agrega a la venta la llanta correspondiente a cada código escaneado.');
                }
            }
            $buscarUnidadesSku = $this->baseDatos->prepare(
                'SELECT codigo_barras FROM inventario_unidades
                 WHERE producto_id = :product AND almacen_codigo = :warehouse AND estado = \'disponible\'
                 ORDER BY id' . $this->bloqueoFilas()
            );
            foreach ($cantidadesPorProducto as $idProducto => $cantidad) {
                if (!InventarioUnidades::esLlanta($productosPorId[$idProducto])) {
                    continue;
                }
                $buscarUnidadesSku->execute(['product' => $idProducto, 'warehouse' => $almacen]);
                $unidadesDisponibles = $buscarUnidadesSku->fetchAll(PDO::FETCH_COLUMN);
                $codigosProducto = $codigosPorProducto[$idProducto] ?? [];
                $codigosUsados = array_fill_keys($codigosProducto, true);
                $unidadesAsignadas = 0;
                $unidadesPorAsignar = $cantidad - count($codigosProducto);
                if ($unidadesPorAsignar === 0) {
                    $codigosPorProducto[$idProducto] = $codigosProducto;
                    continue;
                }
                foreach ($unidadesDisponibles as $codigoUnidad) {
                    if (isset($codigosUsados[$codigoUnidad])) {
                        continue;
                    }
                    $codigosProducto[] = (string) $codigoUnidad;
                    $codigosUsados[$codigoUnidad] = true;
                    $unidadesAsignadas++;
                    if ($unidadesAsignadas === $unidadesPorAsignar) {
                        break;
                    }
                }
                $cantidadEscaneada = (int) ($escaneosSkuPorProducto[$idProducto] ?? 0);
                if ($unidadesAsignadas < $cantidadEscaneada) {
                    throw new RuntimeException('No hay suficientes llantas disponibles para la cantidad escaneada.');
                }
                if ($codigosProducto !== []) {
                    $codigosPorProducto[$idProducto] = $codigosProducto;
                }
            }

            $numeroVenta = 'SAL-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $insertarVenta = $this->baseDatos->prepare(
                'INSERT INTO ventas (numero_venta, almacen_codigo, fecha_venta, creado_por, notas)
                 VALUES (:number, :warehouse, :date, :user, :notes)'
            );
            $insertarVenta->execute([
                'number' => $numeroVenta,
                'warehouse' => $almacen,
                'date' => $fecha,
                'user' => $idUsuario,
                'notes' => $notas,
            ]);
            $idVenta = (int) $this->baseDatos->lastInsertId();
            $insertarDetalle = $this->baseDatos->prepare(
                'INSERT INTO detalle_ventas (venta_id, producto_id, cantidad, precio_unitario_centavos, total_linea_centavos)
                 VALUES (:sale, :product, :quantity, :unit_price, :line_total)'
            );
            $insertarHistorial = $this->baseDatos->prepare(
                'INSERT INTO historico_ventas_excel
                  (archivo_hash, hoja, fila_origen, codigo, marca, medida, almacen_codigo, fecha_venta, cantidad, tipo_origen)
                 VALUES (:file_hash, :sheet, :source_row, :code, :brand, :size, :warehouse, :sale_date, :quantity, :origin)'
            );
            $insertarMovimiento = $this->baseDatos->prepare(
                'INSERT INTO movimientos_inventario
                  (producto_id, almacen_codigo, tipo_movimiento, cambio_cantidad, tipo_referencia, referencia_id, creado_por, notas)
                 VALUES (:product, :warehouse, :type, :quantity, :reference_type, :reference_id, :user, :notes)'
            );
            $productosActualizados = [];
            foreach ($lineas as $indice => $linea) {
                $precioCentavos = (int) $productosSalida[$indice]['unit_price_cents'];
                $insertarDetalle->execute([
                    'sale' => $idVenta,
                    'product' => $linea['product_id'],
                    'quantity' => $linea['quantity'],
                    'unit_price' => $precioCentavos,
                    'line_total' => $precioCentavos * $linea['quantity'],
                ]);
                $idDetalle = (int) $this->baseDatos->lastInsertId();
                if (in_array($almacen, ['A0101', 'A0102'], true)) {
                    $insertarHistorial->execute([
                        'file_hash' => hash('sha256', 'manual-sale-detail:' . $idDetalle),
                        'sheet' => 'MANUAL',
                        'source_row' => $idDetalle,
                        'code' => $productosSalida[$indice]['sku'],
                        'brand' => $productosSalida[$indice]['brand'],
                        'size' => $productosSalida[$indice]['size'],
                        'warehouse' => $almacen,
                        'sale_date' => $fecha,
                        'quantity' => $linea['quantity'],
                        'origin' => 'manual',
                    ]);
                }
                $insertarMovimiento->execute([
                    'product' => $linea['product_id'],
                    'warehouse' => $almacen,
                    'type' => 'outgoing',
                    'quantity' => -$linea['quantity'],
                    'reference_type' => 'sale',
                    'reference_id' => $idVenta,
                    'user' => $idUsuario,
                    'notes' => 'Salida ' . $numeroVenta,
                ]);
                $saldoRestante = (int) $productosSalida[$indice]['quantity'] - (int) $linea['quantity'];
                $stockMinimo = (int) $productosSalida[$indice]['minimum_stock'];
                $productosActualizados[] = [
                    'name' => (string) $productosSalida[$indice]['name'],
                    'sku' => (string) $productosSalida[$indice]['sku'],
                    'brand' => (string) $productosSalida[$indice]['brand'],
                    'size' => (string) $productosSalida[$indice]['size'],
                    'quantity' => (int) $linea['quantity'],
                    'remaining' => $saldoRestante,
                    'minimum_stock' => $stockMinimo,
                    'low_stock' => $saldoRestante <= $stockMinimo,
                ];
            }
            foreach ($codigosPorProducto as $idProducto => $codigos) {
                InventarioUnidades::marcarVendidas(
                    $this->baseDatos,
                    $codigos,
                    (int) $idProducto,
                    $almacen,
                    $idVenta
                );
            }
            $this->baseDatos->commit();

            return ['numero' => $numeroVenta, 'unidades' => $unidadesTotales, 'items' => $productosActualizados];
        } catch (Throwable $exception) {
            if ($this->baseDatos->inTransaction()) {
                $this->baseDatos->rollBack();
            }
            throw $exception;
        }
    }

    public function obtenerHistorial(array $filtros, string $rol, int $idUsuario): array
    {
        $busqueda = trim((string) ($filtros['q'] ?? ''));
        $almacen = trim((string) ($filtros['warehouse'] ?? ''));
        $desde = trim((string) ($filtros['from'] ?? ''));
        $hasta = trim((string) ($filtros['to'] ?? ''));
        $condiciones = [];
        $parametros = ['role' => $rol, 'user_id' => $idUsuario];
        if ($busqueda !== '') {
            $condiciones[] = '(s.numero_venta LIKE :sale_number OR p.codigo_sku LIKE :sku OR p.nombre LIKE :product_name)';
            $parametros['sale_number'] = '%' . $busqueda . '%';
            $parametros['sku'] = '%' . $busqueda . '%';
            $parametros['product_name'] = '%' . $busqueda . '%';
        }
        if (in_array($almacen, ['A0101', 'A0102', 'A0103'], true)) {
            $condiciones[] = 's.almacen_codigo = :warehouse';
            $parametros['warehouse'] = $almacen;
        } else {
            $almacen = '';
        }
        foreach (['from' => &$desde, 'to' => &$hasta] as $clave => &$valor) {
            $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
            if (!$fecha || $fecha->format('Y-m-d') !== $valor) {
                $valor = '';
                continue;
            }
            $operador = $clave === 'from' ? '>=' : '<=';
            $nombreParametro = $clave === 'from' ? 'date_from' : 'date_to';
            $condiciones[] = 's.fecha_venta ' . $operador . ' :' . $nombreParametro;
            $parametros[$nombreParametro] = $valor;
        }
        unset($valor);
        $filtroSql = $condiciones === [] ? '' : ' AND ' . implode(' AND ', $condiciones);
        $consulta = $this->baseDatos->prepare(
            "SELECT s.numero_venta AS sale_number, s.fecha_venta AS sale_date, s.creado_en AS registered_at,
                    s.id AS sale_id, d.id AS detail_id,
                    s.almacen_codigo AS warehouse_code, w.nombre AS warehouse_name,
                    p.codigo_sku AS sku, p.nombre AS name, d.cantidad AS quantity,
                    d.total_linea_centavos AS line_total_cents,
                    EXISTS(
                      SELECT 1 FROM reportes_ventas r
                      WHERE r.detalle_venta_id = d.id AND r.estado = 'pendiente'
                    ) AS has_pending_report
             FROM ventas s
             JOIN detalle_ventas d ON d.venta_id = s.id
             JOIN productos p ON p.id = d.producto_id
             JOIN almacenes w ON w.codigo = s.almacen_codigo
             WHERE d.estado = 'activa'
               AND (:role = 'administrador' OR s.creado_por = :user_id) $filtroSql
             ORDER BY s.creado_en DESC, s.id DESC LIMIT 50"
        );
        $consulta->execute($parametros);

        return [
            'ventas' => $consulta->fetchAll(),
            'busqueda' => $busqueda,
            'almacen' => $almacen,
            'desde' => $desde,
            'hasta' => $hasta,
        ];
    }

    public function obtenerDetalleVentaParaEditar(int $idDetalle): array|false
    {
        $consulta = $this->baseDatos->prepare(
            "SELECT d.id AS detail_id, d.venta_id AS sale_id, d.producto_id AS product_id,
                    d.cantidad AS quantity, d.precio_unitario_centavos AS unit_price_cents,
                    d.total_linea_centavos AS line_total_cents,
                    s.numero_venta AS sale_number, s.fecha_venta AS sale_date,
                    s.almacen_codigo AS warehouse_code, s.notas AS notes,
                    p.codigo_sku AS sku, p.nombre AS name, p.marca AS brand, p.medida AS size
             FROM detalle_ventas d
             JOIN ventas s ON s.id = d.venta_id
             JOIN productos p ON p.id = d.producto_id
             WHERE d.id = :detail_id AND d.estado = 'activa'"
        );
        $consulta->execute(['detail_id' => $idDetalle]);

        return $consulta->fetch();
    }

    public function obtenerReportesPendientes(): array
    {
        return $this->baseDatos->query(
            "SELECT r.id AS report_id, r.detalle_venta_id AS detail_id,
                    r.venta_id AS sale_id, r.motivo AS reason, r.detalle AS details,
                    r.creado_en AS reported_at, s.numero_venta AS sale_number,
                    s.fecha_venta AS sale_date, s.almacen_codigo AS warehouse_code,
                    p.codigo_sku AS sku, p.nombre AS product_name,
                    d.cantidad AS quantity, u.nombre_completo AS reporter_name
             FROM reportes_ventas r
             JOIN ventas s ON s.id = r.venta_id
             JOIN detalle_ventas d ON d.id = r.detalle_venta_id
             JOIN productos p ON p.id = d.producto_id
             JOIN usuarios u ON u.id = r.reportado_por
             WHERE r.estado = 'pendiente' AND d.estado = 'activa'
             ORDER BY r.creado_en ASC, r.id ASC
             LIMIT 100"
        )->fetchAll();
    }

    public function reportarErrorVenta(
        int $idVenta,
        int $idDetalle,
        int $idUsuario,
        string $motivo,
        string $detalle
    ): void {
        $this->baseDatos->beginTransaction();
        try {
            $consultaDetalle = $this->baseDatos->prepare(
                'SELECT s.creado_por, d.estado
                 FROM ventas s
                 JOIN detalle_ventas d ON d.venta_id = s.id
                 WHERE s.id = :sale_id AND d.id = :detail_id'
                 . $this->bloqueoFilas()
            );
            $consultaDetalle->execute(['sale_id' => $idVenta, 'detail_id' => $idDetalle]);
            $detalleVenta = $consultaDetalle->fetch();
            if ($detalleVenta === false || $detalleVenta['estado'] !== 'activa'
                || (int) $detalleVenta['creado_por'] !== $idUsuario
            ) {
                throw new RuntimeException('Solo puedes reportar una línea activa de una venta que registraste.');
            }

            $buscarPendiente = $this->baseDatos->prepare(
                "SELECT 1 FROM reportes_ventas
                 WHERE detalle_venta_id = :detail_id AND estado = 'pendiente' LIMIT 1"
            );
            $buscarPendiente->execute(['detail_id' => $idDetalle]);
            if ($buscarPendiente->fetchColumn() !== false) {
                throw new RuntimeException('Esta línea ya tiene un reporte pendiente para el administrador.');
            }

            $insertar = $this->baseDatos->prepare(
                "INSERT INTO reportes_ventas
                    (venta_id, detalle_venta_id, reportado_por, motivo, detalle)
                 VALUES (:sale_id, :detail_id, :reporter_id, :reason, :details)"
            );
            $insertar->execute([
                'sale_id' => $idVenta,
                'detail_id' => $idDetalle,
                'reporter_id' => $idUsuario,
                'reason' => $motivo,
                'details' => $detalle,
            ]);
            $this->baseDatos->commit();
        } catch (Throwable $exception) {
            if ($this->baseDatos->inTransaction()) {
                $this->baseDatos->rollBack();
            }
            throw $exception;
        }
    }

    public function corregirDetalleVenta(
        int $idDetalle,
        int $idProducto,
        int $cantidad,
        int $idAdministrador,
        string $motivo
    ): void {
        $this->baseDatos->beginTransaction();
        try {
            $consultaDetalle = $this->baseDatos->prepare(
                "SELECT d.venta_id, d.producto_id, d.cantidad, d.precio_unitario_centavos,
                        s.almacen_codigo, s.numero_venta, s.fecha_venta
                 FROM detalle_ventas d
                 JOIN ventas s ON s.id = d.venta_id
                 WHERE d.id = :detail_id AND d.estado = 'activa'"
                 . $this->bloqueoFilas()
            );
            $consultaDetalle->execute(['detail_id' => $idDetalle]);
            $detalleAnterior = $consultaDetalle->fetch();
            if ($detalleAnterior === false) {
                throw new RuntimeException('La línea de venta ya no está activa o no existe.');
            }

            $idVenta = (int) $detalleAnterior['venta_id'];
            $idProductoAnterior = (int) $detalleAnterior['producto_id'];
            $cantidadAnterior = (int) $detalleAnterior['cantidad'];
            $almacen = (string) $detalleAnterior['almacen_codigo'];
            $buscarProducto = $this->baseDatos->prepare(
                'SELECT p.id, p.nombre AS name, p.codigo_sku AS sku, p.marca AS brand,
                        p.medida AS size, p.categoria AS category,
                        p.precio_unitario_centavos AS unit_price_cents, i.cantidad AS stock
                 FROM productos p
                 JOIN inventario i ON i.producto_id = p.id AND i.almacen_codigo = :warehouse
                 WHERE p.id = :product_id' . $this->bloqueoFilas()
            );
            $buscarProducto->execute(['warehouse' => $almacen, 'product_id' => $idProducto]);
            $productoNuevo = $buscarProducto->fetch();
            if ($productoNuevo === false) {
                throw new RuntimeException('El producto correcto no tiene inventario configurado en este almacén.');
            }

            $stockNuevo = (int) $productoNuevo['stock'];
            if ($idProducto === $idProductoAnterior) {
                $stockFinalAnterior = $stockNuevo + $cantidadAnterior - $cantidad;
                if ($stockFinalAnterior < 0) {
                    throw new RuntimeException('No hay suficiente stock para aumentar la cantidad de esta venta.');
                }
                if ($stockFinalAnterior !== $stockNuevo) {
                    $actualizarStock = $this->baseDatos->prepare(
                        'UPDATE inventario SET cantidad = :quantity, actualizado_en = CURRENT_TIMESTAMP
                         WHERE producto_id = :product_id AND almacen_codigo = :warehouse'
                    );
                    $actualizarStock->execute([
                        'quantity' => $stockFinalAnterior,
                        'product_id' => $idProducto,
                        'warehouse' => $almacen,
                    ]);
                }
            } else {
                $restaurarStock = $this->baseDatos->prepare(
                    'UPDATE inventario SET cantidad = cantidad + :quantity,
                            actualizado_en = CURRENT_TIMESTAMP
                     WHERE producto_id = :product_id AND almacen_codigo = :warehouse'
                );
                $restaurarStock->execute([
                    'quantity' => $cantidadAnterior,
                    'product_id' => $idProductoAnterior,
                    'warehouse' => $almacen,
                ]);
                if ($restaurarStock->rowCount() !== 1) {
                    throw new RuntimeException('No se pudo devolver el stock del producto anterior.');
                }
                if ($stockNuevo < $cantidad) {
                    throw new RuntimeException('Stock insuficiente del producto correcto. Disponible: ' . $stockNuevo . '.');
                }
                $descontarStock = $this->baseDatos->prepare(
                    'UPDATE inventario SET cantidad = cantidad - :quantity,
                            actualizado_en = CURRENT_TIMESTAMP
                     WHERE producto_id = :product_id AND almacen_codigo = :warehouse
                       AND cantidad >= :required_quantity'
                );
                $descontarStock->execute([
                    'quantity' => $cantidad,
                    'product_id' => $idProducto,
                    'warehouse' => $almacen,
                    'required_quantity' => $cantidad,
                ]);
                if ($descontarStock->rowCount() !== 1) {
                    throw new RuntimeException('El stock cambió mientras corregías la venta. Vuelve a intentarlo.');
                }
            }

            $precioCentavos = $idProducto === $idProductoAnterior
                ? (int) $detalleAnterior['precio_unitario_centavos']
                : (int) $productoNuevo['unit_price_cents'];
            $actualizarDetalle = $this->baseDatos->prepare(
                'UPDATE detalle_ventas
                 SET producto_id = :product_id, cantidad = :quantity,
                     precio_unitario_centavos = :unit_price, total_linea_centavos = :line_total
                 WHERE id = :detail_id AND estado = \'activa\''
            );
            $actualizarDetalle->execute([
                'product_id' => $idProducto,
                'quantity' => $cantidad,
                'unit_price' => $precioCentavos,
                'line_total' => $precioCentavos * $cantidad,
                'detail_id' => $idDetalle,
            ]);

            $insertarMovimiento = $this->baseDatos->prepare(
                'INSERT INTO movimientos_inventario
                    (producto_id, almacen_codigo, tipo_movimiento, cambio_cantidad,
                     tipo_referencia, referencia_id, creado_por, notas)
                 VALUES (:product_id, :warehouse, \'adjustment\', :change,
                         \'sale_correction\', :sale_id, :user_id, :notes)'
            );
            if ($idProducto === $idProductoAnterior) {
                $cambioStock = $cantidadAnterior - $cantidad;
                if ($cambioStock !== 0) {
                    $insertarMovimiento->execute([
                        'product_id' => $idProducto,
                        'warehouse' => $almacen,
                        'change' => $cambioStock,
                        'sale_id' => $idVenta,
                        'user_id' => $idAdministrador,
                        'notes' => 'Corrección de ' . $detalleAnterior['numero_venta'] . ': ' . $motivo,
                    ]);
                }
            } else {
                $insertarMovimiento->execute([
                    'product_id' => $idProductoAnterior,
                    'warehouse' => $almacen,
                    'change' => $cantidadAnterior,
                    'sale_id' => $idVenta,
                    'user_id' => $idAdministrador,
                    'notes' => 'Devolución por corrección de ' . $detalleAnterior['numero_venta'] . ': ' . $motivo,
                ]);
                $insertarMovimiento->execute([
                    'product_id' => $idProducto,
                    'warehouse' => $almacen,
                    'change' => -$cantidad,
                    'sale_id' => $idVenta,
                    'user_id' => $idAdministrador,
                    'notes' => 'Nueva salida por corrección de ' . $detalleAnterior['numero_venta'] . ': ' . $motivo,
                ]);
            }

            $hashHistorico = hash('sha256', 'manual-sale-detail:' . $idDetalle);
            $actualizarHistorico = $this->baseDatos->prepare(
                "UPDATE historico_ventas_excel
                 SET codigo = :sku, marca = :brand, medida = :size, fecha_venta = :sale_date,
                     cantidad = :quantity
                 WHERE archivo_hash = :file_hash AND hoja = 'MANUAL' AND fila_origen = :detail_id"
            );
            $actualizarHistorico->execute([
                'sku' => $productoNuevo['sku'],
                'brand' => $productoNuevo['brand'],
                'size' => $productoNuevo['size'],
                'sale_date' => $detalleAnterior['fecha_venta'],
                'quantity' => $cantidad,
                'file_hash' => $hashHistorico,
                'detail_id' => $idDetalle,
            ]);

            $this->sincronizarUnidadesVenta(
                $idVenta,
                $almacen,
                [$idProductoAnterior, $idProducto]
            );
            $this->resolverReportesDetalle(
                $idDetalle,
                $idAdministrador,
                'Venta corregida por el administrador: ' . $motivo
            );
            $this->baseDatos->commit();
        } catch (Throwable $exception) {
            if ($this->baseDatos->inTransaction()) {
                $this->baseDatos->rollBack();
            }
            throw $exception;
        }
    }

    public function anularDetalleVenta(int $idDetalle, int $idAdministrador, string $motivo): void
    {
        $this->baseDatos->beginTransaction();
        try {
            $consultaDetalle = $this->baseDatos->prepare(
                "SELECT d.venta_id, d.producto_id, d.cantidad, s.almacen_codigo, s.numero_venta
                 FROM detalle_ventas d
                 JOIN ventas s ON s.id = d.venta_id
                 WHERE d.id = :detail_id AND d.estado = 'activa'"
                 . $this->bloqueoFilas()
            );
            $consultaDetalle->execute(['detail_id' => $idDetalle]);
            $detalleVenta = $consultaDetalle->fetch();
            if ($detalleVenta === false) {
                throw new RuntimeException('La línea de venta ya no está activa o no existe.');
            }

            $restaurarStock = $this->baseDatos->prepare(
                'UPDATE inventario SET cantidad = cantidad + :quantity, actualizado_en = CURRENT_TIMESTAMP
                 WHERE producto_id = :product_id AND almacen_codigo = :warehouse'
            );
            $restaurarStock->execute([
                'quantity' => (int) $detalleVenta['cantidad'],
                'product_id' => (int) $detalleVenta['producto_id'],
                'warehouse' => (string) $detalleVenta['almacen_codigo'],
            ]);
            if ($restaurarStock->rowCount() !== 1) {
                throw new RuntimeException('No se pudo devolver el stock al almacén.');
            }

            $anular = $this->baseDatos->prepare(
                "UPDATE detalle_ventas
                 SET estado = 'anulada', anulada_en = CURRENT_TIMESTAMP,
                     anulada_por = :admin_id, motivo_anulacion = :reason
                 WHERE id = :detail_id AND estado = 'activa'"
            );
            $anular->execute([
                'admin_id' => $idAdministrador,
                'reason' => $motivo,
                'detail_id' => $idDetalle,
            ]);
            if ($anular->rowCount() !== 1) {
                throw new RuntimeException('La línea cambió mientras se procesaba la anulación.');
            }

            $movimiento = $this->baseDatos->prepare(
                'INSERT INTO movimientos_inventario
                    (producto_id, almacen_codigo, tipo_movimiento, cambio_cantidad,
                     tipo_referencia, referencia_id, creado_por, notas)
                 VALUES (:product_id, :warehouse, \'adjustment\', :change,
                         \'sale_cancellation\', :sale_id, :user_id, :notes)'
            );
            $movimiento->execute([
                'product_id' => (int) $detalleVenta['producto_id'],
                'warehouse' => (string) $detalleVenta['almacen_codigo'],
                'change' => (int) $detalleVenta['cantidad'],
                'sale_id' => (int) $detalleVenta['venta_id'],
                'user_id' => $idAdministrador,
                'notes' => 'Anulación de ' . $detalleVenta['numero_venta'] . ': ' . $motivo,
            ]);

            $hashHistorico = hash('sha256', 'manual-sale-detail:' . $idDetalle);
            $eliminarHistorico = $this->baseDatos->prepare(
                "DELETE FROM historico_ventas_excel
                 WHERE archivo_hash = :file_hash AND hoja = 'MANUAL' AND fila_origen = :detail_id"
            );
            $eliminarHistorico->execute(['file_hash' => $hashHistorico, 'detail_id' => $idDetalle]);

            $this->sincronizarUnidadesVenta(
                (int) $detalleVenta['venta_id'],
                (string) $detalleVenta['almacen_codigo'],
                [(int) $detalleVenta['producto_id']]
            );
            $this->resolverReportesDetalle(
                $idDetalle,
                $idAdministrador,
                'Línea anulada por el administrador: ' . $motivo
            );
            $this->baseDatos->commit();
        } catch (Throwable $exception) {
            if ($this->baseDatos->inTransaction()) {
                $this->baseDatos->rollBack();
            }
            throw $exception;
        }
    }

    public function anularVentaCompleta(int $idVenta, int $idAdministrador, string $motivo): void
    {
        $this->baseDatos->beginTransaction();
        try {
            $consultaDetalle = $this->baseDatos->prepare(
                "SELECT d.id AS detail_id, d.producto_id, d.cantidad,
                        s.almacen_codigo, s.numero_venta
                 FROM ventas s
                 JOIN detalle_ventas d ON d.venta_id = s.id
                 WHERE s.id = :sale_id AND d.estado = 'activa'
                 ORDER BY d.producto_id, d.id" . $this->bloqueoFilas()
            );
            $consultaDetalle->execute(['sale_id' => $idVenta]);
            $lineas = $consultaDetalle->fetchAll();
            if ($lineas === []) {
                throw new RuntimeException('La venta ya no tiene líneas activas o no existe.');
            }

            $almacen = (string) $lineas[0]['almacen_codigo'];
            $numeroVenta = (string) $lineas[0]['numero_venta'];
            $cantidadesPorProducto = [];
            $idsDetalle = [];
            $idsProducto = [];
            foreach ($lineas as $linea) {
                $idProducto = (int) $linea['producto_id'];
                $cantidadesPorProducto[$idProducto] = ($cantidadesPorProducto[$idProducto] ?? 0)
                    + (int) $linea['cantidad'];
                $idsDetalle[] = (int) $linea['detail_id'];
                $idsProducto[] = $idProducto;
            }
            ksort($cantidadesPorProducto, SORT_NUMERIC);

            $restaurarStock = $this->baseDatos->prepare(
                'UPDATE inventario SET cantidad = cantidad + :quantity, actualizado_en = CURRENT_TIMESTAMP
                 WHERE producto_id = :product_id AND almacen_codigo = :warehouse'
            );
            $movimiento = $this->baseDatos->prepare(
                'INSERT INTO movimientos_inventario
                    (producto_id, almacen_codigo, tipo_movimiento, cambio_cantidad,
                     tipo_referencia, referencia_id, creado_por, notas)
                 VALUES (:product_id, :warehouse, \'adjustment\', :change,
                         \'sale_cancellation\', :sale_id, :user_id, :notes)'
            );
            foreach ($cantidadesPorProducto as $idProducto => $cantidad) {
                $restaurarStock->execute([
                    'quantity' => $cantidad,
                    'product_id' => $idProducto,
                    'warehouse' => $almacen,
                ]);
                if ($restaurarStock->rowCount() !== 1) {
                    throw new RuntimeException('No se pudo devolver el stock de todos los productos.');
                }
                $movimiento->execute([
                    'product_id' => $idProducto,
                    'warehouse' => $almacen,
                    'change' => $cantidad,
                    'sale_id' => $idVenta,
                    'user_id' => $idAdministrador,
                    'notes' => 'Anulación de ' . $numeroVenta . ': ' . $motivo,
                ]);
            }

            $anular = $this->baseDatos->prepare(
                "UPDATE detalle_ventas
                 SET estado = 'anulada', anulada_en = CURRENT_TIMESTAMP,
                     anulada_por = :admin_id, motivo_anulacion = :reason
                 WHERE id = :detail_id AND estado = 'activa'"
            );
            $eliminarHistorico = $this->baseDatos->prepare(
                "DELETE FROM historico_ventas_excel
                 WHERE archivo_hash = :file_hash AND hoja = 'MANUAL' AND fila_origen = :detail_id"
            );
            foreach ($idsDetalle as $idDetalle) {
                $anular->execute([
                    'admin_id' => $idAdministrador,
                    'reason' => $motivo,
                    'detail_id' => $idDetalle,
                ]);
                if ($anular->rowCount() !== 1) {
                    throw new RuntimeException('Una línea cambió mientras se procesaba la anulación.');
                }
                $eliminarHistorico->execute([
                    'file_hash' => hash('sha256', 'manual-sale-detail:' . $idDetalle),
                    'detail_id' => $idDetalle,
                ]);
            }

            $this->sincronizarUnidadesVenta($idVenta, $almacen, $idsProducto);
            $resolverReportes = $this->baseDatos->prepare(
                "UPDATE reportes_ventas
                 SET estado = 'resuelto', revisado_por = :admin_id,
                     revisado_en = CURRENT_TIMESTAMP, notas_revision = :note
                 WHERE venta_id = :sale_id AND estado = 'pendiente'"
            );
            $resolverReportes->execute([
                'admin_id' => $idAdministrador,
                'note' => 'Venta anulada por el administrador: ' . $motivo,
                'sale_id' => $idVenta,
            ]);
            $this->baseDatos->commit();
        } catch (Throwable $exception) {
            if ($this->baseDatos->inTransaction()) {
                $this->baseDatos->rollBack();
            }
            throw $exception;
        }
    }

    public function cerrarReporteVenta(int $idReporte, int $idAdministrador, string $nota): void
    {
        $consulta = $this->baseDatos->prepare(
            "UPDATE reportes_ventas
             SET estado = 'cerrado', revisado_por = :admin_id,
                 revisado_en = CURRENT_TIMESTAMP, notas_revision = :note
             WHERE id = :report_id AND estado = 'pendiente'"
        );
        $consulta->execute([
            'admin_id' => $idAdministrador,
            'note' => $nota,
            'report_id' => $idReporte,
        ]);
        if ($consulta->rowCount() !== 1) {
            throw new RuntimeException('El reporte ya fue revisado o no existe.');
        }
    }

    private function sincronizarUnidadesVenta(int $idVenta, string $almacen, array $idProductos): void
    {
        $idProductos = array_values(array_unique(array_map('intval', $idProductos)));
        $liberar = $this->baseDatos->prepare(
            "UPDATE inventario_unidades
             SET estado = 'disponible', venta_id = NULL
             WHERE venta_id = :sale_id AND producto_id = :product_id AND estado = 'vendida'"
        );
        $categoriaProducto = $this->baseDatos->prepare('SELECT categoria FROM productos WHERE id = :product_id');
        $cantidadVendida = $this->baseDatos->prepare(
            "SELECT COALESCE(SUM(cantidad), 0)
             FROM detalle_ventas
             WHERE venta_id = :sale_id AND producto_id = :product_id AND estado = 'activa'"
        );
        $marcarVendida = $this->baseDatos->prepare(
            "UPDATE inventario_unidades SET estado = 'vendida', venta_id = :sale_id
             WHERE id = :unit_id AND estado = 'disponible'"
        );

        foreach ($idProductos as $idProducto) {
            $liberar->execute(['sale_id' => $idVenta, 'product_id' => $idProducto]);
            $categoriaProducto->execute(['product_id' => $idProducto]);
            if (!InventarioUnidades::esLlanta(['category' => $categoriaProducto->fetchColumn()])) {
                continue;
            }

            $cantidadVendida->execute(['sale_id' => $idVenta, 'product_id' => $idProducto]);
            $cantidadNecesaria = (int) $cantidadVendida->fetchColumn();
            if ($cantidadNecesaria === 0) {
                continue;
            }
            $seleccionar = $this->baseDatos->prepare(
                "SELECT id FROM inventario_unidades
                 WHERE producto_id = :product_id AND almacen_codigo = :warehouse AND estado = 'disponible'
                 ORDER BY id LIMIT " . $cantidadNecesaria . $this->bloqueoFilas()
            );
            $seleccionar->execute(['product_id' => $idProducto, 'warehouse' => $almacen]);
            foreach ($seleccionar->fetchAll(PDO::FETCH_COLUMN) as $idUnidad) {
                $marcarVendida->execute(['sale_id' => $idVenta, 'unit_id' => (int) $idUnidad]);
                if ($marcarVendida->rowCount() !== 1) {
                    throw new RuntimeException('No se pudo actualizar el estado de una llanta de la venta.');
                }
            }
        }
    }

    private function resolverReportesDetalle(int $idDetalle, int $idAdministrador, string $nota): void
    {
        $resolver = $this->baseDatos->prepare(
            "UPDATE reportes_ventas
             SET estado = 'resuelto', revisado_por = :admin_id,
                 revisado_en = CURRENT_TIMESTAMP, notas_revision = :note
             WHERE detalle_venta_id = :detail_id AND estado = 'pendiente'"
        );
        $resolver->execute([
            'admin_id' => $idAdministrador,
            'note' => $nota,
            'detail_id' => $idDetalle,
        ]);
    }

    public function coincideConVentaImportada(array $lineas, string $almacen, string $fecha): bool
    {
        if (!in_array($almacen, ['A0101', 'A0102'], true)) {
            return false;
        }

        $consulta = $this->baseDatos->prepare(
            'SELECT 1
             FROM ventas_excel_importadas h
             JOIN productos p ON p.codigo_sku = h.codigo
             WHERE h.almacen_codigo = :warehouse
               AND h.fecha_venta = :sale_date
               AND p.id = :product
               AND h.cantidad = :quantity
             LIMIT 1'
        );
        foreach ($lineas as $linea) {
            $consulta->execute([
                'warehouse' => $almacen,
                'sale_date' => $fecha,
                'product' => $linea['product_id'],
                'quantity' => $linea['quantity'],
            ]);
            if ($consulta->fetchColumn() !== false) {
                return true;
            }
        }

        $consulta = $this->baseDatos->prepare(
            'SELECT 1
             FROM ventas s
             JOIN detalle_ventas d ON d.venta_id = s.id
             WHERE s.almacen_codigo = :warehouse
               AND s.fecha_venta = :sale_date
               AND d.estado = \'activa\'
               AND d.producto_id = :product
               AND d.cantidad = :quantity
             LIMIT 1'
        );
        foreach ($lineas as $linea) {
            $consulta->execute([
                'warehouse' => $almacen,
                'sale_date' => $fecha,
                'product' => $linea['product_id'],
                'quantity' => $linea['quantity'],
            ]);
            if ($consulta->fetchColumn() !== false) {
                return true;
            }
        }

        return false;
    }
}
