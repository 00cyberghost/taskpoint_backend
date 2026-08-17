<?php

namespace App\Exceptions;

use RuntimeException;

class AccountDeletionBlockedException extends RuntimeException
{
    /**
     * @param  array<int, string>  $blockers
     */
    public function __construct(
        public readonly array $blockers,
        string $message = 'Your account cannot be deleted until all pending items are resolved.',
    ) {
        parent::__construct($message);
    }
}
