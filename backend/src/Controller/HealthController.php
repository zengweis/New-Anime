<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\Response;

final class HealthController
{
    public function index(): void
    {
        Response::ok([
            'status'  => 'ok',
            'php'     => PHP_VERSION,
            'time'    => date('c'),
            'timezone' => date_default_timezone_get(),
        ]);
    }
}
