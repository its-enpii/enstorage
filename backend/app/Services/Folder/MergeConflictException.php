<?php

namespace App\Services\Folder;

use RuntimeException;

/**
 * Dilempar saat merge folder dengan `conflict=fail` menemukan nama anak
 * yang bentrok. FolderController menangkapnya → 422 + rollback transaksi.
 */
class MergeConflictException extends RuntimeException {}
