<?php
declare(strict_types=1);

// Centraliza sesiones, autenticación, roles, rutas iniciales y protección CSRF.
function app_start_web_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // Protege la cookie y prepara el token que usarán los formularios.
    $isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_name('black4x4_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
}

function app_require_authenticated_user(PDO $database): array
{
    // Bloquea páginas privadas si la sesión no corresponde a una cuenta activa.
    if (!isset($_SESSION['user_id'])) {
        $returnPath = app_scan_return_path($_GET);
        $loginPath = 'index.php?pagina=login';
        if ($returnPath !== null) {
            $loginPath .= '&return_to=' . rawurlencode($returnPath);
        }
        header('Location: ' . $loginPath);
        exit;
    }

    $statement = $database->prepare(
        'SELECT id, nombre_completo AS full_name, correo AS email, rol AS role FROM usuarios WHERE id = :id AND activo = 1'
    );
    $statement->execute(['id' => (int) $_SESSION['user_id']]);
    $user = $statement->fetch();

    if ($user === false) {
        unset($_SESSION['user_id']);
        header('Location: index.php?pagina=login');
        exit;
    }

    return $user;
}

function app_sale_scan_path(array $parameters): ?string
{
    $sku = $parameters['scan_sku'] ?? null;
    $warehouse = $parameters['warehouse'] ?? null;
    if (($parameters['pagina'] ?? '') !== 'ventas'
        || ($parameters['view'] ?? '') !== 'new'
        || !is_string($sku)
        || preg_match('/^[A-Z0-9 .\\-$\\/+%]{1,40}$/D', $sku) !== 1
        || ($warehouse !== null
            && (!is_string($warehouse) || !in_array($warehouse, ['A0101', 'A0102', 'A0103'], true)))
    ) {
        return null;
    }

    $pathParameters = [
        'pagina' => 'ventas',
        'view' => 'new',
        'scan_sku' => $sku,
    ];
    if ($warehouse !== null) {
        $pathParameters['warehouse'] = $warehouse;
    }

    return 'index.php?' . http_build_query($pathParameters);
}

function app_inventory_count_scan_path(array $parameters): ?string
{
    $barcode = $parameters['barcode'] ?? null;
    $warehouse = $parameters['warehouse'] ?? null;
    if (($parameters['pagina'] ?? '') !== 'inventario'
        || ($parameters['view'] ?? '') !== 'count'
        || ($parameters['count_scan'] ?? '') !== '1'
        || !is_string($warehouse)
        || !in_array($warehouse, ['A0101', 'A0102', 'A0103'], true)
        || !is_string($barcode)
        || preg_match('/^[A-Z0-9 .\\-$\\/+%]{1,80}$/D', $barcode) !== 1
    ) {
        return null;
    }

    return 'index.php?' . http_build_query([
        'pagina' => 'inventario',
        'view' => 'count',
        'warehouse' => $warehouse,
        'barcode' => $barcode,
        'count_scan' => '1',
    ]);
}

function app_scan_return_path(array $parameters): ?string
{
    return app_sale_scan_path($parameters) ?? app_inventory_count_scan_path($parameters);
}

function app_login_return_path(string $returnTo): ?string
{
    $components = parse_url($returnTo);
    if (!is_array($components)
        || ($components['path'] ?? '') !== 'index.php'
        || isset($components['scheme'])
        || isset($components['host'])
        || isset($components['user'])
        || isset($components['pass'])
        || isset($components['fragment'])
    ) {
        return null;
    }

    $parameters = [];
    parse_str((string) ($components['query'] ?? ''), $parameters);

    return app_scan_return_path($parameters);
}

function app_home_path_for_role(string $role): string
{
    // Todos los roles comienzan en el panel, que muestra acciones según sus permisos.
    return match ($role) {
        'administrador', 'vendedor', 'almacen' => 'index.php?pagina=home',
        default => 'index.php?pagina=login',
    };
}

function app_require_role(PDO $database, array $allowedRoles): array
{
    // Verifica identidad y rol antes de permitir una operación del módulo.
    $user = app_require_authenticated_user($database);
    if (!in_array($user['role'], $allowedRoles, true)) {
        http_response_code(403);
        exit('No tienes permiso para acceder a esta sección.');
    }

    return $user;
}

function app_csrf_token(): string
{
    // Entrega el token que debe incluir cada formulario que modifica datos.
    return (string) ($_SESSION['csrf_token'] ?? '');
}

function app_csrf_is_valid(string $token): bool
{
    // Compara el token recibido con el de la sesión para bloquear CSRF.
    $sessionToken = app_csrf_token();

    return $sessionToken !== '' && hash_equals($sessionToken, $token);
}
