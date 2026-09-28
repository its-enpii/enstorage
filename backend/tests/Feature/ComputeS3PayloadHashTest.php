<?php

namespace Tests\Feature;

use App\Http\Middleware\ComputeS3PayloadHash;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class ComputeS3PayloadHashTest extends TestCase
{
    use RefreshDatabase;

    public function test_middleware_sets_attribute_for_s3_put(): void
    {
        $body = 'unsigned payload';

        $request = Request::create(
            '/api/v1/s3/sidbm/unsigned.txt',
            'PUT',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/octet-stream'],
            $body,
        );

        $mw = new ComputeS3PayloadHash;

        $response = $mw->handle($request, function (Request $r): Response {
            return new Response('ok');
        });

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(hash('sha256', $body), $request->attributes->get('s3_payload_hash'));
    }
}
