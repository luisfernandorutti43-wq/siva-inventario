<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'Controladores' . DIRECTORY_SEPARATOR . 'Controladores.php';

(new ImportarVentasControlador())->manejar();