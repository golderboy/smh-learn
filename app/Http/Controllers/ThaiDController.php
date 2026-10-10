<?php

declare(strict_types=1);

namespace App\Http\Controllers;

/** Reserved routes. Fail closed until production integration acceptance. */
class ThaiDController extends Controller
{
    public function redirect(): never
    {
        abort(404);
    }

    public function callback(): never
    {
        abort(404);
    }
}
