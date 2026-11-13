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

    expect($provider->declaredNames($document))->toBe([
        ['name' => 'home', 'line' => 2, 'character' => 46],
        ['name' => 'admin.dashboard', 'line' => 5, 'character' => 55],
        ['name' => 'admin.shops.index', 'line' => 8, 'character' => 70],
        ['name' => 'api.ping', 'line' => 12, 'character' => 86],
    ]);
    expect(array_column($provider->counts($document), 'count', 'line'))->toBe([
        2 => 1,
        5 => 1,
        8 => 1,
    ]);
});
