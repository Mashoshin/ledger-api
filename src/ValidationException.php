<?php

declare(strict_types=1);

namespace Ledger;

/**
 * Ошибка валидации входных данных → HTTP 400 {"error": "<сообщение>"}.
 */
final class ValidationException extends \RuntimeException
{
}
