<?php

declare(strict_types=1);

namespace App\Support\Files;

use RuntimeException;

final class UnsafeFileUpload extends RuntimeException
{
    public function __construct(FileScanResult $result)
    {
        parent::__construct(sprintf('File upload rejected because malware scan result was [%s].', $result->value));
    }
}
