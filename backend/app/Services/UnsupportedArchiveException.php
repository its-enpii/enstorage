<?php

namespace App\Services;

use RuntimeException;

/**
 * Arsip tidak valid / format tidak didukung. Controller memetakan ini
 * ke envelope `not_found` (HTTP 200) sesuai konvensi repo.
 */
class UnsupportedArchiveException extends RuntimeException {}
