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
    window.ui = SwaggerUIBundle({
      url: '/openapi.json',
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
            ],
            'components' => [
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
                                'enum' => ['pending'],
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
