<?php

namespace App\Support;

use App\Services\FileRecordCreator;

final class UploadSizeLimit
{
    public static function canonicalMessage(): string
    {
        return sprintf(
            'Ukuran file melebihi batas maksimum 1 GB (%s byte).',
            number_format(FileRecordCreator::MAX_FILE_SIZE_BYTES, 0, ',', '.'),
        );
    }

    /**
     * Batas chunked upload (docs/api.md §`POST /files/upload/init`): 20 GB.
     * Format sama dengan canonicalMessage() supaya pesan tetap seragam.
     */
    public static function chunkedMessage(): string
    {
        return sprintf(
            'Ukuran file melebihi batas maksimum chunked upload 20 GB (%s byte).',
            number_format(FileRecordCreator::MAX_CHUNKED_FILE_SIZE_BYTES, 0, ',', '.'),
        );
    }
}
