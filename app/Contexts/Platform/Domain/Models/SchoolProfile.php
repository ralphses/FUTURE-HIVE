<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'school_id', 'contact_type', 'canonical_contact', 'address_line1', 'address_line2',
    'city', 'state', 'postal_code', 'country', 'timezone', 'logo_public_id',
    'logo_format', 'logo_resource_type',
])]
#[Hidden(['id', 'canonical_contact', 'logo_public_id'])]
final class SchoolProfile extends Model
{
    use TenantScoped;

    protected function casts(): array
    {
        return [];
    }
}
