<?php

declare(strict_types=1);

$delayMilliseconds = (int) ($argv[1] ?? 1200);
\usleep($delayMilliseconds * 1000);
echo "done\n";
