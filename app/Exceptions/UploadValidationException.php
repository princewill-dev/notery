<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an upload fails validation after bytes have been received
 * (for example an assembled file that is too large or whose contents do not
 * match the declared type). Carries the HTTP status the API should return.
 */
class UploadValidationException extends RuntimeException
{
    public function __construct(string $message, private readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}
