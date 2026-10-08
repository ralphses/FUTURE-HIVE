<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

trait TenantScoped
{
    public static function bootTenantScoped(): void
    {
        static::addGlobalScope('trusted_tenant', static function (Builder $builder): void {
            $model = $builder->getModel();
            $builder->where($model->qualifyColumn('school_id'), TenantContext::require()->schoolId);
        });

        static::creating(static function (Model $model): void {
            $context = TenantContext::require();
            $requestedSchoolId = $model->getAttribute('school_id');

            if ($requestedSchoolId !== null && (int) $requestedSchoolId !== $context->schoolId) {
                throw new InvalidArgumentException('The school ownership does not match the trusted tenant context.');
            }

            $model->setAttribute('school_id', $context->schoolId);
        });

        static::saving(static function (Model $model): void {
            if (! $model->exists || ! $model->isDirty('school_id')) {
                return;
            }

            if ((int) $model->getAttribute('school_id') !== TenantContext::require()->schoolId) {
                throw new InvalidArgumentException('School ownership cannot be changed.');
            }
        });
    }
}
