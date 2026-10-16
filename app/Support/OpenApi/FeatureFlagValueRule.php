<?php

declare(strict_types=1);

namespace App\Support\OpenApi;

use Dedoc\Scramble\Contracts\RuleTransformer;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\Types\Type;
use Dedoc\Scramble\Support\RuleTransforming\NormalizedRule;
use Dedoc\Scramble\Support\RuleTransforming\RuleTransformerContext;

final class FeatureFlagValueRule implements RuleTransformer
{
    public function shouldHandle(NormalizedRule $rule): bool
    {
        return $rule->is('required') || $rule->is('sometimes');
    }

    public function toSchema(Type $previous, NormalizedRule $rule, RuleTransformerContext $context): Type
    {
        if ($context->field !== 'value') {
            return $previous;
        }

        return (new StringType)->enum(['true', 'false', 'enabled', 'limited', 'disabled']);
    }
}
