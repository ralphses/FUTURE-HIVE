<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Application\Contracts;

use App\Contexts\Platform\Domain\Models\ImportBatch;
use App\Contexts\Platform\Domain\Models\ImportStagedRow;
use Illuminate\Contracts\Auth\Authenticatable;

interface ImportSpecializedCommitter
{
    /**
     * @return array{status: string, action: string, id: string|null, error: string|null}
     */
    public function commitRow(Authenticatable $actor, ImportBatch $batch, ImportStagedRow $row): array;
}
