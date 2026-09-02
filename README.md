# ledger

Журнал проводок (двойная запись) и вычисление балансов — сервис 2 платёжного
потока. Единственный источник правды о движении денег. Никого не вызывает.

- **PHP 8.3+**, без фреймворков и composer-зависимостей.
- Хранение — JSON-файл `var/storage.json` (создаётся при первой записи).
- Порт по умолчанию — **8082**.

## Модель

Проводка — неизменяемая запись «сумма ушла со счёта `debit` на счёт `credit`».
Проводки только дописываются: редактирования и удаления нет.

Баланс счёта = сумма проводок, где счёт — `credit`, минус сумма проводок, где
счёт — `debit`. Отрицательный баланс допустим.

Деньги — целые числа в копейках (`amount: 150000` = 1 500,00 ₽), валюта одна —
`RUB` (в API не передаётся). `amount` всегда строго больше нуля.

Счёт — строка `^acc_[a-z0-9_]+$`. Счета заранее не заводятся: любой корректный
идентификатор считается существующим.

## Запуск

```bash
php -S localhost:8082 public/index.php
```

## Ручки

### GET /health

Служебная проверка для smoke-тестов.

```bash
curl -s localhost:8082/health
# 200 {"status":"ok","service":"ledger"}
```

### POST /entries

Записать массив из 1..2 проводок атомарно: либо записаны все, либо ни одной.

Валидация (`400`): формат счетов, `debit != credit`, `amount` — целое > 0,
`payment_id` — непустая строка, длина массива 1..2.

```bash
curl -s -X POST localhost:8082/entries \
  -H 'Content-Type: application/json' \
  -d '[{"payment_id":"pay_a1b2c3d4","debit":"acc_vasya","credit":"acc_petya","amount":100000}]'
```

Ответ `201` — массив записанных проводок в том же порядке:

```json
[
  {
    "id": "ent_x1y2z3a4",
    "payment_id": "pay_a1b2c3d4",
    "debit": "acc_vasya",
    "credit": "acc_petya",
    "amount": 100000,
    "created_at": "2026-08-31T12:00:00+03:00"
  }
]
```

#### Идемпотентность по `payment_id`

Если в журнале уже есть проводки хотя бы с одним из `payment_id` запроса, это
повтор: в журнал **не дописывается ничего**, отдаётся `200` с ранее записанными
проводками — с теми же `id` и `created_at`, в порядке их записи в журнале.

Содержимое повтора не сверяется: `payment_id` — единственный ключ, расхождение
по `debit`/`credit`/`amount` ошибкой не является. Сверять параметры — дело
вызывающей стороны.

Порядок проверок строгий: валидация → дедуп → запись. Невалидное тело даёт
`400` даже для уже известного `payment_id`.

```bash
# первый вызов
curl -s -o /dev/null -w '%{http_code}\n' -X POST localhost:8082/entries \
  -H 'Content-Type: application/json' \
  -d '[{"payment_id":"pay_a1b2c3d4","debit":"acc_vasya","credit":"acc_petya","amount":100000}]'
# 201

# тот же payment_id — повтор
curl -s -o /dev/null -w '%{http_code}\n' -X POST localhost:8082/entries \
  -H 'Content-Type: application/json' \
  -d '[{"payment_id":"pay_a1b2c3d4","debit":"acc_vasya","credit":"acc_petya","amount":100000}]'
# 200, те же id — баланс не изменился
```

Пример ошибки (`debit == credit`):

```bash
curl -s -X POST localhost:8082/entries \
  -H 'Content-Type: application/json' \
  -d '[{"payment_id":"pay_1","debit":"acc_vasya","credit":"acc_vasya","amount":100}]'
# 400 {"error":"debit and credit must differ"}
```

### GET /accounts/{id}/balance

Баланс счёта. Счёт может отсутствовать в журнале — это не ошибка, баланс `0`.
`entries_count` — число проводок, где счёт участвует любой стороной.

```bash
curl -s localhost:8082/accounts/acc_petya/balance
# 200 {"account":"acc_petya","balance":100000,"entries_count":1}

curl -s localhost:8082/accounts/acc_vasya/balance
# 200 {"account":"acc_vasya","balance":-100000,"entries_count":1}

curl -s localhost:8082/accounts/acc_unknown/balance
# 200 {"account":"acc_unknown","balance":0,"entries_count":0}
```

## Форматы ответов

- Тела запросов и ответов — JSON, `Content-Type: application/json`.
- Успех: `200` (чтение, а также повтор `POST /entries` по известному
  `payment_id`) или `201` (создание).
- Ошибка валидации: `400` с телом `{"error":"<описание>"}`.
- Неизвестный маршрут: `404` с тем же форматом.

## Вне скоупа v1

Список проводок, фильтры, сторно, контроль овердрафта. Проводки остаются
неизменяемыми: только дозапись, удаления нет.
