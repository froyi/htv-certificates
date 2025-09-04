<?php

return [
    // Path to the pdftk binary. You can set this to an absolute path if pdftk is not in PATH.
    // Example (macOS/Homebrew): /opt/homebrew/bin/pdftk-java
    // Example (Linux/Debian): /usr/bin/pdftk
    'binary' => env('PDFTK_PATH', 'pdftk'),
];
