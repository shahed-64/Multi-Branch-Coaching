
<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';

Route::get('/{any?}', function () {
    return file_get_contents(public_path('app/index.html'));
})->where('any', '^(?!api).*$');
