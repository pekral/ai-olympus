#!/usr/bin/env php
<?php

declare(strict_types = 1);

// Existing sources can contain legacy slots. The acli-only publisher never renders them as media.
if (array_slice($argv ?? [], 1) !== ['strip']) {
    fwrite(STDERR, "usage: screenshot-slots.php strip < body\n");
    exit(2);
}

while (($line = fgets(STDIN)) !== false) {
    if (preg_match('/^\{screenshot:[rf]-[0-9a-f]{16}:(?:head|base)\}\s*$/D', $line) !== 1) {
        echo $line;
    }
}
