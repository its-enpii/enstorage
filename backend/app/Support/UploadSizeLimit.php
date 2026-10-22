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
}
