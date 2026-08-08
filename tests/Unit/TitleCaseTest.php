<?php

use App\Shared\Support\TitleCase;

/**
 * Names are stored SHOUTING so they sort and match consistently; this is
 * the layer that makes them presentable again without wrecking the model
 * numbers and acronyms a Nigerian electronics catalogue is full of.
 */
it('title-cases a plain name', function () {
    expect(TitleCase::format('CHINEDU OKAFOR'))->toBe('Chinedu Okafor');
});

it('leaves an acronym in capitals', function (string $input, string $expected) {
    expect(TitleCase::format($input))->toBe($expected);
})->with([
    ['SAMSUNG 55" QLED SMART TV', 'Samsung 55" QLED Smart TV'],
    ['LG 43" FULL HD LED TELEVISION', 'LG 43" Full HD LED Television'],
    ['USB TYPE-C CABLE', 'USB Type-C Cable'],
    ['HP PAVILION 15 CORE I5 LAPTOP', 'HP Pavilion 15 Core I5 Laptop'],
]);

it('does not touch a token carrying a digit', function (string $input, string $expected) {
    expect(TitleCase::format($input))->toBe($expected);
})->with([
    // A hyphen inside a model number must not split it into words.
    ['SONY WH-CH720N NOISE CANCELLING HEADPHONES', 'Sony WH-CH720N Noise Cancelling Headphones'],
    ['INFINIX NOTE 40 PRO 256GB', 'Infinix Note 40 Pro 256GB'],
    ['ORAIMO 20000MAH POWER BANK', 'Oraimo 20000MAH Power Bank'],
]);

it('writes company abbreviations as words rather than initialisms', function () {
    expect(TitleCase::format('BRIGHT ELECTRONICS LTD'))->toBe('Bright Electronics Ltd')
        ->and(TitleCase::format('AMASHPAY NIG. LIMITED'))->toBe('Amashpay Nig. Limited');
});

it('keeps minor words lower case except at the start', function () {
    expect(TitleCase::format('MEN AND WOMEN OF THE HOUSE'))->toBe('Men and Women of the House')
        ->and(TitleCase::format('THE HOUSE OF WRAPPERS'))->toBe('The House of Wrappers');
});

it('splits an address on its punctuation', function () {
    expect(TitleCase::format('12 MARINA ROAD, ETI-OSA'))->toBe('12 Marina Road, Eti-Osa');
});

it('keeps a single-letter initial capitalised', function () {
    expect(TitleCase::format('YAKUBU D. MUSA'))->toBe('Yakubu D. Musa');
});

it('leaves text that is already mixed case alone', function () {
    // Mixed case means a human chose it; only all-caps text is ours.
    expect(TitleCase::format('iPhone 15 Pro Max'))->toBe('iPhone 15 Pro Max');
});

it('handles empty and null', function () {
    expect(TitleCase::format(null))->toBeNull()
        ->and(TitleCase::format('   '))->toBeNull();
});

it('preserves non-Latin text', function () {
    expect(TitleCase::format('ÀMÙ ONÍRÙ'))->toBe('Àmù Onírù');
});
