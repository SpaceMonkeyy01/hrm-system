<?php

namespace App\Services\Import;

use RuntimeException;

/** Employee_Master.xlsx failed validation; nothing was written. */
class MasterValidationException extends RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(count($errors).' problem(s) in the master file. Nothing was saved.');
    }
}
