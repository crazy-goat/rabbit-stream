<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/examples', __DIR__ . '/bin'])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setUnsupportedPhpVersionAllowed(true)
    ->setRules([
        '@PSR12' => true,
        // The code uses both `fn(` and `fn (`, and both `new class {` and `new class (`.
        // Keep these spacing rules off, as PHP_CodeSniffer did not check them.
        'function_declaration' => false,
        'class_definition' => false,
        'new_with_parentheses' => ['anonymous_class' => false],
        'declare_strict_types' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays']],
        'ternary_to_null_coalescing' => true,
        'single_blank_line_at_eof' => true,
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache');
