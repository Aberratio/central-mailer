<?php

declare(strict_types=1);

namespace CentralMailer\Controllers;

use CentralMailer\Suppression\SuppressionRepository;
use CentralMailer\Suppression\UnsubscribeToken;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Response;

final class UnsubscribeController
{
    public function __construct(
        private readonly SuppressionRepository $suppressions,
        private readonly ?UnsubscribeToken $token,
        private readonly LoggerInterface $logger
    ) {
    }

    public function confirm(ServerRequestInterface $request): ResponseInterface
    {
        $token = $this->tokenFromRequest($request);
        $verified = $token === null || $this->token === null ? null : $this->token->verify($token);
        if ($verified === null) {
            return $this->html($this->page(
                'Nieprawidlowy link',
                '<p>Ten link rezygnacji jest nieprawidlowy albo uszkodzony.</p>'
            ), 400);
        }

        return $this->html($this->page(
            'Rezygnacja z subskrypcji',
            sprintf(
                '<p>Czy chcesz zrezygnowac z otrzymywania wiadomosci marketingowych na adres <strong>%s</strong>?</p>
                 <form method="post" action="/unsubscribe?token=%s">
                   <button type="submit">Tak, wypisz mnie</button>
                 </form>',
                htmlspecialchars($verified['email'], ENT_QUOTES),
                htmlspecialchars(urlencode($token), ENT_QUOTES)
            )
        ));
    }

    public function unsubscribe(ServerRequestInterface $request): ResponseInterface
    {
        $token = $this->tokenFromRequest($request);
        $verified = $token === null || $this->token === null ? null : $this->token->verify($token);
        if ($verified === null) {
            $response = new Response(400);
            $response->getBody()->write(json_encode(['error' => 'invalid_token'], JSON_THROW_ON_ERROR));

            return $response->withHeader('Content-Type', 'application/json');
        }

        // Idempotent by design; already-suppressed also returns 200 so the endpoint
        // never leaks whether an address is on the list.
        $created = $this->suppressions->add(
            $verified['email'],
            'unsubscribe',
            'marketing',
            $verified['sourceApp']
        );
        if ($created) {
            $this->logger->info('Recipient unsubscribed from marketing email', [
                'sourceApp' => $verified['sourceApp'],
            ]);
        }

        if (str_contains($request->getHeaderLine('Accept'), 'text/html')) {
            return $this->html($this->page(
                'Wypisano z listy',
                '<p>Nie bedziesz juz otrzymywac wiadomosci marketingowych na ten adres.</p>'
            ));
        }

        $response = new Response(200);
        $response->getBody()->write(json_encode(['status' => 'unsubscribed'], JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }

    private function tokenFromRequest(ServerRequestInterface $request): ?string
    {
        $token = $request->getQueryParams()['token'] ?? null;
        if (!is_string($token) || $token === '') {
            $body = $request->getParsedBody();
            $token = is_array($body) ? ($body['token'] ?? null) : null;
        }

        return is_string($token) && $token !== '' ? $token : null;
    }

    private function page(string $title, string $body): string
    {
        return sprintf(
            '<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>%1$s</title>
  <style>
    body { font-family: system-ui, sans-serif; max-width: 480px; margin: 15vh auto; padding: 0 16px; color: #1c1c1c; }
    button { padding: 10px 18px; font-size: 15px; cursor: pointer; }
  </style>
</head>
<body>
  <h1>%1$s</h1>
  %2$s
</body>
</html>',
            htmlspecialchars($title, ENT_QUOTES),
            $body
        );
    }

    private function html(string $content, int $status = 200): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write($content);

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
