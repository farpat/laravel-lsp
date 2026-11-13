<?php

use App\Lsp\Document;
use App\Lsp\Features\References\RouteNameReferenceProvider;
use App\Lsp\Support\FileUri;

$routes = <<<'PHP'
<?php

Route::get('/', HomeController::class)->name('home');

Route::prefix('admin')->name('admin.')->middleware('auth')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::as('shops.')->group(function () {
        Route::get('/shops', [ShopController::class, 'index'])->name('index');
    });
});

Route::name('api.')->group(fn () => Route::get('/ping', PingController::class)->name('ping'));
PHP;

it('reads a route name with the name prefixes of the groups around it', function () use ($routes) {
    $built = throwawayProject([
        'routes/web.php'     => $routes,
        'app/Navigation.php' => "<?php route('admin.dashboard'); route('admin.shops.index'); to_route('home'); route('dashboard');",
    ]);
    $provider = new RouteNameReferenceProvider($built['project'], $built['index']);
    $document = new Document((string) FileUri::fromPath($built['path'] . '/routes/web.php'), $routes);

    expect($provider->declaredLines($document))->toBe([
        2  => 'home',
        5  => 'admin.dashboard',
        8  => 'admin.shops.index',
        12 => 'api.ping',
    ]);
    expect($provider->counts($document))->toBe([
        2 => 1,
        5 => 1,
        8 => 1,
    ]);
});
