<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/tests')
    // The binding tester driver (tests/bindingtester) is exercised by the
    // upstream bindingtester harness itself, not by the coding standard.
    ->exclude('bindingtester')
;

return (new PhpCsFixer\Config())
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache')
    ->setFinder($finder)
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2x0' => true,
        // Equivalent of the old Slevomat rules.
        'declare_strict_types' => true,
        'ordered_imports' => [
            'sort_algorithm' => 'alpha',
            'imports_order' => ['class', 'function', 'const'],
        ],
        'no_unused_imports' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays']],
        'ternary_to_null_coalescing' => true,
        // The existing code style keeps the braces of an empty body on separate
        // lines, as PSR-12 did; PER-CS would collapse them to `{}`.
        'single_line_empty_body' => false,
    ])
;
