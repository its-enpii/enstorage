<?php

namespace App\Http\Controllers;

use App\Http\ApiResponse;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;

abstract class Controller
{
    use ApiResponse, AuthorizesRequests, ValidatesRequests;
}
