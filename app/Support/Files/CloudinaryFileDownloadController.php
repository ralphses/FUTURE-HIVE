<?php

declare(strict_types=1);

namespace App\Support\Files;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class CloudinaryFileDownloadController extends Controller
{
    public function __invoke(Request $request, CloudinaryAssetStore $store): RedirectResponse
    {
        try {
            $reference = new SchoolFileReference(
                schoolId: (string) $request->query('school_id'),
                publicId: (string) $request->query('public_id'),
                format: (string) $request->query('format', 'bin'),
            );
        } catch (InvalidArgumentException) {
            abort(404);
        }

        return redirect()->away($store->cloudinaryDownloadUrl($reference));
    }
}
