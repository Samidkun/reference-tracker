<?php

namespace App\Providers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Resolve {reference} from the signed-in user's own library.
        //
        // Without this, implicit binding found ANY reference by id: a foreign
        // row returned 403 while a missing id returned 404, so an
        // authenticated user could enumerate which ids exist (an existence
        // oracle). Resolving through the owner's relation makes both cases
        // 404, and guarantees the FormRequest never validates a row the user
        // cannot touch - so validation cannot be used as a second oracle.
        Route::bind('reference', function (string $value) {
            $user = Auth::user();

            abort_unless($user !== null, 404);

            return $user->references()->findOrFail($value);
        });
    }
}
