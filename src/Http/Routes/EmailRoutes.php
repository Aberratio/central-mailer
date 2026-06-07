<?php

declare(strict_types=1);

namespace CentralMailer\Http\Routes;

use CentralMailer\Controllers\EmailController;
use Slim\App;

final class EmailRoutes
{
    public static function register(App $app): void
    {
        $app->get('/emails', [EmailController::class, 'index']);
        $app->get('/emails/unsent', [EmailController::class, 'unsent']);
        $app->post('/emails/worker/run', [EmailController::class, 'runWorker']);
        $app->post('/emails', [EmailController::class, 'create']);
        $app->post('/emails/batch', [EmailController::class, 'batch']);
        $app->get('/emails/batch/{id}', [EmailController::class, 'showBatch']);
        $app->get('/emails/batch/{id}/events', [EmailController::class, 'batchEvents']);
        $app->get('/emails/{id}', [EmailController::class, 'show']);
        $app->get('/emails/{id}/events', [EmailController::class, 'events']);
        $app->options('/{routes:.+}', fn ($request, $response) => $response);
    }
}
