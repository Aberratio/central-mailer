<?php

declare(strict_types=1);

namespace CentralMailer\Tests\Http\Routes;

use CentralMailer\Controllers\UnsubscribeController;
use CentralMailer\Http\Routes\UnsubscribeRoutes;
use CentralMailer\Suppression\SuppressionRepository;
use CentralMailer\Suppression\UnsubscribeToken;
use CentralMailer\Tests\Support\DatabaseTestCase;
use DI\Container;
use Psr\Log\NullLogger;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class UnsubscribeRoutesTest extends DatabaseTestCase
{
    private SuppressionRepository $suppressions;
    private UnsubscribeToken $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->suppressions = new SuppressionRepository($this->pdo);
        $this->token = new UnsubscribeToken(str_repeat('s', 32));
    }

    public function testOneClickPostCreatesMarketingScopedSuppression(): void
    {
        $token = $this->token->generate('subscriber@example.test', 'app-a');
        $response = $this->app()->handle(
            (new ServerRequestFactory())->createServerRequest('POST', '/unsubscribe')
                ->withQueryParams(['token' => $token])
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($this->suppressions->isSuppressed('subscriber@example.test', 'app-a', 'marketing'));
        self::assertFalse($this->suppressions->isSuppressed('subscriber@example.test', 'app-a', 'transactional'));
        self::assertFalse($this->suppressions->isSuppressed('subscriber@example.test', 'app-b', 'marketing'));
    }

    public function testRepeatedUnsubscribeIsIdempotentAndStillReturns200(): void
    {
        $token = $this->token->generate('subscriber@example.test', 'app-a');
        $app = $this->app();
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/unsubscribe')
            ->withQueryParams(['token' => $token]);

        self::assertSame(200, $app->handle($request)->getStatusCode());
        self::assertSame(200, $app->handle($request)->getStatusCode());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM email_suppressions')->fetchColumn());
    }

    public function testInvalidTokenReturns400WithoutCreatingSuppression(): void
    {
        $response = $this->app()->handle(
            (new ServerRequestFactory())->createServerRequest('POST', '/unsubscribe')
                ->withQueryParams(['token' => 'not-a-token'])
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM email_suppressions')->fetchColumn());
    }

    public function testGetRendersConfirmationPageWithOneClickForm(): void
    {
        $token = $this->token->generate('subscriber@example.test', 'app-a');
        $response = $this->app()->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/unsubscribe')
                ->withQueryParams(['token' => $token])
        );
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('subscriber@example.test', $body);
        self::assertStringContainsString('method="post"', $body);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM email_suppressions')->fetchColumn());
    }

    private function app(): \Slim\App
    {
        $container = new Container();
        $container->set(UnsubscribeController::class, fn () => new UnsubscribeController(
            $this->suppressions,
            $this->token,
            new NullLogger()
        ));
        AppFactory::setContainer($container);
        $app = AppFactory::create();
        UnsubscribeRoutes::register($app);

        return $app;
    }
}
