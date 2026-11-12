<?php

namespace Tests\Feature;

use App\Models\GoogleAccount;
use App\Services\Google\GoogleClientFactory;
use App\Services\Google\GoogleDriveFolderService;
use App\Services\Google\GoogleDriveUploader;
use App\Services\Google\GoogleTokenService;
use Google\Client as GoogleClient;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

class DeleteFilesBulkChunkTest extends TestCase
{
    /** @var array<int, array<int, string>> */
    private array $batchCalls = [];

    public function test_250_ids_are_split_into_multiple_batches(): void
    {
        $uploader = $this->makeUploader(fn (array $ids) => $this->batchResponse(array_fill_keys($ids, 204)));
        $ids = $this->ids(250);

        $failed = $uploader->deleteFilesBulk($this->account(), $ids);

        $this->assertSame([], $failed);
        $this->assertCount(3, $this->batchCalls);
        $this->assertSame([100, 100, 50], array_map('count', $this->batchCalls));
        $this->assertSame($ids, array_merge(...$this->batchCalls));
    }

    public function test_99_ids_use_exactly_one_batch(): void
    {
        $uploader = $this->makeUploader(fn (array $ids) => $this->batchResponse(array_fill_keys($ids, 204)));

        $failed = $uploader->deleteFilesBulk($this->account(), $this->ids(99));

        $this->assertSame([], $failed);
        $this->assertCount(1, $this->batchCalls);
        $this->assertCount(99, $this->batchCalls[0]);
    }

    public function test_failed_ids_are_returned_from_their_chunk(): void
    {
        $uploader = $this->makeUploader(function (array $ids) {
            $statuses = array_fill_keys($ids, 204);
            $statuses['gd-3'] = 404;

            return $this->batchResponse($statuses);
        });

        $failed = $uploader->deleteFilesBulk($this->account(), $this->ids(5));

        $this->assertSame(['gd-3'], $failed);
        $this->assertCount(1, $this->batchCalls);
    }

    public function test_all_failed_ids_are_returned(): void
    {
        $uploader = $this->makeUploader(function (array $ids) {
            return $this->batchResponse(array_fill_keys($ids, 500));
        });
        $ids = $this->ids(3);

        $failed = $uploader->deleteFilesBulk($this->account(), $ids);

        $this->assertSame($ids, $failed);
        $this->assertCount(1, $this->batchCalls);
    }

    public function test_inner_403_soft_limit_is_retried_once_then_succeeds(): void
    {
        $uploader = $this->makeUploader(function (array $ids, int $call) {
            return $this->batchResponse(array_fill_keys($ids, $call === 1 ? 403 : 204));
        });

        $failed = $uploader->deleteFilesBulk($this->account(), $this->ids(3));

        $this->assertSame([], $failed);
        $this->assertCount(2, $this->batchCalls);
    }

    public function test_batch_level_403_soft_limit_is_retried_once_then_succeeds(): void
    {
        $uploader = $this->makeUploader(function (array $ids, int $call) {
            if ($call === 1) {
                return new PsrResponse(
                    403,
                    ['Content-Type' => 'application/json'],
                    json_encode(['error' => ['message' => 'Exceeds allowed inner batch requests soft limit. Please retry.']]),
                );
            }

            return $this->batchResponse(array_fill_keys($ids, 204));
        });

        $failed = $uploader->deleteFilesBulk($this->account(), $this->ids(3));

        $this->assertSame([], $failed);
        $this->assertCount(2, $this->batchCalls);
    }

    /**
     * @param  callable(array<int, string>, int): PsrResponse  $responder
     */
    private function makeUploader(callable $responder): GoogleDriveUploader
    {
        $this->batchCalls = [];

        $handler = function (RequestInterface $request) use ($responder) {
            preg_match_all('/Content-ID:\s*([^\r\n]+)/', (string) $request->getBody(), $matches);
            $ids = array_map('trim', $matches[1]);
            $this->batchCalls[] = $ids;

            return Create::promiseFor($responder($ids, count($this->batchCalls)));
        };

        $client = new GoogleClient;
        $client->setHttpClient(new Client(['handler' => HandlerStack::create($handler)]));

        $factory = \Mockery::mock(GoogleClientFactory::class);
        $factory->shouldReceive('makeFor')->once()->andReturn($client);

        $tokens = \Mockery::mock(GoogleTokenService::class);
        $tokens->shouldReceive('ensureFreshToken')->once()->andReturn('TOKEN');

        return new GoogleDriveUploader(
            $factory,
            $tokens,
            \Mockery::mock(GoogleDriveFolderService::class),
        );
    }

    /**
     * @param  array<int|string, int>  $statuses
     */
    private function batchResponse(array $statuses): PsrResponse
    {
        $boundary = 'batch_test_boundary';
        $body = '';

        foreach ($statuses as $id => $status) {
            $id = (string) $id;
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Type: application/http\r\n";
            $body .= "Content-ID: response-{$id}\r\n\r\n";
            $body .= "HTTP/1.1 {$status} Test\r\n";
            $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";

            if ($status !== 204) {
                $message = $status === 403
                    ? 'Exceeds allowed inner batch requests soft limit. Please retry.'
                    : 'Internal Error';
                $body .= json_encode(['error' => ['code' => $status, 'message' => $message]]);
                $body .= "\r\n";
            }
        }

        $body .= "--{$boundary}--";

        return new PsrResponse(200, ['Content-Type' => "multipart/mixed; boundary={$boundary}"], $body);
    }

    private function account(): GoogleAccount
    {
        $account = new GoogleAccount;
        $account->access_token = 'TOKEN';

        return $account;
    }

    /**
     * @return array<int, string>
     */
    private function ids(int $count): array
    {
        return array_map(fn (int $i) => 'gd-'.$i, range(1, $count));
    }
}
