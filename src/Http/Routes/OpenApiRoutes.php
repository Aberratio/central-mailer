<?php

declare(strict_types=1);

namespace CentralMailer\Http\Routes;

use CentralMailer\Config\Env;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Response;

final class OpenApiRoutes
{
    public static function register(App $app): void
    {
        $container = $app->getContainer();

        $app->get('/openapi.json', function ($request, ResponseInterface $response) use ($container): ResponseInterface {
            $env = $container->get(Env::class);
            $response->getBody()->write(json_encode(self::spec($env), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return $response->withHeader('Content-Type', 'application/json');
        });

        $app->get('/docs', function ($request, ResponseInterface $response): ResponseInterface {
            $html = <<<'HTML'
<!doctype html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Central Mailer API Docs</title>
  <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css">
  <style>
    body { margin: 0; background: #f7f7f7; }
    .swagger-ui .topbar { display: none; }
  </style>
</head>
<body>
  <div id="swagger-ui"></div>
  <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
  <script>
    const specUrl = window.location.pathname.replace(/\/docs\/?$/, '/openapi.json');

    window.ui = SwaggerUIBundle({
      url: specUrl,
      dom_id: '#swagger-ui',
      deepLinking: true,
      persistAuthorization: true
    });
  </script>
</body>
</html>
HTML;
            $response->getBody()->write($html);

            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        });
    }

    /** @return array<string, mixed> */
    private static function spec(Env $env): array
    {
        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'Central Mailer API',
                'version' => '1.0.0',
                'description' => 'Centralna usluga kolejkowanej wysylki e-mail przez SMTP.',
            ],
            'servers' => [
                [
                    'url' => $env->string('APP_URL', 'http://localhost:8080'),
                ],
            ],
            'tags' => [
                [
                    'name' => 'Emails',
                    'description' => 'Kolejkowanie wiadomosci i odczyt statusu.',
                ],
            ],
            'paths' => [
                '/emails' => [
                    'post' => [
                        'tags' => ['Emails'],
                        'summary' => 'Dodaje e-mail do kolejki',
                        'operationId' => 'createEmail',
                        'security' => [['ApiKeyAuth' => []]],
                        'parameters' => [
                            ['$ref' => '#/components/parameters/IdempotencyKey'],
                        ],
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/EmailCreateRequest'],
                                    'example' => [
                                        'to' => 'recipient@example.com',
                                        'subject' => 'Test',
                                        'html' => '<p>To jest test</p>',
                                        'text' => 'To jest test',
                                        'priority' => 'normal',
                                        'metadata' => ['userId' => 123],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Powtorzone zadanie z tym samym Idempotency-Key. Zwraca istniejaca wiadomosc.',
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/EmailQueuedResponse'],
                                    ],
                                ],
                            ],
                            '201' => [
                                'description' => 'Wiadomosc zostala dodana do kolejki.',
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/EmailQueuedResponse'],
                                    ],
                                ],
                            ],
                            '400' => ['$ref' => '#/components/responses/ValidationError'],
                            '401' => ['$ref' => '#/components/responses/UnauthorizedError'],
                            '409' => ['$ref' => '#/components/responses/IdempotencyConflict'],
                            '500' => ['$ref' => '#/components/responses/InternalServerError'],
                        ],
                    ],
                ],
                '/emails/batch' => [
                    'post' => [
                        'tags' => ['Emails'],
                        'summary' => 'Dodaje paczke e-maili ze wspolna trescia',
                        'operationId' => 'createEmailBatch',
                        'security' => [['ApiKeyAuth' => []]],
                        'parameters' => [
                            ['$ref' => '#/components/parameters/IdempotencyKey'],
                        ],
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/EmailBatchRequest'],
                                ],
                            ],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Powtorzone zadanie batch.',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/EmailBatchResponse']]],
                            ],
                            '201' => [
                                'description' => 'Paczka zostala dodana do kolejki.',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/EmailBatchResponse']]],
                            ],
                            '400' => ['$ref' => '#/components/responses/ValidationError'],
                            '401' => ['$ref' => '#/components/responses/UnauthorizedError'],
                            '409' => ['$ref' => '#/components/responses/IdempotencyConflict'],
                            '500' => ['$ref' => '#/components/responses/InternalServerError'],
                        ],
                    ],
                ],
                '/emails/test' => [
                    'post' => [
                        'tags' => ['Emails'],
                        'summary' => 'Dodaje testowy e-mail do kolejki',
                        'operationId' => 'createTestEmail',
                        'security' => [['ApiKeyAuth' => []]],
                        'parameters' => [
                            ['$ref' => '#/components/parameters/IdempotencyKey'],
                        ],
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/EmailTestRequest'],
                                    'example' => ['to' => 'recipient@example.com'],
                                ],
                            ],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Powtorzone zadanie z tym samym Idempotency-Key. Zwraca istniejaca wiadomosc.',
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/EmailQueuedResponse'],
                                    ],
                                ],
                            ],
                            '201' => [
                                'description' => 'Testowa wiadomosc zostala dodana do kolejki.',
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/EmailQueuedResponse'],
                                    ],
                                ],
                            ],
                            '400' => ['$ref' => '#/components/responses/ValidationError'],
                            '401' => ['$ref' => '#/components/responses/UnauthorizedError'],
                            '409' => ['$ref' => '#/components/responses/IdempotencyConflict'],
                            '500' => ['$ref' => '#/components/responses/InternalServerError'],
                        ],
                    ],
                ],
                '/emails/{id}' => [
                    'get' => [
                        'tags' => ['Emails'],
                        'summary' => 'Pobiera status e-maila',
                        'operationId' => 'getEmail',
                        'security' => [['ApiKeyAuth' => []]],
                        'parameters' => [
                            [
                                'name' => 'id',
                                'in' => 'path',
                                'required' => true,
                                'schema' => [
                                    'type' => 'string',
                                    'format' => 'uuid',
                                ],
                                'description' => 'Identyfikator wiadomosci zwrocony przy dodaniu do kolejki.',
                            ],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Status wiadomosci.',
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/EmailStatusResponse'],
                                    ],
                                ],
                            ],
                            '401' => ['$ref' => '#/components/responses/UnauthorizedError'],
                            '404' => ['$ref' => '#/components/responses/NotFoundError'],
                            '500' => ['$ref' => '#/components/responses/InternalServerError'],
                        ],
                    ],
                ],
                '/emails/{id}/events' => [
                    'get' => [
                        'tags' => ['Emails'],
                        'summary' => 'Pobiera historie statusow i prob wysylki',
                        'operationId' => 'getEmailEvents',
                        'security' => [['ApiKeyAuth' => []]],
                        'parameters' => [
                            ['$ref' => '#/components/parameters/EmailId'],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Historia zdarzen wiadomosci.',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/EmailEventsResponse']]],
                            ],
                            '401' => ['$ref' => '#/components/responses/UnauthorizedError'],
                            '404' => ['$ref' => '#/components/responses/NotFoundError'],
                            '500' => ['$ref' => '#/components/responses/InternalServerError'],
                        ],
                    ],
                ],
            ],
            'components' => [
                'parameters' => [
                    'IdempotencyKey' => [
                        'name' => 'Idempotency-Key',
                        'in' => 'header',
                        'required' => false,
                        'description' => 'Unikalny klucz zadania w obrebie aplikacji. Powtorzenie identycznego zadania zwraca ten sam e-mail.',
                        'schema' => [
                            'type' => 'string',
                            'maxLength' => 255,
                            'example' => 'order-confirmation-12345',
                        ],
                    ],
                    'EmailId' => [
                        'name' => 'id',
                        'in' => 'path',
                        'required' => true,
                        'schema' => [
                            'type' => 'string',
                            'format' => 'uuid',
                        ],
                    ],
                ],
                'securitySchemes' => [
                    'ApiKeyAuth' => [
                        'type' => 'apiKey',
                        'in' => 'header',
                        'name' => 'X-API-Key',
                    ],
                ],
                'schemas' => [
                    'EmailCreateRequest' => [
                        'type' => 'object',
                        'required' => ['to', 'subject', 'html'],
                        'properties' => [
                            'to' => [
                                'type' => 'string',
                                'format' => 'email',
                                'example' => 'recipient@example.com',
                            ],
                            'subject' => [
                                'type' => 'string',
                                'maxLength' => 255,
                                'example' => 'Test',
                            ],
                            'html' => [
                                'type' => 'string',
                                'maxLength' => 1000000,
                                'example' => '<p>To jest test</p>',
                            ],
                            'text' => [
                                'type' => 'string',
                                'nullable' => true,
                                'maxLength' => 1000000,
                                'example' => 'To jest test',
                            ],
                            'priority' => [
                                'type' => 'string',
                                'enum' => ['normal', 'high'],
                                'default' => 'normal',
                            ],
                            'metadata' => [
                                'type' => 'object',
                                'nullable' => true,
                                'additionalProperties' => true,
                                'example' => ['userId' => 123],
                            ],
                            'attachments' => [
                                'type' => 'array',
                                'items' => ['$ref' => '#/components/schemas/EmailAttachmentRequest'],
                            ],
                        ],
                    ],
                    'EmailAttachmentRequest' => [
                        'type' => 'object',
                        'required' => ['filename', 'contentBase64'],
                        'properties' => [
                            'filename' => ['type' => 'string', 'maxLength' => 255, 'example' => 'qr.png'],
                            'contentBase64' => ['type' => 'string', 'format' => 'byte'],
                        ],
                    ],
                    'EmailBatchRequest' => [
                        'type' => 'object',
                        'required' => ['subject', 'html', 'recipients'],
                        'properties' => [
                            'subject' => ['type' => 'string', 'maxLength' => 255],
                            'html' => ['type' => 'string', 'maxLength' => 1000000],
                            'text' => ['type' => 'string', 'nullable' => true, 'maxLength' => 1000000],
                            'priority' => ['type' => 'string', 'enum' => ['normal', 'high'], 'default' => 'normal'],
                            'metadata' => ['type' => 'object', 'nullable' => true, 'additionalProperties' => true],
                            'recipients' => [
                                'type' => 'array',
                                'minItems' => 1,
                                'items' => ['$ref' => '#/components/schemas/EmailBatchRecipient'],
                            ],
                        ],
                    ],
                    'EmailBatchRecipient' => [
                        'type' => 'object',
                        'required' => ['to'],
                        'properties' => [
                            'to' => ['type' => 'string', 'format' => 'email'],
                            'metadata' => ['type' => 'object', 'nullable' => true, 'additionalProperties' => true],
                        ],
                    ],
                    'EmailBatchResponse' => [
                        'type' => 'object',
                        'required' => ['id', 'emails'],
                        'properties' => [
                            'id' => ['type' => 'string', 'format' => 'uuid'],
                            'emails' => [
                                'type' => 'array',
                                'items' => ['$ref' => '#/components/schemas/EmailQueuedResponse'],
                            ],
                        ],
                    ],
                    'EmailTestRequest' => [
                        'type' => 'object',
                        'required' => ['to'],
                        'properties' => [
                            'to' => [
                                'type' => 'string',
                                'format' => 'email',
                                'example' => 'recipient@example.com',
                            ],
                        ],
                    ],
                    'EmailQueuedResponse' => [
                        'type' => 'object',
                        'required' => ['id', 'status'],
                        'properties' => [
                            'id' => [
                                'type' => 'string',
                                'format' => 'uuid',
                            ],
                            'status' => [
                                'type' => 'string',
                                'enum' => ['pending', 'processing', 'sent', 'retry', 'failed'],
                            ],
                        ],
                    ],
                    'EmailStatusResponse' => [
                        'type' => 'object',
                        'required' => ['id', 'status', 'sourceApp', 'to', 'subject', 'attempts', 'createdAt'],
                        'properties' => [
                            'id' => [
                                'type' => 'string',
                                'format' => 'uuid',
                            ],
                            'status' => [
                                'type' => 'string',
                                'enum' => ['pending', 'processing', 'sent', 'retry', 'failed'],
                            ],
                            'sourceApp' => [
                                'type' => 'string',
                                'example' => 'app-a',
                            ],
                            'to' => [
                                'type' => 'string',
                                'format' => 'email',
                            ],
                            'subject' => [
                                'type' => 'string',
                            ],
                            'attempts' => [
                                'type' => 'integer',
                                'minimum' => 0,
                            ],
                            'lastError' => [
                                'type' => 'string',
                                'nullable' => true,
                            ],
                            'createdAt' => [
                                'type' => 'string',
                                'format' => 'date-time',
                            ],
                            'sentAt' => [
                                'type' => 'string',
                                'format' => 'date-time',
                                'nullable' => true,
                            ],
                            'batchId' => [
                                'type' => 'string',
                                'format' => 'uuid',
                                'nullable' => true,
                            ],
                        ],
                    ],
                    'EmailEventsResponse' => [
                        'type' => 'object',
                        'required' => ['id', 'events'],
                        'properties' => [
                            'id' => ['type' => 'string', 'format' => 'uuid'],
                            'events' => [
                                'type' => 'array',
                                'items' => ['$ref' => '#/components/schemas/EmailEvent'],
                            ],
                        ],
                    ],
                    'EmailEvent' => [
                        'type' => 'object',
                        'required' => ['type', 'status', 'attempt', 'createdAt'],
                        'properties' => [
                            'type' => ['type' => 'string'],
                            'status' => ['type' => 'string'],
                            'attempt' => ['type' => 'integer'],
                            'errorCode' => ['type' => 'string', 'nullable' => true],
                            'errorMessage' => ['type' => 'string', 'nullable' => true],
                            'providerMessageId' => ['type' => 'string', 'nullable' => true],
                            'details' => ['type' => 'object', 'nullable' => true, 'additionalProperties' => true],
                            'createdAt' => ['type' => 'string', 'format' => 'date-time'],
                        ],
                    ],
                    'ErrorResponse' => [
                        'type' => 'object',
                        'required' => ['error'],
                        'properties' => [
                            'error' => [
                                'type' => 'string',
                            ],
                            'details' => [
                                'type' => 'string',
                            ],
                        ],
                    ],
                ],
                'responses' => [
                    'ValidationError' => [
                        'description' => 'Niepoprawne dane wejsciowe.',
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/ErrorResponse'],
                                'example' => ['error' => 'Recipient email is invalid'],
                            ],
                        ],
                    ],
                    'UnauthorizedError' => [
                        'description' => 'Brak poprawnego klucza API.',
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/ErrorResponse'],
                                'example' => ['error' => 'Invalid or missing API key'],
                            ],
                        ],
                    ],
                    'IdempotencyConflict' => [
                        'description' => 'Idempotency-Key zostal juz uzyty dla innej wiadomosci.',
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/ErrorResponse'],
                                'example' => ['error' => 'Idempotency-Key was already used for a different email'],
                            ],
                        ],
                    ],
                    'NotFoundError' => [
                        'description' => 'Wiadomosc nie istnieje albo nalezy do innej aplikacji.',
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/ErrorResponse'],
                                'example' => ['error' => 'Email not found'],
                            ],
                        ],
                    ],
                    'InternalServerError' => [
                        'description' => 'Blad serwera.',
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/ErrorResponse'],
                                'example' => ['error' => 'Internal server error'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
