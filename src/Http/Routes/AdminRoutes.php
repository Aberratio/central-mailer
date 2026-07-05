<?php

declare(strict_types=1);

namespace CentralMailer\Http\Routes;

use CentralMailer\Controllers\AdminController;
use Slim\App;

final class AdminRoutes
{
    public static function register(App $app): void
    {
        $app->get('/admin/status', [AdminController::class, 'status']);
        $app->get('/admin/unsent', [AdminController::class, 'unsent']);
    }
}
