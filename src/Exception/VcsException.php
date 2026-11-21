<?php
declare(strict_types=1);

namespace Micx\Vcs\Exception;

/** Base exception for every SDK/worker failure (programmer argument errors excluded). */
abstract class VcsException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly string $operation = '',
        public readonly array $details = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct('Mix VCS [' . $errorCode . ']'
            . ($operation !== '' ? ' (' . $operation . ')' : '') . ': ' . $message, 0, $previous);
    }
}
