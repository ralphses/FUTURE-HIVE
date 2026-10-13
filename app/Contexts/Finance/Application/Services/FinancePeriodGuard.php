<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Application\Services;

use App\Contexts\Finance\Domain\Models\FeeInvoice;
use App\Contexts\Finance\Domain\Models\FinancePeriod;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class FinancePeriodGuard
{
    public static function assertOpenForTerm(int $schoolId, int $termId): void
    {
        $period = FinancePeriod::query()->where('school_id', $schoolId)->where('academic_term_id', $termId)->first();
        if ($period instanceof FinancePeriod && $period->status === 'closed') {
            throw new ModelNotFoundException;
        }
    }

    public static function assertOpenForInvoice(FeeInvoice $invoice): void
    {
        $invoice->loadMissing('assessment');
        if ($invoice->assessment !== null) {
            self::assertOpenForTerm((int) $invoice->school_id, (int) $invoice->assessment->academic_term_id);
        }
    }
}
