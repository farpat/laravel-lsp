<?php

use App\Lsp\Document;
use App\Lsp\Features\Events\DispatchedEvents;

it('places a dispatched event after an emoji in UTF-16 units', function () {
    $built = throwawayProject([]);
    $events = new class($built['project']) extends DispatchedEvents
    {
        protected function resolve(Document $document, string $written): ?string
        {
            return $written;
        }
    };

    $sites = $events->in(new Document('file:///app/Pay.php', '<?php /* 😀 */ event(new OrderPaid($order));'));

    expect($sites[0]['range'])->toBe([
        'start' => ['line' => 0, 'character' => 25],
        'end'   => ['line' => 0, 'character' => 34],
    ]);
});
