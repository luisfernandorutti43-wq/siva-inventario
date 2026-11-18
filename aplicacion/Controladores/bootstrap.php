<?php
declare(strict_types=1);

// Carga la conexión y define utilidades reutilizadas por las páginas PHP.
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'configuracion' . DIRECTORY_SEPARATOR . 'conexion.php';

// Lee una configuración opcional guardada en la tabla configuracion_app.
function app_setting(PDO $database, string $key): ?string
{
    $statement = $database->prepare('SELECT valor FROM configuracion_app WHERE clave = :key');
    $statement->execute(['key' => $key]);
    $value = $statement->fetchColumn();

    return $value === false ? null : (string) $value;
}

// Evita que texto de la base o formularios se interprete como HTML ejecutable.
function escape_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Cuenta caracteres correctamente incluso si mbstring no está habilitado.
function text_length(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }

    preg_match_all('/./us', $value, $characters);

    return count($characters[0]);
}