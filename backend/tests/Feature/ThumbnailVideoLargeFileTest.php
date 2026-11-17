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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Keluhan produksi: "file dengan ukuran GB tidak memiliki thumbnail".
 *
 * Root cause terukur: dua lapis penolakan pada video besar.
 *  1. `MAX_VIDEO_BYTES = 500 MB` men-skip video lebih besar tanpa mencoba
 *     (158 kejadian `video di atas batas, dilewati` di log produksi).
 *  2. Video di bawah batas pun diunduh dulu (probe 24 MB atau utuh), dan
 *     probe gagal pada MP4 yang menaruh `moov atom` di akhir berkas
 *     (exit 183/161, 2136 kejadian di log produksi).
 *
 * Perbaikan: frame grab LANGSUNG dari Drive lewat ffmpeg `-i <url> -headers`,
 * sehingga ffmpeg yang mengirim HTTP Range. Diukur di produksi: 1.0–3.9s
 * untuk video 1.6–3.7 GB, tanpa satu byte pun ditulis ke disk lebih dulu.
 */
class ThumbnailVideoLargeFileTest extends TestCase
{
    use RefreshDatabase;

    private ?string $fixture = null;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        if ($this->fixture !== null && is_file($this->fixture)) {
            @unlink($this->fixture);
        }
        parent::tearDown();
    }

    /** MP4 2 detik yang bisa dibaca ffmpeg (moov di awal = paling mudah). */
    private function makeMp4(): string
    {
        if ($this->fixture !== null) {
            return $this->fixture;
        }

        $path = sys_get_temp_dir().'/large_thumb_'.uniqid().'.mp4';
        @exec(
            'ffmpeg -hide_banner -loglevel error -f lavfi -i testsrc=size=96x96:rate=10 -t 2 '
            .'-c:v libx264 -pix_fmt yuv420p -movflags +faststart -y '.escapeshellarg($path).' 2>&1',
            $out,
            $code,
        );

        if ($code !== 0 || ! is_file($path)) {
            $this->markTestSkipped('ffmpeg tidak tersedia untuk membuat MP4 uji.');
        }

        return $this->fixture = $path;
    }

    private function makeUser(): User
    {
        return User::create([
            'name' => 'Pemilik Video',
            'email' => 'video-'.uniqid().'@example.test',
            'password' => bcrypt('x'),
        ]);
    }

    private function makeVideo(User $user, int $size): FileModel
    {
        return FileModel::create([
            'user_id' => $user->id,
            'name' => 'besar.mp4',
            'original_name' => 'besar.mp4',
            'mime_type' => 'video/mp4',
            'size' => $size,
            'gdrive_file_id' => 'g-'.uniqid(),
            'client_key' => strtolower((string) Str::ulid()),
            'client_key_origin' => 'server',
            'upload_status' => FileModel::STATUS_DONE,
            'storage_driver' => 'gdrive',
        ]);
    }

    /**
     * Video beberapa GB WAJIB diproses. Batas 500 MB adalah satu-satunya
     * alasan 158 video produksi berakhir tanpa thumbnail.
     */
    public function test_video_beberapa_gb_tidak_lagi_dilewati_karena_ukuran(): void
    {
        $this->assertGreaterThan(
            500 * 1024 * 1024,
            GenerateThumbnailJob::MAX_VIDEO_BYTES,
            'Batas 500 MB membuat video GB dilewati tanpa dicoba.',
        );

        $this->assertGreaterThanOrEqual(
            1024 * 1024 * 1024 * 1024, // 1 TiB
            GenerateThumbnailJob::MAX_VIDEO_BYTES,
            'Ukuran tidak boleh lagi menjadi penghalang thumbnail video.',
        );
    }

    /**
     * Invarian inti: frame diambil dari URL Drive, bukan dari salinan lokal.
     * ffmpeg dipalsukan (skrip shell) agar tes tidak menyentuh jaringan, dan
     * argumen yang dipakai job direkam untuk diperiksa.
     */
    public function test_frame_diambil_langsung_dari_url_drive_tanpa_unduh_berkas(): void
    {
        $mp4 = $this->makeMp4();
        $user = $this->makeUser();

        $account = GoogleAccount::factory()->create([
            'user_id' => $user->id,
            'email' => 'drive-'.uniqid().'@example.test',
            'access_token' => 'TOKEN_DRIVE_UJI',
        ]);

        // 3.5 GB: di atas batas lama, persis kelas ukuran yang dilaporkan.
        $file = $this->makeVideo($user, 3810019639);
        $file->google_account_id = $account->id;
        $file->save();

        // Token dianggap segar (tidak menyentuh Google).
        $tokens = \Mockery::mock(GoogleTokenService::class)->makePartial();
        $tokens->shouldReceive('ensureFreshToken')->andReturnUsing(function ($acct) {
            $acct->access_token = 'TOKEN_DRIVE_UJI';

            return $acct->access_token;
        });
        $this->app->instance(GoogleTokenService::class, $tokens);

        // ffmpeg palsu: rekam argumen, hasilkan JPEG asli dari fixture.
        // Path ffmpeg NYATA diambil sebelum PATH diubah, supaya stub tidak
        // memanggil dirinya sendiri (percobaan pertama gagal: `command -v
        // ffmpeg` di dalam stub menunjuk stub itu sendiri → exit 127).
        $realFfmpeg = trim((string) shell_exec('command -v ffmpeg'));
        if ($realFfmpeg === '' || ! is_file($realFfmpeg)) {
            $this->markTestSkipped('binary ffmpeg nyata tidak ditemukan.');
        }

        $stub = sys_get_temp_dir().'/ffmpeg_stub_'.uniqid().'.sh';
        $argsFile = sys_get_temp_dir().'/ffmpeg_args_'.uniqid().'.txt';
        file_put_contents($stub, implode("\n", [
            '#!/bin/sh',
            'printf "%s\n" "$@" > '.escapeshellarg($argsFile),
            'for last; do :; done',
            'exec '.escapeshellarg($realFfmpeg).' -hide_banner -loglevel error -i '
                .escapeshellarg($mp4).' -frames:v 1 -f image2 -update 1 "$last"',
        ])."\n");
        chmod($stub, 0755);

        // `detectFfmpeg()` memakai `which ffmpeg`; arahkan PATH ke stub.
        $oldPath = getenv('PATH');
        putenv('PATH='.dirname($stub).':'.$oldPath);
        $named = dirname($stub).'/ffmpeg';
        @unlink($named);
        symlink($stub, $named);

        try {
            (new GenerateThumbnailJob($file->id))->handle(
                app(ThumbnailGenerator::class),
                app(FileSourceFetcher::class),
            );
        } finally {
            putenv('PATH='.$oldPath);
            @unlink($named);
            @unlink($stub);
        }

        $this->assertFileExists($argsFile, 'ffmpeg harus dipanggil untuk video 3.5 GB.');
        $args = (string) file_get_contents($argsFile);
        @unlink($argsFile);

        $this->assertStringContainsString(
            'googleapis.com/drive/v3/files/'.$file->gdrive_file_id,
            $args,
            'Frame harus diambil dari URL Drive, bukan salinan lokal.',
        );
        $this->assertStringContainsString(
            'TOKEN_DRIVE_UJI',
            $args,
            'ffmpeg butuh header Authorization untuk membaca Drive.',
        );
        $this->assertStringNotContainsString(
            'stream_src',
            $args,
            'Tidak boleh ada berkas hasil unduhan yang dijadikan masukan.',
        );

        $this->assertSame(
            1,
            DB::table('thumbnails')->where('file_id', $file->id)->count(),
            'Thumbnail video besar harus tersimpan.',
        );
    }

    /** Tanpa akun Google, job tidak boleh melempar — cukup dilewati. */
    public function test_video_tanpa_akun_google_tidak_melempar(): void
    {
        $user = $this->makeUser();
        $file = $this->makeVideo($user, 2 * 1024 * 1024 * 1024);

        (new GenerateThumbnailJob($file->id))->handle(
            app(ThumbnailGenerator::class),
            app(FileSourceFetcher::class),
        );

        $this->assertSame(0, DB::table('thumbnails')->where('file_id', $file->id)->count());
    }
}
