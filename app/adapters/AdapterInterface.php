<?php

declare(strict_types=1);

interface AdapterInterface
{
    public function publish(string $text, string $url, array $account): string;
}
