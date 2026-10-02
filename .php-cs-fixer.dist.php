<?php

declare(strict_types=1);

use PhpCsFixer\Finder;
use Sirix\CsFixerConfig\ConfigBuilder;

$finder = Finder::create()
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/test')
    // PHP 8.4-only property-hook syntax; the linter cannot parse it on PHP 8.2/8.3.
    ->notPath('Support/PropertyHookFixtures.php')
;

return ConfigBuilder::create()
    ->setFinder($finder)
    ->setRules([
        '@PHP8x2Migration' => true,
        'Gordinskiy/line_length_limit' => false,
        'php_unit_test_class_requires_covers' => false,
        'php_unit_internal_class' => false,
        'phpdoc_to_comment' => false,
        'no_extra_blank_lines' => false,
        'method_argument_space' => false,
        'PedroTroller/line_break_between_method_arguments' => false,
    ])
    ->getConfig()
    ->setCacheFile('data/cache/.php-cs-fixer.cache')
;
