<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;

abstract class Controller
{
    // Laravel 11+ ships an empty base controller; the API controllers rely on
    // $this->authorize(...) (policies/gates) and $this->validate(...).
    use AuthorizesRequests;
    use ValidatesRequests;
}
