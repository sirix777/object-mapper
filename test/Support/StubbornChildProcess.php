<?php

declare(strict_types=1);

if (\function_exists('pcntl_signal')) {
    \pcntl_signal(SIGTERM, SIG_IGN);
}

$readyPath = $argv[1] ?? '';
if ('' !== $readyPath) {
    \file_put_contents($readyPath, "ready\n");
}

$deadline = \microtime(true) + 2.5;
while (\microtime(true) < $deadline) {
    \usleep(10_000);
}

echo "stubborn-done\n";
