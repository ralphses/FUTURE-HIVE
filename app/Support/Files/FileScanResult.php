<?php

declare(strict_types=1);

namespace App\Support\Files;

enum FileScanResult: string
{
    case Clean = 'clean';
    case Infected = 'infected';
    case Unavailable = 'unavailable';
}
