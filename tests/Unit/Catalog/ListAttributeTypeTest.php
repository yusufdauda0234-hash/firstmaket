<?php

use App\Shared\Enums\AttributeType;

it('splits one point per line into items', function () {
    $items = AttributeType::BulletList->cast("45.7MP sensor\n153-point AF\n4K UHD at 30fps");

    expect($items)->toBe(['45.7MP sensor', '153-point AF', '4K UHD at 30fps']);
});

it('drops blank lines', function () {
    expect(AttributeType::BulletList->cast("One\n\n\nTwo\n  \n"))->toBe(['One', 'Two']);
});

it('strips bullet characters people paste in', function (string $input) {
    // The page draws its own markers, so a pasted one would show twice.
    expect(AttributeType::BulletList->cast($input))->toBe(['A feature']);
})->with([
    'dash' => '- A feature',
    'asterisk' => '* A feature',
    'bullet' => '• A feature',
    'numbered' => '1. A feature',
    'numbered bracket' => '2) A feature',
    'indented' => '   -   A feature',
]);

it('rescues a paragraph that was already a list in disguise', function () {
    /*
     * The reason this type exists. A "Long text" key-features field held one
     * run-on paragraph of " - " separated points, which read as a wall of
     * text. Switching that field to a list must recover the points rather
     * than throw the vendor's work away.
     */
    $items = AttributeType::BulletList->cast(
        'Key Features - Capture stunning detail with the 45.7MP sensor. - Achieve tack-sharp focus. - Create 4K UHD video.'
    );

    expect($items)->toBe([
        'Key Features',
        'Capture stunning detail with the 45.7MP sensor.',
        'Achieve tack-sharp focus.',
        'Create 4K UHD video.',
    ]);
});

it('does not mistake a decimal at the start of a line for a marker', function () {
    // "45.7MP sensor" must not be read as item 45 and lose its first digits.
    expect(AttributeType::BulletList->cast('45.7MP full-frame sensor'))
        ->toBe(['45.7MP full-frame sensor'])
        ->and(AttributeType::BulletList->cast('1.5m cable included'))
        ->toBe(['1.5m cable included']);
});

it('accepts an array as it comes from the form', function () {
    expect(AttributeType::NumberedList->cast(['Unbox it', 'Charge it', '']))
        ->toBe(['Unbox it', 'Charge it']);
});

it('offers the items to anything that can draw a list', function () {
    expect(AttributeType::BulletList->items("One\nTwo"))->toBe(['One', 'Two'])
        // Only list types have items; everything else has a single value.
        ->and(AttributeType::Text->items('One'))->toBe([]);
});

it('still reads as a string where a list has nowhere to go', function () {
    expect(AttributeType::BulletList->display(['One', 'Two']))->toBe('One • Two');
});

it('validates the items, not just the array', function () {
    expect(AttributeType::BulletList->rulesFor(true))->toBe(['required', 'array', 'max:30'])
        ->and(AttributeType::NumberedList->eachRulesFor())->toBe(['string', 'max:300']);
});

it('is offered to staff as two distinct choices', function () {
    // The admin field manager builds its dropdown from the enum, so this is
    // all that is needed for the type to appear there.
    expect(AttributeType::BulletList->label())->toBe('Bullet list')
        ->and(AttributeType::NumberedList->label())->toBe('Numbered list')
        ->and(AttributeType::BulletList->isList())->toBeTrue()
        ->and(AttributeType::Textarea->isList())->toBeFalse();
});

it('does not ask for a list of choices', function () {
    // Only select and multiselect need options configured in admin.
    expect(AttributeType::BulletList->hasOptions())->toBeFalse();
});
