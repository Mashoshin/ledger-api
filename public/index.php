<?php

declare(strict_types=1);

namespace Ledger;

/*
 * Точка входа сервиса ledger (порт 8082).
 * Запуск: php -S localhost:8082 public/index.php
 *
 * Маршруты:
 *   GET  /health                     — служебная проверка
 *   POST /entries                    — атомарно записать массив проводок (1..2)
 *   GET  /accounts/{id}/balance      — баланс счёта
 */

require __DIR__ . '/../src/ValidationException.php';
require __DIR__ . '/../src/Validator.php';
require __DIR__ . '/../src/Clock.php';
require __DIR__ . '/../src/Storage.php';
require __DIR__ . '/../src/Ledger.php';
require __DIR__ . '/../src/Http.php';

$storageFile = __DIR__ . '/../var/storage.json';
$ledger = new Ledger(new Storage($storageFile));

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/');
if ($path === '') {
    $path = '/';
}

try {
    // GET /health
    if ($method === 'GET' && $path === '/health') {
        Http::json(200, ['status' => 'ok', 'service' => 'ledger']);
        return;
    }

    // POST /entries — тело: массив из 1..2 проводок, атомарная запись
    if ($method === 'POST' && $path === '/entries') {
        $entries = $ledger->createEntries(Http::jsonBody());
        Http::json(201, $entries);
        return;
    }

    // GET /accounts/{id}/balance
    if ($method === 'GET' && preg_match('#^/accounts/([^/]+)/balance$#', $path, $m) === 1) {
        $account = urldecode($m[1]);
        // Формат счёта проверяем и здесь: некорректный id → 400.
        Validator::account($account, 'account');
        Http::json(200, $ledger->balance($account));
        return;
    }

    // Неизвестный маршрут.
    Http::json(404, ['error' => 'not found']);
} catch (ValidationException $e) {
    Http::json(400, ['error' => $e->getMessage()]);
} catch (\Throwable $e) {
    Http::json(500, ['error' => 'internal server error']);
}
