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
        $app->get('/admin/sent', [AdminController::class, 'sent']);
        $app->post('/admin/emails/{id}/requeue', [AdminController::class, 'requeue']);
        $app->get('/admin/suppressions', [AdminController::class, 'suppressions']);
        $app->post('/admin/suppressions', [AdminController::class, 'addSuppression']);
        $app->delete('/admin/suppressions/{id}', [AdminController::class, 'removeSuppression']);
    }
}
