<?php

namespace Tests\Feature;

use App\Jobs\GenerateThumbnailJob;
use App\Models\File as FileModel;
use App\Models\GoogleAccount;
use App\Models\User;
use App\Services\FileSourceFetcher;
use App\Services\Google\GoogleTokenService;
use App\Services\ThumbnailGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Dulu file ini mengunci strategi "probe 24 MB lalu unduh UTUH bila probe
 * terpotong" untuk mengatasi MP4 yang menaruh `moov atom` di akhir berkas.
 *
 * Strategi itu TERBUKTI GAGAL di produksi: 2136 entri log
 * `ekstraksi frame video gagal` (exit 183/161), termasuk setelah unduhan
 * utuh (`after_full_download: true`). Penyebabnya unduhan utuh multi-GB
 * melewati batas waktu job dan boros disk.
 *
 * Strategi yang sekarang dikunci di sini: frame diambil LANGSUNG dari Drive
 * lewat ffmpeg `-i <url> -headers`, sehingga ffmpeg sendiri yang mengirim
 * HTTP Range untuk `moov` + beberapa detik data.
 */
class GenerateThumbnailDriveRangeTest extends TestCase
{
    use RefreshDatabase;

    private string $validMp4Path;

    private string $garbagePath;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        // MP4 faststart (moov di awal) — ffmpeg bisa ambil frame.
        $this->validMp4Path = sys_get_temp_dir().'/moov_valid_'.uniqid().'.mp4';
        @unlink($this->validMp4Path);
        @exec('ffmpeg -hide_banner -loglevel error -f lavfi -i testsrc=size=64x64:rate=10 -t 2 -c:v libx264 -pix_fmt yuv420p -movflags +faststart -y '
            .escapeshellarg($this->validMp4Path).' 2>&1', $out, $code);
        if ($code !== 0 || ! is_file($this->validMp4Path)) {
            $this->markTestSkipped('ffmpeg tidak tersedia untuk membuat MP4 uji.');
        }

        // 1 KB sampah = simulasi sumber yang tidak bisa dibaca ffmpeg.
        $this->garbagePath = sys_get_temp_dir().'/moov_trunc_'.uniqid().'.mp4';
        file_put_contents($this->garbagePath, str_repeat("\0", 1024));
    }

    protected function tearDown(): void
    {
        @unlink($this->validMp4Path);
        @unlink($this->garbagePath);
        parent::tearDown();
    }

    private function makeVideoFile(): FileModel
    {
        $user = User::create([
            'name' => 'Moov User',
            'email' => 'moov-'.uniqid().'@example.test',
            'password' => bcrypt('x'),
        ]);

        $account = GoogleAccount::factory()->create([
            'user_id' => $user->id,
            'email' => 'moov-drive-'.uniqid().'@example.test',
            'access_token' => 'TOKEN_MOOV',
        ]);

        return FileModel::create([
            'user_id' => $user->id,
            'google_account_id' => $account->id,
            'name' => 'moov.mp4',
            'original_name' => 'moov.mp4',
            'mime_type' => 'application/octet-stream',
            'size' => 3 * 1024 * 1024,
            'client_key' => strtolower((string) Str::ulid()),
            'gdrive_file_id' => 'g-'.uniqid(),
            'upload_status' => FileModel::STATUS_DONE,
            'storage_driver' => 'gdrive',
        ]);
    }

    /** Token dianggap segar supaya tes tidak menyentuh Google. */
    private function fakeFreshToken(): void
    {
        $tokens = \Mockery::mock(GoogleTokenService::class)->makePartial();
        $tokens->shouldReceive('ensureFreshToken')->andReturnUsing(function ($acct) {
            $acct->access_token = 'TOKEN_MOOV';

            return $acct->access_token;
        });
        $this->app->instance(GoogleTokenService::class, $tokens);
    }

    /**
     * Pasang ffmpeg palsu di PATH: rekam argumen, hasilkan JPEG dari fixture
     * (atau gagal bila `$succeed` false).
     *
     * @return array{args:string, cleanup:callable}
     */
    private function stubFfmpeg(bool $succeed): array
    {
        $real = trim((string) shell_exec('command -v ffmpeg'));
        if ($real === '' || ! is_file($real)) {
            $this->markTestSkipped('binary ffmpeg nyata tidak ditemukan.');
        }

        $dir = sys_get_temp_dir().'/ffstub_'.uniqid();
        @mkdir($dir, 0775, true);
        $stub = $dir.'/ffmpeg';
        $argsFile = $dir.'/args.txt';
        $source = $succeed ? $this->validMp4Path : $this->garbagePath;

        file_put_contents($stub, implode("\n", [
            '#!/bin/sh',
            'printf "%s\n" "$@" > '.escapeshellarg($argsFile),
            'for last; do :; done',
            'exec '.escapeshellarg($real).' -hide_banner -loglevel error -i '
                .escapeshellarg($source).' -frames:v 1 -f image2 -update 1 "$last"',
        ])."\n");
        chmod($stub, 0755);

        $oldPath = getenv('PATH');
        putenv('PATH='.$dir.':'.$oldPath);

        return [
            'args' => $argsFile,
            'cleanup' => function () use ($oldPath, $dir) {
                putenv('PATH='.$oldPath);
                @unlink($dir.'/ffmpeg');
                @unlink($dir.'/args.txt');
                @rmdir($dir);
            },
        ];
    }

    /**
     * Invarian inti: sumber frame adalah URL Drive, bukan salinan lokal, dan
     * tidak ada unduhan berkas (`stream_src`) yang dipakai sebagai masukan.
     */
    public function test_ffmpeg_membaca_url_drive_bukan_salinan_lokal(): void
    {
        $file = $this->makeVideoFile();
        $this->fakeFreshToken();
        $stub = $this->stubFfmpeg(true);

        try {
            (new GenerateThumbnailJob($file->id))->handle(
                app(ThumbnailGenerator::class),
                app(FileSourceFetcher::class),
            );
        } finally {
            // BACA argumen SEBELUM cleanup: cleanup menghapus args.txt.
            // (Percobaan pertama tes ini gagal karena urutannya terbalik.)
            $argsExists = is_file($stub['args']);
            $args = $argsExists ? (string) file_get_contents($stub['args']) : '';
            ($stub['cleanup'])();
        }

        $this->assertTrue($argsExists, 'ffmpeg harus dipanggil untuk video.');
        $this->assertStringContainsString(
            'googleapis.com/drive/v3/files/'.$file->gdrive_file_id,
            $args,
            'ffmpeg harus membaca URL Drive (HTTP Range), bukan berkas hasil unduhan.',
        );
        $this->assertStringContainsString('TOKEN_MOOV', $args, 'Authorization wajib diteruskan.');
        $this->assertStringNotContainsString('stream_src', $args, 'tidak boleh ada masukan hasil unduhan.');

        $this->assertSame(
            1,
            DB::table('thumbnails')->where('file_id', $file->id)->count(),
            'thumbnail harus tersimpan.',
        );
        $this->assertFileDoesNotExist(
            Storage::disk('local')->path('temp/'.$file->id.'.thumb_frame.jpg'),
            'frame antara harus dibersihkan.',
        );
    }

    /** ffmpeg gagal → tidak ada thumbnail, tapi job TIDAK melempar. */
    public function test_kegagalan_ffmpeg_dicatat_tanpa_melempar(): void
    {
        Log::spy();

        $file = $this->makeVideoFile();
        $this->fakeFreshToken();
        $stub = $this->stubFfmpeg(false);

        try {
            (new GenerateThumbnailJob($file->id))->handle(
                app(ThumbnailGenerator::class),
                app(FileSourceFetcher::class),
            );
        } finally {
            ($stub['cleanup'])();
        }
        @unlink($stub['args']);

        $this->assertSame(0, DB::table('thumbnails')->where('file_id', $file->id)->count());
        Log::shouldHaveReceived('warning')->withArgs(
            function (string $message, array $context) use ($file) {
                return $message === 'GenerateThumbnailJob: ekstraksi frame dari Drive gagal'
                    && $context['file_id'] === $file->id
                    && array_key_exists('exit_code', $context);
            },
        )->once();
    }

    /**
     * Video tanpa akun Google tetap dilewati (bukan exception): file lokal
     * murni tidak punya URL Drive untuk dibaca ffmpeg.
     */
    public function test_video_tanpa_akun_google_dilewati(): void
    {
        $user = User::create([
            'name' => 'Tanpa Akun',
            'email' => 'noacct-'.uniqid().'@example.test',
            'password' => bcrypt('x'),
        ]);

        $file = FileModel::create([
            'user_id' => $user->id,
            'google_account_id' => null,
            'name' => 'lokal.mp4',
            'original_name' => 'lokal.mp4',
            'mime_type' => 'video/mp4',
            'size' => 1024,
            'client_key' => strtolower((string) Str::ulid()),
            'gdrive_file_id' => 'g-'.uniqid(),
            'upload_status' => FileModel::STATUS_DONE,
            'storage_driver' => 'gdrive',
        ]);

        (new GenerateThumbnailJob($file->id))->handle(
            app(ThumbnailGenerator::class),
            app(FileSourceFetcher::class),
        );

        $this->assertSame(0, DB::table('thumbnails')->where('file_id', $file->id)->count());
    }
}
