<?php

declare(strict_types=1);

require __DIR__ . '/../app/env.php';
load_env(__DIR__ . '/../.env');

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/posts.php';

publish_due_posts(function (string $line): void {
    fwrite(STDOUT, $line);
});
