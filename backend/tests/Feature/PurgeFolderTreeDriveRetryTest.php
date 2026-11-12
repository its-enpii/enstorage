<?php

namespace Tests\Feature;

use App\Jobs\PurgeFolderTree;
use App\Models\File;
use App\Models\Folder;
use App\Models\GoogleAccount;
use App\Models\User;
use App\Services\Folder\FolderPathService;
use App\Services\Google\GoogleClientFactory;
use App\Services\Google\GoogleDriveFolderService;
use App\Services\Google\GoogleDriveUploader;
use App\Services\Google\GoogleTokenService;
use App\Services\Google\QuotaManager;
use App\Services\WebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\FakeDriveScanService;
use Tests\TestCase;

class PurgeFolderTreeDriveRetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_drive_ids_get_one_retry_pass_before_db_cleanup_finishes(): void
    {
        [$user, $account, $folder] = $this->makeTree();
        $file = $this->makeFile($user, $folder, $account, 'gd-file-1');
        $uploader = $this->makeUploader(succeedOnSecondCall: true);

        $this->runJob($folder, $user, $uploader);

        $this->assertCount(2, $uploader->calls);
        $this->assertSame([$file->gdrive_file_id], $uploader->calls[0]);
        $this->assertSame([$file->gdrive_file_id], $uploader->calls[1]);
        $this->assertDatabaseMissing('folders', ['id' => $folder->id]);
    }

    public function test_final_drive_failures_are_logged_as_structured_residuals(): void
    {
        Log::spy();

        [$user, $account, $folder] = $this->makeTree();
        $file = $this->makeFile($user, $folder, $account, 'gd-file-1');
        $uploader = $this->makeUploader(succeedOnSecondCall: false);

        $this->runJob($folder, $user, $uploader);

        $this->assertCount(2, $uploader->calls);
        Log::shouldHaveReceived('error')->withArgs(
            function (string $message, array $context) use ($folder, $account, $file): bool {
                return $message === 'PurgeFolderTree: drive residuals'
                    && $context['folder_id'] === $folder->id
                    && $context['account_id'] === $account->id
                    && $context['count'] === 1
                    && $context['gdrive_file_ids'] === [$file->gdrive_file_id];
            },
        )->once();
    }

    public function test_null_google_account_skips_drive_delete(): void
    {
        [$user, $account, $folder] = $this->makeTree();
        $this->makeFile($user, $folder, null, 'gd-orphan');
        $uploader = $this->makeUploader(succeedOnSecondCall: true);

        $this->runJob($folder, $user, $uploader);

        $this->assertSame([], $uploader->calls);
        $this->assertDatabaseMissing('folders', ['id' => $folder->id]);
    }

    /**
     * @return array{0: User, 1: GoogleAccount, 2: Folder}
     */
    private function makeTree(): array
    {
        $user = User::factory()->create();
        $account = GoogleAccount::factory()->create(['user_id' => $user->id]);
        $folder = Folder::create([
            'user_id' => $user->id,
            'name' => 'Proyek',
            'path' => '/',
            'gdrive_folder_id' => 'gd-folder-1',
        ]);

        return [$user, $account, $folder];
    }

    private function makeFile(User $user, Folder $folder, ?GoogleAccount $account, string $gdriveFileId): File
    {
        return File::create([
            'user_id' => $user->id,
            'folder_id' => $folder->id,
            'google_account_id' => $account?->id,
            'name' => 'berkas.txt',
            'original_name' => 'berkas.txt',
            'mime_type' => 'text/plain',
            'size' => 10,
            'gdrive_file_id' => $gdriveFileId,
            'upload_status' => File::STATUS_DONE,
            'client_key' => strtolower((string) Str::ulid()),
        ]);
    }

    private function makeUploader(bool $succeedOnSecondCall): GoogleDriveUploader
    {
        return new class(app(GoogleClientFactory::class), app(GoogleTokenService::class), app(GoogleDriveFolderService::class), $succeedOnSecondCall) extends GoogleDriveUploader
        {
            /** @var array<int, array<int, string>> */
            public array $calls = [];

            public function __construct(
                GoogleClientFactory $factory,
                GoogleTokenService $tokens,
                GoogleDriveFolderService $folderService,
                private readonly bool $succeedOnSecondCall,
            ) {
                parent::__construct($factory, $tokens, $folderService);
            }

            public function deleteFilesBulk(GoogleAccount $account, array $gdriveFileIds): array
            {
                $this->calls[] = array_values($gdriveFileIds);

                return $this->succeedOnSecondCall && count($this->calls) > 1
                    ? []
                    : array_values($gdriveFileIds);
            }
        };
    }

    private function runJob(Folder $folder, User $user, GoogleDriveUploader $uploader): void
    {
        $folderService = new FakeDriveScanService(
            app(GoogleClientFactory::class),
            app(GoogleTokenService::class),
            app(QuotaManager::class),
            app(FolderPathService::class),
        );

        (new PurgeFolderTree($folder->id, $user->id, (string) Str::uuid(), true))
            ->handle($uploader, $folderService, app(WebhookService::class));
    }
}
