<?php
declare(strict_types=1);

$rutas = [
    'home' => ['Controladores.php', 'PanelControlador', 'mostrar'],
    'productos' => ['Controladores.php', 'ProductosControlador', 'mostrar'],
    'ventas' => ['Controladores.php', 'VentasControlador', 'mostrar'],
    'compras' => ['Controladores.php', 'ComprasControlador', 'mostrar'],
    'inventario' => ['Controladores.php', 'InventarioControlador', 'mostrar'],
    'movimientos' => ['Controladores.php', 'InventarioControlador', 'mostrar'],
    'etiquetas_inventario' => ['Controladores.php', 'EtiquetasInventarioControlador', 'mostrar'],
    'etiquetas_producto' => ['Controladores.php', 'EtiquetasProductoControlador', 'mostrar'],
    'prediccion' => ['Controladores.php', 'PronosticoControlador', 'mostrar'],
    'prediccion_marcas' => ['Controladores.php', 'PronosticoControlador', 'mostrar'],
    'usuarios' => ['Controladores.php', 'UsuariosControlador', 'mostrar'],
    'login' => ['Controladores.php', 'AccesoControlador', 'mostrar'],
    'importar' => ['Controladores.php', 'ImportarVentasControlador', 'manejar'],
];

$pagina = trim((string) ($_GET['pagina'] ?? ''));
if ($pagina === '') {
    $idProductoOperativo = filter_var($_GET['operational_product_id'] ?? null, FILTER_VALIDATE_INT);
    if ($idProductoOperativo !== false
        && $idProductoOperativo !== null
        && $idProductoOperativo > 0
        && trim((string) ($_GET['warehouse'] ?? '')) !== ''
    ) {
        $pagina = 'etiquetas_inventario';
    } else {
        $pagina = 'home';
    }
}
if (!isset($rutas[$pagina])) {
    http_response_code(404);
    exit('No se encontró la página solicitada.');
}

[$archivoControlador, $nombreControlador, $metodo] = $rutas[$pagina];
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'aplicacion' . DIRECTORY_SEPARATOR
    . 'Controladores' . DIRECTORY_SEPARATOR . $archivoControlador;
(new $nombreControlador())->$metodo();
