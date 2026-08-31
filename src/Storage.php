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
     * Добавить проводку под эксклюзивной блокировкой и вернуть её же.
     *
     * Колбэк получает текущий список проводок и возвращает новую проводку,
     * которая будет дописана в конец. Чтение и запись происходят под одной
     * блокировкой — так гарантируется отсутствие гонок.
     *
     * @param callable(array<int, array<string, mixed>>): array<string, mixed> $build
     * @return array<string, mixed>
     */
    public function appendEntry(callable $build): array
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

            $entry = $build($entries);
            $entries[] = $entry;

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

            return $entry;
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
