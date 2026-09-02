<?php

declare(strict_types=1);

namespace Ledger;

/**
 * Журнал проводок (двойная запись) и вычисление балансов.
 *
 * Проводка — неизменяемая запись «сумма ушла со счёта debit на счёт credit».
 * Проводки только дописываются: редактирования и удаления нет.
 * Запись идемпотентна по payment_id: повтор не создаёт новых проводок.
 *
 * Баланс счёта = сумма проводок, где счёт — credit,
 * минус сумма проводок, где счёт — debit. Отрицательный баланс допустим.
 */
final class Ledger
{
    public function __construct(
        private readonly Storage $storage
    ) {
    }

    /**
     * Создать массив проводок атомарно (всё-или-ничего), идемпотентно.
     *
     * Тело — массив из 1..2 проводок. Порядок операций строгий:
     * валидация → проверка на повтор → запись.
     *
     * 1. Сначала валидируются ВСЕ элементы; при нарушении хотя бы одного —
     *    ValidationException (роутер вернёт 400) и в журнал не пишется ничего.
     *    Невалидный вход даёт 400 даже для уже известного payment_id.
     * 2. Затем — проверка на повтор по `payment_id` и, если повтора нет,
     *    атомарная дозапись в журнал в порядке массива. Оба шага выполняются
     *    внутри одного захвата блокировки хранилища.
     *
     * Повтор (хотя бы один `payment_id` уже в журнале): ничего не дописывается,
     * возвращаются ранее записанные проводки с прежними `id` и `created_at`.
     * Содержимое повтора не сверяется — `payment_id` единственный ключ.
     *
     * @param array<mixed> $input массив входных проводок
     * @return array{created: bool, entries: array<int, array<string, mixed>>}
     *         created=true — проводки записаны (201); false — повтор (200)
     */
    public function createEntries(array $input): array
    {
        if (!array_is_list($input) || count($input) < 1 || count($input) > 2) {
            throw new ValidationException('request body must be an array of 1 to 2 entries');
        }

        // Валидируем и строим ВСЕ проводки до записи — атомарность на уровне
        // валидации: любая ошибка прерывает обработку до обращения к хранилищу.
        $built = [];
        foreach ($input as $item) {
            if (!is_array($item)) {
                throw new ValidationException('each entry must be a JSON object');
            }

            $debit = Validator::account($item['debit'] ?? null, 'debit');
            $credit = Validator::account($item['credit'] ?? null, 'credit');
            $amount = Validator::amount($item['amount'] ?? null);
            $paymentId = Validator::nonEmptyString($item['payment_id'] ?? null, 'payment_id');

            if ($debit === $credit) {
                throw new ValidationException('debit and credit must differ');
            }

            $built[] = [
                'id' => 'ent_' . bin2hex(random_bytes(4)),
                'payment_id' => $paymentId,
                'debit' => $debit,
                'credit' => $credit,
                'amount' => $amount,
                'created_at' => Clock::now(),
            ];
        }

        // Проверка на повтор и запись — под одной блокировкой внутри Storage.
        return $this->storage->appendEntriesIfNew($built);
    }

    /**
     * Посчитать баланс счёта и число проводок, где он участвует.
     *
     * Счёт может отсутствовать в журнале — это не ошибка, баланс 0.
     *
     * @return array{account: string, balance: int, entries_count: int}
     */
    public function balance(string $account): array
    {
        $balance = 0;
        $count = 0;

        foreach ($this->storage->readEntries() as $entry) {
            $involved = false;

            if (($entry['credit'] ?? null) === $account) {
                $balance += (int) $entry['amount'];
                $involved = true;
            }
            if (($entry['debit'] ?? null) === $account) {
                $balance -= (int) $entry['amount'];
                $involved = true;
            }
            if ($involved) {
                $count++;
            }
        }

        return [
            'account' => $account,
            'balance' => $balance,
            'entries_count' => $count,
        ];
    }
}
