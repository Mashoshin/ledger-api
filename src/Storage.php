<?php

declare(strict_types=1);

namespace Ledger;

/**
 * Простое JSON-хранилище в файле var/storage.json.
 *
 * Данные хранятся в виде {"entries": [ ... ]}.
 * Файла нет — считается пустым хранилищем и создаётся при первой записи.
 * Проверка на повтор и запись выполняются под одной эксклюзивной блокировкой,
 * чтобы конкурентные запросы не портили файл и не создавали дублей.
 */
final class Storage
{
    private string $file;

    public function __construct(string $file)
    {
        $this->file = $file;
    }

    /**
     * Прочитать все проводки.
     *
     * @return array<int, array<string, mixed>>
     */
    public function readEntries(): array
    {
        if (!is_file($this->file)) {
            return [];
        }

        $raw = file_get_contents($this->file);
        if ($raw === false || $raw === '') {
            return [];
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['entries']) || !is_array($data['entries'])) {
            return [];
        }

        return $data['entries'];
    }

    /**
     * Атомарно дописать массив проводок, если это не повтор.
     *
     * Идемпотентность по `payment_id`: если в журнале уже есть проводки хотя бы
     * с одним из `payment_id` переданных проводок, запрос считается повтором —
     * в журнал не дописывается ничего, возвращаются ранее записанные проводки
     * по этим `payment_id` в порядке их записи в журнале. Содержимое повтора
     * не сверяется: `payment_id` — единственный ключ.
     *
     * Проверка на повтор и дозапись выполняются под ОДНИМ захватом flock:
     * иначе два одновременных запроса с одинаковым `payment_id` оба увидели бы
     * пустой журнал и оба записали бы проводки.
     *
     * Новый `payment_id` — все переданные проводки добавляются в конец журнала
     * одной записью: либо все, либо ни одной.
     *
     * @param array<int, array<string, mixed>> $newEntries проводки в порядке добавления
     * @return array{created: bool, entries: array<int, array<string, mixed>>}
     *         created=true — проводки записаны; false — повтор, отдан журнал
     */
    public function appendEntriesIfNew(array $newEntries): array
    {
        $this->ensureDir();

        $handle = fopen($this->file, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open storage file for writing');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Cannot acquire storage lock');
            }

            $raw = stream_get_contents($handle);
            $entries = [];
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && isset($decoded['entries']) && is_array($decoded['entries'])) {
                    $entries = $decoded['entries'];
                }
            }

            // Проверка на повтор — под той же блокировкой, что и запись ниже.
            $paymentIds = [];
            foreach ($newEntries as $entry) {
                $paymentIds[(string) $entry['payment_id']] = true;
            }

            $alreadyWritten = [];
            foreach ($entries as $entry) {
                $paymentId = $entry['payment_id'] ?? null;
                if (is_string($paymentId) && isset($paymentIds[$paymentId])) {
                    $alreadyWritten[] = $entry;
                }
            }

            if ($alreadyWritten !== []) {
                return ['created' => false, 'entries' => $alreadyWritten];
            }

            foreach ($newEntries as $entry) {
                $entries[] = $entry;
            }

            $json = json_encode(
                ['entries' => $entries],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            if ($json === false) {
                throw new \RuntimeException('Cannot encode storage data');
            }

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $json);
            fflush($handle);

            return ['created' => true, 'entries' => $newEntries];
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function ensureDir(): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }
}
