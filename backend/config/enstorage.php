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

    /*
    |--------------------------------------------------------------------------
    | S3 Public Buckets
    |--------------------------------------------------------------------------
    |
    | Bucket yang mengizinkan operasi baca (GET dan HEAD) tanpa autentikasi.
    | Browser (tag <img>, <video>, tautan unduh) tidak mengirim header S3/API key,
    | sehingga URL publik yang dihasilkan oleh Flysystem `Storage::url()` harus
    | dapat diakses secara terbuka. Default: 'public,sidbm,new_sidbm'.
    |
    */
    'public_buckets' => env('ENSTORAGE_PUBLIC_BUCKETS', 'public,sidbm,new_sidbm'),
];
