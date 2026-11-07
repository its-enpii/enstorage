<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Locks the ffmpeg dependency parity between the app image and the queue
 * worker image.
 *
 * GenerateThumbnailJob runs on the worker (queue), not on the app
 * container, so if only backend/Dockerfile installs ffmpeg the job's
 * detectFfmpeg() returns null on the worker and every video is silently
 * skipped while documents keep working. That regression shipped once;
 * these tests fail if either image stops installing ffmpeg.
 */
class DockerfileFfmpegParityTest extends TestCase
{
    private function contents(string $relativePath): string
    {
        $path = base_path($relativePath);

        $this->assertFileExists($path, "Dockerfile not found: {$relativePath}");

        return (string) file_get_contents($path);
    }

    /**
     * Flatten a Dockerfile by collapsing line continuations, then return the
     * single logical `apk add ...` command(s) found in it.
     *
     * Dockerfiles only use LF, so matching "\n" is sufficient here.
     *
     * @return list<string>
     */
    private function apkAddCommands(string $contents): array
    {
        $flat = (string) preg_replace('/\\\\[ \t]*\n/', ' ', $contents);

        $commands = [];
        $offset = 0;
        while (($pos = strpos($flat, 'apk add', $offset)) !== false) {
            // Stop at the next shell chain operator (`&&`, `;`, `|`).
            $rest = substr($flat, $pos);
            $end = strcspn($rest, ';|&');
            $commands[] = rtrim(substr($rest, 0, $end));
            $offset = $pos + 7;
        }

        return $commands;
    }

    /**
     * @return list<string>
     */
    private function installedPackages(string $dockerfile): array
    {
        $packages = [];

        foreach ($this->apkAddCommands($this->contents($dockerfile)) as $command) {
            foreach (preg_split('/\s+/', $command) ?: [] as $token) {
                if ($token === '' || in_array($token, ['apk', 'add', '--no-cache'], true)) {
                    continue;
                }
                $packages[] = $token;
            }
        }

        return $packages;
    }

    public function test_app_image_installs_ffmpeg(): void
    {
        $this->assertContains(
            'ffmpeg',
            $this->installedPackages('Dockerfile'),
            'backend/Dockerfile must keep installing ffmpeg (app container baseline).'
        );
    }

    public function test_worker_image_installs_ffmpeg(): void
    {
        $this->assertContains(
            'ffmpeg',
            $this->installedPackages('docker/worker.Dockerfile'),
            'worker.Dockerfile must install ffmpeg: GenerateThumbnailJob runs on the queue worker.'
        );
    }

    public function test_worker_does_not_remove_ffmpeg_in_apk_del(): void
    {
        $flat = (string) preg_replace('/\\\\[ \t]*\n/', ' ', $this->contents('docker/worker.Dockerfile'));

        if (preg_match('/apk del[^;&|]*/', $flat, $matches) === 1) {
            $removed = preg_split('/\s+/', $matches[0]) ?: [];
            $this->assertNotContains(
                'ffmpeg',
                $removed,
                'ffmpeg is a runtime dependency and must not be removed by `apk del`.'
            );
        } else {
            $this->assertStringNotContainsString('apk del', $flat);
        }
    }
}
