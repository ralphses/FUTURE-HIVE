<?php

declare(strict_types=1);

namespace App\Support\OpenApi;

use Dedoc\Scramble\Extensions\TypeToSchemaExtension;
use Dedoc\Scramble\Support\Generator\Types\MixedType;
use Dedoc\Scramble\Support\Type\Type;
use Dedoc\Scramble\Support\Type\UnknownType;

final class UnknownTypeToSchema extends TypeToSchemaExtension
{
    /**
     * Handles unresolved open-ended JSON shapes so generated API schemas stay
     * explicit without weakening the runtime request or response contracts.
     */
    public function shouldHandle(Type $type): bool
    {
        $signature = $type->toString();

        return $type instanceof UnknownType
            || str_contains($signature, 'unknown')
            || str_contains($signature, 'mixed')
            || str_contains($signature, 'TValue')
            || str_contains($signature, 'TMapWithKeys')
            || str_contains($signature, 'TProvider');
    }

    public function toSchema(Type $type): MixedType
    {
        return new MixedType;
    }
}
