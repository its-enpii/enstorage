<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class DocsController extends Controller
{
    public function ui(): Response
    {
        return response()->view('docs');
    }

    public function spec(): BinaryFileResponse
    {
        $path = public_path('docs/openapi.yaml');
        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => 'application/yaml',
        ]);
    }
}
