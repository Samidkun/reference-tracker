<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    // Laravel 13 ships an empty base controller; $this->authorize() only
    // exists if this trait is pulled in explicitly.
    use AuthorizesRequests;
}
