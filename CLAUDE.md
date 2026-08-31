# CLAUDE.md — ledger

Гид для агентов по этому репозиторию. Держи его в актуальном состоянии при
изменении кода.

## Что это

`ledger` — сервис 2 платёжного потока (порт **8082**). Журнал проводок
(двойная запись) и вычисление балансов. Единственный источник правды о движении
денег. **Никого не вызывает** и ничего не знает про `payments`/`notifications`.

Спецификация всего полигона — `../SERVICES-SPEC.md` (раздел «Сервис 2: ledger»).
README.md — пользовательская документация с примерами curl.

## Технологии и ограничения

- **PHP 8.3+**, **без фреймворков и без composer-зависимостей**. Только stdlib.
- Один входной скрипт `public/index.php` с простым роутером.
- Хранение — JSON-файл `var/storage.json`. Файла нет → пустое хранилище,
  создаётся при первой записи. Каталог `var/` в `.gitignore`.
- Деньги — целые числа в **копейках**, никаких float. Валюта одна — `RUB`,
  в API не передаётся. `amount` всегда строго > 0.
- Счёт — строка `^acc_[a-z0-9_]+$`. Счета заранее не заводятся: любой корректный
  идентификатор считается существующим.

## Модель

Проводка — неизменяемая запись «сумма ушла со счёта `debit` на счёт `credit`».
Только дописывание: редактирования и удаления нет.

Баланс счёта = Σ(amount, где счёт — `credit`) − Σ(amount, где счёт — `debit`).
Отрицательный баланс допустим. `entries_count` — число проводок, где счёт
участвует любой стороной.

## Структура кода

```
public/index.php            точка входа + роутер + обработка ошибок
src/Ledger.php              доменная логика: createEntry(), balance()
src/Storage.php             JSON-хранилище, атомарная дозапись под flock
src/Validator.php           валидация (account/amount/non-empty string)
src/ValidationException.php ошибка валидации → HTTP 400
src/Http.php                разбор JSON-тела, отправка JSON-ответа
src/Clock.php               метка времени ISO 8601 (+03:00)
```

Поток: `index.php` разбирает метод+путь → зовёт `Ledger` → `Ledger` валидирует и
пишет через `Storage`. `ValidationException` ловится в `index.php` и отдаётся
как `400 {"error": ...}`.

## Ручки

| Метод + путь | Назначение | Успех |
|---|---|---|
| `GET /health` | служебная проверка | `200 {"status":"ok","service":"ledger"}` |
| `POST /entries` | создать проводку | `201` с телом проводки |
| `GET /accounts/{id}/balance` | баланс счёта | `200 {account, balance, entries_count}` |

- `POST /entries` валидация (`400`): формат счетов, `debit != credit`,
  `amount` — целое > 0, `payment_id` — непустая строка.
- `GET .../balance`: несуществующий счёт — не ошибка, баланс `0`. Некорректный
  формат id в URL → `400`.
- Неизвестный маршрут → `404 {"error":"not found"}`.
- Идентификаторы проводок генерирует сервис: `ent_` + 8 hex-символов.

## Запуск

```bash
php -S localhost:8082 public/index.php
```

Если PHP не в PATH (например, Homebrew): используй полный путь, например
`/opt/homebrew/opt/php/bin/php`.

## Как протестировать

Синтаксис:

```bash
php -l public/index.php && for f in src/*.php; do php -l "$f"; done
```

Smoke-тест (сервер должен быть запущен на 8082):

```bash
B=localhost:8082

# health
curl -s $B/health
# → {"status":"ok","service":"ledger"}

# создать проводку
curl -s -X POST $B/entries -H 'Content-Type: application/json' \
  -d '{"payment_id":"pay_a1b2c3d4","debit":"acc_vasya","credit":"acc_petya","amount":100000}'
# → 201, {id: "ent_...", ...}

# балансы
curl -s $B/accounts/acc_petya/balance   # → balance 100000, entries_count 1
curl -s $B/accounts/acc_vasya/balance   # → balance -100000, entries_count 1
curl -s $B/accounts/acc_ghost/balance   # → balance 0, entries_count 0
```

Проверки валидации (все должны вернуть `400`):

```bash
# debit == credit
curl -s -X POST $B/entries -H 'Content-Type: application/json' \
  -d '{"payment_id":"p1","debit":"acc_a","credit":"acc_a","amount":100}'
# amount = 0
curl -s -X POST $B/entries -H 'Content-Type: application/json' \
  -d '{"payment_id":"p1","debit":"acc_a","credit":"acc_b","amount":0}'
# amount дробный
curl -s -X POST $B/entries -H 'Content-Type: application/json' \
  -d '{"payment_id":"p1","debit":"acc_a","credit":"acc_b","amount":100.5}'
# неверный формат счёта
curl -s -X POST $B/entries -H 'Content-Type: application/json' \
  -d '{"payment_id":"p1","debit":"ACC_A","credit":"acc_b","amount":100}'
# пустой payment_id
curl -s -X POST $B/entries -H 'Content-Type: application/json' \
  -d '{"payment_id":"","debit":"acc_a","credit":"acc_b","amount":100}'
```

Чистый прогон с нуля: удали `var/storage.json` перед стартом сервера.

## Границы (вне скоупа v1)

Список проводок, фильтры, сторно, контроль овердрафта, идемпотентность по
`payment_id` (повторный `POST` с тем же `payment_id` **осознанно** создаёт вторую
проводку). Не добавлять без явного тикета.
