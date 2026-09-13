<?php

use App\Lsp\Document;
use App\Lsp\Features\References\ConfigReferenceProvider;
use App\Lsp\Features\References\TranslationKeyReferenceProvider;
use App\Lsp\Project;
use App\Lsp\Support\FileUri;
use App\Lsp\Support\WorkspaceIndex;

/**
 * Open the given file of a throwaway project as a document.
 *
 * @param  array{project: Project, index: WorkspaceIndex, path: string}  $built
 */
function documentOf(array $built, string $file): Document
{
    return new Document(
        (string) FileUri::fromPath($built['path'] . '/' . $file),
        (string) file_get_contents($built['path'] . '/' . $file),
    );
}

$notifications = <<<'PHP'
<?php

return [
    'site_online' => 'Votre site est en ligne',
    'payment_failed' => "Votre paiement n'est pas passé",

    // 'commented' => 'is not a key',
    'admin' => [
        'payment_failed' => 'Impayé : :domain',
        'shop_sold' => 'Vente : :domain',
    ],
];
PHP;

test('reads a translation key with the file and the arrays it sits under', function () use ($notifications) {
    $built = throwawayProject(['lang/fr/notifications.php' => $notifications]);
    $provider = new TranslationKeyReferenceProvider($built['project'], $built['index']);

    expect($provider->declaredLines(documentOf($built, 'lang/fr/notifications.php')))->toBe([
        3 => 'notifications.site_online',
        4 => 'notifications.payment_failed',
        7 => 'notifications.admin',
        8 => 'notifications.admin.payment_failed',
        9 => 'notifications.admin.shop_sold',
    ]);
});

test('counts the usages of a translation key, a nested one apart from its namesake', function () use ($notifications) {
    $built = throwawayProject([
        'lang/fr/notifications.php'      => $notifications,
        'app/Notifications/Paid.php'     => "<?php __('notifications.payment_failed'); trans_choice('notifications.site_online', 2);",
        'app/Notifications/Admin.php'    => "<?php Lang::get('notifications.admin.payment_failed'); __('notifications.admin.payment_failed');",
        'resources/views/mail.blade.php' => "@lang('notifications.site_online') {{ __('other.site_online') }}",
    ]);
    $provider = new TranslationKeyReferenceProvider($built['project'], $built['index']);

    expect($provider->counts(documentOf($built, 'lang/fr/notifications.php')))->toBe([
        3 => 2,
        4 => 1,
        8 => 2,
    ]);
});

test('reads a package translation with its namespace and a deeper file with its slash', function () {
    $built = throwawayProject([
        'lang/vendor/shop/fr/cart.php' => "<?php return ['empty' => 'Panier vide'];",
        'lang/fr/mails/welcome.php'    => "<?php return ['subject' => 'Bienvenue'];",
    ]);
    $provider = new TranslationKeyReferenceProvider($built['project'], $built['index']);

    expect($provider->declaredLines(documentOf($built, 'lang/vendor/shop/fr/cart.php')))->toBe([0 => 'shop::cart.empty']);
    expect($provider->declaredLines(documentOf($built, 'lang/fr/mails/welcome.php')))->toBe([0 => 'mails/welcome.subject']);
});

test('counts the usages of a nested config key', function () {
    $built = throwawayProject([
        'config/services.php' => <<<'PHP'
        <?php

        return [
            'stripe' => [
                'secret_key' => env('STRIPE_SECRET_KEY', ''),
            ],
        ];
        PHP,
        'app/Billing.php' => "<?php config('services.stripe.secret_key'); config('services.stripe');",
    ]);
    $provider = new ConfigReferenceProvider($built['project'], $built['index']);

    expect($provider->counts(documentOf($built, 'config/services.php')))->toBe([
        3 => 1,
        4 => 1,
    ]);
});
