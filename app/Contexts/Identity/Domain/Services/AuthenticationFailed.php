<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Domain\Services;

use Illuminate\Auth\AuthenticationException;

final class AuthenticationFailed extends AuthenticationException {}
