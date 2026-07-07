<?php

declare(strict_types=1);

namespace CentralMailer\Http\Routes;

use CentralMailer\Controllers\UnsubscribeController;
use Slim\App;

final class UnsubscribeRoutes
{
    public static function register(App $app): void
    {
        $app->get('/unsubscribe', [UnsubscribeController::class, 'confirm']);
        $app->post('/unsubscribe', [UnsubscribeController::class, 'unsubscribe']);
    }
}
