<?php

declare(strict_types=1);

namespace CentralMailer\Http\Routes;

use CentralMailer\Controllers\EmailController;
use Slim\App;

final class EmailRoutes
{
    public static function register(App $app): void
    {
        $app->post('/emails', [EmailController::class, 'create']);
        $app->post('/emails/test', [EmailController::class, 'test']);
        $app->get('/emails/{id}', [EmailController::class, 'show']);
        $app->options('/{routes:.+}', fn ($request, $response) => $response);
    }
}
