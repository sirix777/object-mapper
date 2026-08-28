<?php

declare(strict_types=1);

use Sirix\CsFixerConfig\ConfigBuilder;

return ConfigBuilder::create()
    ->inDir(__DIR__ . '/src')
    ->inDir(__DIR__ . '/test')
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
