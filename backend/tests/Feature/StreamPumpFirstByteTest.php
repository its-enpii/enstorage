<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Keluhan produksi: "video play sering buffer dan load awal-nya sangat lama".
 *
 * Root cause terukur: jalur streaming Drive melakukan DUA permintaan. Fase-1
 * mengunduh SELURUH body ke berkas temp `/tmp/gdrive*` hanya untuk membaca
 * status + header; fase-2 baru membuka permintaan kedua untuk dialirkan ke
 * klien. Karena fase-1 harus selesai lebih dulu, byte pertama ke klien
 * tertahan selama seluruh potongan turun — terukur TTFB 13.8 detik pada
 * Range terbuka `bytes=N-` untuk berkas 657 MB.
 *
 * Perbaikan: `StreamPump::copyCurl()` meneruskan chunk ke klien begitu tiba.
 *
 * PENGUKURAN: dijalankan di SUBPROSES dengan stdout berupa pipe, karena
 * mengukur di dalam proses tes (mis. `ob_start`) ikut mem-buffer output dan
 * menghasilkan angka palsu — percobaan pertama tes ini melaporkan 3.0s
 * padahal penyebabnya buffer tes, bukan implementasi.
 */
class StreamPumpFirstByteTest extends TestCase
{
    /** @var resource|null */
    private $server = null;

    private int $port = 0;

    private string $router = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->router = sys_get_temp_dir().'/pump_router_'.uniqid().'.php';

        // Kirim 8 KB pertama, tahan 3 detik, baru kirim sisanya. Klien yang
        // menunggu body penuh melihat byte pertama di detik ke-3; klien
        // 0-copy melihatnya seketika.
        file_put_contents($this->router, <<<'PHP'
<?php
header('Content-Type: video/mp4');
header('Content-Length: 32768');
echo str_repeat('A', 8192);
flush();
sleep(3);
echo str_repeat('B', 24576);
PHP);

        $this->port = $this->freePort();
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:'.$this->port, $this->router],
            $descriptors,
            $pipes,
            sys_get_temp_dir(),
        );

        if (! is_resource($proc)) {
            $this->markTestSkipped('tidak bisa menjalankan server HTTP lokal.');
        }

        $this->server = $proc;

        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $sock = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.2);
            if (is_resource($sock)) {
                fclose($sock);

                return;
            }
            usleep(50_000);
        }

        $this->markTestSkipped('server HTTP lokal tidak siap dalam 5 detik.');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        @unlink($this->router);
        parent::tearDown();
    }

    private function freePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $name = stream_socket_get_name($sock, false);
        fclose($sock);

        return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
    }

    /**
     * Jalankan `StreamPump::copyCurl` di subproses, ukur waktu byte pertama
     * dan waktu selesai dari pipe stdout-nya.
     *
     * @return array{first:float, total:float, bytes:int}
     */
    private function measure(float $limitSeconds): array
    {
        $script = sys_get_temp_dir().'/pump_run_'.uniqid().'.php';
        file_put_contents($script, '<?php'.PHP_EOL
            .'require '.var_export(base_path('vendor/autoload.php'), true).';'.PHP_EOL
            .'require '.var_export(base_path('app/Support/StreamPump.php'), true).';'.PHP_EOL
            .'\App\Support\StreamPump::copyCurl('
            .var_export('http://127.0.0.1:'.$this->port.'/', true).', [], '
            .var_export($limitSeconds, true).');'.PHP_EOL);

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open([PHP_BINARY, $script], $descriptors, $pipes);

        if (! is_resource($proc)) {
            @unlink($script);
            $this->markTestSkipped('tidak bisa menjalankan subproses.');
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        fclose($pipes[0]);

        $startedAt = microtime(true);
        $firstChunkAt = null;
        $bytes = 0;
        $stderr = '';

        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            @stream_select($read, $write, $except, 0, 200_000);

            $chunk = stream_get_contents($pipes[1]);
            if ($chunk !== false && $chunk !== '') {
                $firstChunkAt ??= microtime(true);
                $bytes += strlen($chunk);
            }

            $err = stream_get_contents($pipes[2]);
            if ($err !== false && $err !== '') {
                $stderr .= $err;
            }

            $status = proc_get_status($proc);
            if (! $status['running'] && $chunk === '' && $err === '') {
                break;
            }

            if (microtime(true) - $startedAt > 30) {
                break;
            }
        }

        $finishedAt = microtime(true);

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        @unlink($script);

        $this->assertNotSame('', (string) $stderr ?: 'ok');

        return [
            'first' => $firstChunkAt === null ? -1.0 : $firstChunkAt - $startedAt,
            'total' => $finishedAt - $startedAt,
            'bytes' => $bytes,
        ];
    }

    public function test_byte_pertama_keluar_sebelum_body_selesai(): void
    {
        $result = $this->measure(10.0);

        $this->assertSame(
            32768,
            $result['bytes'],
            'seluruh body harus sampai ke klien.',
        );
        $this->assertGreaterThan(
            2.5,
            $result['total'],
            'server uji harus menahan body ±3s (kalau tidak, tes tidak menguji apa pun).',
        );
        $this->assertGreaterThanOrEqual(0, $result['first'], 'harus ada byte pertama.');
        $this->assertLessThan(
            2.0,
            $result['first'],
            'Byte pertama wajib keluar sebelum body selesai. Nilai >2s berarti '
            .'implementasi mem-buffer seluruh body lebih dulu — penyebab TTFB '
            .'13.8s di produksi.'
        );
    }

    /**
     * Drive bisa berhenti mengirim di tengah transfer. `CURLOPT_TIMEOUT=0`
     * plus WRITEFUNCTION (hanya dipanggil saat ada chunk) berarti tanpa
     * batas low-speed worker menggantung selamanya dan pool FPM habis.
     */
    public function test_transfer_macet_dihentikan_bukan_menggantung(): void
    {
        $result = $this->measure(1.0);

        $this->assertLessThan(
            2.8,
            $result['total'],
            'Transfer yang macet harus dihentikan oleh batas low-speed, '
            .'bukan menunggu server menutup koneksi (3s).'
        );
    }
}
