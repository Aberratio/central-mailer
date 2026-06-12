<?php

declare(strict_types=1);

namespace CentralMailer\Http\Routes;

use CentralMailer\Config\Env;
use CentralMailer\Http\ApiVersion;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Response;

final class OpenApiRoutes
{
    public static function register(App $app): void
    {
        $container = $app->getContainer();
        if (!$container->get(Env::class)->bool('APP_DOCS_ENABLED', true)) {
            return;
        }

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
  <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5.17.14/swagger-ui.css">
  <style>
    body { margin: 0; background: #f7f7f7; }
    .swagger-ui .topbar { display: none; }
  </style>
</head>
<body>
  <div id="swagger-ui"></div>
  <script src="https://unpkg.com/swagger-ui-dist@5.17.14/swagger-ui-bundle.js"></script>
  <script>
    const specUrl = window.location.pathname.replace(/\/docs\/?$/, '/openapi.json');

    window.ui = SwaggerUIBundle({
      url: specUrl,
      dom_id: '#swagger-ui',
      deepLinking: true,
      persistAuthorization: false
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
        $maxBatchRecipients = $env->int('EMAIL_BATCH_MAX_RECIPIENTS', 1000);
        $maxAttachmentCount = $env->int('EMAIL_ATTACHMENT_MAX_COUNT', 5);
        $maxAttachmentBytes = $env->int('EMAIL_ATTACHMENT_MAX_TOTAL_BYTES', 5_000_000);
        $maxRequestBodyBytes = $env->int('APP_MAX_REQUEST_BODY_BYTES', 12_000_000);
        $allowedAttachmentTypes = array_values(array_filter(array_map(
            'trim',
            explode(',', $env->string('EMAIL_ATTACHMENT_ALLOWED_MIME_TYPES', 'image/png,application/pdf'))
        )));

        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'Central Mailer API',
                'version' => ApiVersion::VERSION,
                'description' => implode("\n\n", [
                    'Centralna usluga kolejkowanej wysylki e-mail przez SMTP.',
                    'Kazdy klient uwierzytelnia sie naglowkiem `X-API-Key`. Klucz okresla aplikacje zrodlowa, a aplikacja moze odczytywac tylko wlasne wiadomosci.',
                    'Przyjecie wiadomosci do kolejki nie oznacza jej dostarczenia. Status `sent` potwierdza przyjecie przez serwer SMTP, ale nie gwarantuje dostarczenia do skrzynki odbiorcy.',
                    'Domeny `example.com`, `example.net` i `example.org` sa odrzucane, poniewaz nie moga odbierac poczty.',
                    sprintf('Maksymalny rozmiar calego body requestu w tym srodowisku wynosi %d bajtow.', $maxRequestBodyBytes),
                ]),
            ],
            'servers' => [
                [
                    'url' => $env->string('APP_URL', 'http://localhost:8080'),
                    'description' => 'Biezace srodowisko API',
                ],
            ],
            'security' => [['ApiKeyAuth' => []]],
            'tags' => [
                [
                    'name' => 'Emails',
                    'description' => 'Kolejkowanie wiadomosci, odczyt statusu i historia prob wysylki.',
                ],
                [
                    'name' => 'System',
                    'description' => 'Publiczne endpointy diagnostyczne.',
                ],
            ],
            'paths' => [
                '/health' => [
                    'get' => [
                        'tags' => ['System'],
                        'summary' => 'Sprawdza dostepnosc API i bazy danych',
                        'description' => 'Publiczny health check. Zwraca sukces tylko wtedy, gdy API moze wykonac zapytanie do bazy danych.',
                        'operationId' => 'getHealth',
                        'security' => [],
                        'responses' => [
                            '200' => [
                                'description' => 'API i polaczenie z baza danych dzialaja.',
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/HealthResponse'],
                                        'example' => ['status' => 'ok', 'apiVersion' => ApiVersion::VERSION],
                                    ],
                                ],
                            ],
                            '500' => ['$ref' => '#/components/responses/InternalServerError'],
                        ],
                    ],
                ],
                '/emails' => [
                    'get' => [
                        'tags' => ['Emails'],
                        'summary' => 'Listuje statusy e-maili z zakresu czasu',
                        'description' => 'Zwraca aktualne statusy wiadomosci aplikacji zrodlowej. Zakres dotyczy `createdAt`; bez parametrow domyslnie obejmuje dzisiaj.',
                        'operationId' => 'listEmails',
                        'parameters' => [
                            ['$ref' => '#/components/parameters/FromDateTime'],
                            ['$ref' => '#/components/parameters/ToDateTime'],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Lista statusow wiadomosci.',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/EmailListResponse']]],
                            ],
                            '400' => ['$ref' => '#/components/responses/ValidationError'],
                            '401' => ['$ref' => '#/components/responses/UnauthorizedError'],
                            '500' => ['$ref' => '#/components/responses/InternalServerError'],
                        ],
                    ],
                    'post' => [
                        'tags' => ['Emails'],
                        'summary' => 'Dodaje e-mail do kolejki',
                        'description' => 'Tworzy pojedyncza wiadomosc. Tresc i odbiorca sa walidowane przed zapisaniem, a wysylka odbywa sie asynchronicznie przez worker.',
                        'operationId' => 'createEmail',
                        'parameters' => [
                            ['$ref' => '#/components/parameters/IdempotencyKey'],
                        ],
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/EmailCreateRequest'],
                                    'example' => [
                                        'to' => 'recipient@company.pl',
                                        'subject' => 'Potwierdzenie zamowienia',
                                        'html' => '<p>Dziekujemy za zamowienie.</p>',
                                        'text' => 'Dziekujemy za zamowienie.',
                                        'priority' => 'normal',
                                        'metadata' => ['orderId' => 12345],
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
                            '429' => ['$ref' => '#/components/responses/TooManyRequests'],
                            '413' => ['$ref' => '#/components/responses/RequestTooLarge'],
                            '500' => ['$ref' => '#/components/responses/InternalServerError'],
                        ],
                    ],
                ],
                '/emails/unsent' => [
                    'get' => [
                        'tags' => ['Emails'],
                        'summary' => 'Listuje e-maile niewyslane',
                        'description' => 'Zwraca wszystkie wiadomosci aplikacji zrodlowej, ktorych aktualny status jest inny niz `sent`.',
                        'operationId' => 'listUnsentEmails',
                        'responses' => [
                            '200' => [
                                'description' => 'Lista wiadomosci niewyslanych.',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/EmailUnsentListResponse']]],
                            ],
                            '401' => ['$ref' => '#/components/responses/UnauthorizedError'],
                            '500' => ['$ref' => '#/components/responses/InternalServerError'],
                        ],
                    ],
                ],
                '/emails/worker/run' => [
                    'post' => [
                        'tags' => ['Emails'],
                        'summary' => 'Uruchamia pojedynczy przebieg workera',
                        'description' => 'Awaryjnie wykonuje jeden synchroniczny przebieg standardowego workera. Endpoint moze realnie wyslac wiadomosci przez skonfigurowany SMTP.',
                        'operationId' => 'runEmailWorkerOnce',
                        'responses' => [
                            '200' => [
                                'description' => 'Worker wykonal pojedynczy przebieg.',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/WorkerRunResponse']]],
                            ],
                            '401' => ['$ref' => '#/components/responses/UnauthorizedError'],
                            '503' => ['$ref' => '#/components/responses/ServiceUnavailableError'],
                            '500' => ['$ref' => '#/components/responses/InternalServerError'],
                        ],
                    ],
                ],
                '/emails/batch' => [
                    'post' => [
                        'tags' => ['Emails'],
                        'summary' => 'Dodaje paczke e-maili ze wspolna trescia',
                        'description' => 'Tworzy osobna wiadomosc dla kazdego odbiorcy, ale przechowuje wspolny temat i tresc tylko raz. Batch nie obsluguje zalacznikow.',
                        'operationId' => 'createEmailBatch',
                        'parameters' => [
                            ['$ref' => '#/components/parameters/IdempotencyKey'],
                        ],
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/EmailBatchRequest'],
                                    'example' => [
                                        'subject' => 'Newsletter',
                                        'html' => '<p>Nowosci w tym miesiacu.</p>',
                                        'text' => 'Nowosci w tym miesiacu.',
                                        'priority' => 'normal',
                                        'metadata' => ['campaign' => 'june-2026'],
                                        'recipients' => [
                                            ['to' => 'one@company.pl', 'metadata' => ['userId' => 1]],
                                            ['to' => 'two@company.pl', 'metadata' => ['userId' => 2]],
                                        ],
                                    ],
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
                            '429' => ['$ref' => '#/components/responses/TooManyRequests'],
                            '413' => ['$ref' => '#/components/responses/RequestTooLarge'],
                            '500' => ['$ref' => '#/components/responses/InternalServerError'],
                        ],
                    ],
                ],
                '/emails/batch/{id}' => [
                    'get' => [
                        'tags' => ['Emails'],
                        'summary' => 'Pobiera status batcha e-maili',
                        'description' => 'Zwraca batch i aktualne statusy wszystkich wiadomosci w paczce.',
                        'operationId' => 'getEmailBatch',
                        'parameters' => [
                            ['$ref' => '#/components/parameters/BatchId'],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Status batcha.',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/EmailBatchStatusResponse']]],
                            ],
                            '401' => ['$ref' => '#/components/responses/UnauthorizedError'],
                            '404' => ['$ref' => '#/components/responses/NotFoundError'],
                            '500' => ['$ref' => '#/components/responses/InternalServerError'],
                        ],
                    ],
                ],
                '/emails/batch/{id}/events' => [
                    'get' => [
                        'tags' => ['Emails'],
                        'summary' => 'Pobiera historie zdarzen batcha',
                        'description' => 'Zwraca chronologiczna historie zdarzen wszystkich wiadomosci w paczce.',
                        'operationId' => 'getEmailBatchEvents',
                        'parameters' => [
                            ['$ref' => '#/components/parameters/BatchId'],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Historia zdarzen batcha.',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/EmailBatchEventsResponse']]],
                            ],
                            '401' => ['$ref' => '#/components/responses/UnauthorizedError'],
                            '404' => ['$ref' => '#/components/responses/NotFoundError'],
                            '500' => ['$ref' => '#/components/responses/InternalServerError'],
                        ],
                    ],
                ],
                '/emails/{id}' => [
                    'get' => [
                        'tags' => ['Emails'],
                        'summary' => 'Pobiera status e-maila',
                        'description' => 'Zwraca biezacy stan wiadomosci nalezacej do aplikacji rozpoznanej po kluczu API.',
                        'operationId' => 'getEmail',
                        'parameters' => [
                            ['$ref' => '#/components/parameters/EmailId'],
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
                        'description' => 'Zwraca chronologiczna historie zdarzen wiadomosci, w tym kolejkowanie, proby wysylki, retry, rate limiting i wynik SMTP.',
                        'operationId' => 'getEmailEvents',
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
                        'description' => 'Zalecany unikalny klucz zadania w obrebie aplikacji. Powtorzenie identycznego zadania zwraca ten sam wynik z HTTP 200. Uzycie klucza dla innej tresci zwraca HTTP 409. Dozwolone sa widoczne znaki ASCII.',
                        'schema' => [
                            'type' => 'string',
                            'minLength' => 1,
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
                        'description' => 'Identyfikator wiadomosci zwrocony przez POST /emails lub w odpowiedzi batch.',
                        'example' => '550e8400-e29b-41d4-a716-446655440000',
                    ],
                    'BatchId' => [
                        'name' => 'id',
                        'in' => 'path',
                        'required' => true,
                        'schema' => [
                            'type' => 'string',
                            'format' => 'uuid',
                        ],
                        'description' => 'Identyfikator batcha zwrocony przez POST /emails/batch.',
                    ],
                    'FromDateTime' => [
                        'name' => 'from',
                        'in' => 'query',
                        'required' => false,
                        'schema' => ['type' => 'string'],
                        'description' => 'Poczatek zakresu `createdAt` w formacie `Y-m-d` albo `Y-m-d H:i:s`. Domyslnie poczatek dzisiejszego dnia.',
                    ],
                    'ToDateTime' => [
                        'name' => 'to',
                        'in' => 'query',
                        'required' => false,
                        'schema' => ['type' => 'string'],
                        'description' => 'Koniec zakresu `createdAt` w formacie `Y-m-d` albo `Y-m-d H:i:s`. Domyslnie koniec dzisiejszego dnia.',
                    ],
                ],
                'securitySchemes' => [
                    'ApiKeyAuth' => [
                        'type' => 'apiKey',
                        'in' => 'header',
                        'name' => 'X-API-Key',
                        'description' => 'Klucz klienta API. Okresla aplikacje zrodlowa, jej limity i zakres widocznosci wiadomosci.',
                    ],
                ],
                'schemas' => [
                    'HealthResponse' => [
                        'type' => 'object',
                        'required' => ['status', 'apiVersion'],
                        'properties' => [
                            'status' => [
                                'type' => 'string',
                                'enum' => ['ok'],
                                'example' => 'ok',
                            ],
                            'apiVersion' => [
                                'type' => 'string',
                                'example' => ApiVersion::VERSION,
                            ],
                        ],
                    ],
                    'EmailCreateRequest' => [
                        'type' => 'object',
                        'description' => 'Pojedyncza wiadomosc e-mail. Pole `from` nie jest obslugiwane; nadawca jest konfigurowany centralnie.',
                        'required' => ['to', 'subject', 'html'],
                        'properties' => [
                            'to' => [
                                'type' => 'string',
                                'format' => 'email',
                                'description' => 'Adres odbiorcy. Domena musi moc odbierac poczte; domeny example.com, example.net i example.org sa odrzucane.',
                                'example' => 'recipient@company.pl',
                            ],
                            'subject' => [
                                'type' => 'string',
                                'maxLength' => 255,
                                'description' => 'Niepusty temat wiadomosci, maksymalnie 255 znakow.',
                                'example' => 'Potwierdzenie zamowienia',
                            ],
                            'html' => [
                                'type' => 'string',
                                'maxLength' => 1000000,
                                'description' => 'Wymagana, niepusta tresc HTML. Limit wynosi 1 000 000 bajtow.',
                                'example' => '<p>Dziekujemy za zamowienie.</p>',
                            ],
                            'text' => [
                                'type' => 'string',
                                'nullable' => true,
                                'maxLength' => 1000000,
                                'description' => 'Opcjonalna wersja tekstowa. Limit wynosi 1 000 000 bajtow.',
                                'example' => 'Dziekujemy za zamowienie.',
                            ],
                            'priority' => [
                                'type' => 'string',
                                'enum' => ['normal', 'high', 'technical'],
                                'default' => 'normal',
                                'description' => '`high` ma pierwszenstwo przed `normal`. `technical` trafia do osobnej kolejki FIFO i jest wysylane przez Gmail SMTP.',
                            ],
                            'metadata' => [
                                'type' => 'object',
                                'nullable' => true,
                                'additionalProperties' => true,
                                'description' => 'Opcjonalne dane klienta przechowywane razem z wiadomoscia. Zakodowany JSON nie moze przekroczyc 64 000 bajtow.',
                                'example' => ['orderId' => 12345],
                            ],
                            'attachments' => [
                                'type' => 'array',
                                'maxItems' => $maxAttachmentCount,
                                'description' => sprintf(
                                    'Opcjonalne zalaczniki. Maksymalnie %d plikow i %d bajtow lacznie po dekodowaniu Base64. Dozwolone typy MIME: %s.',
                                    $maxAttachmentCount,
                                    $maxAttachmentBytes,
                                    implode(', ', $allowedAttachmentTypes)
                                ),
                                'items' => ['$ref' => '#/components/schemas/EmailAttachmentRequest'],
                            ],
                        ],
                    ],
                    'EmailAttachmentRequest' => [
                        'type' => 'object',
                        'description' => 'Zalacznik zakodowany w Base64. Serwis rozpoznaje rzeczywisty MIME type z zawartosci, nie z nazwy pliku.',
                        'required' => ['filename', 'contentBase64'],
                        'properties' => [
                            'filename' => [
                                'type' => 'string',
                                'maxLength' => 255,
                                'description' => 'Nazwa pliku bez sciezki i znakow sterujacych.',
                                'example' => 'qr.png',
                            ],
                            'contentBase64' => [
                                'type' => 'string',
                                'format' => 'byte',
                                'description' => 'Pelna zawartosc pliku zakodowana jako poprawny Base64.',
                            ],
                        ],
                    ],
                    'EmailBatchRequest' => [
                        'type' => 'object',
                        'description' => 'Wspolna tresc wysylana jako osobne wiadomosci do wielu odbiorcow. Odbiorcy moga nadpisac temat, tresc, metadata i zalaczniki.',
                        'required' => ['subject', 'html', 'recipients'],
                        'properties' => [
                            'subject' => ['type' => 'string', 'maxLength' => 255, 'description' => 'Wspolny, niepusty temat.'],
                            'html' => ['type' => 'string', 'maxLength' => 1000000, 'description' => 'Wspolna, niepusta tresc HTML. Limit wynosi 1 000 000 bajtow.'],
                            'text' => ['type' => 'string', 'nullable' => true, 'maxLength' => 1000000, 'description' => 'Opcjonalna wspolna wersja tekstowa. Limit wynosi 1 000 000 bajtow.'],
                            'priority' => [
                                'type' => 'string',
                                'enum' => ['normal', 'high', 'technical'],
                                'default' => 'normal',
                                'description' => '`high` ma pierwszenstwo przed `normal`. `technical` trafia do osobnej kolejki FIFO i jest wysylane przez Gmail SMTP.',
                            ],
                            'metadata' => [
                                'type' => 'object',
                                'nullable' => true,
                                'additionalProperties' => true,
                                'description' => 'Wspolne metadata dla wszystkich wiadomosci, maksymalnie 64 000 bajtow jako JSON.',
                            ],
                            'attachments' => [
                                'type' => 'array',
                                'maxItems' => $maxAttachmentCount,
                                'description' => 'Opcjonalne wspolne zalaczniki kopiowane do kazdego odbiorcy.',
                                'items' => ['$ref' => '#/components/schemas/EmailAttachmentRequest'],
                            ],
                            'recipients' => [
                                'type' => 'array',
                                'minItems' => 1,
                                'maxItems' => $maxBatchRecipients,
                                'description' => sprintf('Lista odbiorcow. Limit dla tego srodowiska: %d.', $maxBatchRecipients),
                                'items' => ['$ref' => '#/components/schemas/EmailBatchRecipient'],
                            ],
                        ],
                    ],
                    'EmailBatchRecipient' => [
                        'type' => 'object',
                        'required' => ['to'],
                        'properties' => [
                            'to' => [
                                'type' => 'string',
                                'format' => 'email',
                                'description' => 'Adres odbiorcy z domena zdolna do odbioru poczty.',
                                'example' => 'recipient@company.pl',
                            ],
                            'metadata' => [
                                'type' => 'object',
                                'nullable' => true,
                                'additionalProperties' => true,
                                'description' => 'Metadata tylko dla tego odbiorcy, maksymalnie 64 000 bajtow jako JSON.',
                            ],
                            'subject' => ['type' => 'string', 'nullable' => true, 'maxLength' => 255, 'description' => 'Opcjonalny temat tylko dla tego odbiorcy.'],
                            'html' => ['type' => 'string', 'nullable' => true, 'maxLength' => 1000000, 'description' => 'Opcjonalna tresc HTML tylko dla tego odbiorcy.'],
                            'text' => ['type' => 'string', 'nullable' => true, 'maxLength' => 1000000, 'description' => 'Opcjonalna wersja tekstowa tylko dla tego odbiorcy.'],
                            'attachments' => [
                                'type' => 'array',
                                'maxItems' => $maxAttachmentCount,
                                'description' => 'Opcjonalne zalaczniki tylko dla tego odbiorcy.',
                                'items' => ['$ref' => '#/components/schemas/EmailAttachmentRequest'],
                            ],
                        ],
                    ],
                    'EmailBatchResponse' => [
                        'type' => 'object',
                        'description' => 'Wynik utworzenia batcha. Kazdy element `emails` jest osobna wiadomoscia z wlasnym identyfikatorem.',
                        'required' => ['id', 'emails'],
                        'properties' => [
                            'id' => ['type' => 'string', 'format' => 'uuid', 'description' => 'Identyfikator batcha.'],
                            'emails' => [
                                'type' => 'array',
                                'items' => ['$ref' => '#/components/schemas/EmailQueuedResponse'],
                            ],
                        ],
                    ],
                    'EmailBatchStatusResponse' => [
                        'type' => 'object',
                        'required' => ['id', 'sourceApp', 'subject', 'createdAt', 'emails'],
                        'properties' => [
                            'id' => ['type' => 'string', 'format' => 'uuid'],
                            'sourceApp' => ['type' => 'string'],
                            'subject' => ['type' => 'string'],
                            'createdAt' => ['type' => 'string', 'format' => 'date-time'],
                            'emails' => [
                                'type' => 'array',
                                'items' => ['$ref' => '#/components/schemas/EmailStatusResponse'],
                            ],
                        ],
                    ],
                    'EmailListResponse' => [
                        'type' => 'object',
                        'required' => ['from', 'to', 'emails'],
                        'properties' => [
                            'from' => ['type' => 'string', 'format' => 'date-time'],
                            'to' => ['type' => 'string', 'format' => 'date-time'],
                            'emails' => [
                                'type' => 'array',
                                'items' => ['$ref' => '#/components/schemas/EmailStatusResponse'],
                            ],
                        ],
                    ],
                    'EmailUnsentListResponse' => [
                        'type' => 'object',
                        'required' => ['emails'],
                        'properties' => [
                            'emails' => [
                                'type' => 'array',
                                'items' => ['$ref' => '#/components/schemas/EmailStatusResponse'],
                            ],
                        ],
                    ],
                    'WorkerRunResponse' => [
                        'type' => 'object',
                        'required' => ['status', 'queue'],
                        'properties' => [
                            'status' => ['type' => 'string', 'enum' => ['ok']],
                            'queue' => ['type' => 'string', 'enum' => ['standard']],
                        ],
                    ],
                    'EmailQueuedResponse' => [
                        'type' => 'object',
                        'description' => 'Potwierdzenie zapisania wiadomosci w kolejce albo wynik idempotentnego powtorzenia.',
                        'required' => ['id', 'status'],
                        'properties' => [
                            'id' => [
                                'type' => 'string',
                                'format' => 'uuid',
                                'description' => 'Identyfikator uzywany do odczytu statusu i zdarzen.',
                                'example' => '550e8400-e29b-41d4-a716-446655440000',
                            ],
                            'status' => [
                                'type' => 'string',
                                'enum' => ['pending', 'processing', 'sent', 'retry', 'failed'],
                                'description' => 'Biezacy status. Nowa wiadomosc zwykle ma status `pending`.',
                                'example' => 'pending',
                            ],
                        ],
                    ],
                    'EmailStatusResponse' => [
                        'type' => 'object',
                        'description' => 'Biezacy stan wiadomosci. Pola nullable sa zawsze zwracane, ale moga nie miec jeszcze wartosci.',
                        'required' => ['id', 'status', 'sourceApp', 'to', 'subject', 'priority', 'attempts', 'lastError', 'providerMessageId', 'createdAt', 'updatedAt', 'sentAt', 'batchId'],
                        'properties' => [
                            'id' => [
                                'type' => 'string',
                                'format' => 'uuid',
                            ],
                            'status' => [
                                'type' => 'string',
                                'enum' => ['pending', 'processing', 'sent', 'retry', 'failed'],
                                'description' => '`pending`: oczekuje; `processing`: worker wysyla; `retry`: oczekuje na kolejna probe; `sent`: SMTP przyjal; `failed`: zakonczono proby.',
                            ],
                            'sourceApp' => [
                                'type' => 'string',
                                'description' => 'Aplikacja zrodlowa ustalona na podstawie klucza API.',
                                'example' => 'app-a',
                            ],
                            'to' => [
                                'type' => 'string',
                                'format' => 'email',
                            ],
                            'subject' => [
                                'type' => 'string',
                            ],
                            'priority' => [
                                'type' => 'string',
                                'enum' => ['normal', 'high', 'technical'],
                                'description' => 'Aktualna kolejka priorytetu. Po fallbacku technicznym moze zmienic sie z `technical` na `normal`.',
                            ],
                            'attempts' => [
                                'type' => 'integer',
                                'minimum' => 0,
                                'description' => 'Liczba zakonczonych niepowodzeniem prob wysylki w aktualnej kolejce.',
                            ],
                            'lastError' => [
                                'type' => 'string',
                                'nullable' => true,
                                'description' => 'Ostatni blad wysylki, jezeli wystapil.',
                            ],
                            'providerMessageId' => [
                                'type' => 'string',
                                'nullable' => true,
                                'description' => 'Stabilny SMTP Message-ID po przyjeciu wiadomosci przez provider.',
                            ],
                            'createdAt' => [
                                'type' => 'string',
                                'format' => 'date-time',
                            ],
                            'updatedAt' => [
                                'type' => 'string',
                                'format' => 'date-time',
                            ],
                            'sentAt' => [
                                'type' => 'string',
                                'format' => 'date-time',
                                'nullable' => true,
                                'description' => 'Czas przyjecia wiadomosci przez serwer SMTP.',
                            ],
                            'batchId' => [
                                'type' => 'string',
                                'format' => 'uuid',
                                'nullable' => true,
                                'description' => 'Identyfikator batcha albo null dla pojedynczej wiadomosci.',
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
                    'EmailBatchEventsResponse' => [
                        'type' => 'object',
                        'required' => ['id', 'events'],
                        'properties' => [
                            'id' => ['type' => 'string', 'format' => 'uuid'],
                            'events' => [
                                'type' => 'array',
                                'items' => ['$ref' => '#/components/schemas/EmailBatchEvent'],
                            ],
                        ],
                    ],
                    'EmailBatchEvent' => [
                        'allOf' => [
                            ['$ref' => '#/components/schemas/EmailEvent'],
                            [
                                'type' => 'object',
                                'required' => ['emailId'],
                                'properties' => [
                                    'emailId' => ['type' => 'string', 'format' => 'uuid'],
                                ],
                            ],
                        ],
                    ],
                    'EmailEvent' => [
                        'type' => 'object',
                        'description' => 'Pojedyncze zdarzenie cyklu zycia wiadomosci.',
                        'required' => ['type', 'status', 'attempt', 'errorCode', 'errorMessage', 'providerMessageId', 'details', 'createdAt'],
                        'properties' => [
                            'type' => [
                                'type' => 'string',
                                'enum' => ['queued', 'processing', 'rate_limited', 'retry', 'failed', 'sent', 'technical_fallback', 'processing_timeout'],
                                'description' => 'Rodzaj zdarzenia. Lista moze zostac rozszerzona w kolejnych wersjach API.',
                            ],
                            'status' => [
                                'type' => 'string',
                                'enum' => ['pending', 'processing', 'sent', 'retry', 'failed'],
                                'description' => 'Status wiadomosci po zdarzeniu.',
                            ],
                            'attempt' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Numer proby zwiazanej ze zdarzeniem.'],
                            'errorCode' => ['type' => 'string', 'nullable' => true],
                            'errorMessage' => ['type' => 'string', 'nullable' => true],
                            'providerMessageId' => ['type' => 'string', 'nullable' => true],
                            'details' => [
                                'type' => 'object',
                                'nullable' => true,
                                'additionalProperties' => true,
                                'description' => 'Dodatkowe dane zalezne od typu zdarzenia, np. `nextAttemptAt` albo zmiana priorytetu.',
                            ],
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
                                'description' => 'Szczegoly dostepne tylko przy wlaczonym APP_DEBUG.',
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
                    'RequestTooLarge' => [
                        'description' => sprintf('Body requestu przekracza limit %d bajtow dla tego srodowiska.', $maxRequestBodyBytes),
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/ErrorResponse'],
                                'example' => ['error' => 'Request body is too large'],
                            ],
                        ],
                    ],
                    'TooManyRequests' => [
                        'description' => 'Limit przyjmowania wiadomosci lub pojemnosc kolejki klienta zostaly osiagniete.',
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/ErrorResponse'],
                                'example' => ['error' => 'Email enqueue rate limit reached'],
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
                    'ServiceUnavailableError' => [
                        'description' => 'Funkcja jest niedostepna w tym runtime.',
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/ErrorResponse'],
                                'example' => ['error' => 'Worker is not available in this runtime'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
