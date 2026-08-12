<?php

declare(strict_types=1);

/*
 * The rules specific to this package. The fleet's shared ones ship with the
 * testbench and run from vendor/ alongside these.
 *
 * Three of them are properties of the source rather than of a run — "no sibling
 * module is reached for", "no float touches a figure", "no rate reaches the
 * markup" — and the only way to state a property of the source is to read it.
 *
 * Tax owns rate determination, the arithmetic and the evidence. It owns no
 * price, no order and no filing, and a presentation package for it inherits
 * every one of those exclusions: it reaches for none of the five sibling
 * modules the domain package disclaims, by name, so that renaming one cannot
 * quietly drop it out of a pattern.
 */

const SIBLING_NAMESPACES = [
    'Liberu\Ecommerce\Pricing\\',
    'Liberu\Ecommerce\Cart\\',
    'Liberu\Ecommerce\Checkout\\',
    'Liberu\Ecommerce\Orders\\',
    'Liberu\Ecommerce\Refunds\\',
    'Liberu\Ecommerce\MultiTenderPayments\\',
];

const SIBLING_PACKAGES = [
    'liberusoftware/ecommerce-pricing',
    'liberusoftware/ecommerce-cart',
    'liberusoftware/ecommerce-checkout',
    'liberusoftware/ecommerce-orders',
    'liberusoftware/ecommerce-refunds',
    'liberusoftware/ecommerce-multi-tender-payments',
];

/**
 * Every PHP file the package ships under src/.
 *
 * @return list<string>
 */
function sourceFiles(): array
{
    $files = [];
    $directory = new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src');

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator($directory) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

/**
 * The package's executable source, with every comment stripped out.
 *
 * The comments here quote what is forbidden so a reader can see why, and a
 * naive grep would find the quotation and call it the offence. Stripping
 * comments is what makes "there is no `round(` in this package" a statement
 * about the code.
 */
function sourceCode(): string
{
    $code = '';

    foreach (sourceFiles() as $file) {
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }
    }

    return $code;
}

it('reaches for no sibling module in its source', function (string $namespace) {
    foreach (sourceFiles() as $file) {
        // `toContain` is variadic, so it takes the needle and nothing else: a
        // failure message passed here would be read as a second needle.
        expect((string) file_get_contents($file))->not->toContain($namespace);
    }
})->with(SIBLING_NAMESPACES);

it('requires no sibling module in its manifest', function (string $package) {
    $composer = json_decode(
        (string) file_get_contents(dirname(__DIR__, 2).'/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($composer['require'])->not->toHaveKey($package)
        ->and($composer['require-dev'])->not->toHaveKey($package);
})->with(SIBLING_PACKAGES);

it('names every sibling it disclaims, so a rename cannot quietly drop one', function () {
    expect(SIBLING_NAMESPACES)->toHaveCount(6)
        ->and(SIBLING_PACKAGES)->toHaveCount(6);
});

it('keeps Filament out of a Livewire surface', function () {
    // The shared rule skips a module whose category is `presentation`, which
    // this one is. That skip exists for `-filament` packages; a Livewire
    // surface has no more business importing a panel framework than a domain
    // module does, so the rule is restated here rather than inherited away.
    expect(sourceCode())->not->toContain('Filament\\');
});

it('computes no figure in floating point', function (string $construct) {
    expect(sourceCode())->not->toContain($construct);
})->with([
    'round(',
    'number_format(',
    'floatval(',
    '(float)',
    '(double)',
    'sprintf(',
]);

it('renders no rate, jurisdiction or registration column in its markup', function (string $column) {
    // Comments stripped for the same reason the PHP source is: the comment at
    // the top of that view names what it deliberately leaves out, and a naive
    // grep would read the explanation as the offence.
    //
    // The form field a shopper types their own number into is called
    // `registration_number` and is not on this list. It is an input, not a
    // disclosure — what must never appear is the *seller's*, and
    // `tests/Feature/DisclosureTest.php` asserts that against real markup.
    $view = (string) preg_replace(
        '/\{\{--.*?--\}\}/s',
        '',
        (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/breakdown.blade.php'),
    );

    expect($view)->not->toContain($column);
})->with([
    'basis_points',
    'jurisdiction_code',
    'rate_version',
    'no_tax_reason',
    'treatment',
    'claimed_registration_number',
    'validation_authority',
    'validation_response',
]);

it('holds the domain package in both require and require-dev', function () {
    // In `require` because it is a runtime dependency, and in `require-dev`
    // because that is what tells PackageTestCase to boot the sibling's declared
    // provider: without it the domain's migrations never run and every test
    // here fails on a missing table rather than on anything it asserted.
    $composer = json_decode(
        (string) file_get_contents(dirname(__DIR__, 2).'/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($composer['require'])->toHaveKey('liberusoftware/ecommerce-tax')
        ->and($composer['require-dev'])->toHaveKey('liberusoftware/ecommerce-tax')
        ->and($composer['require']['liberusoftware/ecommerce-tax'])
        ->toBe($composer['require-dev']['liberusoftware/ecommerce-tax']);
});

it('declares the VCS repository its domain package is published from', function () {
    // Composer honours `repositories` only from the root manifest. That is
    // enough for this package's own CI, where it is root — and not enough for a
    // host, which needs the same entry. `docs/adoption.md` says so.
    $composer = json_decode(
        (string) file_get_contents(dirname(__DIR__, 2).'/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($composer['repositories'])->toHaveCount(1)
        ->and($composer['repositories'][0]['url'])
        ->toBe('https://github.com/liberusoftware/module-ecommerce-tax');
});
