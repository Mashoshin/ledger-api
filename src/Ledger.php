<?php

declare(strict_types=1);

namespace Ledger;

/**
 * Журнал проводок (двойная запись) и вычисление балансов.
 *
 * Проводка — неизменяемая запись «сумма ушла со счёта debit на счёт credit».
 * Проводки только дописываются: редактирования и удаления нет.
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
     * Создать проводку. Валидация выполняется здесь; при нарушении —
     * ValidationException (роутер вернёт 400).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed> созданная проводка
     */
    public function createEntry(array $input): array
    {
        $debit = Validator::account($input['debit'] ?? null, 'debit');
        $credit = Validator::account($input['credit'] ?? null, 'credit');
        $amount = Validator::amount($input['amount'] ?? null);
        $paymentId = Validator::nonEmptyString($input['payment_id'] ?? null, 'payment_id');

        if ($debit === $credit) {
            throw new ValidationException('debit and credit must differ');
        }

        return $this->storage->appendEntry(static function () use ($paymentId, $debit, $credit, $amount): array {
            return [
                'id' => 'ent_' . bin2hex(random_bytes(4)),
                'payment_id' => $paymentId,
                'debit' => $debit,
                'credit' => $credit,
                'amount' => $amount,
                'created_at' => Clock::now(),
            ];
        });
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
