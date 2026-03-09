<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ScrapeJobController;
use App\Http\Controllers\WebsiteController;
use App\Models\ScrapeJob;
use App\Models\Website;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard', [
        'stats' => [
            'websites'  => Website::where('user_id', Auth::id())->count(),
            'total_jobs'=> ScrapeJob::where('user_id', Auth::id())->count(),
            'completed' => ScrapeJob::where('user_id', Auth::id())
                              ->where('status','completed')->count(),
            'running'   => ScrapeJob::where('user_id', Auth::id())
                              ->whereIn('status',['pending','running'])->count(),
        ]
    ]);
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// ── Authenticated routes ──────────────────────────────────────────────────────
Route::middleware(['auth', 'verified'])->group(function () {

    // Websites CRUD
    Route::prefix('websites')->name('websites.')->group(function () {
        Route::get('/',              [WebsiteController::class, 'index'])  ->name('index');
        Route::post('/',             [WebsiteController::class, 'store'])  ->name('store');
        Route::put('/{website}',     [WebsiteController::class, 'update']) ->name('update');
        Route::delete('/{website}',  [WebsiteController::class, 'destroy'])->name('destroy');
    });

    // Scraping
    Route::prefix('scraping')->name('scraping.')->group(function () {
        Route::get('/',              [ScrapeJobController::class, 'index'])   ->name('index');
        Route::post('/start',        [ScrapeJobController::class, 'start'])   ->name('start');
        Route::get('/poll',          [ScrapeJobController::class, 'poll'])    ->name('poll');
        Route::get('/history',       [ScrapeJobController::class, 'history']) ->name('history');
        Route::get('/{job}/download',[ScrapeJobController::class, 'download'])->name('download');
        Route::post('/{job}/cancel', [ScrapeJobController::class, 'cancel'])  ->name('cancel');
    });
});

require __DIR__.'/auth.php';
