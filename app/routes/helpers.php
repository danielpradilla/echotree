<?php

declare(strict_types=1);

function base_path($request): string
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    return rtrim(str_replace('/index.php', '', $script), '/');
}

function url_for($request, string $path): string
{
    return base_path($request) . $path;
}
