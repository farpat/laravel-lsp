<?php

use App\Lsp\Support\Position;

it('counts the characters before an offset in UTF-16 units, as the editor does', function () {
    $text = "<?php\n/* 😀 */ \$name";

    expect(Position::fromOffset($text, (int) strpos($text, '$name')))->toBe([
        'line'      => 1,
        'character' => 9,
    ]);
});
