<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

/**
 * Reads a configuration value loaded via .env (through phpdotenv).
 *
 * Checks $_ENV first, then $_SERVER, then getenv() as a last resort.
 * Some shared hosts disable the putenv() function for security —
 * phpdotenv relies on putenv() to make values visible to getenv(), so on
 * those hosts getenv() silently returns false even though .env loaded
 * correctly into $_ENV. Reading $_ENV directly works regardless of
 * whether putenv() is available.
 */
function envValue(string $name): ?string
{
    if (array_key_exists($name, $_ENV)) {
        return (string) $_ENV[$name];
    }

    if (array_key_exists($name, $_SERVER)) {
        return (string) $_SERVER[$name];
    }

    $value = getenv($name);

    return $value === false ? null : $value;
}
