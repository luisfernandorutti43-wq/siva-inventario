<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'web.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Modelos' . DIRECTORY_SEPARATOR . 'Modelos.php';

abstract class ControladorBase
{
    protected PDO $baseDatos;
    protected array $usuarioActual;
    protected string $tokenCsrf;

    protected function iniciarPagina(
        ?array $rolesPermitidos = null,
        ?string $politicaContenido = null,
        bool $requiereAutenticacion = true
    ): void {
        app_start_web_session();
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header(
            'Content-Security-Policy: ' . ($politicaContenido
                ?? "default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'")
        );

        $this->baseDatos = app_database();
        $this->usuarioActual = $requiereAutenticacion
            ? ($rolesPermitidos === null
                ? app_require_authenticated_user($this->baseDatos)
                : app_require_role($this->baseDatos, $rolesPermitidos))
            : [];
        $this->tokenCsrf = app_csrf_token();
    }

    protected function mostrarVista(string $nombre, array $datos = [], bool $incluirBarraSuperior = false): void
    {
        $database = $this->baseDatos;
        $currentUser = $this->usuarioActual;
        $csrfToken = $this->tokenCsrf;
        if ($incluirBarraSuperior) {
            $datos = array_merge((new BarraSuperiorModelo($this->baseDatos))->obtenerAlertas(), $datos);
        }
        extract($datos, EXTR_OVERWRITE);

        require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Vistas' . DIRECTORY_SEPARATOR
            . 'paginas' . DIRECTORY_SEPARATOR . $nombre . '.php';
    }
}