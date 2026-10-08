<?php

declare(strict_types=1);

namespace App\Support\Files;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Header;
use Dedoc\Scramble\Attributes\IgnoreResponse;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class CloudinaryFileDownloadController extends Controller
{
    #[Endpoint(
        title: 'Generate a signed file download',
        description: 'Redirects to a short-lived signed Cloudinary URL after validating the school-scoped asset reference.',
    )]
    #[QueryParameter(
        name: 'school_id',
        description: 'Trusted school identifier used to resolve the school-scoped asset namespace.',
        required: true,
        type: 'string',
        example: 'school_demo_001',
    )]
    #[QueryParameter(
        name: 'public_id',
        description: 'Server-generated Cloudinary public identifier; original filenames are not accepted.',
        required: true,
        type: 'string',
        example: 'schoolos/schools/school_demo_001/018f4b5e-7f7a-7d9c-8a74-0c2e3a4e5f67',
    )]
    #[QueryParameter(
        name: 'format',
        description: 'Stored raw-file format.',
        required: false,
        type: 'string',
        example: 'pdf',
        default: 'bin',
    )]
    #[IgnoreResponse(200)]
    #[Response(status: 302, description: 'Redirects to the short-lived signed Cloudinary download URL.')]
    #[Header(
        name: 'Location',
        description: 'Short-lived signed download URL. It must not be reused after expiry.',
        type: 'string',
        format: 'uri-reference',
        required: true,
        status: 302,
    )]
    #[Header(
        name: 'X-Request-ID',
        description: 'Request correlation identifier.',
        type: 'string',
        format: 'uuid',
        required: true,
        status: '*',
    )]
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
