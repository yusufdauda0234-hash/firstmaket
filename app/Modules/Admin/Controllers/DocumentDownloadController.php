<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\UploadedDocument;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The only way a CAC/identity document ever leaves storage: an authenticated,
 * permission-checked stream on the admin subdomain. Documents live on a
 * private disk with no public URL (docs/firstmarket_Security_Compliance.md).
 */
class DocumentDownloadController extends Controller
{
    public function __invoke(UploadedDocument $uploadedDocument): StreamedResponse
    {
        return Storage::disk($uploadedDocument->disk)->download(
            $uploadedDocument->path,
            $uploadedDocument->original_name,
        );
    }
}
