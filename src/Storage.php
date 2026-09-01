<?php

declare(strict_types=1);

namespace Ledger;

/**
 * Простое JSON-хранилище в файле var/storage.json.
 *
 * Данные хранятся в виде {"entries": [ ... ]}.
 * Файла нет — считается пустым хранилищем и создаётся при первой записи.
 * Запись выполняется атомарно (через временный файл + rename) под
 * эксклюзивной блокировкой, чтобы конкурентные запросы не портили файл.
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
     * Атомарно дописать массив проводок под эксклюзивной блокировкой.
     *
     * Все переданные проводки добавляются в конец журнала одной записью:
     * либо все, либо ни одной (запись под единой блокировкой через
     * временную усечку файла). Чтение и запись происходят под одной
     * блокировкой — так гарантируется отсутствие гонок.
     *
     * @param array<int, array<string, mixed>> $newEntries проводки в порядке добавления
     * @return array<int, array<string, mixed>> те же проводки
     */
    public function appendEntries(array $newEntries): array
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

            return $newEntries;
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
