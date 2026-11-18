<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ControladorBase.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Servicios' . DIRECTORY_SEPARATOR . 'Servicios.php';


final class AccesoControlador extends ControladorBase
{
    public function mostrar(): void
    {
        $mensajeError = '';
        $correoAnterior = '';
        $rutaSolicitada = $_POST['return_to'] ?? $_GET['return_to'] ?? '';
        $rutaDespuesDeIniciarSesion = app_login_return_path(
            is_string($rutaSolicitada) ? $rutaSolicitada : ''
        );
        $baseDatos = null;
        $baseDatosDisponible = false;
        $configuracionDisponible = false;
        $cantidadUsuarios = 0;
        $usuarioActual = null;
        $modelo = null;

        try {
            $this->iniciarPagina(null, null, false);
            $baseDatos = $this->baseDatos;
            $tokenCsrf = $this->tokenCsrf;
            $modelo = new AccesoModelo($baseDatos);
            $baseDatosDisponible = true;
            $cantidadUsuarios = $modelo->contarUsuarios();
            $configuracionDisponible = $cantidadUsuarios === 0;
            if (isset($_SESSION['user_id'])) {
                $usuarioActual = $modelo->obtenerUsuarioActivo((int) $_SESSION['user_id']);
                if ($usuarioActual === null
                    || !in_array($usuarioActual['role'], ['administrador', 'vendedor', 'almacen'], true)
                ) {
                    unset($_SESSION['user_id']);
                    $usuarioActual = null;
                }
            }
        } catch (Throwable $exception) {
            error_log('No se pudo iniciar la base de datos de la aplicación: ' . $exception->getMessage());
            if (session_status() === PHP_SESSION_ACTIVE) {
                $tokenCsrf = app_csrf_token();
            }
        }

        if ($usuarioActual !== null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . ($rutaDespuesDeIniciarSesion
                ?? app_home_path_for_role((string) $usuarioActual['role'])));
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $baseDatosDisponible && $modelo instanceof AccesoModelo) {
            if (!app_csrf_is_valid((string) ($_POST['csrf_token'] ?? ''))) {
                $mensajeError = 'La sesión del formulario venció. Recarga la página e inténtalo de nuevo.';
            } else {
                $accion = (string) ($_POST['action'] ?? 'login');
                if ($accion === 'logout') {
                    $_SESSION = [];
                    session_regenerate_id(true);
                    header('Location: index.php?pagina=login');
                    exit;
                }
                if ($accion === 'setup' && $configuracionDisponible) {
                    $nombre = trim((string) ($_POST['full_name'] ?? ''));
                    $correo = strtolower(trim((string) ($_POST['email'] ?? '')));
                    $contrasena = (string) ($_POST['password'] ?? '');
                    $confirmacion = (string) ($_POST['password_confirmation'] ?? '');
                    if ($nombre === '' || text_length($nombre) > 120) {
                        $mensajeError = 'Escribe tu nombre (máximo 120 caracteres).';
                    } elseif (filter_var($correo, FILTER_VALIDATE_EMAIL) === false) {
                        $mensajeError = 'Escribe un correo electrónico válido.';
                    } elseif (text_length($contrasena) < 12) {
                        $mensajeError = 'La contraseña debe tener al menos 12 caracteres.';
                    } elseif (!hash_equals($contrasena, $confirmacion)) {
                        $mensajeError = 'Las contraseñas no coinciden.';
                    } else {
                        try {
                            $idUsuario = $modelo->crearAdministradorInicial($nombre, $correo, $contrasena);
                            session_regenerate_id(true);
                            $_SESSION['user_id'] = $idUsuario;
                            header('Location: index.php?pagina=home');
                            exit;
                        } catch (Throwable $exception) {
                            error_log('No se pudo crear el administrador inicial: ' . $exception->getMessage());
                            $mensajeError = 'No se pudo crear la cuenta inicial. Verifica los datos y vuelve a intentarlo.';
                        }
                    }
                } elseif ($accion === 'login' && $cantidadUsuarios > 0) {
                    $correo = strtolower(trim((string) ($_POST['email'] ?? '')));
                    $contrasena = (string) ($_POST['password'] ?? '');
                    $correoAnterior = $correo;
                    if (filter_var($correo, FILTER_VALIDATE_EMAIL) === false || $contrasena === '') {
                        $mensajeError = 'El correo o la contraseña no son correctos.';
                    } else {
                        try {
                            $resultado = $modelo->autenticar(
                                $correo,
                                $contrasena,
                                (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')
                            );
                            if ($resultado['estado'] === 'bloqueada') {
                                $mensajeError = 'No se pudo iniciar sesión. Espera 15 minutos e inténtalo de nuevo.';
                            } elseif ($resultado['estado'] === 'correcta') {
                                session_regenerate_id(true);
                                $_SESSION['user_id'] = $resultado['id'];
                                header('Location: ' . ($rutaDespuesDeIniciarSesion
                                    ?? app_home_path_for_role($resultado['rol'])));
                                exit;
                            } else {
                                $mensajeError = 'El correo o la contraseña no son correctos.';
                            }
                        } catch (Throwable $exception) {
                            error_log('No se pudo iniciar sesión: ' . $exception->getMessage());
                            $mensajeError = 'No se pudo iniciar sesión. Inténtalo de nuevo.';
                        }
                    }
                } else {
                    $mensajeError = 'La acción solicitada no es válida.';
                }
            }
        }

        $mostrarConfiguracion = $baseDatosDisponible && $cantidadUsuarios === 0 && $configuracionDisponible;
        $mostrarAcceso = $baseDatosDisponible && $cantidadUsuarios > 0 && $usuarioActual === null;
        $this->mostrarVista('login', [
            'error' => $mensajeError,
            'oldEmail' => $correoAnterior,
            'databaseReady' => $baseDatosDisponible,
            'setupReady' => $mostrarConfiguracion,
            'userCount' => $cantidadUsuarios,
            'currentUser' => $usuarioActual,
            'showSetup' => $mostrarConfiguracion,
            'showLogin' => $mostrarAcceso,
            'returnTo' => $rutaDespuesDeIniciarSesion,
        ]);
    }
}


final class ComprasControlador extends ControladorBase
{
    public function mostrar(): void
    {
        $this->iniciarPagina(['administrador', 'almacen']);
        $baseDatos = $this->baseDatos;
        $usuarioActual = $this->usuarioActual;
        $tokenCsrf = $this->tokenCsrf;
        $modelo = new ComprasModelo($baseDatos);
        $mensajeError = '';
        $mensajeExito = (string) ($_SESSION['purchase_flash'] ?? '');
        $mensajeVozCompra = (string) ($_SESSION['purchase_voice_flash'] ?? '');
        $cambiosStockCompra = is_array($_SESSION['purchase_stock_changes'] ?? null)
            ? $_SESSION['purchase_stock_changes']
            : [];
        unset($_SESSION['purchase_flash'], $_SESSION['purchase_voice_flash'], $_SESSION['purchase_stock_changes']);
        $valoresFormulario = [
            'supplier' => '', 'supplier_document' => '', 'warehouse_code' => 'A0101',
            'purchase_date' => date('Y-m-d'), 'notes' => '', 'product_ids' => [''],
            'quantities' => ['1'], 'unit_costs' => [''], 'factory_barcodes' => [''],
        ];

        if ($_SERVER['REQUEST_METHOD'] === 'POST'
            && (string) ($_POST['action'] ?? '') === 'cancel_purchase'
        ) {
            $idCompra = filter_var($_POST['purchase_id'] ?? null, FILTER_VALIDATE_INT);
            $motivoAnulacion = trim((string) ($_POST['cancellation_reason'] ?? ''));
            if ($usuarioActual['role'] !== 'administrador') {
                $mensajeError = 'Solo el administrador puede anular compras.';
            } elseif (!app_csrf_is_valid((string) ($_POST['csrf_token'] ?? ''))) {
                $mensajeError = 'El formulario venció. Recarga la página e inténtalo de nuevo.';
            } elseif ($idCompra === false || $idCompra < 1
                || (string) ($_POST['confirm_cancellation'] ?? '') !== '1'
                || $motivoAnulacion === '' || text_length($motivoAnulacion) > 500
            ) {
                $mensajeError = 'Confirma la anulación e indica un motivo de hasta 500 caracteres.';
            } else {
                try {
                    $resultado = $modelo->anularCompra(
                        $idCompra,
                        (int) $usuarioActual['id'],
                        $motivoAnulacion
                    );
                    $_SESSION['purchase_flash'] = 'Compra ' . $resultado['numero']
                        . ' anulada. Se revirtió el stock y el movimiento de ajuste quedó registrado.';
                    $_SESSION['purchase_voice_flash'] = 'Compra anulada. Stock revertido.';
                    header('Location: index.php?pagina=compras');
                    exit;
                } catch (RuntimeException $exception) {
                    $mensajeError = $exception->getMessage();
                } catch (Throwable $exception) {
                    error_log('No se pudo anular la compra: ' . $exception->getMessage());
                    $mensajeError = 'No se pudo anular la compra. No se modificaron las existencias.';
                }
            }
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            foreach (['supplier', 'supplier_document', 'warehouse_code', 'purchase_date', 'notes'] as $campo) {
                $valoresFormulario[$campo] = trim((string) ($_POST[$campo] ?? ''));
            }
            foreach (['product_ids', 'quantities', 'unit_costs', 'factory_barcodes'] as $campo) {
                $valoresFormulario[$campo] = array_values(array_map(
                    static fn ($value): string => trim((string) $value),
                    (array) ($_POST[$campo] ?? [])
                ));
            }

            $lineasCompra = [];
            $lineaInvalida = count($valoresFormulario['product_ids']) !== count($valoresFormulario['quantities'])
                || count($valoresFormulario['product_ids']) !== count($valoresFormulario['unit_costs'])
                || count($valoresFormulario['product_ids']) > 30
                || count($valoresFormulario['factory_barcodes']) > count($valoresFormulario['product_ids']);
            foreach ($valoresFormulario['product_ids'] as $indice => $productoEntrada) {
                $cantidadEntrada = $valoresFormulario['quantities'][$indice] ?? '';
                $costoEntrada = $valoresFormulario['unit_costs'][$indice] ?? '';
                $codigosFabricante = array_values(array_filter(
                    preg_split('/\R/u', $valoresFormulario['factory_barcodes'][$indice] ?? '') ?: [],
                    static fn (string $codigo): bool => trim($codigo) !== ''
                ));
                if ($productoEntrada === '' && in_array($cantidadEntrada, ['', '1'], true)
                    && $costoEntrada === '' && $codigosFabricante === []
                ) {
                    continue;
                }
                $idProducto = filter_var($productoEntrada, FILTER_VALIDATE_INT);
                $cantidad = filter_var($cantidadEntrada, FILTER_VALIDATE_INT);
                if ($idProducto === false || $idProducto < 1
                    || $cantidad === false || $cantidad < 1 || $cantidad > 1_000_000
                    || count($codigosFabricante) > $cantidad
                    || !preg_match('/^\d{1,10}(?:[.,]\d{1,2})?$/', $costoEntrada)
                ) {
                    $lineaInvalida = true;
                    continue;
                }
                $partesCosto = preg_split('/[.,]/', $costoEntrada, 2);
                $costoCentavos = ((int) $partesCosto[0] * 100) + (int) str_pad($partesCosto[1] ?? '', 2, '0');
                if ($costoCentavos < 1 || $costoCentavos > intdiv(PHP_INT_MAX, $cantidad)) {
                    $lineaInvalida = true;
                    continue;
                }
                $lineasCompra[] = [
                    'product_id' => $idProducto,
                    'quantity' => $cantidad,
                    'unit_cost_cents' => $costoCentavos,
                    'factory_barcodes' => $codigosFabricante,
                ];
            }

            $fechaCompra = DateTimeImmutable::createFromFormat('!Y-m-d', $valoresFormulario['purchase_date']);
            $almacenesValidos = array_column($modelo->obtenerAlmacenes(), 'code');
            if (!app_csrf_is_valid((string) ($_POST['csrf_token'] ?? ''))) {
                $mensajeError = 'El formulario venció. Recarga la página e inténtalo de nuevo.';
            } elseif (!in_array((string) ($_POST['action'] ?? ''), ['create_purchase', 'update_purchase'], true)) {
                $mensajeError = 'La acción solicitada no es válida.';
            } elseif (($_POST['action'] ?? '') === 'update_purchase'
                && ($usuarioActual['role'] !== 'administrador'
                    || filter_var($_POST['purchase_id'] ?? null, FILTER_VALIDATE_INT) === false
                    || (int) $_POST['purchase_id'] < 1)
            ) {
                $mensajeError = 'Solo el administrador puede editar una compra válida.';
            } elseif ($valoresFormulario['supplier'] === '' || text_length($valoresFormulario['supplier']) > 200) {
                $mensajeError = 'Escribe el nombre del proveedor (máximo 200 caracteres).';
            } elseif ($valoresFormulario['supplier_document'] === ''
                || text_length($valoresFormulario['supplier_document']) > 120
            ) {
                $mensajeError = 'Escribe la factura o el documento de la compra (máximo 120 caracteres).';
            } elseif (text_length($valoresFormulario['supplier_document']) > 120 || text_length($valoresFormulario['notes']) > 500) {
                $mensajeError = 'El documento del proveedor o las notas superan el límite permitido.';
            } elseif ($lineasCompra === [] || $lineaInvalida) {
                $mensajeError = 'Agrega al menos un producto con cantidad y costo unitario válidos.';
            } elseif (!$fechaCompra || $fechaCompra->format('Y-m-d') !== $valoresFormulario['purchase_date']) {
                $mensajeError = 'Selecciona una fecha válida.';
            } elseif (!in_array($valoresFormulario['warehouse_code'], $almacenesValidos, true)) {
                $mensajeError = 'Selecciona un almacén válido.';
            } else {
                try {
                    if ((string) $_POST['action'] === 'update_purchase') {
                        $modelo->actualizarCompra(
                            (int) $_POST['purchase_id'],
                            $lineasCompra,
                            $valoresFormulario['supplier'],
                            $valoresFormulario['supplier_document'],
                            $valoresFormulario['purchase_date'],
                            $valoresFormulario['notes'],
                            (int) $usuarioActual['id']
                        );
                        $_SESSION['purchase_flash'] = 'Compra actualizada. Los cambios de existencias quedaron registrados en Movimientos.';
                    } else {
                        $resultado = $modelo->registrarCompra(
                            $lineasCompra,
                            $valoresFormulario['supplier'],
                            $valoresFormulario['supplier_document'],
                            $valoresFormulario['warehouse_code'],
                            $valoresFormulario['purchase_date'],
                            (int) $usuarioActual['id'],
                            $valoresFormulario['notes']
                        );
                        $_SESSION['purchase_flash'] = 'Compra ' . $resultado['numero'] . ' registrada. Se recibieron '
                            . $resultado['unidades'] . ' unidades en ' . $valoresFormulario['warehouse_code'] . '.';
                        $_SESSION['purchase_voice_flash'] = 'Compra registrada. Ingresaron '
                            . $resultado['unidades'] . ' unidades a ' . $valoresFormulario['warehouse_code'] . '.';
                        $_SESSION['purchase_stock_changes'] = [
                            'purchase_number' => $resultado['numero'],
                            'warehouse_code' => $valoresFormulario['warehouse_code'],
                            'items' => $resultado['stock_changes'],
                        ];
                    }
                    header('Location: index.php?pagina=compras');
                    exit;
                } catch (RuntimeException $exception) {
                    $mensajeError = $exception->getMessage();
                } catch (Throwable $exception) {
                    error_log('No se pudo registrar la compra: ' . $exception->getMessage());
                    $mensajeError = 'No se pudo registrar la compra. No se modificó el stock; revisa los datos e inténtalo otra vez.';
                }
            }
        }

        $productos = $modelo->obtenerProductos();
        $almacenes = $modelo->obtenerAlmacenes();
        $editandoCompra = (string) ($_GET['view'] ?? '') === 'edit';
        $compraEnEdicion = null;
        if ($editandoCompra) {
            $idCompra = filter_var(
                $_GET['purchase_id'] ?? $_POST['purchase_id'] ?? null,
                FILTER_VALIDATE_INT
            );
            if ($usuarioActual['role'] !== 'administrador') {
                $mensajeError = 'Solo el administrador puede editar compras.';
                $editandoCompra = false;
            } elseif ($idCompra === false || $idCompra < 1
                || ($compraEnEdicion = $modelo->obtenerCompraParaEditar($idCompra)) === null
            ) {
                $mensajeError = 'No se encontró una compra activa para editar.';
                $editandoCompra = false;
            } elseif ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                $valoresFormulario['supplier'] = (string) $compraEnEdicion['supplier'];
                $valoresFormulario['supplier_document'] = (string) $compraEnEdicion['supplier_document'];
                $valoresFormulario['warehouse_code'] = (string) $compraEnEdicion['warehouse_code'];
                $valoresFormulario['purchase_date'] = (string) $compraEnEdicion['purchase_date'];
                $valoresFormulario['notes'] = (string) $compraEnEdicion['notes'];
                $valoresFormulario['product_ids'] = array_map(
                    static fn (array $item): string => (string) $item['product_id'],
                    $compraEnEdicion['items']
                );
                $valoresFormulario['quantities'] = array_map(
                    static fn (array $item): string => (string) $item['quantity'],
                    $compraEnEdicion['items']
                );
                $valoresFormulario['unit_costs'] = array_map(
                    static fn (array $item): string => number_format((int) $item['unit_cost_cents'] / 100, 2, '.', ''),
                    $compraEnEdicion['items']
                );
            }
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' && (string) ($_GET['view'] ?? '') === 'new') {
            $almacenesDisponibles = array_column($almacenes, 'code');
            $almacenSugerido = strtoupper(trim((string) ($_GET['warehouse_code'] ?? '')));
            if (in_array($almacenSugerido, $almacenesDisponibles, true)) {
                $valoresFormulario['warehouse_code'] = $almacenSugerido;
            }
            $productoSugerido = filter_var($_GET['product_id'] ?? null, FILTER_VALIDATE_INT);
            $productosDisponibles = array_map('intval', array_column($productos, 'id'));
            if ($productoSugerido !== false && in_array($productoSugerido, $productosDisponibles, true)) {
                $valoresFormulario['product_ids'] = [(string) $productoSugerido];
            }
        }
        $incluirComprasAnuladas = (string) ($_GET['include_cancelled'] ?? '') === '1';
        $almacenHistorial = strtoupper(trim((string) ($_GET['warehouse_code'] ?? '')));
        $codigosAlmacenes = array_column($almacenes, 'code');
        if ($almacenHistorial !== '' && !in_array($almacenHistorial, $codigosAlmacenes, true)) {
            $mensajeError = 'Selecciona un almacén válido para filtrar el historial.';
            $almacenHistorial = '';
        }
        $historial = $modelo->obtenerHistorial($incluirComprasAnuladas, $almacenHistorial);
        $compras = $historial['compras'];
        $detallesPorCompra = $historial['detalles'];
        $auditoriaPorCompra = $usuarioActual['role'] === 'administrador'
            ? $modelo->obtenerAuditoria(array_map(
                static fn (array $compra): int => (int) $compra['id'],
                $compras
            ))
            : [];
        $mostrarFormulario = (string) ($_GET['view'] ?? '') === 'new'
            || $editandoCompra
            || ($mensajeError !== '' && (string) ($_GET['view'] ?? '') !== 'edit');

        $this->mostrarVista('compras', [
            'products' => $productos,
            'warehouses' => $almacenes,
            'errorMessage' => $mensajeError,
            'successMessage' => $mensajeExito,
            'purchaseVoiceMessage' => $mensajeVozCompra,
            'purchaseStockChanges' => $cambiosStockCompra,
            'formValues' => $valoresFormulario,
            'purchaseItemsById' => $detallesPorCompra,
            'purchaseAuditById' => $auditoriaPorCompra,
            'purchases' => $compras,
            'showPurchaseForm' => $mostrarFormulario,
            'editingPurchase' => $editandoCompra,
            'purchaseBeingEdited' => $compraEnEdicion,
            'includeCancelledPurchases' => $incluirComprasAnuladas,
            'purchaseHistoryWarehouse' => $almacenHistorial,
            'isPurchaseAdministrator' => $usuarioActual['role'] === 'administrador',
        ], true);
    }
}


final class EtiquetasInventarioControlador extends ControladorBase
{
    private const MAX_ETIQUETAS_POR_IMPRESION = 500;

    private function urlEscaneoMovil(string $codigoBarras, string $codigoAlmacen, bool $conteoFisico): string
    {
        $parametros = [
            'pagina' => 'inventario',
            'warehouse' => $codigoAlmacen,
            'barcode' => $codigoBarras,
        ];
        if ($conteoFisico) {
            $parametros['view'] = 'count';
            $parametros['count_scan'] = '1';
        } else {
            $parametros['scan'] = '1';
        }

        return InventoryMobileUrl::forCurrentApp($parametros) ?? $codigoBarras;
    }

    public function mostrar(): void
    {
        $this->iniciarPagina(
            ['administrador', 'almacen'],
            "default-src 'self'; style-src 'self'; script-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'"
        );
        $baseDatos = $this->baseDatos;
        $idProducto = filter_var($_GET['product_id'] ?? null, FILTER_VALIDATE_INT);
        $idProductoOperativo = filter_var($_GET['operational_product_id'] ?? null, FILTER_VALIDATE_INT);
        $codigoAlmacen = trim((string) ($_GET['warehouse'] ?? ''));
        $esProductoOperativo = $idProductoOperativo !== false
            && $idProductoOperativo !== null
            && $idProductoOperativo > 0;
        if ($codigoAlmacen === ''
            || ($esProductoOperativo && ($idProducto !== false && $idProducto !== null))
            || (!$esProductoOperativo && ($idProducto === false || $idProducto === null || $idProducto < 1))
        ) {
            http_response_code(400);
            exit('Selecciona un producto y almacén válidos.');
        }

        $modelo = new EtiquetasInventarioModelo($baseDatos);
        $idProductoEtiqueta = $esProductoOperativo ? $idProductoOperativo : $idProducto;
        $producto = $esProductoOperativo
            ? $modelo->obtenerProductoOperativo($idProductoOperativo, $codigoAlmacen)
            : $modelo->obtenerProducto($idProducto, $codigoAlmacen);
        if ($producto === false || !is_string($producto['barcode']) || $producto['barcode'] === '') {
            http_response_code(404);
            exit('No se encontró el producto con etiqueta en ese almacén.');
        }
        $cantidadInicial = max(1, min((int) $producto['quantity'], self::MAX_ETIQUETAS_POR_IMPRESION));
        $copias = filter_var($_GET['copies'] ?? (string) $cantidadInicial, FILTER_VALIDATE_INT);
        $copias = $copias === false || $copias < 1
            ? $cantidadInicial
            : min($copias, self::MAX_ETIQUETAS_POR_IMPRESION);
        $imprimirEtiquetas = (string) ($_GET['print'] ?? '') === '1';
        $codigoQrSvg = InventoryQrCode::svg(
            $this->urlEscaneoMovil((string) $producto['barcode'], $codigoAlmacen, $esProductoOperativo)
        );
        $codigoBarrasSvg = '';
        $advertenciaCodigoBarras = '';
        try {
            $codigoBarrasSvg = Code39Barcode::svg(strtoupper(trim((string) $producto['barcode'])));
        } catch (InvalidArgumentException) {
            $advertenciaCodigoBarras = 'Este código no es compatible con Code 39. La etiqueta conservará el QR.';
        }
        $this->mostrarVista('etiquetas_inventario', [
            'idProducto' => $idProductoEtiqueta,
            'parametroProducto' => $esProductoOperativo ? 'operational_product_id' : 'product_id',
            'esProductoOperativo' => $esProductoOperativo,
            'producto' => $producto,
            'copias' => $copias,
            'imprimirEtiquetas' => $imprimirEtiquetas,
            'codigoQrSvg' => $codigoQrSvg,
            'codigoBarrasSvg' => $codigoBarrasSvg,
            'advertenciaCodigoBarras' => $advertenciaCodigoBarras,
        ]);
    }
}


final class ImportarVentasControlador extends ControladorBase
{
    private function borrarArchivoPendiente(array $pendiente): void
    {
        $ruta = (string) ($pendiente['staged_path'] ?? '');
        if ($ruta !== '' && is_file($ruta)) {
            unlink($ruta);
        }
    }

    private function redirigirPronostico(): never
    {
        header('Location: index.php?pagina=prediccion_marcas');
        exit;
    }

    public function manejar(): void
    {
        $this->iniciarPagina(
            ['administrador'],
            "default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'"
        );
        $database = $this->baseDatos;
        $currentUser = $this->usuarioActual;
        $importService = new HistoricalSalesImportService($database);
        $pendingKey = 'pending_sales_import';
        $destination = 'index.php?pagina=prediccion_marcas';
        $action = '';

        try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $this->redirigirPronostico();
    }
    if (!app_csrf_is_valid((string) ($_POST['csrf_token'] ?? ''))) {
        throw new RuntimeException('El formulario venció. Recarga la página e inténtalo de nuevo.');
    }

    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'cancel_monthly_sales') {
        $pending = $_SESSION[$pendingKey] ?? null;
        if (!is_array($pending)
            || !hash_equals((string) ($pending['token'] ?? ''), (string) ($_POST['confirmation_token'] ?? ''))
        ) {
            throw new RuntimeException('La revisión venció. Selecciona de nuevo el archivo para importarlo.');
        }
        $this->borrarArchivoPendiente($pending);
        unset($_SESSION[$pendingKey]);
        $_SESSION['sales_import_flash'] = 'Se canceló la importación. No se guardaron el Excel ni sus ventas.';
        $this->redirigirPronostico();
    }

    if ($action === 'stage_monthly_sales') {
        $upload = $_FILES['sales_file'] ?? null;
        if (!is_array($upload)) {
            throw new RuntimeException('Selecciona un archivo Excel .xlsx válido para revisar.');
        }
        $uploadError = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            $message = in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'El archivo supera el límite permitido por el servidor.'
                : 'No se recibió el archivo correctamente. Selecciona de nuevo un Excel .xlsx.';
            throw new RuntimeException($message);
        }
        $temporaryPath = (string) ($upload['tmp_name'] ?? '');
        if (!is_uploaded_file($temporaryPath)) {
            throw new RuntimeException('No se pudo verificar el archivo recibido. Selecciónalo e inténtalo otra vez.');
        }

        $prepared = $importService->prepare(
            $temporaryPath,
            (string) ($upload['name'] ?? ''),
            ''
        );

        $confirmationToken = bin2hex(random_bytes(32));
        $stagedPath = tempnam(sys_get_temp_dir(), 'siva-sales-import-');
        if ($stagedPath === false || !copy($temporaryPath, $stagedPath)) {
            if (is_string($stagedPath) && is_file($stagedPath)) {
                unlink($stagedPath);
            }
            throw new RuntimeException('No se pudo preparar el archivo para la revisión. Inténtalo otra vez.');
        }
        if (!chmod($stagedPath, 0600)) {
            unlink($stagedPath);
            throw new RuntimeException('No se pudo proteger el archivo temporal de revisión. Inténtalo otra vez.');
        }

        $previousPending = $_SESSION[$pendingKey] ?? null;
        if (is_array($previousPending)) {
            $this->borrarArchivoPendiente($previousPending);
        }

        $_SESSION[$pendingKey] = [
            'token' => $confirmationToken,
            'staged_path' => $stagedPath,
            'file_hash' => $prepared['file_hash'],
            'original_name' => $prepared['original_name'],
            'report_warehouse' => (string) ($_POST['report_warehouse'] ?? ''),
            'created_at' => time(),
            'month' => $prepared['month'],
            'first_sale_date' => $prepared['first_sale_date'],
            'last_sale_date' => $prepared['last_sale_date'],
            'forecast_start_date' => $prepared['forecast_start_date'],
            'forecast_end_date' => $prepared['forecast_end_date'],
            'row_count' => count($prepared['sales_rows']),
            'report_format' => $prepared['report_format'],
            'warehouse' => $prepared['report_warehouse'],
            'excluded_warehouse_rows' => $prepared['excluded_warehouse_rows'],
            'overlap' => $prepared['overlap'],
        ];
        header('Location: ' . $destination . '&revision=1');
        exit;
    }

    if ($action !== 'confirm_monthly_sales') {
        throw new RuntimeException('La acción solicitada no es válida.');
    }

    $pending = $_SESSION[$pendingKey] ?? null;
    if (!is_array($pending)
        || !hash_equals((string) ($pending['token'] ?? ''), (string) ($_POST['confirmation_token'] ?? ''))
    ) {
        throw new RuntimeException('La revisión venció. Selecciona de nuevo el archivo para importarlo.');
    }
    if ((int) ($pending['created_at'] ?? 0) < time() - 1800) {
        $this->borrarArchivoPendiente($pending);
        unset($_SESSION[$pendingKey]);
        throw new RuntimeException('La revisión venció después de 30 minutos. Selecciona de nuevo el archivo.');
    }
    $stagedPath = (string) ($pending['staged_path'] ?? '');
    if ($stagedPath === '' || !is_file($stagedPath)) {
        unset($_SESSION[$pendingKey]);
        throw new RuntimeException('No se encontró el archivo pendiente. Selecciónalo de nuevo para importarlo.');
    }
    $currentHash = hash_file('sha256', $stagedPath);
    if (!is_string($currentHash) || !hash_equals((string) $pending['file_hash'], $currentHash)) {
        $this->borrarArchivoPendiente($pending);
        unset($_SESSION[$pendingKey]);
        throw new RuntimeException('El archivo cambió después de revisarlo. Selecciónalo de nuevo antes de confirmar.');
    }

    $prepared = $importService->prepare(
        $stagedPath,
        (string) $pending['original_name'],
        ''
    );
    if (!hash_equals((string) $pending['file_hash'], $prepared['file_hash'])) {
        throw new RuntimeException('El archivo revisado no coincide. Vuelve a seleccionarlo antes de confirmar.');
    }
    if ((int) ($prepared['overlap']['row_count'] ?? 0) > 0
        && (string) ($_POST['confirmar_traslape'] ?? '') !== '1'
    ) {
        $_SESSION[$pendingKey]['overlap'] = $prepared['overlap'];
        $_SESSION['sales_import_error'] = 'Confirma si deseas continuar: el archivo comparte fechas con importaciones anteriores.';
        $this->redirigirPronostico();
    }

    $importService->persist($stagedPath, $prepared, (int) $currentUser['id']);
    $this->borrarArchivoPendiente($pending);
    unset($_SESSION[$pendingKey]);
    $_SESSION['sales_import_flash'] = 'Se guardaron el Excel original y '
        . number_format(count($prepared['sales_rows'])) . ' filas de ventas de '
        . $prepared['month'] . ' en las importaciones de Excel, separados del histórico de entrenamiento. El inventario no fue modificado.'
        . ($prepared['report_format'] && $prepared['report_warehouse'] === null
            ? ' El reporte se guardó sin asignarlo a un almacén.'
            : '')
        . ($prepared['excluded_warehouse_rows'] > 0
            ? ' Se excluyeron ' . number_format($prepared['excluded_warehouse_rows'])
                . ' filas de otros almacenes.'
            : '');
    $this->redirigirPronostico();
} catch (PDOException $exception) {
    if ($database->inTransaction()) {
        $database->rollBack();
    }
    error_log('No se pudo guardar la importación de ventas: ' . $exception->getMessage());
    $_SESSION['sales_import_error'] = 'No se pudo guardar la importación; no se guardaron el archivo ni sus ventas. Inténtalo de nuevo.';
    $this->redirigirPronostico();
} catch (RuntimeException $exception) {
    if ($action === 'confirm_monthly_sales') {
        $pending = $_SESSION[$pendingKey] ?? null;
        if (is_array($pending)) {
            $this->borrarArchivoPendiente($pending);
            unset($_SESSION[$pendingKey]);
        }
    }
    if ($exception instanceof DuplicateHistoricalSalesFileException) {
        $_SESSION['sales_import_duplicate_warning'] = true;
    } else {
        unset($_SESSION['sales_import_duplicate_warning']);
    }
    $_SESSION['sales_import_error'] = $exception->getMessage();
    $this->redirigirPronostico();
} catch (Throwable $exception) {
    if ($database->inTransaction()) {
        $database->rollBack();
    }
    error_log('No se pudo importar ventas históricas: ' . $exception->getMessage());
    $_SESSION['sales_import_error'] = 'No se pudo guardar la importación; no se guardaron el archivo ni sus ventas. Revisa el archivo e inténtalo otra vez.';
    $this->redirigirPronostico();
        }
    }
}


final class InventarioControlador extends ControladorBase
{
    public function mostrar(): void
    {
        if ((string) ($_GET['pagina'] ?? '') === 'movimientos') {
            $_GET['view'] = 'movements';
        }
        $this->iniciarPagina(['administrador', 'almacen'], "default-src 'self'; style-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        $baseDatos = $this->baseDatos;
        $usuarioActual = $this->usuarioActual;
        $tokenCsrf = $this->tokenCsrf;
        $modelo = new InventarioModelo($baseDatos);
        $this->limpiarPrevisualizacionesEscaneosExpiradas();

        if ((string) ($_GET['ajax'] ?? '') === 'physical_count_suggestions') {
            $this->responderSugerenciasConteoFisico($modelo);
        }
        if ((string) ($_GET['ajax'] ?? '') === 'inventory_search_suggestions') {
            $this->responderSugerenciasBusquedaInventario($modelo);
        }

        $exportarConteoExcel = (string) ($_GET['exportar_conteo_fisico'] ?? '') === '1';
        $exportarConteoPdf = (string) ($_GET['exportar_conteo_fisico_pdf'] ?? '') === '1';
        if ($exportarConteoExcel || $exportarConteoPdf) {
            $almacenConteo = strtoupper(trim((string) ($_GET['warehouse'] ?? '')));
            try {
                $reporteConteo = $modelo->obtenerReporteConteoFisico($almacenConteo);
                if ($reporteConteo === []) {
                    $_SESSION['inventario_conteo_error'] = 'Todavía no hay conteos guardados para descargar en este almacén.';
                    header('Location: index.php?pagina=inventario&view=count&warehouse=' . rawurlencode($almacenConteo));
                    exit;
                }

                $sufijoReporte = strtolower($almacenConteo) . '_' . date('Ymd_His');
                if ($exportarConteoPdf) {
                    $contenidoReporte = (new InventoryPhysicalCountPdfExporter())->generate($reporteConteo);
                    header('Content-Type: application/pdf');
                    header('Content-Disposition: attachment; filename="reporte_conteo_fisico_' . $sufijoReporte . '.pdf"');
                } else {
                    $contenidoReporte = (new InventoryReportExcelExporter())->generatePhysicalCount($reporteConteo);
                    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                    header('Content-Disposition: attachment; filename="reporte_conteo_fisico_' . $sufijoReporte . '.xlsx"');
                }
                header('Content-Length: ' . strlen($contenidoReporte));
                header('Cache-Control: no-store');
                echo $contenidoReporte;
                exit;
            } catch (Throwable $exception) {
                error_log('No se pudo generar el reporte de conteo físico: ' . $exception->getMessage());
                $_SESSION['inventario_conteo_error'] = 'No se pudo generar el reporte de conteo físico. Inténtalo de nuevo.';
                $destino = 'index.php?pagina=inventario&view=count&warehouse=' . rawurlencode($almacenConteo);
                header('Location: ' . $destino);
                exit;
            }
        }

        if ((string) ($_GET['descargar_reporte'] ?? '') === '1') {
            try {
                $reporte = $modelo->obtenerReporteOperativo($_GET, true);
                $contenidoExcel = (new InventoryReportExcelExporter())->generate($reporte);
                $sufijoAlmacen = $reporte['warehouse'] !== '' ? strtolower($reporte['warehouse']) : 'todos';
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="reporte_inventario_' . $sufijoAlmacen . '_' . date('Ymd_His') . '.xlsx"');
                header('Content-Length: ' . strlen($contenidoExcel));
                header('Cache-Control: no-store');
                echo $contenidoExcel;
                exit;
            } catch (Throwable $exception) {
                error_log('No se pudo generar el reporte de inventario: ' . $exception->getMessage());
                $_SESSION['inventario_mensaje_error'] = 'No se pudo generar el reporte de Excel. Inténtalo de nuevo.';
                header('Location: index.php?pagina=inventario&view=report');
                exit;
            }
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST'
            && (string) ($_POST['accion'] ?? '') === 'escanear_conteo_fisico'
        ) {
            $this->procesarEscaneoConteoFisico($modelo);
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST'
            && (string) ($_POST['accion'] ?? '') === 'guardar_conteo_fisico'
        ) {
            $this->procesarConteoFisico($modelo, $baseDatos);
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST'
            && (string) ($_POST['accion'] ?? '') === 'importar_inventario'
        ) {
            $_SESSION['inventario_mensaje_error'] = 'La sustitución del inventario completo está deshabilitada. Solo se pueden aplicar escaneos aprobados.';
            header('Location: index.php?pagina=inventario');
            exit;
        }
        $mensajeExito = (string) ($_SESSION['inventario_mensaje_exito'] ?? '');
        $mensajeError = (string) ($_SESSION['inventario_mensaje_error'] ?? '');
        $mensajeConteoExito = (string) ($_SESSION['inventario_conteo_exito'] ?? '');
        $mensajeConteoError = (string) ($_SESSION['inventario_conteo_error'] ?? '');
        $mensajeConteoVoz = (string) ($_SESSION['inventario_conteo_voz'] ?? '');
        $mensajeConteoEscaneoVoz = (string) ($_SESSION['inventario_conteo_escaneo_voz'] ?? '');
        $mostrarAvisoEscaneoConteo = (bool) ($_SESSION['inventario_conteo_escaneo_aviso'] ?? false);
        $mensajeEscaneoExito = (string) ($_SESSION['inventario_escaneo_exito'] ?? '');
        $mensajeEscaneoError = (string) ($_SESSION['inventario_escaneo_error'] ?? '');
        $mensajeEscaneoVoz = (string) ($_SESSION['inventario_escaneo_voz'] ?? '');
        $mensajeLoteEscaneosEliminado = (string) ($_SESSION['inventario_lote_escaneos_eliminado'] ?? '');
        $productoAgregado = $_SESSION['product_added_flash'] ?? null;
        $mensajeVozProducto = (string) ($_SESSION['product_voice_flash'] ?? '');
        $codigoBarrasReintentoEscaneo = (string) ($_SESSION['inventario_escaneo_reintento_codigo'] ?? '');
        $almacenReintentoEscaneo = (string) ($_SESSION['inventario_escaneo_reintento_almacen'] ?? '');
        unset(
            $_SESSION['inventario_mensaje_exito'],
            $_SESSION['inventario_mensaje_error'],
            $_SESSION['inventario_conteo_exito'],
            $_SESSION['inventario_conteo_error'],
            $_SESSION['inventario_conteo_voz'],
            $_SESSION['inventario_conteo_escaneo_voz'],
            $_SESSION['inventario_conteo_escaneo_aviso'],
            $_SESSION['inventario_escaneo_exito'],
            $_SESSION['inventario_escaneo_error'],
            $_SESSION['inventario_escaneo_voz'],
            $_SESSION['inventario_lote_escaneos_eliminado'],
            $_SESSION['inventario_escaneo_reintento_codigo'],
            $_SESSION['inventario_escaneo_reintento_almacen'],
            $_SESSION['product_flash'],
            $_SESSION['product_voice_flash'],
            $_SESSION['product_added_flash']
        );

        $datosVista = $modelo->obtenerDatosPagina($_GET);
        $previsualizacionEscaneos = $_SESSION['inventario_escaneos_previsualizacion'] ?? null;
        if (is_array($previsualizacionEscaneos)
            && (
                (int) ($previsualizacionEscaneos['created_at'] ?? 0) < time() - 1800
                || (int) ($previsualizacionEscaneos['user_id'] ?? 0) !== (int) $usuarioActual['id']
            )
        ) {
            $this->descartarPrevisualizacionEscaneos();
            $previsualizacionEscaneos = null;
            $mensajeEscaneoError = 'La vista previa venció o ya no está disponible. Vuelve a seleccionar el Excel.';
        }
        if (!is_array($previsualizacionEscaneos)
            || !isset($previsualizacionEscaneos['summary'])
            || !is_array($previsualizacionEscaneos['summary'])
        ) {
            $previsualizacionEscaneos = null;
        }
        $datosVista['scanImportPreview'] = is_array($previsualizacionEscaneos)
            ? $previsualizacionEscaneos['summary']
            : null;
        $vistaInventarioSolicitada = (string) ($_GET['view'] ?? '');
        $datosVista['showWarehouseComparison'] = $vistaInventarioSolicitada === 'compare'
            || ($vistaInventarioSolicitada === '' && (string) ($_GET['count_scan'] ?? '') !== '1');
        $datosVista['showOperationalReport'] = (string) ($_GET['view'] ?? '') === 'report';
        $datosVista['operationalReport'] = $datosVista['showOperationalReport']
            ? $modelo->obtenerReporteOperativo($_GET)
            : [
                'warehouses' => [],
                'warehouse' => '',
                'from' => '',
                'to' => '',
                'stock_rows' => [],
                'alert_rows' => [],
                'total_products' => 0,
                'total_units' => 0,
                'critical_count' => 0,
                'replenishment_count' => 0,
                'movements' => [],
                'movement_count' => 0,
            ];
        $datosVista['warehouseComparison'] = $datosVista['showWarehouseComparison']
            ? $modelo->obtenerComparacionAlmacenesOperativos($_GET)
            : ['warehouses' => [], 'search' => '', 'rows' => [], 'total_rows' => 0, 'total_units' => 0, 'stocked_product_count' => 0, 'differing_product_count' => 0, 'page' => 1, 'page_size' => 20, 'total_pages' => 1, 'page_offset' => 0];
        $datosVista['slowMovingProducts'] = $datosVista['showWarehouseComparison']
            ? $modelo->obtenerAlertasRotacionLenta($_GET)
            : ['warehouses' => [], 'warehouse' => '', 'search' => '', 'threshold_days' => 30, 'total_rows' => 0, 'rows' => []];
        $datosVista['showStockAlerts'] = (string) ($_GET['view'] ?? '') === 'alerts';
        $datosVista['stockAlerts'] = $datosVista['showStockAlerts']
            ? $modelo->obtenerAlertasStockOperativo($_GET)
            : ['warehouses' => [], 'warehouse' => '', 'search' => '', 'status' => '', 'rows' => [], 'total_rows' => 0, 'critical_count' => 0, 'in_transit_count' => 0, 'page' => 1, 'page_size' => 20, 'total_pages' => 1, 'page_offset' => 0];
        $datosVista['showPhysicalCount'] = (string) ($_GET['view'] ?? '') === 'count'
            || (string) ($_GET['count_scan'] ?? '') === '1';
        $almacenConteoSolicitado = strtoupper(trim((string) ($_GET['warehouse'] ?? '')));
        $conteosEscaneados = in_array($almacenConteoSolicitado, ['A0101', 'A0102', 'A0103'], true)
            ? ($_SESSION['inventario_conteo_escaneos'][(int) $usuarioActual['id']][$almacenConteoSolicitado] ?? [])
            : [];
        $conteosConfirmados = $datosVista['showPhysicalCount']
            && in_array($almacenConteoSolicitado, ['A0101', 'A0102', 'A0103'], true)
            ? $modelo->obtenerUltimosConteosFisicos($almacenConteoSolicitado)
            : [];
        $datosVista['scannedPhysicalCountByProduct'] = [];
        $datosVista['pendingPhysicalCountByProduct'] = [];
        $productosEscaneados = [];
        $ultimoProductoConteoId = in_array($almacenConteoSolicitado, ['A0101', 'A0102', 'A0103'], true)
            ? (int) ($_SESSION['inventario_conteo_ultimo_producto'][(int) $usuarioActual['id']][$almacenConteoSolicitado] ?? 0)
            : 0;
        if ($ultimoProductoConteoId > 0) {
            $productosEscaneados[] = $ultimoProductoConteoId;
        }
        if (is_array($conteosEscaneados)) {
            foreach (array_reverse($conteosEscaneados, true) as $scan) {
                if (is_array($scan)
                    && is_array($scan['product'] ?? null)
                    && (int) ($scan['quantity'] ?? 0) > 0
                ) {
                    $productoIdEscaneado = (int) ($scan['product']['product_id'] ?? 0);
                    if ($productoIdEscaneado > 0) {
                        $datosVista['scannedPhysicalCountByProduct'][$productoIdEscaneado] = (int) $scan['quantity'];
                        $datosVista['pendingPhysicalCountByProduct'][$productoIdEscaneado] = true;
                        if (!in_array($productoIdEscaneado, $productosEscaneados, true)) {
                            $productosEscaneados[] = $productoIdEscaneado;
                        }
                    }
                }
            }
        }
        if (is_array($conteosConfirmados)) {
            foreach ($conteosConfirmados as $productId => $quantity) {
                $productId = (int) $productId;
                $quantity = filter_var($quantity, FILTER_VALIDATE_INT);
                if ($productId > 0 && $quantity !== false && $quantity >= 0) {
                    if (!array_key_exists($productId, $datosVista['scannedPhysicalCountByProduct'])) {
                        $datosVista['scannedPhysicalCountByProduct'][$productId] = $quantity;
                    }
                    if (!in_array($productId, $productosEscaneados, true)) {
                        $productosEscaneados[] = $productId;
                    }
                }
            }
        }
        $filtrosConteoFisico = array_merge($_GET, ['scanned_product_ids' => $productosEscaneados]);
        $datosVista['physicalCount'] = $datosVista['showPhysicalCount']
            ? $modelo->obtenerConteoFisico($filtrosConteoFisico)
            : ['warehouses' => [], 'warehouse' => '', 'search' => '', 'rows' => [], 'total_rows' => 0, 'page' => 1, 'page_size' => 20, 'total_pages' => 1, 'page_offset' => 0];
        if ($datosVista['showPhysicalCount'] && $productosEscaneados !== []) {
            $filasPorProducto = [];
            foreach ($datosVista['physicalCount']['rows'] as $row) {
                $filasPorProducto[(int) $row['product_id']] = $row;
            }
            $filasPrioritarias = [];
            foreach ($productosEscaneados as $productoIdEscaneado) {
                if (isset($filasPorProducto[$productoIdEscaneado])) {
                    $filasPrioritarias[] = $filasPorProducto[$productoIdEscaneado];
                    unset($filasPorProducto[$productoIdEscaneado]);
                }
            }
            $datosVista['physicalCount']['rows'] = array_merge(
                $filasPrioritarias,
                array_values($filasPorProducto)
            );
        }
        $requestedSimilarProductId = filter_var($_GET['producto_similar'] ?? null, FILTER_VALIDATE_INT);
        $datosVista['similarProductId'] = $requestedSimilarProductId !== false && $requestedSimilarProductId > 0
            ? $requestedSimilarProductId
            : 0;
        $datosVista['showSimilarProductAnalysis'] = $datosVista['showPhysicalCount']
            && (string) ($_GET['analizar_similares'] ?? '') === '1'
            && $datosVista['physicalCount']['warehouse'] !== ''
            && $datosVista['similarProductId'] > 0;
        $datosVista['similarProductAnalysis'] = $datosVista['showSimilarProductAnalysis']
            ? $modelo->analizarCoincidenciasConteoFisico(
                $datosVista['physicalCount']['warehouse'],
                $datosVista['similarProductId']
            )
            : ['products_analyzed' => 0, 'total_matches' => 0, 'matches' => [], 'focus_product' => null];
        $datosVista['countScanAutoSubmit'] = (string) ($_GET['count_scan'] ?? '') === '1';
        $datosVista['showScanner'] = (string) ($_GET['view'] ?? '') === 'scan'
            || (string) ($_GET['scan'] ?? '') === '1';
        extract($datosVista, EXTR_SKIP);
        $datosVista['inventoryImportSuccess'] = $mensajeExito;
        $datosVista['inventoryImportError'] = $mensajeError;
        $datosVista['physicalCountSuccess'] = $mensajeConteoExito;
        $datosVista['physicalCountError'] = $mensajeConteoError;
        $datosVista['physicalCountVoiceMessage'] = $mensajeConteoVoz;
        $datosVista['physicalCountScanVoiceMessage'] = $mensajeConteoEscaneoVoz;
        $datosVista['physicalCountScanSuccess'] = $mostrarAvisoEscaneoConteo && $mensajeConteoExito !== '';
        $datosVista['physicalCountScanError'] = $mostrarAvisoEscaneoConteo && $mensajeConteoError !== '';
        $datosVista['inventoryScanSuccess'] = $mensajeEscaneoExito;
        $datosVista['inventoryScanError'] = $mensajeEscaneoError;
        $datosVista['inventoryScanVoiceMessage'] = $mensajeEscaneoVoz;
        $datosVista['deletedScanBatchVoiceMessage'] = $mensajeLoteEscaneosEliminado;
        $datosVista['inventoryScanRetryBarcode'] = $codigoBarrasReintentoEscaneo;
        $datosVista['inventoryScanRetryWarehouse'] = $almacenReintentoEscaneo;
        $datosVista['addedProduct'] = is_array($productoAgregado) ? $productoAgregado : null;
        $datosVista['productVoiceMessage'] = $mensajeVozProducto;
        $this->mostrarVista('inventario', $datosVista, true);
    }

    private function procesarConteoFisico(InventarioModelo $modelo, PDO $baseDatos): never
    {
        $almacen = strtoupper(trim((string) ($_POST['warehouse'] ?? '')));
        $busqueda = trim((string) ($_POST['q'] ?? ''));
        $productoEntrada = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
        $cantidadFisica = filter_var($_POST['physical_quantity'] ?? null, FILTER_VALIDATE_INT);
        $motivo = trim((string) ($_POST['reason'] ?? ''));

        try {
            if (!app_csrf_is_valid((string) ($_POST['token_csrf'] ?? ''))) {
                throw new RuntimeException('El formulario venció. Recarga la página e inténtalo de nuevo.');
            }
            if ($productoEntrada === false || $productoEntrada < 1 || $cantidadFisica === false) {
                throw new RuntimeException('Elige un producto y escribe la cantidad física contada.');
            }

            $conteo = $modelo->ajustarConteoFisico(
                $productoEntrada,
                $almacen,
                $cantidadFisica,
                $motivo,
                (int) $this->usuarioActual['id']
            );
            $diferencia = (int) $conteo['difference'];
            $_SESSION['inventario_conteo_exito'] = $diferencia === 0
                ? $conteo['sku'] . ': conteo confirmado en ' . $conteo['physical_quantity'] . ' unidades; no hubo diferencia.'
                : $conteo['sku'] . ': stock corregido de ' . $conteo['previous_quantity'] . ' a '
                    . $conteo['physical_quantity'] . ' unidades (diferencia '
                    . ($diferencia > 0 ? '+' : '') . $diferencia . ').';
            $cantidadContada = (int) $conteo['physical_quantity'];
            $_SESSION['inventario_conteo_voz'] = 'Conteo guardado. Cantidad contada: '
                . $cantidadContada . ' ' . ($cantidadContada === 1 ? 'unidad.' : 'unidades.');
            $_SESSION['inventario_conteo_ultimo_producto'][(int) $this->usuarioActual['id']][$almacen]
                = (int) $productoEntrada;
            unset(
                $_SESSION['inventario_conteo_escaneos'][(int) $this->usuarioActual['id']]
                    [$almacen][$productoEntrada]
            );
            unset($_SESSION['inventario_conteo_error']);
        } catch (RuntimeException $exception) {
            $_SESSION['inventario_conteo_error'] = $exception->getMessage();
        } catch (Throwable $exception) {
            if ($baseDatos->inTransaction()) {
                $baseDatos->rollBack();
            }
            error_log('No se pudo guardar el conteo físico: ' . $exception->getMessage());
            $_SESSION['inventario_conteo_error'] = 'No se pudo guardar el conteo. No se modificó el stock.';
        }

        $destino = 'index.php?pagina=inventario&view=count&warehouse=' . rawurlencode($almacen);
        if ($busqueda !== '') {
            $destino .= '&q=' . rawurlencode($busqueda);
        }
        header('Location: ' . $destino);
        exit;
    }

    private function responderSugerenciasConteoFisico(InventarioModelo $modelo): never
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        try {
            $warehouse = strtoupper(trim((string) ($_GET['warehouse'] ?? '')));
            $search = trim((string) ($_GET['q'] ?? ''));
            if (strlen($search) > 200) {
                http_response_code(400);
                echo json_encode(['error' => 'La búsqueda es demasiado larga.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            echo json_encode([
                'results' => $modelo->buscarSugerenciasConteoFisico($warehouse, $search),
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            error_log('No se pudieron buscar productos para el conteo físico: ' . $exception->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'No se pudieron cargar las sugerencias. Inténtalo nuevamente.'], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    private function responderSugerenciasBusquedaInventario(InventarioModelo $modelo): never
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        try {
            $search = trim((string) ($_GET['q'] ?? ''));
            if (strlen($search) > 200) {
                http_response_code(400);
                echo json_encode(['error' => 'La búsqueda es demasiado larga.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $warehouse = strtoupper(trim((string) ($_GET['warehouse'] ?? '')));
            $view = (string) ($_GET['view'] ?? '');
            $results = in_array($view, ['alerts', 'compare'], true)
                ? $modelo->buscarSugerenciasInventarioOperativo(
                    $search,
                    $warehouse,
                    12,
                    $view === 'alerts'
                )
                : $modelo->buscarSugerenciasInventarioB4x01($search, $warehouse);
            echo json_encode(['results' => $results], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            error_log('No se pudieron buscar productos del inventario: ' . $exception->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'No se pudieron cargar las sugerencias. Inténtalo nuevamente.'], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    private function procesarEscaneoConteoFisico(InventarioModelo $modelo): never
    {
        $almacen = strtoupper(trim((string) ($_POST['warehouse'] ?? '')));
        $codigoBarras = (string) ($_POST['barcode'] ?? '');
        unset(
            $_SESSION['inventario_conteo_escaneo_aviso'],
            $_SESSION['inventario_conteo_escaneo_voz']
        );

        try {
            if (!app_csrf_is_valid((string) ($_POST['token_csrf'] ?? ''))) {
                throw new RuntimeException('El formulario venció. Recarga la página e inténtalo de nuevo.');
            }

            $producto = $modelo->buscarProductoConteoFisicoPorCodigoBarras($codigoBarras, $almacen);
            $usuarioId = (int) $this->usuarioActual['id'];
            $productoId = (int) $producto['product_id'];
            $conteosPorUsuario = $_SESSION['inventario_conteo_escaneos'][$usuarioId] ?? [];
            $conteoAlmacen = $conteosPorUsuario[$almacen] ?? [];
            $cantidadAnterior = (int) ($conteoAlmacen[$productoId]['quantity'] ?? 0);
            if ($cantidadAnterior >= 2147483647) {
                throw new RuntimeException('El conteo alcanzó el máximo permitido. Revisa la cantidad manualmente.');
            }
            $cantidadEscaneada = $cantidadAnterior + 1;
            unset($conteoAlmacen[$productoId]);
            $conteoAlmacen[$productoId] = [
                'product' => $producto,
                'quantity' => $cantidadEscaneada,
            ];
            $conteosPorUsuario[$almacen] = $conteoAlmacen;
            $_SESSION['inventario_conteo_escaneos'][$usuarioId] = $conteosPorUsuario;
            $_SESSION['inventario_conteo_ultimo_producto'][$usuarioId][$almacen] = $productoId;

            $_SESSION['inventario_conteo_exito'] = $producto['sku']
                . ': escaneo agregado al conteo (' . $cantidadEscaneada . ' '
                . ($cantidadEscaneada === 1 ? 'unidad' : 'unidades')
                . '). Sigue escaneando este producto y confirma el total cuando termines.';
            $_SESSION['inventario_conteo_escaneo_voz'] = 'Llevas ' . $cantidadEscaneada . ' '
                . ($cantidadEscaneada === 1 ? 'escaneo.' : 'escaneos.');
            $_SESSION['inventario_conteo_escaneo_aviso'] = true;
            unset($_SESSION['inventario_conteo_error']);
        } catch (RuntimeException $exception) {
            $_SESSION['inventario_conteo_escaneo_aviso'] = true;
            $_SESSION['inventario_conteo_error'] = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('No se pudo procesar el escaneo del conteo físico: ' . $exception->getMessage());
            $_SESSION['inventario_conteo_escaneo_aviso'] = true;
            $_SESSION['inventario_conteo_error'] = 'No se pudo registrar el escaneo. Inténtalo de nuevo o captura el conteo manualmente.';
        }

        $destino = 'index.php?pagina=inventario&view=count&warehouse=' . rawurlencode($almacen);
        header('Location: ' . $destino);
        exit;
    }

    private function procesarEscaneo(InventarioModelo $modelo, PDO $baseDatos): never
    {
        $almacen = strtoupper(trim((string) ($_POST['warehouse'] ?? '')));
        $codigoBarras = (string) ($_POST['barcode'] ?? '');
        unset(
            $_SESSION['inventario_escaneo_reintento_codigo'],
            $_SESSION['inventario_escaneo_reintento_almacen']
        );
        try {
            if (!app_csrf_is_valid((string) ($_POST['token_csrf'] ?? ''))) {
                throw new RuntimeException('El formulario venció. Recarga la página e inténtalo de nuevo.');
            }

            $escaneo = $modelo->registrarEscaneo(
                $codigoBarras,
                $almacen,
                (int) $this->usuarioActual['id']
            );
            $cantidadEscaneos = (int) $escaneo['scan_count'];
            $_SESSION['inventario_escaneo_exito'] = 'Escaneo guardado automáticamente en el Excel pendiente. '
                . $escaneo['name'] . ' (' . $escaneo['brand'] . ' ' . $escaneo['measure'] . ') se ha escaneado '
                . $cantidadEscaneos . ' ' . ($cantidadEscaneos === 1 ? 'vez' : 'veces')
                . ' en el almacén ' . $escaneo['warehouse'] . '. El stock todavía no cambió.';
            $_SESSION['inventario_escaneo_voz'] = 'Llevas ' . $cantidadEscaneos . ' '
                . ($cantidadEscaneos === 1 ? 'escaneo.' : 'escaneos.');
            unset($_SESSION['inventario_escaneo_error']);
        } catch (PDOException $exception) {
            if ($baseDatos->inTransaction()) {
                $baseDatos->rollBack();
            }
            error_log('No se pudo guardar el escaneo de inventario: ' . $exception->getMessage());
            $_SESSION['inventario_escaneo_error'] = 'No se pudo guardar el escaneo; no se modificó la cantidad.';
            $_SESSION['inventario_escaneo_reintento_codigo'] = $codigoBarras;
            $_SESSION['inventario_escaneo_reintento_almacen'] = $almacen;
        } catch (EscaneoSinStockException $exception) {
            $_SESSION['inventario_escaneo_error'] = $exception->getMessage();
            $_SESSION['inventario_escaneo_voz'] = $exception->getMessage();
        } catch (RuntimeException $exception) {
            $_SESSION['inventario_escaneo_error'] = $exception->getMessage();
        } catch (Throwable $exception) {
            if ($baseDatos->inTransaction()) {
                $baseDatos->rollBack();
            }
            error_log('No se pudo registrar el escaneo de inventario: ' . $exception->getMessage());
            $_SESSION['inventario_escaneo_error'] = 'No se pudo guardar el escaneo; no se modificó la cantidad.';
            $_SESSION['inventario_escaneo_reintento_codigo'] = $codigoBarras;
            $_SESSION['inventario_escaneo_reintento_almacen'] = $almacen;
        }

        $destino = 'index.php?pagina=inventario&view=scan';
        if (in_array($almacen, ['A0101', 'A0102', 'A0103'], true)) {
            $destino .= '&warehouse=' . rawurlencode($almacen);
        }
        header('Location: ' . $destino);
        exit;
    }

    private function descargarEscaneos(InventarioModelo $modelo): never
    {
        $almacen = strtoupper(trim((string) ($_GET['warehouse'] ?? '')));
        $loteDescarga = trim((string) ($_GET['lote'] ?? ''));
        if ($almacen !== '' && !in_array($almacen, ['A0101', 'A0102', 'A0103'], true)) {
            http_response_code(400);
            exit('El almacén seleccionado no es válido.');
        }
        if ($loteDescarga === '' && $almacen === '') {
            $_SESSION['inventario_escaneo_error'] = 'Selecciona un almacén antes de descargar el Excel pendiente.';
            header('Location: index.php?pagina=inventario&view=scan');
            exit;
        }

        try {
            $descarga = $modelo->prepararDescargaEscaneos(
                $loteDescarga === '' ? ($almacen === '' ? null : $almacen) : null,
                $loteDescarga === '' ? null : $loteDescarga
            );
            if (!is_array($descarga)) {
                $_SESSION['inventario_escaneo_error'] = $loteDescarga === ''
                    ? 'No hay escaneos nuevos para descargar.'
                    : 'No se encontró el Excel de ese lote descargado.';
                $destino = 'index.php?pagina=inventario&view=scan';
                if ($almacen !== '') {
                    $destino .= '&warehouse=' . rawurlencode($almacen);
                }
                header('Location: ' . $destino);
                exit;
            }
        } catch (Throwable $exception) {
            error_log('No se pudo generar el Excel de escaneos: ' . $exception->getMessage());
            $_SESSION['inventario_escaneo_error'] = 'No se pudo generar el Excel. Inténtalo de nuevo.';
            $destino = 'index.php?pagina=inventario&view=scan';
            if ($almacen !== '') {
                $destino .= '&warehouse=' . rawurlencode($almacen);
            }
            header('Location: ' . $destino);
            exit;
        }

        $contenidoExcel = $descarga['content'];
        $sufijoAlmacen = strtolower((string) $descarga['warehouse']);
        $sufijoLote = substr(str_replace('-', '', $descarga['batch_id']), 0, 8);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="escaneos_inventario_' . $sufijoAlmacen . '_' . date('Ymd_His') . '_' . $sufijoLote . '.xlsx"');
        header('Content-Length: ' . strlen($contenidoExcel));
        header('Cache-Control: no-store');
        echo $contenidoExcel;
        exit;
    }

    private function procesarEliminacionLoteEscaneos(InventarioModelo $modelo, PDO $baseDatos): never
    {
        try {
            if (!app_csrf_is_valid((string) ($_POST['token_csrf'] ?? ''))) {
                throw new RuntimeException('El formulario venció. Recarga la página e inténtalo de nuevo.');
            }
            if ((string) ($_POST['confirmar_eliminacion'] ?? '') !== '1') {
                throw new RuntimeException('Marca la casilla de confirmación para borrar el lote.');
            }
            $eliminados = $modelo->eliminarLoteEscaneosDescargado(
                trim((string) ($_POST['lote_descarga'] ?? ''))
            );
            $mensajeEliminacion = 'Se borraron definitivamente '
                . number_format($eliminados) . ' escaneos del lote descargado. Ese Excel ya no se puede aplicar.';
            $_SESSION['inventario_escaneo_exito'] = $mensajeEliminacion;
            $_SESSION['inventario_lote_escaneos_eliminado'] = $mensajeEliminacion;
            unset($_SESSION['inventario_escaneo_error'], $_SESSION['inventario_escaneo_voz']);
        } catch (RuntimeException $exception) {
            $_SESSION['inventario_escaneo_error'] = $exception->getMessage();
        } catch (Throwable $exception) {
            if ($baseDatos->inTransaction()) {
                $baseDatos->rollBack();
            }
            error_log('No se pudo borrar el lote descargado de escaneos: ' . $exception->getMessage());
            $_SESSION['inventario_escaneo_error'] = 'No se pudo borrar el lote descargado.';
        }

        header('Location: index.php?pagina=inventario&view=scan');
        exit;
    }

    private function procesarPrevisualizacionEscaneos(InventarioModelo $modelo): never
    {
        $rutaGuardada = null;
        try {
            if (!app_csrf_is_valid((string) ($_POST['token_csrf'] ?? ''))) {
                throw new RuntimeException('El formulario venció. Recarga la página e inténtalo de nuevo.');
            }
            $this->descartarPrevisualizacionEscaneos();
            $archivo = $_FILES['archivo_excel'] ?? null;
            if (!is_array($archivo)) {
                throw new RuntimeException('Selecciona el Excel de escaneos revisado.');
            }
            $errorCarga = (int) ($archivo['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($errorCarga !== UPLOAD_ERR_OK) {
                $mensaje = in_array($errorCarga, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                    ? 'El archivo supera el límite permitido por el servidor.'
                    : 'No se recibió el archivo correctamente. Selecciona de nuevo el Excel.';
                throw new RuntimeException($mensaje);
            }
            $rutaTemporal = (string) ($archivo['tmp_name'] ?? '');
            if (!is_uploaded_file($rutaTemporal)) {
                throw new RuntimeException('No se pudo verificar el archivo recibido. Selecciona de nuevo el Excel.');
            }

            $resumen = $modelo->previsualizarEscaneosArchivo(
                $rutaTemporal,
                (string) ($archivo['name'] ?? '')
            );
            $rutaGuardada = tempnam(sys_get_temp_dir(), 'siva-scan-');
            if ($rutaGuardada === false) {
                throw new RuntimeException('No se pudo guardar el Excel para confirmar la vista previa.');
            }
            if (!copy($rutaTemporal, $rutaGuardada)) {
                unlink($rutaGuardada);
                throw new RuntimeException('No se pudo guardar el Excel para confirmar la vista previa.');
            }

            $productos = $resumen['products'];
            $resumen['total_products'] = count($productos);
            $resumen['remaining_products'] = max(0, count($productos) - 200);
            $resumen['products'] = array_slice($productos, 0, 200);
            $_SESSION['inventario_escaneos_previsualizacion'] = [
                'path' => $rutaGuardada,
                'filename' => $resumen['filename'],
                'hash' => hash_file('sha256', $rutaGuardada),
                'user_id' => (int) $this->usuarioActual['id'],
                'created_at' => time(),
                'summary' => $resumen,
            ];
            $rutaGuardada = null;
            unset($_SESSION['inventario_escaneo_error'], $_SESSION['inventario_escaneo_exito'], $_SESSION['inventario_escaneo_voz']);
        } catch (RuntimeException $exception) {
            if (is_string($rutaGuardada) && is_file($rutaGuardada)) {
                unlink($rutaGuardada);
            }
            $_SESSION['inventario_escaneo_error'] = $exception->getMessage();
        } catch (Throwable $exception) {
            if (is_string($rutaGuardada) && is_file($rutaGuardada)) {
                unlink($rutaGuardada);
            }
            error_log('No se pudo preparar la vista previa del Excel de escaneos: ' . $exception->getMessage());
            $_SESSION['inventario_escaneo_error'] = 'No se pudo revisar el Excel. No se modificó el inventario.';
        }

        header('Location: index.php?pagina=inventario&view=scan');
        exit;
    }

    private function procesarAplicacionEscaneos(InventarioModelo $modelo, PDO $baseDatos): never
    {
        $previsualizacion = $_SESSION['inventario_escaneos_previsualizacion'] ?? null;
        try {
            if (!app_csrf_is_valid((string) ($_POST['token_csrf'] ?? ''))) {
                throw new RuntimeException('El formulario venció. Recarga la página e inténtalo de nuevo.');
            }
            if (!is_array($previsualizacion)
                || (int) ($previsualizacion['user_id'] ?? 0) !== (int) $this->usuarioActual['id']
                || (int) ($previsualizacion['created_at'] ?? 0) < time() - 1800
            ) {
                throw new RuntimeException('La vista previa venció o ya no está disponible. Vuelve a revisar el Excel.');
            }
            $ruta = (string) ($previsualizacion['path'] ?? '');
            $hashEsperado = (string) ($previsualizacion['hash'] ?? '');
            if ($ruta === '' || !is_file($ruta) || $hashEsperado === ''
                || !hash_equals($hashEsperado, (string) hash_file('sha256', $ruta))
            ) {
                throw new RuntimeException('El Excel de la vista previa ya no está disponible o cambió. Vuelve a seleccionarlo.');
            }

            $resumen = $modelo->importarEscaneosArchivo(
                $ruta,
                (string) $previsualizacion['filename'],
                (int) $this->usuarioActual['id'],
                (string) ($previsualizacion['summary']['stock_snapshot_hash'] ?? '')
            );
            $_SESSION['inventario_escaneo_exito'] = 'Se agregaron '
                . number_format((int) $resumen['unidades']) . ' unidades de '
                . number_format((int) $resumen['productos'])
                . ' productos desde ' . $resumen['archivo'] . '.';
            $frasesVoz = [
                'Excel aprobado. Se agregaron ' . (int) $resumen['unidades'] . ' unidades a '
                    . (int) $resumen['productos'] . ' productos.',
            ];
            foreach (array_slice($resumen['stock_changes'], 0, 5) as $cambio) {
                $frasesVoz[] = $cambio['name'] . ', almacén ' . $cambio['warehouse']
                    . ': se agregaron ' . $cambio['units_added'] . ' unidades y ahora hay '
                    . $cambio['stock_after'] . ' en existencia.';
                if ($cambio['low_stock']) {
                    $frasesVoz[] = 'Alerta: el stock está en o por debajo del mínimo de '
                        . $cambio['minimum_stock'] . ' unidades.';
                }
            }
            $productosRestantesVoz = count($resumen['stock_changes']) - 5;
            if ($productosRestantesVoz > 0) {
                $frasesVoz[] = 'Hay ' . $productosRestantesVoz . ' productos adicionales actualizados.';
            }
            $_SESSION['inventario_escaneo_voz'] = implode(' ', $frasesVoz);
            unset($_SESSION['inventario_escaneo_error']);
        } catch (PDOException $exception) {
            if ($baseDatos->inTransaction()) {
                $baseDatos->rollBack();
            }
            error_log('No se pudo aplicar el Excel confirmado de escaneos: ' . $exception->getMessage());
            $_SESSION['inventario_escaneo_error'] = 'No se pudo aplicar el Excel. No se modificó el inventario.';
        } catch (RuntimeException $exception) {
            $_SESSION['inventario_escaneo_error'] = $exception->getMessage();
        } catch (Throwable $exception) {
            if ($baseDatos->inTransaction()) {
                $baseDatos->rollBack();
            }
            error_log('No se pudo aplicar el Excel confirmado de escaneos: ' . $exception->getMessage());
            $_SESSION['inventario_escaneo_error'] = 'No se pudo aplicar el Excel. No se modificó el inventario.';
        } finally {
            $this->descartarPrevisualizacionEscaneos();
        }

        header('Location: index.php?pagina=inventario&view=scan');
        exit;
    }

    private function descartarPrevisualizacionEscaneos(): void
    {
        $previsualizacion = $_SESSION['inventario_escaneos_previsualizacion'] ?? null;
        $ruta = is_array($previsualizacion) ? (string) ($previsualizacion['path'] ?? '') : '';
        if ($ruta !== '' && is_file($ruta)) {
            unlink($ruta);
        }
        unset($_SESSION['inventario_escaneos_previsualizacion']);
    }

    private function limpiarPrevisualizacionesEscaneosExpiradas(): void
    {
        $prefijo = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siva-scan-';
        foreach (glob($prefijo . '*') ?: [] as $ruta) {
            $modificadoEn = is_file($ruta) ? filemtime($ruta) : false;
            if ($modificadoEn !== false && $modificadoEn < time() - 1800) {
                unlink($ruta);
            }
        }
    }
}


final class PanelControlador extends ControladorBase
{
    public function mostrar(): void
    {
        $this->iniciarPagina();
        $baseDatos = $this->baseDatos;
        $usuarioActual = $this->usuarioActual;
        $tokenCsrf = $this->tokenCsrf;
        $avisoDemo = (string) ($_SESSION['demo_notice'] ?? '');
        unset($_SESSION['demo_notice']);

        $modelo = new PanelModelo($baseDatos);
        $resumen = $modelo->obtenerResumen();
        $ventasRecientes = $modelo->obtenerVentasRecientes();
        $alertasPrioritarias = $modelo->obtenerAlertasPrioritarias();
        $this->mostrarVista('panel_general', [
            'demoNotice' => $avisoDemo,
            'summary' => $resumen,
            'recentSales' => $ventasRecientes,
            'priorityAlerts' => $alertasPrioritarias,
        ], true);
    }
}


final class ProductosControlador extends ControladorBase
{
    public function mostrar(): void
    {
        $this->iniciarPagina(
            null,
            "default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self' data: blob:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'"
        );
        $baseDatos = $this->baseDatos;
        $usuarioActual = $this->usuarioActual;
        $accion = (string) ($_POST['action'] ?? '');
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $usuarioActual['role'] !== 'administrador') {
            http_response_code(403);
            exit('Solo el administrador puede modificar productos.');
        }
        $tokenCsrf = app_csrf_token();
        $modelo = new ProductosModelo($baseDatos);
        $categorias = $modelo->obtenerCategorias();
        $marcas = $modelo->obtenerMarcas();
        $marcasHistoricas = $modelo->obtenerMarcasHistoricas();
        $valoresFormulario = [
            'name' => '', 'sku' => '', 'category' => $categorias[0], 'brand' => $marcas[0],
            'custom_brand' => '',
            'size' => '', 'description' => '', 'unit_price' => '0.00', 'stock_a0101' => '0',
            'stock_a0102' => '0', 'stock_a0103' => '0', 'minimum_stock' => '6',
            'safety_stock' => '2', 'supplier' => '',
        ];
        $mensajeError = '';
        $mensajeExito = (string) ($_SESSION['product_flash'] ?? '');
        unset($_SESSION['product_flash']);
        $rutaImagenSubida = null;
        $editandoProducto = (string) ($_GET['view'] ?? '') === 'edit';
        $productoEnEdicion = null;
        if ($editandoProducto) {
            $idProductoEdicion = filter_var($_GET['product_id'] ?? null, FILTER_VALIDATE_INT);
            if ($idProductoEdicion !== false && $idProductoEdicion !== null && $idProductoEdicion > 0) {
                $productoEnEdicion = $modelo->obtenerProductoParaEditar($idProductoEdicion);
            }
            if ($productoEnEdicion === false || $productoEnEdicion === null) {
                $mensajeError = 'No se encontró el producto que deseas editar.';
                $editandoProducto = false;
            } elseif ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                foreach (['name', 'sku', 'category', 'brand', 'size', 'description', 'supplier'] as $campo) {
                    $valoresFormulario[$campo] = (string) $productoEnEdicion[$campo];
                }
                $valoresFormulario['unit_price'] = number_format(
                    (int) $productoEnEdicion['unit_price_cents'] / 100,
                    2,
                    '.',
                    ''
                );
            }
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'update_product_stock') {
            $idProducto = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
            $cantidades = [];
            foreach (['A0101' => 'stock_a0101', 'A0102' => 'stock_a0102', 'A0103' => 'stock_a0103'] as $almacen => $campo) {
                $cantidad = filter_var($_POST[$campo] ?? null, FILTER_VALIDATE_INT);
                if ($cantidad === false || $cantidad < 0) {
                    $cantidades = [];
                    break;
                }
                $cantidades[$almacen] = $cantidad;
            }
            if (!app_csrf_is_valid((string) ($_POST['csrf_token'] ?? ''))) {
                $mensajeError = 'El formulario venció. Recarga la página e inténtalo de nuevo.';
            } elseif ($idProducto === false || $idProducto < 1) {
                $mensajeError = 'Selecciona un producto válido.';
            } elseif (count($cantidades) !== 3) {
                $mensajeError = 'Escribe existencias enteras no negativas para los tres almacenes.';
            } else {
                try {
                    $resultadoStock = $modelo->actualizarStockCatalogo(
                        (int) $idProducto,
                        $cantidades,
                        (int) $_SESSION['user_id']
                    );
                    $_SESSION['product_flash'] = $resultadoStock['warehouses_updated'] === 0
                        ? 'Las existencias ya tenían esas cantidades; no se hicieron cambios.'
                        : 'Stock actualizado y registrado en Movimientos.';
                    header('Location: index.php?pagina=productos&' . http_build_query([
                        'catalog_q' => trim((string) ($_POST['catalog_q'] ?? '')),
                        'catalog_page' => (string) ($_POST['catalog_page'] ?? '1'),
                    ]));
                    exit;
                } catch (PDOException) {
                    $mensajeError = 'No se pudo actualizar el stock. Inténtalo de nuevo.';
                } catch (RuntimeException $exception) {
                    $mensajeError = $exception->getMessage();
                }
            }
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if ($accion === 'update_product_price') {
                $idProducto = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
                $precioTexto = trim((string) ($_POST['unit_price'] ?? ''));
                if (!app_csrf_is_valid((string) ($_POST['csrf_token'] ?? ''))) {
                    $mensajeError = 'El formulario venció. Recarga la página e inténtalo de nuevo.';
                } elseif ($idProducto === false || $idProducto < 1) {
                    $mensajeError = 'Selecciona un producto válido.';
                } elseif (!preg_match('/^\d+(?:[.,]\d{1,2})?$/', $precioTexto)) {
                    $mensajeError = 'Escribe un precio válido con hasta dos decimales.';
                } else {
                    try {
                        $precioCentavos = (int) round((float) str_replace(',', '.', $precioTexto) * 100);
                        $modelo->actualizarPrecio((int) $idProducto, $precioCentavos);
                        $_SESSION['product_flash'] = 'Precio de venta actualizado.';
                        header('Location: index.php?pagina=productos&' . http_build_query([
                            'catalog_q' => trim((string) ($_POST['catalog_q'] ?? '')),
                            'catalog_page' => (string) ($_POST['catalog_page'] ?? '1'),
                            'q' => trim((string) ($_POST['q'] ?? '')),
                            'category' => (string) ($_POST['category'] ?? ''),
                            'brand' => (string) ($_POST['brand'] ?? ''),
                            'history_page' => (string) ($_POST['history_page'] ?? '1'),
                        ]));
                        exit;
                    } catch (RuntimeException $exception) {
                        $mensajeError = $exception->getMessage();
                    } catch (PDOException) {
                        $mensajeError = 'No se pudo actualizar el precio. Inténtalo de nuevo.';
                    }
                }
            }
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'update_product_details') {
            foreach (['name', 'sku', 'category', 'brand', 'size', 'description', 'unit_price', 'supplier'] as $campo) {
                $valoresFormulario[$campo] = trim((string) ($_POST[$campo] ?? ''));
            }
            foreach (['name', 'sku', 'size', 'description', 'supplier'] as $campo) {
                $valoresFormulario[$campo] = mb_strtoupper($valoresFormulario[$campo], 'UTF-8');
            }
            $idProductoEdicion = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
            $precioTexto = $valoresFormulario['unit_price'];
            if (!app_csrf_is_valid((string) ($_POST['csrf_token'] ?? ''))) {
                $mensajeError = 'El formulario venció. Recarga la página e inténtalo de nuevo.';
            } elseif ($idProductoEdicion === false || $idProductoEdicion < 1) {
                $mensajeError = 'Selecciona un producto válido para editar.';
            } elseif ($valoresFormulario['name'] === '' || text_length($valoresFormulario['name']) > 160
                || $valoresFormulario['sku'] === '' || text_length($valoresFormulario['sku']) > 40
                || $valoresFormulario['size'] === '' || text_length($valoresFormulario['size']) > 80
            ) {
                $mensajeError = 'Completa el nombre, SKU y medida respetando sus límites.';
            } elseif ((!in_array($valoresFormulario['category'], $categorias, true)
                    && $valoresFormulario['category'] !== (string) ($productoEnEdicion['category'] ?? ''))
                || (!in_array($valoresFormulario['brand'], $marcas, true)
                    && $valoresFormulario['brand'] !== (string) ($productoEnEdicion['brand'] ?? ''))
            ) {
                $mensajeError = 'Selecciona una categoría y marca válidas.';
            } elseif (text_length($valoresFormulario['description']) > 1000
                || text_length($valoresFormulario['supplier']) > 160
            ) {
                $mensajeError = 'La descripción o el proveedor supera el límite permitido.';
            } elseif (!preg_match('/^\d+(?:[.,]\d{1,2})?$/', $precioTexto)) {
                $mensajeError = 'Escribe un precio válido con hasta dos decimales.';
            } else {
                try {
                    $datosProducto = $valoresFormulario;
                    foreach (['name', 'sku', 'brand', 'size', 'description', 'supplier'] as $campo) {
                        $datosProducto[$campo] = mb_strtoupper($datosProducto[$campo], 'UTF-8');
                    }
                    $modelo->actualizarProducto(
                        $idProductoEdicion,
                        $datosProducto,
                        (int) round((float) str_replace(',', '.', $precioTexto) * 100)
                    );
                    $_SESSION['product_flash'] = 'Producto actualizado correctamente.';
                    header('Location: index.php?pagina=productos&' . http_build_query([
                        'catalog_q' => $valoresFormulario['sku'],
                    ]));
                    exit;
                } catch (PDOException $exception) {
                    $mensajeError = str_contains(strtolower($exception->getMessage()), 'unique')
                        ? 'Ese SKU ya está registrado en otro producto.'
                        : 'No se pudo actualizar el producto. Revisa los datos e inténtalo de nuevo.';
                } catch (RuntimeException $exception) {
                    $mensajeError = $exception->getMessage();
                }
                $editandoProducto = true;
                $productoEnEdicion = $modelo->obtenerProductoParaEditar($idProductoEdicion);
            }
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'delete_product') {
            $idProductoEliminar = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
            if (!app_csrf_is_valid((string) ($_POST['csrf_token'] ?? ''))) {
                $mensajeError = 'El formulario venció. Recarga la página e inténtalo de nuevo.';
            } elseif ($idProductoEliminar === false || $idProductoEliminar < 1) {
                $mensajeError = 'Selecciona un producto válido para eliminar.';
            } else {
                try {
                    $rutaImagenEliminar = $modelo->eliminarProductoSinHistorial($idProductoEliminar);
                    if ($rutaImagenEliminar !== null) {
                        $nombreImagen = basename($rutaImagenEliminar);
                        $directorioImagenes = realpath(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'publico' . DIRECTORY_SEPARATOR . 'subidas' . DIRECTORY_SEPARATOR . 'productos');
                        $archivoImagen = $directorioImagenes === false ? false : realpath($directorioImagenes . DIRECTORY_SEPARATOR . $nombreImagen);
                        if ($archivoImagen !== false && str_starts_with($archivoImagen, $directorioImagenes . DIRECTORY_SEPARATOR) && !unlink($archivoImagen)) {
                            error_log('No se pudo eliminar la imagen del producto eliminado: ' . $archivoImagen);
                        }
                    }
                    $_SESSION['product_flash'] = 'Producto eliminado.';
                    header('Location: index.php?pagina=productos&' . http_build_query([
                        'catalog_q' => trim((string) ($_POST['catalog_q'] ?? '')),
                    ]));
                    exit;
                } catch (RuntimeException $exception) {
                    $mensajeError = $exception->getMessage();
                } catch (PDOException $exception) {
                    error_log('No se pudo eliminar el producto: ' . $exception->getMessage());
                    $mensajeError = 'No se pudo eliminar el producto porque tiene información relacionada.';
                }
            }
        }

        if (
            $_SERVER['REQUEST_METHOD'] === 'POST'
            && !in_array($accion, ['update_product_price', 'update_product_stock', 'update_product_details', 'delete_product'], true)
        ) {
            foreach ($valoresFormulario as $campo => $valor) {
                $valoresFormulario[$campo] = trim((string) ($_POST[$campo] ?? $valor));
            }
            foreach (['name', 'sku', 'size', 'description', 'supplier', 'custom_brand'] as $campo) {
                $valoresFormulario[$campo] = mb_strtoupper($valoresFormulario[$campo], 'UTF-8');
            }
            if (!app_csrf_is_valid((string) ($_POST['csrf_token'] ?? ''))) {
                $mensajeError = 'El formulario venció. Recarga la página e inténtalo de nuevo.';
            } elseif (($_POST['action'] ?? '') !== 'create_product') {
                $mensajeError = 'La acción solicitada no es válida.';
            } elseif ($valoresFormulario['name'] === '' || text_length($valoresFormulario['name']) > 160) {
                $mensajeError = 'Escribe un nombre de producto de hasta 160 caracteres.';
            } elseif (text_length($valoresFormulario['sku']) > 40) {
                $mensajeError = 'El SKU no puede superar 40 caracteres.';
            } elseif (!in_array($valoresFormulario['category'], $categorias, true)) {
                $mensajeError = 'Selecciona una categoría válida.';
            } elseif ($valoresFormulario['brand'] === 'Otra'
                && ($valoresFormulario['custom_brand'] === ''
                    || text_length($valoresFormulario['custom_brand']) > 120)
            ) {
                $mensajeError = 'Escribe el nombre de la marca, hasta 120 caracteres.';
            } elseif ($valoresFormulario['brand'] !== 'Otra'
                && !in_array($valoresFormulario['brand'], $marcas, true)
            ) {
                $mensajeError = 'Selecciona una marca válida.';
            } elseif ($valoresFormulario['size'] === '' || text_length($valoresFormulario['size']) > 80) {
                $mensajeError = 'Escribe la medida o tipo del producto.';
            } elseif (text_length($valoresFormulario['description']) > 1000 || text_length($valoresFormulario['supplier']) > 160) {
                $mensajeError = 'La descripción o el proveedor supera el límite permitido.';
            } elseif (!preg_match('/^\d+(?:[.,]\d{1,2})?$/', $valoresFormulario['unit_price'])) {
                $mensajeError = 'Escribe un precio válido con hasta dos decimales.';
            } elseif (filter_var($valoresFormulario['stock_a0101'], FILTER_VALIDATE_INT) === false || (int) $valoresFormulario['stock_a0101'] < 0
                || filter_var($valoresFormulario['stock_a0102'], FILTER_VALIDATE_INT) === false || (int) $valoresFormulario['stock_a0102'] < 0
                || filter_var($valoresFormulario['stock_a0103'], FILTER_VALIDATE_INT) === false || (int) $valoresFormulario['stock_a0103'] < 0
                || filter_var($valoresFormulario['minimum_stock'], FILTER_VALIDATE_INT) === false || (int) $valoresFormulario['minimum_stock'] < 0
                || filter_var($valoresFormulario['safety_stock'], FILTER_VALIDATE_INT) === false || (int) $valoresFormulario['safety_stock'] < 0
                || (int) $valoresFormulario['safety_stock'] > (int) $valoresFormulario['minimum_stock']) {
                $mensajeError = 'El stock inicial debe ser un número entero igual o mayor que cero.';
            } else {
                $precioCentavos = (int) round((float) str_replace(',', '.', $valoresFormulario['unit_price']) * 100);
                $rutaImagen = null;
                $imagen = $_FILES['image'] ?? null;
                if (is_array($imagen) && (int) ($imagen['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    if ((int) $imagen['error'] !== UPLOAD_ERR_OK) {
                        $mensajeError = 'No se pudo cargar la imagen. Inténtalo de nuevo.';
                    } elseif ((int) $imagen['size'] > 5 * 1024 * 1024) {
                        $mensajeError = 'La imagen no puede superar 5 MB.';
                    } else {
                        $tipoMime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $imagen['tmp_name']);
                        $extensiones = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                        if (!is_string($tipoMime) || !isset($extensiones[$tipoMime])) {
                            $mensajeError = 'Usa una imagen JPG, PNG o WEBP.';
                        } else {
                            $directorio = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'publico' . DIRECTORY_SEPARATOR . 'subidas' . DIRECTORY_SEPARATOR . 'productos';
                            if (!is_dir($directorio) && !mkdir($directorio, 0750, true) && !is_dir($directorio)) {
                                $mensajeError = 'No se pudo preparar la carpeta para imágenes.';
                            } else {
                                $nombreArchivo = bin2hex(random_bytes(16)) . '.' . $extensiones[$tipoMime];
                                $rutaImagenSubida = $directorio . DIRECTORY_SEPARATOR . $nombreArchivo;
                                if (!move_uploaded_file((string) $imagen['tmp_name'], $rutaImagenSubida)) {
                                    $mensajeError = 'No se pudo guardar la imagen del producto.';
                                    $rutaImagenSubida = null;
                                } else {
                                    $rutaImagen = '../uploads/products/' . $nombreArchivo;
                                }
                            }
                        }
                    }
                }

                if ($mensajeError === '') {
                    try {
                        if ($valoresFormulario['sku'] === '') {
                            $valoresFormulario['sku'] = $modelo->generarSkuAutomatico();
                        }
                        $datosProducto = $valoresFormulario;
                        if ($datosProducto['brand'] === 'Otra') {
                            $datosProducto['brand'] = $datosProducto['custom_brand'];
                        }
                        foreach (['name', 'sku', 'brand', 'size', 'description', 'supplier'] as $campo) {
                            $datosProducto[$campo] = mb_strtoupper($datosProducto[$campo], 'UTF-8');
                        }
                        $modelo->guardarProducto($datosProducto, $precioCentavos, $rutaImagen, (int) $_SESSION['user_id']);
                        $stockInicialTotal = (int) $valoresFormulario['stock_a0101']
                            + (int) $valoresFormulario['stock_a0102']
                            + (int) $valoresFormulario['stock_a0103'];
                        $_SESSION['product_flash'] = 'Producto agregado al catálogo: '
                            . $valoresFormulario['name'] . ' · SKU ' . $valoresFormulario['sku'] . '.';
                        $_SESSION['product_voice_flash'] = $stockInicialTotal > 0
                            ? 'Producto agregado. Stock inicial registrado.'
                            : 'Producto agregado sin stock inicial.';
                        $_SESSION['product_added_flash'] = [
                            'name' => $valoresFormulario['name'],
                            'sku' => $valoresFormulario['sku'],
                            'initial_stock' => $stockInicialTotal,
                        ];
                        header('Location: index.php?pagina=movimientos&' . http_build_query([
                            'q' => $valoresFormulario['sku'],
                        ]));
                        exit;
                    } catch (PDOException $exception) {
                        if ($rutaImagenSubida !== null && is_file($rutaImagenSubida)) {
                            unlink($rutaImagenSubida);
                        }
                        $mensajeError = str_contains(strtolower($exception->getMessage()), 'unique')
                            ? 'Ese SKU ya está registrado.'
                            : 'No se pudo guardar el producto. Revisa los datos e inténtalo otra vez.';
                    } catch (RuntimeException $exception) {
                        if ($rutaImagenSubida !== null && is_file($rutaImagenSubida)) {
                            unlink($rutaImagenSubida);
                        }
                        $mensajeError = $exception->getMessage();
                    }
                }
            }
        }

        $busqueda = trim((string) ($_GET['q'] ?? $_POST['q'] ?? ''));
        $filtroCategoria = (string) ($_GET['category'] ?? $_POST['category'] ?? '');
        $filtroMarca = (string) ($_GET['brand'] ?? $_POST['brand'] ?? '');
        $busquedaCatalogo = trim((string) ($_GET['catalog_q'] ?? $_POST['catalog_q'] ?? ''));
        $paginaCatalogo = filter_var($_GET['catalog_page'] ?? $_POST['catalog_page'] ?? 1, FILTER_VALIDATE_INT);
        $paginaCatalogo = $paginaCatalogo === false || $paginaCatalogo < 1 ? 1 : $paginaCatalogo;
        $paginaHistorica = filter_var($_GET['history_page'] ?? $_POST['history_page'] ?? 1, FILTER_VALIDATE_INT);
        $paginaHistorica = $paginaHistorica === false || $paginaHistorica < 1 ? 1 : $paginaHistorica;
        $resultadoCatalogo = $modelo->buscarCatalogo($busquedaCatalogo, $paginaCatalogo);
        $resultado = $modelo->buscarProductos(
            $busqueda,
            $filtroCategoria,
            $filtroMarca,
            $marcasHistoricas,
            $paginaHistorica
        );
        $productos = $resultado['productos'];
        $filtroCategoria = $resultado['categoria'];
        $filtroMarca = $resultado['marca'];
        $mostrarFormulario = (string) ($_GET['view'] ?? '') === 'new'
            || $editandoProducto
            || ($mensajeError !== '' && in_array((string) ($_POST['action'] ?? ''), ['create_product', 'update_product_details'], true));

        $this->mostrarVista('productos', [
            'categories' => $categorias,
            'brands' => $marcas,
            'formValues' => $valoresFormulario,
            'errorMessage' => $mensajeError,
            'successMessage' => $mensajeExito,
            'uploadedFilePath' => $rutaImagenSubida,
            'search' => $busqueda,
            'filterCategory' => $filtroCategoria,
            'filterBrand' => $filtroMarca,
            'historyPage' => $resultado['page'],
            'historyPages' => $resultado['pages'],
            'historyTotal' => $resultado['total'],
            'historyPerPage' => $resultado['per_page'],
            'catalogSearch' => $busquedaCatalogo,
            'catalogProducts' => $resultadoCatalogo['products'],
            'catalogTotal' => $resultadoCatalogo['total'],
            'catalogPage' => $resultadoCatalogo['page'],
            'catalogPages' => $resultadoCatalogo['pages'],
            'catalogPerPage' => $resultadoCatalogo['per_page'],
            'historicalBrands' => $marcasHistoricas,
            'products' => $productos,
            'showProductForm' => $mostrarFormulario,
            'editingProduct' => $editandoProducto,
            'productBeingEdited' => $productoEnEdicion,
        ], true);
    }
}


final class EtiquetasProductoControlador extends ControladorBase
{
    private const MAX_ETIQUETAS_POR_IMPRESION = 500;

    public function mostrar(): void
    {
        $this->iniciarPagina(
            null,
            "default-src 'self'; style-src 'self'; script-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'"
        );
        $idProducto = filter_var($_GET['product_id'] ?? null, FILTER_VALIDATE_INT);
        if ($idProducto === false || $idProducto < 1) {
            http_response_code(400);
            exit('Selecciona un producto válido para imprimir.');
        }

        $producto = (new ProductosModelo($this->baseDatos))->obtenerParaEtiqueta((int) $idProducto);
        if ($producto === false) {
            http_response_code(404);
            exit('No se encontró el producto seleccionado.');
        }

        $imprimirEtiquetas = (string) ($_GET['print'] ?? '') === '1';
        $almacenes = $this->baseDatos->query(
            'SELECT codigo AS code, nombre AS name FROM almacenes WHERE activo = 1 ORDER BY codigo'
        )->fetchAll();
        $codigosAlmacenes = array_column($almacenes, 'code');
        $almacen = trim((string) ($_GET['warehouse'] ?? 'A0101'));
        if (!in_array($almacen, $codigosAlmacenes, true)) {
            $almacen = (string) ($codigosAlmacenes[0] ?? '');
        }
        if ($almacen === '') {
            throw new RuntimeException('No hay almacenes activos para imprimir etiquetas.');
        }

        $modelo = new ProductosModelo($this->baseDatos);
        $esLlanta = InventarioUnidades::esLlanta($producto);
        if ($esLlanta) {
            $stock = $modelo->contarUnidadesDisponibles((int) $producto['id'], $almacen);
        } else {
            $consultaStock = $this->baseDatos->prepare(
                'SELECT cantidad FROM inventario WHERE producto_id = :product AND almacen_codigo = :warehouse'
            );
            $consultaStock->execute(['product' => $producto['id'], 'warehouse' => $almacen]);
            $stock = max(0, (int) $consultaStock->fetchColumn());
        }
        $desplazamiento = filter_var($_GET['offset'] ?? '0', FILTER_VALIDATE_INT);
        $desplazamiento = $desplazamiento === false || $desplazamiento < 0 ? 0 : $desplazamiento;
        $etiquetasDisponibles = $esLlanta ? max(0, $stock - $desplazamiento) : $stock;
        $copias = filter_var(
            $_GET['copies'] ?? (string) max(1, min($etiquetasDisponibles, self::MAX_ETIQUETAS_POR_IMPRESION)),
            FILTER_VALIDATE_INT
        );
        $copias = $copias === false || $copias < 1
            ? max(1, min($etiquetasDisponibles, self::MAX_ETIQUETAS_POR_IMPRESION))
            : min($copias, self::MAX_ETIQUETAS_POR_IMPRESION);
        $etiquetas = [];
        $codigo = strtoupper((string) $producto['sku']);
            $urlQr = InventoryMobileUrl::forCurrentApp([
                'pagina' => 'ventas',
                'view' => 'new',
                'warehouse' => $almacen,
                'scan_sku' => $codigo,
            ]) ?? $codigo;
            $qrSvg = InventoryQrCode::svg($urlQr);
        $svg = Code39Barcode::svg($codigo);
        for ($copia = 0; $copia < $copias && $copia < $etiquetasDisponibles; $copia++) {
                $etiquetas[] = ['barcode' => $codigo, 'barcode_svg' => $svg, 'qr_svg' => $qrSvg];
        }
        if ($imprimirEtiquetas && $esLlanta && count($etiquetas) !== $copias) {
            http_response_code(400);
            exit('No hay suficientes llantas disponibles en ese almacén para imprimir esa cantidad de etiquetas.');
        }
        $producto['stock'] = $stock;

        $this->mostrarVista('etiquetas_producto', [
            'producto' => $producto,
            'copias' => $copias,
            'imprimirEtiquetas' => $imprimirEtiquetas,
            'almacenes' => $almacenes,
            'almacenSeleccionado' => $almacen,
            'esLlanta' => $esLlanta,
            'desplazamiento' => $desplazamiento,
            'etiquetasDisponibles' => $etiquetasDisponibles,
            'etiquetas' => $etiquetas,
        ]);
    }
}


final class PronosticoControlador extends ControladorBase
{
    public function mostrar(): void
    {
        $this->iniciarPagina();
        $usuarioActual = $this->usuarioActual;
        $csrfToken = $this->tokenCsrf;
        $errorMessage = '';
        $pendingSalesImport = $_SESSION['pending_sales_import'] ?? null;
        if (is_array($pendingSalesImport)) {
            $pendingPath = (string) ($pendingSalesImport['staged_path'] ?? '');
            $pendingExpired = (int) ($pendingSalesImport['created_at'] ?? 0) < time() - 1800;
            if ($pendingExpired || $pendingPath === '' || !is_file($pendingPath)) {
                if ($pendingPath !== '' && is_file($pendingPath)) {
                    unlink($pendingPath);
                }
                unset($_SESSION['pending_sales_import']);
                $_SESSION['sales_import_error'] = $pendingExpired
                    ? 'La revisión venció después de 30 minutos. Selecciona de nuevo el archivo.'
                    : 'No se encontró el archivo pendiente. Selecciónalo de nuevo para importarlo.';
                $pendingSalesImport = null;
            }
        }
        $isReviewingSalesImport = $usuarioActual['role'] === 'administrador'
            && is_array($pendingSalesImport)
            && (string) ($_GET['revision'] ?? '') === '1';
        $successMessage = (string) ($_SESSION['sales_import_flash'] ?? '');
        $importErrorMessage = (string) ($_SESSION['sales_import_error'] ?? '');
        $showDuplicateUploadWarning = ($_SESSION['sales_import_duplicate_warning'] ?? false) === true;
        unset($_SESSION['sales_import_flash'], $_SESSION['sales_import_error'], $_SESSION['sales_import_duplicate_warning']);
        $selectedForecastWeek = null;
        $visibleBrands = [];
        $visibleProducts = [];
        $weekDayHeaders = [];
        $dailyForecastTotals = [];
        $dailyBrandForecastTotals = [];
        $recentSalesTotal = 0;
        $recentBrandSalesTotal = 0;
        $forecastMessage = '';

        try {
            if (!$isReviewingSalesImport) {
                $forecast = (new PronosticoModelo($this->baseDatos))->obtenerPronostico();
                $forecastMessage = $forecast['mensaje_pronostico'];
                $selectedForecastWeek = $forecast['semana_seleccionada'];
                $visibleBrands = $forecast['marcas_visibles'];
                $dailyBrandForecastTotals = $forecast['totales_marcas_por_dia'];
                $recentBrandSalesTotal = $forecast['total_ventas_recientes_marcas'];
                $visibleProducts = $forecast['productos_visibles'];
                $weekDayHeaders = $forecast['encabezados_dias'];
                $dailyForecastTotals = $forecast['totales_por_dia'];
                $recentSalesTotal = $forecast['total_ventas_recientes'];
            }
        } catch (RuntimeException | PDOException $exception) {
            $errorMessage = $exception->getMessage();
        }

        $this->mostrarVista('prediccion_marcas', [
            'errorMessage' => $errorMessage,
            'pendingSalesImport' => $pendingSalesImport,
            'isReviewingSalesImport' => $isReviewingSalesImport,
            'successMessage' => $successMessage,
            'importErrorMessage' => $importErrorMessage,
            'showDuplicateUploadWarning' => $showDuplicateUploadWarning,
            'forecastMessage' => $forecastMessage,
            'selectedForecastWeek' => $selectedForecastWeek,
            'visibleBrands' => $visibleBrands,
            'visibleProducts' => $visibleProducts,
            'weekDayHeaders' => $weekDayHeaders,
            'dailyForecastTotals' => $dailyForecastTotals,
            'dailyBrandForecastTotals' => $dailyBrandForecastTotals,
            'recentSalesTotal' => $recentSalesTotal,
            'recentBrandSalesTotal' => $recentBrandSalesTotal,
        ], true);
    }
}


final class UsuariosControlador extends ControladorBase
{
    private const ROLES = [
        'administrador' => 'Administrador',
        'vendedor' => 'Vendedor',
        'almacen' => 'Almacén',
    ];
    private const PERMISOS = [
        'administrador' => ['Panel', 'Productos', 'Compras', 'Ventas / Salidas', 'Inventario', 'Pronóstico', 'Usuarios'],
        'vendedor' => ['Panel', 'Productos', 'Ventas / Salidas', 'Pronóstico'],
        'almacen' => ['Panel', 'Productos', 'Compras', 'Inventario', 'Pronóstico'],
    ];

    public function mostrar(): void
    {
        $this->iniciarPagina(['administrador']);
        $baseDatos = $this->baseDatos;
        $usuarioActual = $this->usuarioActual;
        $tokenCsrf = $this->tokenCsrf;
        $modelo = new UsuariosModelo($baseDatos);
        $mensajeError = '';
        $mensajeExito = (string) ($_SESSION['users_flash'] ?? '');
        unset($_SESSION['users_flash']);
        $valoresNuevoUsuario = ['name' => '', 'email' => '', 'role' => 'vendedor'];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $accion = trim((string) ($_POST['action'] ?? ''));
            if (!app_csrf_is_valid((string) ($_POST['csrf_token'] ?? ''))) {
                $mensajeError = 'El formulario venció. Recarga la página e inténtalo de nuevo.';
            } elseif ($accion === 'create_user') {
                $valoresNuevoUsuario = [
                    'name' => trim((string) ($_POST['name'] ?? '')),
                    'email' => strtolower(trim((string) ($_POST['email'] ?? ''))),
                    'role' => trim((string) ($_POST['role'] ?? '')),
                ];
                $contrasena = (string) ($_POST['password'] ?? '');
                if ($valoresNuevoUsuario['name'] === '' || text_length($valoresNuevoUsuario['name']) > 120) {
                    $mensajeError = 'Escribe el nombre de la persona (máximo 120 caracteres).';
                } elseif (filter_var($valoresNuevoUsuario['email'], FILTER_VALIDATE_EMAIL) === false || text_length($valoresNuevoUsuario['email']) > 254) {
                    $mensajeError = 'Escribe un correo electrónico válido.';
                } elseif (!isset(self::ROLES[$valoresNuevoUsuario['role']])) {
                    $mensajeError = 'Selecciona un rol válido.';
                } elseif (text_length($contrasena) < 12) {
                    $mensajeError = 'La contraseña debe tener al menos 12 caracteres.';
                } else {
                    try {
                        $modelo->crearUsuario(
                            $valoresNuevoUsuario['name'],
                            $valoresNuevoUsuario['email'],
                            $contrasena,
                            $valoresNuevoUsuario['role']
                        );
                        $_SESSION['users_flash'] = 'La cuenta se creó correctamente.';
                        header('Location: index.php?pagina=usuarios');
                        exit;
                    } catch (PDOException $exception) {
                        if ($exception->getCode() === '23000') {
                            $mensajeError = 'Ya existe una cuenta con ese correo electrónico.';
                        } else {
                            error_log('No se pudo crear la cuenta: ' . $exception->getMessage());
                            $mensajeError = 'No se pudo crear la cuenta. Inténtalo de nuevo.';
                        }
                    }
                }
            } elseif ($accion === 'update_user') {
                $idUsuario = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
                $nombre = trim((string) ($_POST['name'] ?? ''));
                $correo = strtolower(trim((string) ($_POST['email'] ?? '')));
                $rol = trim((string) ($_POST['role'] ?? ''));
                $estado = trim((string) ($_POST['status'] ?? ''));
                if ($idUsuario === false || $idUsuario < 1) {
                    $mensajeError = 'La cuenta seleccionada no es válida.';
                } elseif ($nombre === '' || text_length($nombre) > 120) {
                    $mensajeError = 'El nombre debe tener entre 1 y 120 caracteres.';
                } elseif (filter_var($correo, FILTER_VALIDATE_EMAIL) === false || text_length($correo) > 254) {
                    $mensajeError = 'Escribe un correo electrónico válido.';
                } elseif (!isset(self::ROLES[$rol]) || !in_array($estado, ['active', 'inactive'], true)) {
                    $mensajeError = 'Selecciona un rol y estado válidos.';
                } else {
                    try {
                        $modelo->actualizarUsuario(
                            $idUsuario,
                            $nombre,
                            $correo,
                            $rol,
                            $estado === 'active',
                            (int) $usuarioActual['id']
                        );
                        $_SESSION['users_flash'] = 'La cuenta y sus permisos se actualizaron correctamente.';
                        header('Location: index.php?pagina=usuarios');
                        exit;
                    } catch (RuntimeException $exception) {
                        $mensajeError = $exception->getMessage();
                    } catch (PDOException $exception) {
                        if ($exception->getCode() === '23000') {
                            $mensajeError = 'Ya existe otra cuenta con ese correo electrónico.';
                        } else {
                            error_log('No se pudo actualizar la cuenta: ' . $exception->getMessage());
                            $mensajeError = 'No se pudo actualizar la cuenta. Inténtalo de nuevo.';
                        }
                    }
                }
            } elseif ($accion === 'reset_password') {
                $idUsuario = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
                $contrasena = (string) ($_POST['password'] ?? '');
                if ($idUsuario === false || $idUsuario < 1) {
                    $mensajeError = 'La cuenta seleccionada no es válida.';
                } elseif (text_length($contrasena) < 12) {
                    $mensajeError = 'La nueva contraseña debe tener al menos 12 caracteres.';
                } elseif (!$modelo->restablecerContrasena($idUsuario, $contrasena)) {
                    $mensajeError = 'No se pudo restablecer la contraseña de esa cuenta.';
                } else {
                    $_SESSION['users_flash'] = 'La contraseña se restableció. Entrégala a la persona de forma segura.';
                    header('Location: index.php?pagina=usuarios');
                    exit;
                }
            } else {
                $mensajeError = 'La acción solicitada no es válida.';
            }
        }

        $usuarios = $modelo->obtenerUsuarios();
        $administradoresActivos = count(array_filter(
            $usuarios,
            static fn (array $usuario): bool => $usuario['role'] === 'administrador' && (int) $usuario['is_active'] === 1
        ));
        $this->mostrarVista('usuarios', [
            'roles' => self::ROLES,
            'rolePermissions' => self::PERMISOS,
            'errorMessage' => $mensajeError,
            'successMessage' => $mensajeExito,
            'newUserValues' => $valoresNuevoUsuario,
            'users' => $usuarios,
            'activeAdministratorCount' => $administradoresActivos,
        ], true);
    }
}


final class VentasControlador extends ControladorBase
{
    public function mostrar(): void
    {
        $this->iniciarPagina(
            ['administrador', 'vendedor'],
            "default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'"
        );
        $baseDatos = $this->baseDatos;
        $usuarioActual = $this->usuarioActual;
        $tokenCsrf = $this->tokenCsrf;
        $modelo = new VentasModelo($baseDatos);
        $mensajeError = (string) ($_SESSION['sale_error_flash'] ?? '');
        unset($_SESSION['sale_error_flash']);
        $mostrarConfirmacionDuplicado = false;
        $confirmarPosibleDuplicado = ($_POST['confirmar_posible_duplicado'] ?? '') === '1';
        $mensajeExito = (string) ($_SESSION['sale_flash'] ?? '');
        $mensajeVozExito = (string) ($_SESSION['sale_voice_flash'] ?? '');
        unset($_SESSION['sale_flash'], $_SESSION['sale_voice_flash']);
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['barcode_lookup'])) {
            $almacenEscaneo = strtoupper(trim((string) ($_GET['warehouse'] ?? '')));
            $codigoEscaneado = trim((string) $_GET['barcode_lookup']);
            header('Content-Type: application/json; charset=utf-8');
            if (!in_array($almacenEscaneo, ['A0101', 'A0102', 'A0103'], true)
                || $codigoEscaneado === '' || strlen($codigoEscaneado) > 80
            ) {
                http_response_code(400);
                echo json_encode(['error' => 'El código o almacén no es válido.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            try {
                $unidad = $modelo->buscarProductoDisponiblePorSku($codigoEscaneado, $almacenEscaneo);
                $tipoEscaneo = 'sku';
                if ($unidad === false) {
                    $unidad = $modelo->buscarUnidadDisponible($codigoEscaneado, $almacenEscaneo);
                    $tipoEscaneo = 'unidad';
                }
                if ($unidad === false) {
                    http_response_code(404);
                    echo json_encode(['error' => 'No encontramos un producto disponible con ese SKU o código en este almacén.'], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                echo json_encode([
                    'product_id' => (int) ($unidad['product_id'] ?? $unidad['id']),
                    'sku' => (string) $unidad['sku'],
                    'name' => (string) $unidad['name'],
                    'category' => (string) ($unidad['category'] ?? 'Llantas'),
                    'barcode' => $tipoEscaneo === 'sku' ? (string) $unidad['sku'] : (string) $unidad['barcode'],
                    'scan_type' => $tipoEscaneo,
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            } catch (Throwable $exception) {
                error_log('No se pudo buscar la unidad escaneada para venta: ' . $exception->getMessage());
                http_response_code(500);
                echo json_encode(['error' => 'No se pudo comprobar el código escaneado.'], JSON_UNESCAPED_UNICODE);
            }
            exit;
        }
        $valoresFormulario = [
            'product_ids' => [''],
            'quantities' => ['1'],
            'scanned_barcodes_by_product' => [],
            'warehouse_code' => 'A0101',
            'sale_date' => date('Y-m-d'),
            'notes' => '',
        ];
        $accionVenta = (string) ($_POST['action'] ?? '');
        if ($_SERVER['REQUEST_METHOD'] === 'POST'
            && in_array($accionVenta, ['report_sale_issue', 'update_sale_detail', 'cancel_sale_detail', 'cancel_sale', 'close_sale_report'], true)
        ) {
            $mensajeAccion = '';
            try {
                if (!app_csrf_is_valid((string) ($_POST['csrf_token'] ?? ''))) {
                    throw new RuntimeException('El formulario venció. Recarga la página e inténtalo de nuevo.');
                }

                if ($accionVenta === 'report_sale_issue') {
                    if ($usuarioActual['role'] !== 'vendedor') {
                        http_response_code(403);
                        exit('Solo el vendedor puede reportar una venta desde este formulario.');
                    }
                    $idVentaReporte = filter_var($_POST['sale_id'] ?? null, FILTER_VALIDATE_INT);
                    $idDetalleReporte = filter_var($_POST['detail_id'] ?? null, FILTER_VALIDATE_INT);
                    $motivoReporte = trim((string) ($_POST['reason'] ?? ''));
                    $detalleReporte = trim((string) ($_POST['details'] ?? ''));
                    if ($idVentaReporte === false || $idVentaReporte < 1
                        || $idDetalleReporte === false || $idDetalleReporte < 1
                    ) {
                        throw new RuntimeException('Selecciona una línea de venta válida para reportar.');
                    }
                    if (!in_array($motivoReporte, ['producto_incorrecto', 'cantidad_incorrecta', 'venta_no_realizada', 'otro'], true)) {
                        throw new RuntimeException('Selecciona el motivo del reporte.');
                    }
                    if ($detalleReporte === '' || text_length($detalleReporte) > 500) {
                        throw new RuntimeException('Explica el problema en hasta 500 caracteres.');
                    }
                    $modelo->reportarErrorVenta(
                        (int) $idVentaReporte,
                        (int) $idDetalleReporte,
                        (int) $usuarioActual['id'],
                        $motivoReporte,
                        $detalleReporte
                    );
                    $_SESSION['sale_flash'] = 'Reporte enviado al administrador. La venta y el stock no cambian hasta que se revise.';
                } elseif ($usuarioActual['role'] !== 'administrador') {
                    http_response_code(403);
                    exit('Solo el administrador puede corregir o cerrar reportes de ventas.');
                } elseif ($accionVenta === 'update_sale_detail') {
                    $idDetalle = filter_var($_POST['detail_id'] ?? null, FILTER_VALIDATE_INT);
                    $idProducto = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
                    $cantidad = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
                    $motivoCorreccion = trim((string) ($_POST['correction_reason'] ?? ''));
                    if ($idDetalle === false || $idDetalle < 1 || $idProducto === false || $idProducto < 1
                        || $cantidad === false || $cantidad < 1
                        || $motivoCorreccion === '' || text_length($motivoCorreccion) > 450
                    ) {
                        throw new RuntimeException('Selecciona el producto correcto, una cantidad válida y explica la corrección (hasta 450 caracteres).');
                    }
                    $modelo->corregirDetalleVenta(
                        (int) $idDetalle,
                        (int) $idProducto,
                        (int) $cantidad,
                        (int) $usuarioActual['id'],
                        $motivoCorreccion
                    );
                    $_SESSION['sale_flash'] = 'Venta corregida. El stock, los movimientos y los reportes quedaron actualizados.';
                } elseif ($accionVenta === 'cancel_sale_detail') {
                    $idDetalle = filter_var($_POST['detail_id'] ?? null, FILTER_VALIDATE_INT);
                    $motivoAnulacion = trim((string) ($_POST['cancellation_reason'] ?? ''));
                    if ($idDetalle === false || $idDetalle < 1) {
                        throw new RuntimeException('Selecciona una línea de venta válida para anular.');
                    }
                    if ($motivoAnulacion === '' || text_length($motivoAnulacion) > 450) {
                        throw new RuntimeException('Escribe el motivo de la anulación, hasta 450 caracteres.');
                    }
                    $modelo->anularDetalleVenta(
                        (int) $idDetalle,
                        (int) $usuarioActual['id'],
                        $motivoAnulacion
                    );
                    $_SESSION['sale_flash'] = 'Línea anulada. El stock volvió al almacén y quedó el movimiento de ajuste.';
                } elseif ($accionVenta === 'cancel_sale') {
                    $idVenta = filter_var($_POST['sale_id'] ?? null, FILTER_VALIDATE_INT);
                    $motivoAnulacion = trim((string) ($_POST['cancellation_reason'] ?? ''));
                    if ($idVenta === false || $idVenta < 1) {
                        throw new RuntimeException('Selecciona una venta válida para anular.');
                    }
                    if ($motivoAnulacion === '' || text_length($motivoAnulacion) > 450) {
                        throw new RuntimeException('Escribe el motivo de la anulación, hasta 450 caracteres.');
                    }
                    $modelo->anularVentaCompleta(
                        (int) $idVenta,
                        (int) $usuarioActual['id'],
                        $motivoAnulacion
                    );
                    $_SESSION['sale_flash'] = 'Venta anulada. Todo el stock volvió a sus almacenes y quedó el historial de ajustes.';
                } else {
                    $idReporte = filter_var($_POST['report_id'] ?? null, FILTER_VALIDATE_INT);
                    $notaRevision = trim((string) ($_POST['resolution_note'] ?? ''));
                    if ($idReporte === false || $idReporte < 1 || text_length($notaRevision) > 500) {
                        throw new RuntimeException('El reporte o la nota de revisión no son válidos.');
                    }
                    $modelo->cerrarReporteVenta(
                        (int) $idReporte,
                        (int) $usuarioActual['id'],
                        $notaRevision
                    );
                    $_SESSION['sale_flash'] = 'Reporte cerrado sin modificar la venta.';
                }

                header('Location: index.php?pagina=ventas');
                exit;
            } catch (RuntimeException $exception) {
                $mensajeAccion = $exception->getMessage();
            } catch (Throwable $exception) {
                error_log('No se pudo procesar la revisión de una venta: ' . $exception->getMessage());
                $mensajeAccion = 'No se pudo procesar la acción. No se hicieron cambios; inténtalo de nuevo.';
            }
            $_SESSION['sale_error_flash'] = $mensajeAccion;
            header('Location: index.php?pagina=ventas');
            exit;
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $valoresFormulario['product_ids'] = array_values(array_map(
                static fn ($value): string => trim((string) $value),
                (array) ($_POST['product_ids'] ?? [])
            ));
            $valoresFormulario['quantities'] = array_values(array_map(
                static fn ($value): string => trim((string) $value),
                (array) ($_POST['quantities'] ?? [])
            ));
            $valoresFormulario['scanned_barcodes_by_product'] = [];
            $codigosEscaneados = [];
            $unidadesInvalidas = false;
            foreach ((array) ($_POST['scanned_barcodes'] ?? []) as $idProducto => $codigos) {
                $idValidado = filter_var($idProducto, FILTER_VALIDATE_INT);
                if ($idValidado === false || $idValidado < 1 || !is_array($codigos)) {
                    $unidadesInvalidas = true;
                    continue;
                }
                $codigosLimpios = [];
                foreach ($codigos as $codigo) {
                    if (!is_string($codigo)) {
                        $unidadesInvalidas = true;
                        continue;
                    }
                    $codigosLimpios[] = trim($codigo);
                }
                $valoresFormulario['scanned_barcodes_by_product'][(int) $idValidado] = $codigosLimpios;
                foreach ($codigosLimpios as $codigo) {
                    $codigosEscaneados[] = $codigo;
                }
            }
            $valoresFormulario['warehouse_code'] = trim((string) ($_POST['warehouse_code'] ?? ''));
            $valoresFormulario['sale_date'] = trim((string) ($_POST['sale_date'] ?? ''));
            $valoresFormulario['notes'] = trim((string) ($_POST['notes'] ?? ''));
            $fechaVenta = DateTimeImmutable::createFromFormat('!Y-m-d', $valoresFormulario['sale_date']);
            $lineas = [];
            $lineaInvalida = count($valoresFormulario['product_ids']) !== count($valoresFormulario['quantities'])
                || count($valoresFormulario['product_ids']) > 30
                || $unidadesInvalidas;
            foreach ($valoresFormulario['product_ids'] as $indice => $productoEntrada) {
                $cantidadEntrada = $valoresFormulario['quantities'][$indice] ?? '';
                if ($productoEntrada === '') {
                    continue;
                }
                $idProducto = filter_var($productoEntrada, FILTER_VALIDATE_INT);
                $cantidad = filter_var($cantidadEntrada, FILTER_VALIDATE_INT);
                if ($idProducto === false || $idProducto < 1 || $cantidad === false || $cantidad < 1) {
                    $lineaInvalida = true;
                    continue;
                }
                $lineas[] = ['product_id' => $idProducto, 'quantity' => $cantidad];
            }

            if (!app_csrf_is_valid((string) ($_POST['csrf_token'] ?? ''))) {
                $mensajeError = 'El formulario venció. Recarga la página e inténtalo de nuevo.';
            } elseif (($_POST['action'] ?? '') !== 'create_sale') {
                $mensajeError = 'La acción solicitada no es válida.';
            } elseif ($lineas === []) {
                $mensajeError = 'Selecciona un producto de la lista y escribe una cantidad de al menos 1.';
            } elseif ($lineaInvalida) {
                $mensajeError = 'Revisa la venta: cada producto seleccionado debe tener una cantidad válida.';
            } elseif (count($codigosEscaneados) > 500) {
                $mensajeError = 'La venta supera el máximo de 500 códigos escaneados.';
            } elseif (!$fechaVenta || $fechaVenta->format('Y-m-d') !== $valoresFormulario['sale_date']) {
                $mensajeError = 'Selecciona una fecha válida.';
            } elseif (!in_array($valoresFormulario['warehouse_code'], ['A0101', 'A0102', 'A0103'], true)) {
                $mensajeError = 'Selecciona un almacén válido.';
            } elseif (text_length($valoresFormulario['notes']) > 500) {
                $mensajeError = 'Las notas no pueden superar los 500 caracteres.';
            } else {
                try {
                    if (!$confirmarPosibleDuplicado && $modelo->coincideConVentaImportada(
                        $lineas,
                        $valoresFormulario['warehouse_code'],
                        $valoresFormulario['sale_date']
                    )) {
                        $mostrarConfirmacionDuplicado = true;
                        $mensajeError = 'Encontramos un registro con el mismo producto, fecha, almacén y cantidad en el Excel o en el historial. Revisa antes de continuar.';
                    } else {
                        $resultado = $modelo->registrarSalida(
                            $lineas,
                            $valoresFormulario['warehouse_code'],
                            $valoresFormulario['sale_date'],
                            $valoresFormulario['notes'],
                            (int) $_SESSION['user_id'],
                            $codigosEscaneados
                        );
                        $_SESSION['sale_flash'] = 'Salida ' . $resultado['numero'] . ' registrada. Se descontaron '
                            . $resultado['unidades'] . ' unidades de ' . $valoresFormulario['warehouse_code'] . '.';
                        $_SESSION['sale_voice_flash'] = 'Venta registrada correctamente.';
                        header('Location: index.php?pagina=ventas');
                        exit;
                    }
                } catch (RuntimeException $exception) {
                    $mensajeError = $exception->getMessage();
                } catch (Throwable $exception) {
                    error_log('No se pudo registrar la salida: ' . $exception->getMessage());
                    $mensajeError = 'No se pudo registrar la salida. No se modificó el stock; revisa los datos e inténtalo otra vez.';
                }
            }
        }

        $productos = $modelo->obtenerProductos();
        $mensajeEscaneo = '';
        $mensajeVozEscaneo = '';
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['scan_sku'])) {
            $skuEntrada = $_GET['scan_sku'];
            $almacenEntrada = $_GET['warehouse'] ?? null;
            if (!is_string($skuEntrada)
                || preg_match('/^[A-Z0-9 .\\-$\\/+%]{1,40}$/D', strtoupper(trim($skuEntrada))) !== 1
            ) {
                $mensajeError = 'El código de la etiqueta no es válido.';
            } elseif ($almacenEntrada !== null
                && (!is_string($almacenEntrada) || !in_array($almacenEntrada, ['A0101', 'A0102', 'A0103'], true))
            ) {
                $mensajeError = 'El almacén de la etiqueta no es válido.';
            } else {
                if (is_string($almacenEntrada)) {
                    $valoresFormulario['warehouse_code'] = $almacenEntrada;
                }
                $skuEscaneado = strtoupper(trim($skuEntrada));
                $productoEscaneado = null;
                foreach ($productos as $producto) {
                    if (strtoupper((string) $producto['sku']) === $skuEscaneado) {
                        $productoEscaneado = $producto;
                        break;
                    }
                }
                if ($productoEscaneado === null) {
                    $mensajeError = 'El código de la etiqueta no corresponde a un producto registrado.';
                } else {
                    $valoresFormulario['product_ids'] = [(string) $productoEscaneado['id']];
                    $valoresFormulario['quantities'] = ['1'];
                    $mensajeEscaneo = 'Etiqueta reconocida: ' . $productoEscaneado['sku'] . ' · '
                        . $productoEscaneado['name'] . '. Revisa la venta antes de registrarla.';
                    $mensajeVozEscaneo = 'Llevas 1 unidad escaneada.';
                }
            }
        }
        $almacenes = $modelo->obtenerAlmacenes();
        $detalleVentaEdicion = null;
        if ((string) ($_GET['view'] ?? '') === 'edit-sale') {
            if ($usuarioActual['role'] !== 'administrador') {
                http_response_code(403);
                exit('Solo el administrador puede editar las ventas.');
            }
            $idDetalleEdicion = filter_var($_GET['detail_id'] ?? null, FILTER_VALIDATE_INT);
            if ($idDetalleEdicion === false || $idDetalleEdicion < 1) {
                $mensajeError = 'Selecciona una línea de venta válida para editar.';
            } else {
                $detalleVentaEdicion = $modelo->obtenerDetalleVentaParaEditar((int) $idDetalleEdicion);
                if ($detalleVentaEdicion === false) {
                    $mensajeError = 'La línea de venta ya fue anulada o no existe.';
                    $detalleVentaEdicion = null;
                }
            }
        }
        $historial = $modelo->obtenerHistorial($_GET, (string) $usuarioActual['role'], (int) $usuarioActual['id']);
        $ventasRecientes = $historial['ventas'];
        $busquedaHistorial = $historial['busqueda'];
        $almacenHistorial = $historial['almacen'];
        $fechaDesde = $historial['desde'];
        $fechaHasta = $historial['hasta'];
        $vistaVenta = (string) ($_GET['view'] ?? '');
        $mostrarFormulario = $vistaVenta === 'new'
            || ($mensajeError !== '' && $vistaVenta !== 'edit-sale');
        $reportesPendientes = $usuarioActual['role'] === 'administrador'
            ? $modelo->obtenerReportesPendientes()
            : [];

        $this->mostrarVista('ventas', [
            'products' => $productos,
            'warehouses' => $almacenes,
            'errorMessage' => $mensajeError,
            'scanMessage' => $mensajeEscaneo,
            'scanVoiceMessage' => $mensajeVozEscaneo,
            'showDuplicateConfirmation' => $mostrarConfirmacionDuplicado,
            'successMessage' => $mensajeExito,
            'saleVoiceMessage' => $mensajeVozExito,
            'formValues' => $valoresFormulario,
            'recentSales' => $ventasRecientes,
            'historySearch' => $busquedaHistorial,
            'historyWarehouse' => $almacenHistorial,
            'historyFrom' => $fechaDesde,
            'historyTo' => $fechaHasta,
            'showSaleForm' => $mostrarFormulario,
            'editingSaleLine' => $detalleVentaEdicion,
            'pendingSaleReports' => $reportesPendientes,
            'isSalesAdministrator' => $usuarioActual['role'] === 'administrador',
            'isSalesVendor' => $usuarioActual['role'] === 'vendedor',
        ], true);
    }
}
