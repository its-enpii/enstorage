<?php

return [
    /*
    |--------------------------------------------------------------------------
    | S3 Signing Prefix
    |--------------------------------------------------------------------------
    |
    | Prefix rute yang TIDAK ikut ditandatangani oleh klien S3 (AWS SDK /
    | Flysystem). AWS SDK menandatangani URI relatif terhadap endpoint, jadi
    | kalau gateway dipasang di `/api/v1/s3`, klien menandatangani `/s3/...`
    | saja. Verifier di gateway harus membuang prefix ini dari canonical URI
    | agar hash-nya cocok. Isi string kosong bila gateway dilayani tepat di
    | root (`/s3/...` langsung tanpa prefix).
    |
    */

    's3_signing_prefix' => env('ENSTORAGE_S3_SIGNING_PREFIX', '/api/v1'),
];
